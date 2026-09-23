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

    public function __construct(?CouriersConfig $config = null)
    {
        $this->config = $config ?? config(CouriersConfig::class);
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
     * @param array<string, string> $paths   key => proxy path
     * @param int                   $ttl     cache TTL for fetched entries
     */
    public function getMany(array $paths, int $ttl): array
    {
        $cache   = service('cache');
        $results = [];
        $misses  = [];

        foreach ($paths as $key => $path) {
            $cacheKey = $this->cacheKey($path);
            $cached   = $cache->get($cacheKey);
            if ($cached !== null) {
                $results[$key] = $cached === 'null' ? null : $cached;
            } else {
                $misses[$key] = $path;
            }
        }

        if ($misses === []) {
            return $results;
        }

        $multi   = curl_multi_init();
        $handles = [];

        foreach ($misses as $key => $path) {
            $ch = curl_init($this->url($path));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => $this->config->proxyTimeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
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

        foreach ($handles as $key => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            $decoded = ($code === 200 && $raw !== false) ? json_decode($raw, true) : null;
            if ($decoded === null) {
                log_message('warning', 'Widget proxy miss {path} (HTTP {code})', [
                    'path' => $misses[$key],
                    'code' => $code,
                ]);
            }

            $results[$key] = $decoded;
            $cache->save($this->cacheKey($misses[$key]), $decoded ?? 'null', $ttl);
        }

        curl_multi_close($multi);

        return $results;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function url(string $path): string
    {
        return rtrim($this->config->proxyBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function cacheKey(string $path): string
    {
        return 'proxy_' . md5($path);
    }

    private function get(string $path): ?array
    {
        $client = single_service('curlrequest', ['timeout' => $this->config->proxyTimeout]);

        try {
            $response = $client->get($this->url($path), [
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('warning', 'Widget proxy error {path}: {msg}', ['path' => $path, 'msg' => $e->getMessage()]);

            return null;
        }
    }

    private function cachedGet(string $path, int $ttl): ?array
    {
        $cache    = service('cache');
        $cacheKey = $this->cacheKey($path);

        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            return $cached === 'null' ? null : $cached;
        }

        $result = $this->get($path);
        $cache->save($cacheKey, $result ?? 'null', $ttl);

        return $result;
    }
}
