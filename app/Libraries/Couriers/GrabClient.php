<?php

namespace App\Libraries\Couriers;

use Config\Couriers as CouriersConfig;

/**
 * Client for the Grab Express Delivery API (GRABEXPRESS - INSTANT).
 *
 * Two steps, mirroring the production .NET backend:
 *   1. Fetch a bearer token from Delami's token endpoint (grabTokenUrl). That
 *      endpoint holds Grab's client credentials and returns a ready
 *      {"token": "Bearer …"}, so no client_id/secret lives in this app.
 *   2. POST pickup + drop-off coordinates to Grab's /deliveries/quotes.
 *
 * Everything is null-safe: a token failure or a non-200 quote returns null so
 * the rate engine simply omits Grab rather than breaking the other couriers.
 *
 * Every call goes through send(), which attaches the cached token and, when
 * Grab answers 401, fetches a fresh one and tries once more — a token that
 * expired before its cache entry no longer hides GrabExpress for hours.
 */
class GrabClient
{
    private CouriersConfig $config;

    /** Ceiling for quotes and lookups; null uses couriers.proxyTimeout. */
    private ?float $timeout = null;

    public function __construct(?CouriersConfig $config = null)
    {
        $this->config = $config ?? config(CouriersConfig::class);
    }

    /**
     * Make quotes and lookups give up after $seconds (null: the configured
     * couriers.proxyTimeout). Booking keeps its own, longer timeout.
     */
    public function setTimeout(?float $seconds): void
    {
        $this->timeout = $seconds === null ? null : max(0.1, $seconds);
    }

    /**
     * A single delivery quote, or null.
     *
     * @param array $origin      address/keywords/cityCode/coordinates
     * @param array $destination address/keywords/cityCode/coordinates
     * @param array $packages    Grab package objects
     *
     * @return array|null the first entry of the quotes[] array
     */
    public function quote(array $origin, array $destination, array $packages): ?array
    {
        $reply = $this->send('POST', 'deliveries/quotes', [
            'serviceType' => 'INSTANT',
            'vehicleType' => $this->config->grabVehicleType,
            'packages'    => $packages,
            'origin'      => $origin,
            'destination' => $destination,
        ], $this->lookupTimeout());

        if ($reply === null) {
            return null;
        }

        [$status, $data] = $reply;

        if ($status !== 200) {
            log_message('warning', 'GrabExpress quote failed (HTTP {code}): {body}', [
                'code' => $status,
                'body' => json_encode($data),
            ]);

            return null;
        }

        return $data['quotes'][0] ?? null;
    }

