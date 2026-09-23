<?php

namespace App\Libraries\Shipping;

use App\Libraries\Couriers\GeocodeClient;
use App\Libraries\Couriers\GrabClient;
use App\Libraries\Couriers\WidgetProxyClient;
use App\Models\StoreModel;
use Config\Couriers as CouriersConfig;

/**
 * Multi-courier shipping rate engine (JNE / Ninja Xpress / SPX / GrabExpress).
 *
 *  - total_price returned in SUBUNITS (IDR x100) per the CarrierService spec
 *  - each courier uses its own ETD
 *  - upstream lookups run in parallel, cached, inside one time budget
 *  - courier selection thresholds are per store, with config defaults
 */
class RateEngine
{
    /**
     * Chargeable weight in whole kg — the one rule the whole app uses.
     *
     * Quoting and booking must agree, and there were three rules before this:
     * ceil() here, round() for Ninja/SPX, and a tiered rule with a 0.2 kg
     * grace band for JNE. A 1.15 kg parcel was quoted at 2 kg and declared to
     * JNE as 1 kg. Under-declaring is the dangerous direction — the courier
     * reweighs and surcharges, and the shopper has already been charged for
     * the heavier figure.
     *
     * ceil() is the safe choice: it is what the shopper paid for, and it is
     * never below the parcel's real weight. Minimum of 1 kg because no
     * courier here bills a fraction.
     */
    public static function billableWeightKg(int $grams): int
    {
        return max(1, (int) ceil($grams / 1000));
    }

    /**
     * Total weight (grams) and cart value (whole IDR) of rate-request items —
     * the one rule both endpoints use, so the cart page and checkout cannot
     * price the same basket from different numbers.
     *
     * Each item is {grams, price (IDR subunits, per unit), quantity}, exactly
     * as Shopify's rate request sends it. A missing quantity is 1; a negative
     * weight, price or quantity counts as 0 rather than shrinking the cart.
     *
     * @return array{0: int, 1: float} [grams, cart total in whole IDR]
     */
    public static function cartFromItems(array $items): array
    {
        $grams    = 0;
        $subunits = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $quantity  = max(0, (int) ($item['quantity'] ?? 1));
            $grams    += max(0, (int) ($item['grams'] ?? 0)) * $quantity;
            $subunits += max(0, (int) ($item['price'] ?? 0)) * $quantity;
        }

