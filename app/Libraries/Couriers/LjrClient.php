<?php

namespace App\Libraries\Couriers;

use Config\Couriers as CouriersConfig;

/**
 * LJR (Lestari Jaya Raya) — tracking only.
 *
 * LJR books nothing in this app; it survives from the legacy click-and-collect
 * era, where shipments created elsewhere still had to be traced. The endpoint
 * is a DreamFactory table read: one row per status change on a waybill.
 *
 * The legacy call (`Shopify::get_track_ljr`) indexed the response as a bare
 * list (`$track[3]['statusName']`), while DreamFactory normally wraps rows in
 * a "resource" key. Both shapes are accepted here rather than betting on one.
 */
class LjrClient
{
    private CouriersConfig $config;

    /** Per-request ceiling in seconds; null keeps the 15s default. */
    private ?float $timeout = null;

    public function __construct(?CouriersConfig $config = null)
    {
        $this->config = $config ?? config('Couriers');
    }

    /**
     * Make the next requests give up after $seconds (null: this client's
     * default). Tracking sets it: a shopper is waiting on the page.
     */
    public function setTimeout(?float $seconds): void
    {
        $this->timeout = $seconds === null ? null : max(0.1, $seconds);
    }

    /**
     * Status rows for a waybill, in whatever order LJR returns them.
     * An empty array means "nothing to show" — never an exception, because
     * this is called while rendering a page a shopper is waiting on.
     *
     * @return list<array<string, mixed>>
     */
    public function track(string $awb): array
    {
        if ($this->config->ljrBaseUrl === '' || $this->config->ljrApiKey === '') {
            return [];
        }

        $client = single_service('curlrequest', ['timeout' => $this->timeout ?? 15]);

        try {
            $response = $client->get(rtrim($this->config->ljrBaseUrl, '/') . '/_table/shipment_status', [
                'query' => [
                    'filter'  => 'airwaybillNumber=' . $awb,
                    'api_key' => $this->config->ljrApiKey,
                ],
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
                // Certificates are verified: the API key rides in the query
                // string, so an unverified connection handed it to anyone in
                // the middle. LJR's :10433 host verifies cleanly (checked
                // 2026-09-23).
            ]);

            if ($response->getStatusCode() !== 200) {
                log_message('error', 'LJR tracking failed for {awb} (HTTP {code})', [
                    'awb'  => $awb,
                    'code' => $response->getStatusCode(),
                ]);

                return [];
            }

            $decoded = json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('error', 'LJR tracking error for {awb}: {msg}', ['awb' => $awb, 'msg' => $e->getMessage()]);

            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $rows = $decoded['resource'] ?? $decoded;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
