<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Shipping\RateEngine;
use App\Models\StoreModel;

/**
 * Shopify CarrierService rate callback.
 *
 * POST /carrier/rates/{storeSlug}?token={shared secret}
 *
 * Response contract (per Shopify docs):
 *  - 200 + {"rates":[...]}  → offer these rates (prices in subunits)
 *  - 200 + {"rates":[]}     → this carrier can't ship the request
 *  - 404                    → force Shopify's backup rates
 */
class CarrierRates extends BaseController
{
    /**
     * Line-item property carrying the delivery method the shopper chose.
     *
     * Shopify's rate request has no field for "which delivery method is this
     * cart for", and cart-level attributes are not forwarded to a carrier
     * service — but `items[].properties` is, verbatim. So the storefront
     * stamps every cart line with this key, and it arrives here.
     *
     * The leading underscore is Shopify's convention for a property that is
     * plumbing rather than something the shopper wrote: it stays out of the
     * checkout summary, the order confirmation and the packing slip.
     */
    public const METHOD_PROPERTY = '_delivery_method';

    /**
     * Line properties carrying the drop-off point the cart priced from.
     *
     * Same channel and same reasoning as METHOD_PROPERTY: Shopify's rate
     * request has no field for "which point was this quoted against", and it
     * forwards no cart-level attributes — but it forwards `items[].properties`
     * verbatim. GrabExpress is priced point-to-point, so this is what keeps the
     * cart's fare and checkout's fare the same number.
     */
    public const LAT_PROPERTY = '_delivery_lat';
    public const LNG_PROPERTY = '_delivery_lng';

    public function quote(string $storeSlug)
    {
        $config = config('Shopify');

        // Constant-time comparison of the shared callback token.
        $token = (string) $this->request->getGet('token');
        if ($config->carrierCallbackToken === '' || ! hash_equals($config->carrierCallbackToken, $token)) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'invalid token']);
        }

        $store = model(StoreModel::class)->findBySlug($storeSlug);
        if ($store === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'unknown store']);
        }

        $payload = $this->request->getJSON(true);
        $rate    = $payload['rate'] ?? null;
        if (! is_array($rate) || empty($rate['destination'])) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'malformed rate request']);
        }

        [$weightGrams, $cartTotal] = $this->extractTotals($rate);

        $method = $this->deliveryMethod($rate);
        if ($method === null) {
            // Not fatal — the quote goes out unrestricted — but it means the
            // shopper is about to be offered instant and standard side by
            // side. Worth a line, because the cause is upstream (a storefront
            // that did not stamp its cart lines) and invisible from here.
            log_message('info', 'Carrier rate request for {slug} carried no {key} property — quoting every courier', [
                'slug' => $storeSlug,
                'key'  => self::METHOD_PROPERTY,
            ]);
        }

        $destination = $this->withDropOffPoint($rate['destination'] ?? [], $rate);

        try {
            $rates = (new RateEngine())->quote($store, $destination, $weightGrams, $cartTotal, $method);
        } catch (\Throwable $e) {
            log_message('error', 'Rate engine failure for {slug}: {msg}', [
                'slug' => $storeSlug,
                'msg'  => $e->getMessage(),
            ]);

            // Let Shopify fall back to backup rates rather than show nothing.
            return $this->response->setStatusCode(404)->setJSON(['error' => 'rate engine unavailable']);
        }

        // A quote carries extra keys the admin screens use; Shopify must only
        // ever see the fields its contract defines.
        $rates = array_map(
            static fn (array $rate) => array_intersect_key($rate, array_flip(RateEngine::SHOPIFY_FIELDS)),
            $rates,
        );

        return $this->response->setJSON(['rates' => $rates]);
    }

    /**
     * The delivery method the shopper chose, from the cart line properties
     * Shopify forwards, or null when the request does not say.
     */
    private function deliveryMethod(array $rate): ?string
    {
        return $this->lineProperty($rate, self::METHOD_PROPERTY);
    }

    /**
     * The drop-off point the CART priced from, merged into the destination.
     *
     * Shopify's rate request carries no coordinates, so without this the engine
     * geocodes the typed address itself — a different question from "where did
     * the shopper drop their pin", and for a pinned order a different answer.
     * The cart quotes GrabExpress from its own point, so a checkout that
     * re-derives one can price the same order differently, or fail to price it
     * at all while the cart showed a fare.
     *
     * Only a complete, in-range pair is honoured; anything else leaves the
     * destination untouched and the engine geocodes exactly as before.
     */
    private function withDropOffPoint(array $destination, array $rate): array
    {
        $lat = $this->lineProperty($rate, self::LAT_PROPERTY);
        $lng = $this->lineProperty($rate, self::LNG_PROPERTY);

        if ($lat === null || $lng === null || ! is_numeric($lat) || ! is_numeric($lng)) {
            return $destination;
        }

        if (abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            log_message('warning', 'Cart lines carried an out-of-range drop-off point ({lat},{lng}) — geocoding the address instead', [
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return $destination;
        }

        $destination['latitude']  = $lat;
        $destination['longitude'] = $lng;

        return $destination;
    }

    /**
     * One line property Shopify forwarded, or null when the request does not
     * carry it.
     *
     * Every line is read rather than just the first, and they have to agree:
     * one cart is delivered one way, to one place, so lines that disagree
     * describe a cart no courier can serve. That resolves to null — fall back
     * to the unrestricted behaviour — rather than letting whichever line
     * happened to sort first decide it silently.
     */
    private function lineProperty(array $rate, string $key): ?string
    {
        $found = [];

        foreach ($rate['items'] ?? [] as $item) {
            $properties = $item['properties'] ?? null;
            if (! is_array($properties)) {
                continue;
            }

            // Shopify sends properties as a key => value map. Some consumers
            // (and the legacy custom callers) send a list of {name, value}
            // pairs instead, so both shapes are read.
            $value = $properties[$key] ?? null;

            if ($value === null) {
                foreach ($properties as $pair) {
                    if (is_array($pair) && ($pair['name'] ?? null) === $key) {
                        $value = $pair['value'] ?? null;
                        break;
                    }
                }
            }

            if (is_string($value) && $value !== '') {
                $found[strtolower(trim($value))] = true;
            }
        }

        if (count($found) !== 1) {
            if ($found !== []) {
                log_message('warning', 'Cart lines disagree on {key} ({values}) — ignoring it', [
                    'key'    => $key,
                    'values' => implode(', ', array_keys($found)),
                ]);
            }

            return null;
        }

        return array_key_first($found);
    }

    /**
     * Weight (grams) and cart total (whole IDR) from the rate request.
     *
     * Standard Shopify payloads carry items[] with grams and subunit prices;
     * the legacy custom consumers send totalWeight/totalPrice instead.
     *
     * @return array{0:int, 1:float}
     */
    private function extractTotals(array $rate): array
    {
        if (! empty($rate['items']) && is_array($rate['items'])) {
            $grams = 0;
            $price = 0;
            foreach ($rate['items'] as $item) {
                $qty    = (int) ($item['quantity'] ?? 1);
                $grams += (int) ($item['grams'] ?? 0) * $qty;
                $price += (int) ($item['price'] ?? 0) * $qty; // subunits
            }

            return [$grams, $price / 100];
        }

        return [
            (int) ($rate['totalWeight'] ?? 0),
            (float) ($rate['totalPrice'] ?? 0),
        ];
    }
}