        return [$grams, (float) ($subunits / 100)];
    }

    /**
     * Delivery methods a quote can be restricted to.
     *
     * The shopper picks one of these on the cart page, and it decides which
     * couriers are even eligible — not merely which are shown. They are
     * different products: STANDARD is next-day-or-later parcel post priced on
     * a postcode, INSTANT is a rider dispatched now and priced on the exact
     * drop-off point, and COLLECT is not a delivery at all — the shopper walks
     * into a branch. Offering them in one list would let a shopper pick
     * "instant" pricing on the standard tab, or be charged a courier fare for
     * an order nobody ships.
     *
     * A quote with no method is unrestricted, which is what every caller got
     * before this existed — see quote().
     */
    public const METHOD_STANDARD = 'standard'; // JNE / Ninja / SPX, by postcode
    public const METHOD_INSTANT  = 'instant';  // GRABEXPRESS only, by coordinates
    public const METHOD_COLLECT  = 'collect';  // in-store pickup, no courier

    public const METHODS = [self::METHOD_STANDARD, self::METHOD_INSTANT, self::METHOD_COLLECT];

    /**
     * The Click and Collect line.
     *
     * A carrier service is the only source of delivery options on a store that
     * defines no static shipping rates, which is the case for delamistore —
     * verified 2026-08-11 against a fresh cart with an address and no carrier
     * call: zero delivery groups. So a pickup order has nothing to check out on
     * unless the engine returns it something, and before this it was quoted
     * every courier instead.
     *
     * THE NAME IS LOAD-BEARING, not cosmetic. AwbService::isClickAndCollect()
     * identifies a pickup order by its shipping-line title, so an order placed
     * on this rate routes to in-store collection rather than to a courier
     * booking. Changing the wording here silently re-routes fulfilment.
     */
    public const COLLECT_CODE = 'BDD-CNC';
    public const COLLECT_NAME = 'Click and Collect';

    /**
     * The only keys Shopify's CarrierService accepts. A quote carries extra
     * keys for this app's own screens; everything else must be stripped
     * before the callback answers.
     */
    public const SHOPIFY_FIELDS = [
        'service_name',
        'service_code',
        'description',
        'total_price',
        'currency',
    ];

    private const CITY_ALIASES = [
        'jaksel'  => 'jakarta selatan',
        'jakbar'  => 'jakarta barat',
        'jaktim'  => 'jakarta timur',
        'jakpus'  => 'jakarta pusat',
        'tangsel' => 'tangerang selatan',
    ];

    /**
     * The least time worth starting an upstream call with. Below this the
     * call is skipped: it could not finish, and would only push the whole
     * reply past Shopify's deadline.
     */
    private const MIN_CALL_SECONDS = 0.3;

    private WidgetProxyClient $proxy;
    private CouriersConfig $config;
    private GrabClient $grab;
    private GeocodeClient $geocoder;

    /** @var \Closure(): float seconds, for the time budget; injectable for tests */
    private \Closure $clock;

    public function __construct(
        ?WidgetProxyClient $proxy = null,
        ?CouriersConfig $config = null,
        ?GrabClient $grab = null,
        ?GeocodeClient $geocoder = null,
        ?\Closure $clock = null,
    ) {
        $this->config   = $config ?? config(CouriersConfig::class);
        $this->proxy    = $proxy ?? new WidgetProxyClient($this->config);
        $this->grab     = $grab ?? new GrabClient($this->config);
        $this->geocoder = $geocoder ?? new GeocodeClient($this->config);
        $this->clock    = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Cap $client's next calls at the time left before $deadline, or report
     * that too little is left to start one. A null deadline (a direct call
     * with no budget) leaves the client's configured timeout alone.
     *
     * @param GeocodeClient|GrabClient|WidgetProxyClient $client
     */
    private function budget(object $client, ?float $deadline): bool
    {
        if ($deadline === null) {
            return true;
        }

        $left = $deadline - ($this->clock)();

        if ($left < self::MIN_CALL_SECONDS) {
            log_message('warning', 'Rate budget spent — skipping {client}', ['client' => get_parent_class($client) ?: $client::class]);

            return false;
        }

        $client->setTimeout($left);

        return true;
    }

    /**
     * Quote all applicable rates for a Shopify CarrierService request.
     *
     * @param array       $store       stores table row
     * @param array       $destination ['postal_code' => ..., 'city' => ...]
     * @param int         $weightGrams cart total weight in grams
     * @param float       $cartTotal   cart total price in whole IDR
     * @param string|null $method      restrict to one of self::METHODS, or
     *                                 null to quote every eligible courier
     *
     * @return array[] rates in Shopify CarrierService format (subunit prices)
     */
    public function quote(array $store, array $destination, int $weightGrams, float $cartTotal, ?string $method = null): array
    {
        // An unrecognised method restricts nothing rather than returning an
        // empty list. This value arrives from a cart the app does not control
        // — a stale storefront build, a hand-made request — and a shopper
        // seeing every rate is a far better failure than one who can't check
        // out at all. CarrierRates logs when it could not find a method.
        //
        // Normalised first, as the checkout callback already does with the line
        // property: "Standard" and "standard" are the same choice, and the two
        // endpoints must not disagree about it.
        $method = is_string($method) ? strtolower(trim($method)) : null;
        $method = in_array($method, self::METHODS, true) ? $method : null;

        // ---- CLICK AND COLLECT: nothing is delivered, so nothing is quoted.
        // Returned before any pricing input below is even read — no zip, no
        // weight, no subsidy applies to an order nobody ships — and before
        // every upstream call, which is the entire latency of the callback.
        if ($method === self::METHOD_COLLECT) {
            return [$this->collectRate()];
        }

        $zip      = trim((string) ($destination['postal_code'] ?? ''));
        $city     = strtolower(trim((string) ($destination['city'] ?? '')));
        $city     = self::CITY_ALIASES[$city] ?? $city;
        $weightKg = self::billableWeightKg($weightGrams);

        $subsidy = 0;
        if ((int) $store['subsidi_ongkir'] > 0 && $cartTotal > (int) $store['minimum_order']) {
            $subsidy = (int) $store['subsidi_ongkir'];
        }

        $rates = [];

        // ---- THE TIME BUDGET. Shopify waits at most 10s for this callback —
        // 5s once a shop passes 1,500 requests a minute, 3s past 3,000 — and a
        // late answer is no answer: checkout falls back to backup rates, and a
        // store with none offers the shopper nothing. Each upstream call used
        // to get a flat 8s, one after another, so one slow dependency could
        // spend 40s. Every call is now capped at what is left of this budget.
        $deadline = ($this->clock)() + $this->config->rateBudgetSeconds;

        // ---- JNE / NINJA / SPX first. They are the core offer and resolve by
        // postcode; instant delivery is GrabExpress alone, so it skips them.
        if ($method !== self::METHOD_INSTANT && $zip !== '') {
            $rates = $this->parcelRates($store, $zip, $weightKg, $cartTotal, $subsidy, $deadline);
        }

        // ---- GRABEXPRESS - INSTANT: coordinate-based, on-demand, priced on
        // the drop-off point, and quoted with whatever time is left. The only
        // rate a coordinates-but-no-zip request can return. Needs a pin, or a
        // street address it can geocode into one — the checkout callback
        // sends the latter.
        //
        // Skipped outright on the standard method — that also spares the
        // geocode and the Grab quote call, which the caller cannot use.
        if ($method !== self::METHOD_STANDARD) {
            $grab = $this->grabRate($destination, $weightKg, $cartTotal, $subsidy, $deadline);
            if ($grab !== null) {
                $rates[] = $grab;
            }
        }

        // Dedupe (service_code) and sort cheapest first.
        $seen = [];
        $rates = array_values(array_filter($rates, static function ($rate) use (&$seen) {
            if (isset($seen[$rate['service_code']])) {
                return false;
            }
            $seen[$rate['service_code']] = true;

            return true;
        }));

        usort($rates, static fn ($a, $b) => $a['total_price'] <=> $b['total_price']);

        return $rates;
    }

    /**
     * JNE / Ninja / SPX for a postcode: two waves of parallel proxy lookups,
     * each capped at the time left before $deadline.
     */
    private function parcelRates(array $store, string $zip, int $weightKg, float $cartTotal, int $subsidy, float $deadline): array
    {
        // Thresholds are per store (admin-editable), falling back to config.
        $jneMaxCart       = StoreModel::threshold($store, 'jne_max_cart', 'jneMaxCart');
        $spxMinCart       = StoreModel::threshold($store, 'spx_min_cart', 'spxMinCart');
        $insuranceMinCart = StoreModel::threshold($store, 'insurance_min_cart', 'insuranceMinCart');

        // Wave 1: independent destination lookups, in parallel.
        if (! $this->budget($this->proxy, $deadline)) {
            return [];
        }
        $geo = $this->proxy->getMany([
            'jne'   => "get_code_destination/{$zip}",
            'ninja' => "get_nxid/{$zip}",
            'spx'   => "zipcode/{$zip}",
        ], $this->config->destinationCacheTtl);

        // Wave 2: rate lookups for every courier that resolved, in parallel.
        $ratePaths = [];
        if (! empty($geo['jne']['code_destination'])) {
            $ratePaths['jne'] = "get_rates_api_jne2/{$this->config->jneOrigin}/{$geo['jne']['code_destination']}";
        }
        if (! empty($geo['ninja']['nxid'])) {
            $ratePaths['ninja'] = "get_rates/{$geo['ninja']['nxid']}";
        }
        if (! empty($geo['spx']['city'])) {
            $spxCity                 = rawurlencode($geo['spx']['city']);
            $ratePaths['spxHemat']   = "ratecard/{$spxCity}/HEMAT";
            $ratePaths['spxReguler'] = "ratecard/{$spxCity}/REGULAR";
        }

        if ($ratePaths === [] || ! $this->budget($this->proxy, $deadline)) {
            return [];
        }
        $quotes = $this->proxy->getMany($ratePaths, $this->config->rateCacheTtl);

        $rates = [];

        // ---- JNE REG: cheap carts only, or fallback when Ninja has no coverage.
        $jneRate = (float) ($quotes['jne']['rates'] ?? 0);
        $jneEtd  = (string) ($quotes['jne']['etd'] ?? '2-4');
        $ninjaAvailable = ! empty($quotes['ninja']['rates']);

        if ($jneRate > 0 && ($jneMaxCart <= 0 || $cartTotal <= $jneMaxCart || ! $ninjaAvailable)) {
            $rates[] = $this->buildRate(
                'JNE - REGULER.',
                'BDD-REG19',
                $jneRate,
                $jneEtd,
                $weightKg,
                $cartTotal,
                $subsidy,
                $this->insurance('jne', $cartTotal, $insuranceMinCart),
            );
        }

        // ---- Ninja Xpress: whenever it has coverage.
        if ($ninjaAvailable) {
            $rates[] = $this->buildRate(
                'NINJA XPRESS - REGULER.',
                'BDD-NINJA',
                (float) $quotes['ninja']['rates'],
                (string) ($quotes['ninja']['etd'] ?? '1-3'),
                $weightKg,
                $cartTotal,
                $subsidy,
                $this->insurance('ninja', $cartTotal, $insuranceMinCart),
            );
        }

        // ---- SPX HEMAT / REGULER: minimum cart value applies.
        if ($cartTotal >= $spxMinCart) {
            foreach (['spxHemat' => ['SPX - HEMAT.', 'BDD-SPX-HEMAT'], 'spxReguler' => ['SPX - REGULER.', 'BDD-SPX-REGULER']] as $key => [$name, $code]) {
                $spxRate = (float) ($quotes[$key]['ratefinal'] ?? 0);
                if ($spxRate > 0) {
                    $rates[] = $this->buildRate(
                        $name,
                        $code,
                        $spxRate,
                        (string) ($quotes[$key]['sla'] ?? '2-4'),
                        $weightKg,
                        $cartTotal,
                        $subsidy,
                        $this->insurance('spx', $cartTotal, $insuranceMinCart),
                    );
                }
            }
        }

        return $rates;
    }

    /**
     * Insurance fee in whole IDR for the given courier, 0 below threshold.
     */
    public function insurance(string $courier, float $cartTotal, ?int $minCart = null): int
    {
        if ($cartTotal < ($minCart ?? $this->config->insuranceMinCart)) {
            return 0;
        }

        return match ($courier) {
            'ninja' => (int) max($this->config->ninjaInsuranceMin, ceil($this->config->ninjaInsuranceRate * $cartTotal)),
            'spx'   => (int) ceil($this->config->spxInsuranceRate * $cartTotal),
            default => (int) ceil($this->config->jneInsuranceRate * $cartTotal),
        };
    }

    /**
     * Build one rate line in Shopify CarrierService format.
     * Prices are converted to subunits (IDR x100) at the very end.
     *
     * Keys outside {@see self::SHOPIFY_FIELDS} are for this app's own use.
     */
    private function buildRate(
        string $serviceName,
        string $serviceCode,
        float $perKgRate,
        string $etd,
        int $weightKg,
        float $cartTotal,
        int $subsidy,
        int $insuranceFee,
    ): array {
        $shipping = $perKgRate * $weightKg;
        $covered  = (int) min($shipping, $subsidy);
        $shipping = max(0, $shipping - $subsidy);
        $total    = (int) ceil($shipping + $insuranceFee);

        // "FREE 5.000" read as if 5.000 were free of charge; what it actually
        // means is that the store paid 5.000 of the postage. Only call it free
        // when the subsidy covers the whole thing and the shopper pays nothing.
        //
        // The amount is always stated, in both forms: CarrierService has no
        // compare-at price, so this is the only channel through which a
        // storefront can show what the postage cost before the subsidy.
        $name = $serviceName;
        if ($covered > 0) {
            $rp = 'Rp ' . number_format($covered, 0, ',', '.');
            $name .= $shipping <= 0
                ? " (Gratis ongkir, subsidi {$rp})"
                : " (Subsidi {$rp})";
        }

        $description = "Estimasi sampai {$etd} hari (Weight: {$weightKg}Kg)";
        if ($insuranceFee > 0) {
            $description .= ', Biaya Asuransi Pengiriman Rp ' . number_format($insuranceFee, 0, ',', '.');
        }

        return [
            'service_name' => $name,
            'service_code' => $serviceCode,
            'description'  => $description,
            'total_price'  => $total * 100, // IDR subunits per CarrierService spec
            'currency'     => 'IDR',

            // Not part of the CarrierService contract — CarrierRates strips
            // these before answering Shopify. They exist so the app's own
            // screens can show the postage before the subsidy was applied.
            'subsidy'      => $covered,
            'price_gross'  => $total + $covered,
        ];
    }

    /**
     * Quote GRABEXPRESS - INSTANT, or null when it does not apply.
     *
     * Returns null (no call) unless Grab is enabled AND the destination carries
     * coordinates AND the drop-off is within reach of the warehouse — an
     * on-demand fare is priced on the pickup→drop-off geometry, which a zip
     * alone cannot give.
     *
     * Mirrors the production backend: a straight-line reach check before any
     * network call, then a second check on Grab's own road distance. Grab
     * returns a single fare (not a per-kg tariff), so it is NOT run through
     * buildRate(); the subsidy/label handling is mirrored here.
     *
     * @param array $destination may carry latitude/longitude, else a street
     *                           address (address1/city/…) that is geocoded
     */
    private function grabRate(array $destination, int $weightKg, float $cartTotal, int $subsidy, ?float $deadline = null): ?array
    {
        if (! $this->config->grabEnabled) {
            return null;
        }

        // The drop-off address text sent to Grab, and the string geocoded when
        // there is no pin.
        $dropAddress = trim((string) ($destination['address'] ?? '')) ?: $this->composeAddress($destination);

        $lat = trim((string) ($destination['latitude'] ?? ''));
        $lng = trim((string) ($destination['longitude'] ?? ''));

        if ($lat === '' || $lng === '') {
            // No pin — this is the native-checkout path. Derive coordinates by
            // geocoding the address. A street address is required; a city/zip
            // alone is too coarse to price an instant courier on.
            if (trim((string) ($destination['address1'] ?? '')) === '' || $dropAddress === '') {
                return null;
            }
            if (! $this->budget($this->geocoder, $deadline)) {
                return null;
            }
            $geo = $this->geocoder->geocode($dropAddress);
            if ($geo === null) {
                return null;
            }
            $lat = (string) $geo[0];
            $lng = (string) $geo[1];
        }

        // Reach check before spending a token + quote call: too far by straight
        // line is certainly too far by road.
        $km = self::haversineKm(
            (float) $this->config->grabOriginLat,
            (float) $this->config->grabOriginLng,
            (float) $lat,
            (float) $lng,
        );
        if ($km > $this->config->grabMaxDistanceKm) {
            return null;
        }

        $origin = [
            'address'     => (string) $this->config->grabOriginAddress,
            'keywords'    => (string) $this->config->grabOriginKeywords,
            'cityCode'    => (string) $this->config->grabOriginCityCode,
            'coordinates' => [
                'latitude'  => (float) $this->config->grabOriginLat,
                'longitude' => (float) $this->config->grabOriginLng,
            ],
        ];
        $drop = [
            'address'     => $dropAddress,
            'keywords'    => (string) ($destination['keywords'] ?? ''),
            'cityCode'    => (string) ($destination['cityCode'] ?? $this->config->grabOriginCityCode),
            'coordinates' => [
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
            ],
        ];
        $packages = [[
            'name'       => 'Order',
            'quantity'   => 1,
            'price'      => (int) $cartTotal,
            'dimensions' => ['weight' => $weightKg],
        ]];

        if (! $this->budget($this->grab, $deadline)) {
            return null;
        }
        $quote = $this->grab->quote($origin, $drop, $packages);
        if ($quote === null) {
            return null;
        }

        // Grab's road distance is in metres; out of the delivery radius → no rate.
        if ((int) ($quote['distance'] ?? 0) > $this->config->grabMaxDistanceKm * 1000) {
            return null;
        }

        // Grab's `amount` is whole IDR (e.g. 65000 = Rp 65.000), the same value
        // the production backend uses directly, despite currency.exponent.
        $fare = $quote['amount'] ?? null;
        if ($fare === null || (float) $fare <= 0) {
            log_message('warning', 'GrabExpress: no usable fare in quote {q}', ['q' => json_encode($quote)]);

            return null;
        }

        // service.type is "INSTANT"; service.name is "GrabExpress". The type is
        // the suffix, so the line reads "GRABEXPRESS - INSTANT".
        $service = (string) ($quote['service']['type'] ?? 'INSTANT');

        // Flat fare, subsidised the same way the other couriers are.
        $fare    = (int) ceil((float) $fare);
        $covered = (int) min($fare, $subsidy);
        $net     = max(0, $fare - $subsidy);

        $name = 'GRABEXPRESS - ' . strtoupper($service) . '.';
        if ($covered > 0) {
            $rp = 'Rp ' . number_format($covered, 0, ',', '.');
            $name .= $net <= 0 ? " (Gratis ongkir, subsidi {$rp})" : " (Subsidi {$rp})";
        }

        return [
            'service_name' => $name,
            'service_code' => 'BDD-GRAB',
            'description'  => 'Pasti tiba hari ini',
            'total_price'  => $net * 100, // IDR subunits per CarrierService spec
            'currency'     => 'IDR',

            'subsidy'      => $covered,
            'price_gross'  => $net + $covered,
        ];
    }

    /**
     * The single line a Click and Collect cart checks out on.
     *
     * Free by definition — the shopper collects at the counter, so there is no
     * carriage to bill. Deliberately NOT subsidised, discounted or weighed:
     * every one of those inputs describes a delivery.
     *
     * Carries no `subsidy`/`price_gross` extras because there is nothing for the
     * app's own screens to break down.
     */
    private function collectRate(): array
    {
        return [
            'service_name' => self::COLLECT_NAME,
            'service_code' => self::COLLECT_CODE,
            'description'  => 'Ambil sendiri di toko pilihan Anda',
            'total_price'  => 0, // IDR subunits per CarrierService spec
            'currency'     => 'IDR',
        ];
    }

    /**
     * Great-circle distance between two lat/lng points, in kilometres.
     * Matches the production backend's GeoHelper.HaversineDistance.
     */
    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r    = 6371.0; // Earth radius, km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * A single address string for geocoding, from whatever address parts the
     * destination carries (Shopify's callback sends address1/address2/city/
     * province/postal_code/country).
     */
    private function composeAddress(array $d): string
    {
        $parts = [];
        foreach (['address1', 'address2', 'city', 'province', 'postal_code', 'country'] as $field) {
            $value = trim((string) ($d[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }
}
