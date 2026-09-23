<?php

namespace App\Libraries\Couriers;

use Config\Couriers as CouriersConfig;

/**
 * Google Geocoding client — a shipping address → [lat, lng].
 *
 * This is what lets GRABEXPRESS work in native Shopify checkout: the rate
 * callback only ever receives the destination address (never a map pin), so
 * the address is turned into coordinates here. Mirrors the production .NET
 * backend, which geocodes the address when a draft order has no pin.
 *
 * Results are cached (an address's location is stable) and every failure is
 * null-safe, so a geocode miss just means Grab is not offered for that address.
 */
class GeocodeClient
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
     * couriers.proxyTimeout) — the rate engine's share of Shopify's budget.
     */
    public function setTimeout(?float $seconds): void
    {
        $this->timeout = $seconds === null ? null : max(0.1, $seconds);
    }

    /**
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '' || trim($this->config->geocodeApiKey) === '') {
            return null;
        }

        $cache = service('cache');
        $key   = 'geocode_' . md5($address);

        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === 'null') {
            return null;
        }

        $client = single_service('curlrequest', ['timeout' => $this->timeout ?? $this->config->proxyTimeout]);

        try {
            $response = $client->get($this->config->geocodeUrl, [
                'query'       => ['address' => $address, 'key' => $this->config->geocodeApiKey],
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                log_message('warning', 'Geocode failed (HTTP {code})', ['code' => $response->getStatusCode()]);

                return null;
            }

            $data = json_decode($response->getBody(), true);
            $loc  = $data['results'][0]['geometry']['location'] ?? null;

            if (! isset($loc['lat'], $loc['lng'])) {
                // A "no result" is a valid answer worth caching briefly so a bad
                // address is not re-queried on every rate request.
                log_message('info', 'Geocode no result for "{a}" ({s})', ['a' => $address, 's' => $data['status'] ?? '?']);
                $cache->save($key, 'null', 3600);

                return null;
            }

            $coords = [(float) $loc['lat'], (float) $loc['lng']];
            $cache->save($key, $coords, $this->config->geocodeCacheTtl);

            return $coords;
        } catch (\Throwable $e) {
            log_message('warning', 'Geocode error: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Coordinates → address fields (reverse geocoding).
     *
     * Used when a shopper drops a map pin: the address form is filled from the
     * pin so the two never disagree — otherwise the parcel would ship to the
     * typed address while Grab was priced on a different point.
     *
     * @return array{address1: string, address2: string, city: string, province: string, provinceCode: string, zip: string, formatted: string}|null
     */
    public function reverseGeocode(float $lat, float $lng): ?array
    {
        if (trim($this->config->geocodeApiKey) === '') {
            return null;
        }

        $cache = service('cache');
        $key   = 'revgeo_' . md5($lat . ',' . $lng);

        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === 'null') {
            return null;
        }

        $client = single_service('curlrequest', ['timeout' => $this->timeout ?? $this->config->proxyTimeout]);

        try {
            $response = $client->get($this->config->geocodeUrl, [
                'query'       => ['latlng' => $lat . ',' . $lng, 'key' => $this->config->geocodeApiKey, 'language' => 'id'],
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                log_message('warning', 'Reverse geocode failed (HTTP {code})', ['code' => $response->getStatusCode()]);

                return null;
            }

            $result = json_decode($response->getBody(), true)['results'][0] ?? null;
            if ($result === null) {
                $cache->save($key, 'null', 3600);

                return null;
            }

            $address              = $this->addressFromComponents($result['address_components'] ?? []);
            $address['formatted'] = (string) ($result['formatted_address'] ?? '');

            $cache->save($key, $address, $this->config->geocodeCacheTtl);

            return $address;
        } catch (\Throwable $e) {
            log_message('warning', 'Reverse geocode error: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Map Google's address_components to the fields the checkout form uses.
     */
    private function addressFromComponents(array $components): array
    {
        $pick = static function (array $types) use ($components) {
            foreach ($components as $c) {
                if (array_intersect($types, $c['types'] ?? [])) {
                    return $c;
                }
            }

            return null;
        };

        $line1 = trim(($pick(['route'])['long_name'] ?? '') . ' ' . ($pick(['street_number'])['long_name'] ?? ''));
        if ($line1 === '') {
            // No street on the pin — use the neighbourhood/building instead.
            $line1 = $pick(['sublocality', 'sublocality_level_1', 'neighborhood', 'premise'])['long_name'] ?? '';
        }

        $province = $pick(['administrative_area_level_1'])['long_name'] ?? '';

        return [
            'address1'     => $line1,
            'address2'     => $pick(['administrative_area_level_3', 'sublocality_level_1'])['long_name'] ?? '',
            'city'         => $pick(['administrative_area_level_2', 'locality'])['long_name'] ?? '',
            'province'     => $province,
            // Google returns the province name, but Shopify's delivery address
            // wants the ISO 3166-2 code. Empty when unmatched, so the caller
            // keeps whatever the form already had rather than a bad code.
            'provinceCode' => self::provinceCode($province),
            'zip'          => $pick(['postal_code'])['long_name'] ?? '',
        ];
    }

    /**
     * Indonesian province name → ISO 3166-2:ID code (what Shopify expects).
     * Keyed most-specific first so "Kepulauan Riau" is not eaten by "Riau".
     */
    private static function provinceCode(string $name): string
    {
        $n = strtolower($name);

        $map = [
            'kepulauan riau' => 'KR', 'kepulauan bangka belitung' => 'BB',
            'nusa tenggara barat' => 'NB', 'nusa tenggara timur' => 'NT',
            'kalimantan barat' => 'KB', 'kalimantan tengah' => 'KT', 'kalimantan selatan' => 'KS',
            'kalimantan timur' => 'KI', 'kalimantan utara' => 'KU',
            'sulawesi utara' => 'SA', 'sulawesi tengah' => 'ST', 'sulawesi selatan' => 'SN',
            'sulawesi tenggara' => 'SG', 'sulawesi barat' => 'SR',
            'sumatera utara' => 'SU', 'sumatera barat' => 'SB', 'sumatera selatan' => 'SS',
            'maluku utara' => 'MU', 'papua barat' => 'PB',
            'jawa barat' => 'JB', 'jawa tengah' => 'JT', 'jawa timur' => 'JI',
            'yogyakarta' => 'YO', 'jakarta' => 'JK', 'banten' => 'BT', 'bali' => 'BA',
            'aceh' => 'AC', 'bengkulu' => 'BE', 'jambi' => 'JA', 'lampung' => 'LA',
            'gorontalo' => 'GO', 'maluku' => 'MA', 'papua' => 'PA', 'riau' => 'RI',
        ];

        foreach ($map as $needle => $code) {
            if (str_contains($n, $needle)) {
                return $code;
            }
        }

        return '';
    }
}
