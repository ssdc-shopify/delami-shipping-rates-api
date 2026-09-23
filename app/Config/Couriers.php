<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Courier integration settings.
 *
 * Values are overridden by .env keys with the `couriers.` prefix.
 * All credentials live in .env only.
 */
class Couriers extends BaseConfig
{
    // ------------------------------------------------------------------
    // Delami widget proxy (rate + destination lookups)
    // ------------------------------------------------------------------
    public string $proxyBaseUrl = '';

    /** Upstream timeout in seconds. Keep short: Shopify's rate callback has a hard time budget. */
    public int $proxyTimeout = 8;

    /**
     * Total seconds a rate quote may spend on upstream calls. Every proxy,
     * geocode and Grab call is capped at what is left of it. Under Shopify's
     * 5s callback limit (the tier past 1,500 requests a minute), with room
     * for PHP and the network hop.
     */
    public float $rateBudgetSeconds = 4.5;

    /**
     * Seconds one courier tracking call may take. A shopper is waiting on the
     * tracking page, and a storefront gives up on the whole lookup at about
     * 20s, so a slow courier is cut off here and the last known scans shown.
     */
    public int $trackTimeout = 8;

    /** Cache TTL (seconds) for destination/zip lookups (stable data). */
    public int $destinationCacheTtl = 86400;

    /** Cache TTL (seconds) for rate lookups. */
    public int $rateCacheTtl = 3600;

    /**
     * Cache TTL (seconds) for a lookup that FAILED — timeout, 5xx, garbage.
     * Short on purpose: a failure says nothing about the postcode, and caching
     * it for the full TTL hid every parcel courier for that postcode for a day.
     */
    public int $failureCacheTtl = 60;

    // ------------------------------------------------------------------
    // JNE
    // ------------------------------------------------------------------
    public string $jneBaseUrl = '';
    public string $jneTraceUrl = '';
    public string $jneUsername = '';
    public string $jneApiKey = '';
    public string $jneBranch = 'BKI000';
    public string $jneOrigin = 'BKI10000';
    public string $jneCodAccount = '';
    public string $jneRegAccount = '';

    // ------------------------------------------------------------------
    // SPX (Shopee Express)
    // ------------------------------------------------------------------
    public string $spxBaseUrl = '';
    public string $spxAppId = '';
    public string $spxAppSecret = '';
    public string $spxUserId = '';
    public string $spxUserSecret = '';

    // ------------------------------------------------------------------
    // LJR (Lestari Jaya Raya) — tracking only
    // ------------------------------------------------------------------
    public string $ljrBaseUrl = '';
    public string $ljrApiKey = '';

    // ------------------------------------------------------------------
    // Ninja Xpress
    // ------------------------------------------------------------------
    public string $ninjaBaseUrl = '';
    public string $ninjaWaybillPrefix = 'DLAMI';

    // ------------------------------------------------------------------
    // GRABEXPRESS - INSTANT — Grab's own Express Delivery API.
    //
    // Coordinate-based and on-demand: get a bearer token from the Delami
    // token endpoint (which holds Grab's client credentials), then POST it to
    // Grab's /deliveries/quotes with pickup + drop-off coordinates. Grab
    // prices on the point-to-point distance. This mirrors the production .NET
    // backend (ShippingRatesService.GetShippingsInstantAsync); see
    // App\Libraries\Couriers\GrabClient.
    //
    // Only quoted when the caller supplies destination coordinates AND the
    // drop-off is within reach of the warehouse. Native Shopify checkout sends
    // no coordinates, so a headless cart must collect a map pin and pass
    // lat/lng to /api/storefront/rates; the rate simulator collects them by hand.
    // ------------------------------------------------------------------

    /** Master switch. Even when true, Grab is skipped without coordinates or out of range. */
    public bool $grabEnabled = true;

    /**
     * Delami token endpoint — returns a ready Grab bearer token ({"token":
     * "Bearer ..."}). Used instead of holding Grab's client_id/secret here.
     */
    public string $grabTokenUrl = 'https://widget.delamibrands.com/omni/api/get_token_grab';

    /** Grab Express API base (production). Quotes POST to {base}deliveries/quotes. */
    public string $grabBaseUrl = 'https://partner-api.grab.com/grab-express/v1/';

    /** How long to cache the Grab bearer token, in minutes (matches the .NET backend). */
    public int $grabTokenCacheMinutes = 180;

    /**
     * How long a Grab BOOKING may take, in seconds. Longer than the quote
     * timeout on purpose: giving up on a slow reply while Grab dispatches the
     * rider anyway is how one order gets two riders.
     */
    public int $grabBookingTimeout = 30;

    /** Grab vehicle class for the quote + booking (BIKE / CAR). */
    public string $grabVehicleType = 'BIKE';

    /**
     * How Grab is paid for a booked delivery. CASHLESS = Delami's Grab account
     * is charged (the normal case); COD would have the rider collect cash.
     */
    public string $grabPaymentMethod = 'CASHLESS';

    /**
     * Farthest the warehouse will deliver by Grab, in km (Haversine straight
     * line). The production backend gates at 40 km, and again on Grab's own
     * road distance (grabMaxDistanceKm * 1000 metres).
     */
    public int $grabMaxDistanceKm = 40;

    /**
     * Pickup origin sent to Grab — the Bekasi warehouse, from the production
     * backend's WarehouseAddress. cityCode CGK is Grab's Jakarta code.
     */
    public string $grabOriginCityCode = 'CGK';
    public string $grabOriginLat      = '-6.2888924';
    public string $grabOriginLng      = '106.9851154';
    public string $grabOriginKeywords = 'PT.Sarana Nusa Logistik';
    public string $grabOriginAddress  = 'Jl. Raya Narogong No.KM 06, Bojong Rawalumbu, Kec. Rawalumbu, Kota Bekasi';

