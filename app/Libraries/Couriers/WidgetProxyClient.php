<?php

namespace App\Libraries\Couriers;

use Config\Couriers as CouriersConfig;

/**
 * Client for the Delami widget proxy (Colorbox shipping API) — the single
 * upstream for rate/destination lookups (JNE, Ninja Xpress, SPX).
 *
 * Lookups are cached, and independent lookups can be fetched in parallel
 * (curl_multi) so the Shopify CarrierService time budget is respected.
 */
class WidgetProxyClient
{
    private CouriersConfig $config;

    /** Per-request ceiling in seconds; null uses couriers.proxyTimeout. */
    private ?float $timeout = null;

    public function __construct(?CouriersConfig $config = null)
    {
        $this->config = $config ?? config(CouriersConfig::class);
    }

    /**
     * Make the next requests give up after $seconds (null: the configured
     * couriers.proxyTimeout) — how the rate engine spends what is left of
     * Shopify's time budget rather than a fixed 8s per call.
     */
    public function setTimeout(?float $seconds): void
    {
        $this->timeout = $seconds === null ? null : max(0.1, $seconds);
    }

    // ------------------------------------------------------------------
    // Single lookups (cached)
    // ------------------------------------------------------------------

    public function jneDestination(string $zip): ?array
    {
        return $this->cachedGet("get_code_destination/{$zip}", $this->config->destinationCacheTtl);
    }

    public function jneRates(string $origin, string $destination): ?array
    {
        return $this->cachedGet("get_rates_api_jne2/{$origin}/{$destination}", $this->config->rateCacheTtl);
    }

    public function ninjaNxid(string $zip): ?array
    {
        return $this->cachedGet("get_nxid/{$zip}", $this->config->destinationCacheTtl);
    }

    public function ninjaRates(string $nxid): ?array
    {
        return $this->cachedGet("get_rates/{$nxid}", $this->config->rateCacheTtl);
    }

    public function ninjaToken(): ?array
    {
        // Short TTL: upstream token has its own expiry.
        return $this->cachedGet('get_token', 300);
    }

    public function spxZipcode(string $zip): ?array
    {
        return $this->cachedGet("zipcode/{$zip}", $this->config->destinationCacheTtl);
    }

    public function spxRatecard(string $city, string $type): ?array
    {
        return $this->cachedGet('ratecard/' . rawurlencode($city) . "/{$type}", $this->config->rateCacheTtl);
    }

    public function spxDestination(string $district, string $zip): ?array
    {
        return $this->cachedGet('get_spx_destination/' . rawurlencode($district) . "/{$zip}", $this->config->destinationCacheTtl);
    }

    public function trackStatus(string $awb): ?array
    {
        return $this->get("get_status_track/{$awb}");
    }

    /**
     * Ninja's full scan history for a waybill (legacy `get_all_track_ninja`).
     *
     * Deliberately uncached: trackStatus() answers "is it delivered", but this
     * backs a page a shopper refreshes precisely because they want the newest
     * scan, and a cached hour-old timeline would look like a stalled parcel.
     */
    public function trackHistory(string $awb): ?array
    {
        return $this->get('trackorder/' . rawurlencode($awb));
    }

