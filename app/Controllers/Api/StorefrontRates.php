<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Couriers\GeocodeClient;
use App\Libraries\Shipping\RateEngine;
use App\Models\StoreModel;

/**
 * Cart-page shipping rates for headless storefronts (Hydrogen, Expo).
 *
 * POST /api/storefront/rates
 *   X-Storefront-Key: pk_…
 *   {"destination": {...}, "items": [{"grams": …, "price": …, "quantity": …}],
 *    "method": "standard"|"instant"|"collect"}
 *
 * Distinct from {@see CarrierRates} on purpose. That endpoint is Shopify's
 * checkout callback, guarded by a shared secret that must never leave a
 * server; this one is called from a browser and from a phone, so its key is
 * publishable and abuse is bounded by throttling instead. Rate quotes are
 * public data — any shopper can obtain one by filling a cart — so the key
 * identifies and rate-limits a caller rather than protecting a secret.
 *
 * Both endpoints run the same RateEngine, which is what makes the cart price
 * and the checkout price agree.
 */
class StorefrontRates extends BaseController
{
    /** Quotes per minute per IP. A cart page re-quotes on every edit. */
    private const RATE_LIMIT = 60;

    public function quote()
    {
        $store = model(StoreModel::class)->findByStorefrontKey(
            (string) $this->request->getHeaderLine('X-Storefront-Key'),
        );

        if ($store === null) {
            return $this->fail(401, 'invalid storefront key');
        }

        // Throttled per store, not globally: one storefront being hammered
        // must not stop another store's cart from quoting.
        //
        // The IP is hashed because it reaches a cache key, and an IPv6 address
        // contains colons — a character the cache handler rejects outright.
        $throttler = service('throttler');
        $bucket    = 'rates-' . $store['id'] . '-' . md5($this->request->getIPAddress());

        if ($throttler->check($bucket, self::RATE_LIMIT, MINUTE) === false) {
            return $this->fail(429, 'too many requests')
                ->setHeader('Retry-After', (string) $throttler->getTokenTime());
        }

        $payload     = $this->request->getJSON(true);
        $destination = $payload['destination'] ?? null;

        if (! is_array($destination) || $destination === []) {
            return $this->fail(400, 'destination is required');
        }

        // items[] only — no totalWeight/totalPrice fallback. CarrierRates
        // accepts both, and the two disagree on units: items[].price is in
        // subunits while totalPrice is whole IDR. Shopify's checkout always
        // sends items[], so accepting only items[] here is what guarantees a
        // cart quote and a checkout quote are computed from the same numbers.
        if (empty($payload['items']) || ! is_array($payload['items'])) {
            return $this->fail(400, 'items[] is required (grams, price in subunits, quantity)');
        }

        // Which delivery method the cart page is quoting for. Optional, and an
        // unknown value quotes everything — the same fallback the checkout
        // callback takes, so the two paths cannot diverge on a bad value.
        //
        // Stated explicitly rather than inferred from whether coordinates were
        // sent: the instant form also carries a full address, so "has a pin"
        // and "wants instant" are not the same question.
        $method = $payload['method'] ?? null;

        $grams = 0;
        $subunits = 0;

        foreach ($payload['items'] as $item) {
            if (! is_array($item)) {
                return $this->fail(400, 'each item must be an object');
            }
            $quantity  = max(1, (int) ($item['quantity'] ?? 1));
            $grams    += (int) ($item['grams'] ?? 0) * $quantity;
            $subunits += (int) ($item['price'] ?? 0) * $quantity;
        }

        try {
            $rates = (new RateEngine())->quote(
                $store,
                $destination,
                $grams,
                $subunits / 100,
                is_string($method) ? $method : null,
            );
        } catch (\Throwable $e) {
            log_message('error', 'Storefront rate failure for {slug}: {msg}', [
                'slug' => $store['slug'],
                'msg'  => $e->getMessage(),
            ]);

            // 503, not 404: the cart has no backup rates to fall through to
            // the way Shopify's checkout does, so the client needs to know
            // this is retryable rather than "no rates for this address".
            return $this->fail(503, 'rate engine unavailable');
        }

        // The same projection the checkout callback applies, so a storefront
        // can never come to depend on internal fields that Shopify's response
        // does not carry.
        $rates = array_map(
            static fn (array $rate) => array_intersect_key($rate, array_flip(RateEngine::SHOPIFY_FIELDS)),
            $rates,
        );

        return $this->response->setJSON(['rates' => array_values($rates)]);
    }

    /**
     * Geocode both ways, so a cart and a map pin stay in sync:
     *   - {"address": "..."}         → {"coordinates": {...}}   (pin the address)
     *   - {"lat": ..., "lng": ...}   → {"address": {...}}       (address from pin)
     *
     * Uses the same geocoder the rate engine uses for native checkout, so the
     * pin, the address and the price all agree.
     *
     * POST /api/storefront/geocode   (X-Storefront-Key: pk_…)
     */
    public function geocode()
    {
        $store = model(StoreModel::class)->findByStorefrontKey(
            (string) $this->request->getHeaderLine('X-Storefront-Key'),
        );

        if ($store === null) {
            return $this->fail(401, 'invalid storefront key');
        }

        $throttler = service('throttler');
        $bucket    = 'geocode-' . $store['id'] . '-' . md5($this->request->getIPAddress());

        if ($throttler->check($bucket, self::RATE_LIMIT, MINUTE) === false) {
            return $this->fail(429, 'too many requests')
                ->setHeader('Retry-After', (string) $throttler->getTokenTime());
        }

        $payload = $this->request->getJSON(true);
        $geo     = new GeocodeClient();

        $lat = $payload['lat'] ?? $payload['latitude'] ?? null;
        $lng = $payload['lng'] ?? $payload['longitude'] ?? null;

        // Reverse: coordinates → address (a shopper moved the pin).
        if (is_numeric($lat) && is_numeric($lng)) {
            $address = $geo->reverseGeocode((float) $lat, (float) $lng);
            if ($address === null) {
                return $this->fail(404, 'coordinates could not be resolved to an address');
            }

            return $this->response->setJSON(['address' => $address]);
        }

        // Forward: address → coordinates (pin the typed address).
        $addressText = trim((string) ($payload['address'] ?? ''));
        if ($addressText === '') {
            return $this->fail(400, 'address or lat/lng is required');
        }

        $coords = $geo->geocode($addressText);
        if ($coords === null) {
            return $this->fail(404, 'address could not be geocoded');
        }

        return $this->response->setJSON([
            'coordinates' => ['latitude' => $coords[0], 'longitude' => $coords[1]],
        ]);
    }

    private function fail(int $status, string $message)
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $message]);
    }
}