    /**
     * Book (dispatch) a GrabExpress delivery. Returns the decoded response
     * (deliveryID, trackingURL, status, quote…) or null on failure.
     *
     * ⚠️ A successful call DISPATCHES A REAL RIDER and charges the account.
     * Only reached with mock mode off — AwbService mocks it otherwise.
     *
     * Uses couriers.grabBookingTimeout, not the quote timeout. A booking is
     * not racing Shopify's checkout, and giving up early is the dangerous
     * direction: Grab may have dispatched the rider while we report a
     * failure, and the next attempt then dispatches a second one.
     *
     * @param array $drop merchantOrderID, cartTotal, weightKg, dropAddress,
     *                    dropLat, dropLng, recipientFirst/Last/Phone/Email
     */
    public function createDelivery(array $drop): ?array
    {
        $c    = $this->config;
        $body = [
            'merchantOrderID' => (string) $drop['merchantOrderID'],
            'serviceType'     => 'INSTANT',
            'vehicleType'     => $c->grabVehicleType,
            'paymentMethod'   => $c->grabPaymentMethod,
            'packages'        => [[
                'name'       => 'Order',
                'quantity'   => 1,
                'price'      => (int) $drop['cartTotal'],
                'dimensions' => ['weight' => (int) $drop['weightKg']],
            ]],
            'origin' => [
                'address'     => (string) $c->grabOriginAddress,
                'keywords'    => (string) $c->grabOriginKeywords,
                'coordinates' => [
                    'latitude'  => (float) $c->grabOriginLat,
                    'longitude' => (float) $c->grabOriginLng,
                ],
            ],
            'destination' => [
                'address'     => (string) $drop['dropAddress'],
                'coordinates' => [
                    'latitude'  => (float) $drop['dropLat'],
                    'longitude' => (float) $drop['dropLng'],
                ],
            ],
            'sender' => [
                'firstName'   => (string) ($c->shipperBrand ?: $c->shipperName),
                'companyName' => (string) $c->shipperName,
                'phone'       => (string) $c->shipperPhone,
                'smsEnabled'  => false,
            ],
            'recipient' => [
                'firstName'  => (string) $drop['recipientFirst'],
                'lastName'   => (string) ($drop['recipientLast'] ?? ''),
                'email'      => (string) ($drop['recipientEmail'] ?? ''),
                'phone'      => (string) $drop['recipientPhone'],
                'smsEnabled' => true,
            ],
        ];

        $reply = $this->send('POST', 'deliveries', $body, (float) $c->grabBookingTimeout);

        if ($reply === null) {
            return null;
        }

        [$status, $data] = $reply;

        if ($status !== 200 && $status !== 201) {
            log_message('error', 'GrabExpress create delivery failed (HTTP {code}): {body}', [
                'code' => $status,
                'body' => json_encode($data),
            ]);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * The current state of a booked delivery: status, and the timeline of
     * milestones Grab stamps as the rider progresses. Null when the token,
     * the network, or Grab itself will not answer.
     *
     * The delivery id is what AwbService stored as the waybill, so a Grab row
     * can be traced with nothing but its airwaybills record.
     */
    public function getDelivery(string $deliveryId): ?array
    {
        $reply = $this->send('GET', 'deliveries/' . rawurlencode($deliveryId), null, $this->lookupTimeout());

        if ($reply === null) {
            return null;
        }

        [$status, $data] = $reply;

        if ($status !== 200) {
            log_message('error', 'GrabExpress delivery lookup failed for {id} (HTTP {code}): {body}', [
                'id'   => $deliveryId,
                'code' => $status,
                'body' => json_encode($data),
            ]);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * One authorised call to Grab: [status, decoded body], or null when no
     * token could be had or the request never completed.
     *
     * A 401 means Grab no longer accepts the cached token — it can expire
     * before its cache entry does — so the token is dropped, a fresh one is
     * fetched, and the call is made once more. Safe for a booking too: a 401
     * is a refusal, not a delivery.
     *
     * @return array{0: int, 1: mixed}|null
     */
    private function send(string $method, string $path, ?array $body, float $timeout): ?array
    {
        $url = rtrim($this->config->grabBaseUrl, '/') . '/' . $path;

        foreach ([false, true] as $fresh) {
            $token = $this->accessToken($timeout, $fresh);
            if ($token === null) {
                return null;
            }

            $headers = [
                // The token endpoint already includes the "Bearer " prefix.
                'Authorization' => $token,
                'Accept'        => 'application/json',
            ];
            if ($body !== null) {
                $headers['Content-Type'] = 'application/json';
            }

            $response = $this->request($method, $url, $headers, $body === null ? null : json_encode($body), $timeout);
            if ($response === null) {
                return null;
            }

            if ($response['code'] === 401 && ! $fresh) {
                log_message('info', 'GrabExpress rejected the cached token — fetching a fresh one.');

                continue;
            }

            return [$response['code'], json_decode($response['body'], true)];
        }

        return null;
    }

    /**
     * A Grab bearer token (including the "Bearer " prefix), or null.
     *
     * Cached for grabTokenCacheMinutes so a burst of quotes shares one token;
     * $fresh skips the cache and replaces it.
     */
    private function accessToken(float $timeout, bool $fresh = false): ?string
    {
        $cache = service('cache');
        $key   = 'grab_token_' . md5($this->config->grabTokenUrl);

        if (! $fresh) {
            $cached = $cache->get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = $this->request('GET', $this->config->grabTokenUrl, ['Accept' => 'application/json'], null, $timeout);

        if ($response === null || $response['code'] !== 200) {
            log_message('warning', 'GrabExpress token failed (HTTP {code})', ['code' => $response['code'] ?? 0]);

            return null;
        }

        $token = json_decode($response['body'], true)['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        $cache->save($key, $token, max(60, $this->config->grabTokenCacheMinutes * 60));

        return $token;
    }

    /**
     * The one place that touches the network: ['code' => int, 'body' => string],
     * or null when the request did not complete. A test answers in its place.
     *
     * @return array{code: int, body: string}|null
     */
    protected function request(string $method, string $url, array $headers, ?string $body, float $timeout): ?array
    {
        try {
            $options = ['headers' => $headers, 'http_errors' => false];
            if ($body !== null) {
                $options['body'] = $body;
            }

            $response = single_service('curlrequest', ['timeout' => $timeout])->request($method, $url, $options);

            return ['code' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
        } catch (\Throwable $e) {
            log_message('warning', 'GrabExpress {method} {url} error: {msg}', [
                'method' => $method,
                'url'    => $url,
                'msg'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function lookupTimeout(): float
    {
        return $this->timeout ?? (float) $this->config->proxyTimeout;
    }
}
