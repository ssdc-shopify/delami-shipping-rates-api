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
 */
class GrabClient
{
    private CouriersConfig $config;

    public function __construct(?CouriersConfig $config = null)
    {
        $this->config = $config ?? config(CouriersConfig::class);
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
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }

        $client = single_service('curlrequest', ['timeout' => $this->config->proxyTimeout]);

        try {
            $response = $client->post(rtrim($this->config->grabBaseUrl, '/') . '/deliveries/quotes', [
                'headers' => [
                    // The token endpoint already includes the "Bearer " prefix.
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body' => json_encode([
                    'serviceType' => 'INSTANT',
                    'vehicleType' => $this->config->grabVehicleType,
                    'packages'    => $packages,
                    'origin'      => $origin,
                    'destination' => $destination,
                ]),
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                log_message('warning', 'GrabExpress quote failed (HTTP {code}): {body}', [
                    'code' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                ]);

                return null;
            }

            $quotes = json_decode($response->getBody(), true)['quotes'] ?? [];

            return $quotes[0] ?? null;
        } catch (\Throwable $e) {
            log_message('warning', 'GrabExpress quote error: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Book (dispatch) a GrabExpress delivery. Returns the decoded response
     * (deliveryID, trackingURL, status, quote…) or null on failure.
     *
     * ⚠️ A successful call DISPATCHES A REAL RIDER and charges the account.
     * Only reached with mock mode off — AwbService mocks it otherwise.
     *
     * @param array $drop merchantOrderID, cartTotal, weightKg, dropAddress,
     *                    dropLat, dropLng, recipientFirst/Last/Phone/Email
     */
    public function createDelivery(array $drop): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }

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

        $client = single_service('curlrequest', ['timeout' => $this->config->proxyTimeout]);

        try {
            $response = $client->post(rtrim($this->config->grabBaseUrl, '/') . '/deliveries', [
                'headers' => [
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'        => json_encode($body),
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200 && $status !== 201) {
                log_message('error', 'GrabExpress create delivery failed (HTTP {code}): {body}', [
                    'code' => $status,
                    'body' => (string) $response->getBody(),
                ]);

                return null;
            }

            return json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('error', 'GrabExpress create delivery error: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
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
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }

        $client = single_service('curlrequest', ['timeout' => $this->config->proxyTimeout]);

        try {
            $response = $client->get(
                rtrim($this->config->grabBaseUrl, '/') . '/deliveries/' . rawurlencode($deliveryId),
                [
                    'headers' => [
                        'Authorization' => $token,
                        'Accept'        => 'application/json',
                    ],
                    'http_errors' => false,
                ],
            );

            if ($response->getStatusCode() !== 200) {
                log_message('error', 'GrabExpress delivery lookup failed for {id} (HTTP {code}): {body}', [
                    'id'   => $deliveryId,
                    'code' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                ]);

                return null;
            }

            return json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('error', 'GrabExpress delivery lookup error for {id}: {msg}', [
                'id'  => $deliveryId,
                'msg' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A cached Grab bearer token (including the "Bearer " prefix), or null.
     *
     * Cached for grabTokenCacheMinutes so a burst of quotes shares one token.
     */
    private function accessToken(): ?string
    {
        $cache = service('cache');
        $key   = 'grab_token_' . md5($this->config->grabTokenUrl);

        $cached = $cache->get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $client = single_service('curlrequest', ['timeout' => $this->config->proxyTimeout]);

        try {
            $response = $client->get($this->config->grabTokenUrl, [
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                log_message('warning', 'GrabExpress token failed (HTTP {code})', ['code' => $response->getStatusCode()]);

                return null;
            }

            $token = json_decode($response->getBody(), true)['token'] ?? null;
            if (! is_string($token) || $token === '') {
                return null;
            }

            $cache->save($key, $token, max(60, $this->config->grabTokenCacheMinutes * 60));

            return $token;
        } catch (\Throwable $e) {
            log_message('warning', 'GrabExpress token error: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }
}