    /**
     * Fetch several proxy paths in parallel. Returns [key => decoded array|null].
     * Cached entries are served from cache; only misses hit the network.
     *
     * @param array<string, string> $paths key => proxy path
     * @param int                   $ttl   cache TTL for an answer
     */
    public function getMany(array $paths, int $ttl): array
    {
        $cache   = service('cache');
        $results = [];
        $misses  = [];

        foreach ($paths as $key => $path) {
            $cached = $cache->get($this->cacheKey($path));
            if ($cached !== null) {
                $results[$key] = $cached === 'null' ? null : $cached;
            } else {
                $misses[$key] = $path;
            }
        }

        if ($misses === []) {
            return $results;
        }

        $urls      = array_map(fn (string $path) => $this->url($path), $misses);
        $responses = $this->fetchMany($urls, $this->timeoutSeconds());

        foreach ($misses as $key => $path) {
            [$value, $failed] = $this->interpret($responses[$key] ?? ['code' => 0, 'body' => false]);

            if ($failed) {
                log_message('warning', 'Widget proxy miss {path} (HTTP {code})', [
                    'path' => $path,
                    'code' => $responses[$key]['code'] ?? 0,
                ]);
            }

            $results[$key] = $value;
            $this->remember($path, $value, $failed, $ttl);
        }

        return $results;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Run several GETs at once. Returns [key => ['code' => int, 'body' => string|false]];
     * a transport failure (timeout, refused connection) reports code 0.
     *
     * The one place that touches the network for getMany(), so a test can
     * answer in its place.
     *
     * @param array<string, string> $urls
     *
     * @return array<string, array{code: int, body: string|false}>
     */
    protected function fetchMany(array $urls, float $timeout): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_FOLLOWLOCATION    => true,
                CURLOPT_MAXREDIRS         => 3,
                CURLOPT_TIMEOUT_MS        => (int) ($timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) (min(3.0, $timeout) * 1000),
                CURLOPT_HTTPHEADER        => ['Accept: application/json'],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running && $status === CURLM_OK);

        $responses = [];

        foreach ($handles as $key => $ch) {
            $responses[$key] = [
                'code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body' => curl_multi_getcontent($ch) ?? false,
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $responses;
    }

    /**
     * One GET. Same shape as fetchMany(), and the same test seam.
     *
     * @return array{code: int, body: string|false}
     */
    protected function fetch(string $url, float $timeout): array
    {
        try {
            $response = single_service('curlrequest', ['timeout' => $timeout])->get($url, [
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            return ['code' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
        } catch (\Throwable $e) {
            log_message('warning', 'Widget proxy error {url}: {msg}', ['url' => $url, 'msg' => $e->getMessage()]);

            return ['code' => 0, 'body' => false];
        }
    }

    /**
     * An upstream reply as [value, failed].
     *
     * A 200 carrying JSON is an ANSWER, even when that JSON says nothing — no
     * coverage for a postcode is a real reply worth remembering. Anything else
     * (no connection, a timeout, a 5xx, an HTML error page) is a FAILURE: it
     * says nothing about the postcode, only about the proxy at that moment.
     *
     * @param array{code: int, body: string|false} $response
     *
     * @return array{0: array|null, 1: bool}
     */
    private function interpret(array $response): array
    {
        if ($response['code'] !== 200 || ! is_string($response['body']) || $response['body'] === '') {
            return [null, true];
        }

        $decoded = json_decode($response['body'], true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [null, true];
        }

        return [is_array($decoded) ? $decoded : null, false];
    }

    /**
     * Cache a lookup. An answer keeps its full TTL; a failure is kept only
     * briefly (couriers.failureCacheTtl).
     *
     * Failures used to be cached exactly like answers — for a day, for a
     * postcode lookup — so a single proxy timeout removed JNE, Ninja and SPX
     * from checkout for that postcode until the entry expired. They are still
     * cached for a moment, so an outage is not met with a request storm.
     */
    private function remember(string $path, ?array $value, bool $failed, int $ttl): void
    {
        service('cache')->save(
            $this->cacheKey($path),
            $value ?? 'null',
            $failed ? $this->config->failureCacheTtl : $ttl,
        );
    }

    private function timeoutSeconds(): float
    {
        return $this->timeout ?? (float) $this->config->proxyTimeout;
    }

    private function url(string $path): string
    {
        return rtrim($this->config->proxyBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function cacheKey(string $path): string
    {
        return 'proxy_' . md5($path);
    }

    /** Uncached GET — for tracking, where a stale answer looks like a stalled parcel. */
    private function get(string $path): ?array
    {
        return $this->interpret($this->fetch($this->url($path), $this->timeoutSeconds()))[0];
    }

    private function cachedGet(string $path, int $ttl): ?array
    {
        $cached = service('cache')->get($this->cacheKey($path));
        if ($cached !== null) {
            return $cached === 'null' ? null : $cached;
        }

        [$value, $failed] = $this->interpret($this->fetch($this->url($path), $this->timeoutSeconds()));
        $this->remember($path, $value, $failed, $ttl);

        return $value;
    }
}