    /**
     * Google Geocoding — turns a shipping address into coordinates when no map
     * pin is supplied. This is what lets GrabExpress work in NATIVE Shopify
     * checkout: the rate callback receives only the address (never a pin), so
     * the address is geocoded here, exactly as the production .NET backend does
     * (GoogleSetting.GoogleMaps.ApiKey). Blank key disables geocoding, so Grab
     * is then only offered when a caller passes explicit coordinates.
     *
     * The key is a secret: set it as couriers.geocodeApiKey in .env, never here.
     */
    public string $geocodeApiKey   = '';
    public string $geocodeUrl      = 'https://maps.googleapis.com/maps/api/geocode/json';
    public int    $geocodeCacheTtl = 604800; // 7 days — an address's location is stable

    // ------------------------------------------------------------------
    // Rate thresholds (whole IDR) — DEFAULTS ONLY.
    //
    // These are the fallback for a store that leaves the field blank; the
    // per-store values live in Admin -> Stores. Override the fallback per
    // environment with couriers.jneMaxCart etc. in .env — the values below
    // are only what applies when neither is set.
    // ------------------------------------------------------------------

    /** JNE REG is offered only up to this cart value (0 disables the cap). */
    public int $jneMaxCart = 200_000;

    /** SPX is offered only from this cart value up. */
    public int $spxMinCart = 50_000;

    /** Insurance is added from this cart value up. */
    public int $insuranceMinCart = 500_000;

    /** Insurance rates per courier, as a fraction of cart value. */
    public float $jneInsuranceRate   = 0.002;   // 0.2%
    public float $spxInsuranceRate   = 0.002;   // 0.2%
    public float $ninjaInsuranceRate = 0.0025;  // 0.25%

    /** Minimum Ninja insurance fee once the threshold is met. */
    public int $ninjaInsuranceMin = 2_500;

    // ------------------------------------------------------------------
    // Mock AWB mode
    //
    // When true, AWB generation invents a waybill locally instead of calling
    // JNE/Ninja/SPX. The Shopify order IS still fulfilled with that mock
    // tracking number so the flow can be verified end to end, but the
    // customer notification is suppressed. Labels still render.
    //
    // Switched in Admin → Settings and stored in the database — read it with
    // App\Libraries\Awb\MockMode::enabled(), never from this property. This
    // value is only the default until an operator first saves a choice, and
    // it is ON so a fresh or misconfigured install cannot book real shipments
    // by accident.
    // ------------------------------------------------------------------
    public bool $mockAwb = true;

    // ------------------------------------------------------------------
    // Rate service_code → courier routing
    //
    // These codes are what RateEngine puts in the CarrierService response;
    // Shopify stores the chosen one on the order as shipping_line.code, so
    // this map is the authoritative link from "what the shopper picked" to
    // "which courier gets the AWB". Codes not listed here fall back to the
    // shipping-line title heuristic in AwbService::routeCourier().
    //
    // spxServiceType is sent as SPX base_info.service_type.
    //
    // null means "SPX has not told us the value for this service". HEMAT is
    // the economy service and is sold cheaper than REGULER, but SPX has not
    // published its service_type, so booking one currently sends REGULER's:
    // the shopper pays the HEMAT price and we buy the REGULER service, and
    // the difference is ours to absorb. AwbService::spxServiceType() logs a
    // warning on every such booking so the leak is visible rather than
    // silent. Replace the null with the real value once SPX confirms it —
    // nothing else needs changing. It is deliberately NOT guessed: a wrong
    // service_type books the wrong product entirely.
    // ------------------------------------------------------------------
    public array $serviceMap = [
        'BDD-REG19'       => ['courier' => 'jne'],
        'BDD-NINJA'       => ['courier' => 'ninja'],
        'BDD-SPX-HEMAT'   => ['courier' => 'spx', 'spxServiceType' => null],
        'BDD-SPX-REGULER' => ['courier' => 'spx', 'spxServiceType' => 1],
        'BDD-SPX'         => ['courier' => 'spx', 'spxServiceType' => 1],

        // Grab has no booking client yet: in mock mode (the default) this
        // books as MOCK-GRAB-…; with mock off it falls through to the JNE
        // arm in AwbService, which is wrong for a real Grab shipment. Wire a
        // Grab booking client before turning mock off with Grab in use.
        'BDD-GRAB'        => ['courier' => 'grab'],
    ];

    // ------------------------------------------------------------------
    // Shipper identity (printed on labels / sent to couriers) — not secret
    // ------------------------------------------------------------------
    public string $shipperName = 'PT. DELAMIBRANDS KHARISMA BUSANA';
    public string $shipperBrand = 'EXECUTIVE';
    public string $shipperAddress1 = 'Jl.Raya Narogong No.12 RT.007 RW.003';
    public string $shipperAddress2 = 'Bojong Rawalumbu';
    public string $shipperCity = 'BEKASI';
    public string $shipperZip = '17116';
    public string $shipperPhone = '622129779599';

    // Return slip (printed rotated on every label)
    public string $returnCompany = 'PT. Sarana Nusa Logistik';
    public string $returnAddress = "JL. Raya Narogong KM 6 No.20 RT 007 RW 003\nKelurahan: Bojong Rawa Lumbu\nKecamatan: Rawa Lumbu\nKota Bekasi, Jawa Barat 17116\nUp/Penerima: Gudang Ecommerce a.n Miftah 0896 8388 2225";
}
