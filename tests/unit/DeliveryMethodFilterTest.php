<?php

namespace Tests\Unit;

use App\Controllers\Api\CarrierRates;
use App\Libraries\Couriers\GeocodeClient;
use App\Libraries\Couriers\GrabClient;
use App\Libraries\Couriers\WidgetProxyClient;
use App\Libraries\Shipping\RateEngine;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * Standard and Instant are separate products, not two views of one rate list:
 * one is parcel post priced on a postcode, the other a rider dispatched now
 * and priced on the drop-off point. A shopper who chose one must not be
 * offered the other at checkout.
 *
 * The restriction has to hold on BOTH paths — the cart page quote and
 * Shopify's checkout callback — or the cart shows one list and checkout
 * another.
 */
final class DeliveryMethodFilterTest extends CIUnitTestCase
{
    /** A destination carrying both a zip and a pin, so nothing but the method decides. */
    private const DESTINATION = [
        'postal_code' => '12950',
        'city'        => 'jakarta selatan',
        'address1'    => 'Jl. H. R. Rasuna Said 7',
        'latitude'    => '-6.2285501',
        'longitude'   => '106.8337856',
    ];

    private const STORE = [
        'subsidi_ongkir'     => 0,
        'minimum_order'      => 0,
        'jne_max_cart'       => 0,       // no cap, so JNE always quotes
        'spx_min_cart'       => 0,
        'insurance_min_cart' => 9_999_999,
    ];

    /**
     * An engine with every upstream faked, and a record of which ones were
     * reached — the filter is meant to skip the calls, not just drop their
     * results, since that latency is the whole cost of the callback.
     */
    private static function engine(): RateEngine
    {
        $proxy = new class extends WidgetProxyClient {
            public int $calls = 0;

            public function __construct()
            {
                parent::__construct();
            }

            public function getMany(array $paths, int $ttl): array
            {
                $this->calls++;

                // Wave 1 resolves the destination, wave 2 the rate cards.
                return isset($paths['spx'])
                    ? [
                        'jne'   => ['code_destination' => 'CGK10000'],
                        'ninja' => ['nxid' => 'IDJKT'],
                        'spx'   => ['city' => 'JAKARTA'],
                    ]
                    : [
                        'jne'        => ['rates' => 10_000, 'etd' => '2-3'],
                        'ninja'      => ['rates' => 12_000, 'etd' => '1-2'],
                        'spxHemat'   => ['ratefinal' => 9_000, 'sla' => '3-4'],
                        'spxReguler' => ['ratefinal' => 11_000, 'sla' => '2-3'],
                    ];
            }
        };

        $grab = new class extends GrabClient {
            public int $calls = 0;

            public function __construct()
            {
                parent::__construct();
            }

            public function quote(array $origin, array $destination, array $packages): ?array
            {
                $this->calls++;

                return [
                    'amount'   => 65_000,
                    'distance' => 15_000,
                    'service'  => ['type' => 'INSTANT'],
                ];
            }
        };

        $geocoder = new class extends GeocodeClient {
            public function __construct()
            {
                parent::__construct();
            }

            public function geocode(string $address): ?array
            {
                return [-6.2285501, 106.8337856];
            }
        };

        return new RateEngine($proxy, null, $grab, $geocoder);
    }

    /** Read a private property of the engine, to inspect the fakes behind it. */
    private static function upstream(RateEngine $engine, string $name): object
    {
        $property = new \ReflectionProperty(RateEngine::class, $name);
        $property->setAccessible(true);

        return $property->getValue($engine);
    }

    private static function codes(array $rates): array
    {
        return array_map(static fn ($rate) => $rate['service_code'], $rates);
    }

    private static function quote(RateEngine $engine, ?string $method): array
    {
        return $engine->quote(self::STORE, self::DESTINATION, 1_000, 150_000.0, $method);
    }

    // ------------------------------------------------------------------
    // The engine
    // ------------------------------------------------------------------

    public function testStandardOffersTheZipCouriersAndNeverGrab(): void
    {
        $engine = self::engine();
        $codes  = self::codes(self::quote($engine, RateEngine::METHOD_STANDARD));

        $this->assertNotContains('BDD-GRAB', $codes, 'instant must not appear on the standard method');
        $this->assertContains('BDD-REG19', $codes);
        $this->assertContains('BDD-NINJA', $codes);
        $this->assertSame(0, self::upstream($engine, 'grab')->calls, 'Grab must not even be called');
    }

    public function testInstantOffersGrabAloneAndSkipsTheProxyEntirely(): void
    {
        $engine = self::engine();
        $codes  = self::codes(self::quote($engine, RateEngine::METHOD_INSTANT));

        $this->assertSame(['BDD-GRAB'], $codes, 'instant is GrabExpress and nothing else');
        $this->assertSame(0, self::upstream($engine, 'proxy')->calls, 'the courier proxy must not be called');
    }

    public function testCollectOffersOnlyTheFreePickupLineAndCallsNoUpstream(): void
    {
        $engine = self::engine();
        $rates  = self::quote($engine, RateEngine::METHOD_COLLECT);

        $this->assertSame([RateEngine::COLLECT_CODE], self::codes($rates), 'collect is pickup and nothing else');
        $this->assertSame(0, $rates[0]['total_price'], 'a shopper who collects pays no carriage');
        $this->assertSame(0, self::upstream($engine, 'proxy')->calls, 'the courier proxy must not be called');
        $this->assertSame(0, self::upstream($engine, 'grab')->calls, 'Grab must not be called');
    }

    public function testCollectIsQuotedWithoutAZipOrAPin(): void
    {
        // Pickup is priced on nothing at all, so a destination the courier
        // methods would refuse must still produce the line.
        $rates = self::engine()->quote(self::STORE, [], 0, 0.0, RateEngine::METHOD_COLLECT);

        $this->assertSame([RateEngine::COLLECT_CODE], self::codes($rates));
    }

    public function testCollectIsNeverSubsidisedIntoADifferentPrice(): void
    {
        // The subsidy exists to discount carriage. There is none here, and a
        // "Gratis ongkir" suffix on a pickup line would be nonsense.
        $store = ['subsidi_ongkir' => 20_000, 'minimum_order' => 0, 'jne_max_cart' => 0, 'spx_min_cart' => 0, 'insurance_min_cart' => 9_999_999];
        $rates = self::engine()->quote($store, self::DESTINATION, 1_000, 150_000.0, RateEngine::METHOD_COLLECT);

        $this->assertSame(0, $rates[0]['total_price']);
        $this->assertSame(RateEngine::COLLECT_NAME, $rates[0]['service_name']);
    }

    public function testTheCollectRateNameIsTheOneFulfilmentRoutesOn(): void
    {
        // AwbService::isClickAndCollect() reads the shipping-line title to decide
        // whether an order is collected or couriered. If this name drifts, orders
        // silently start being booked with a courier instead.
        $this->assertStringContainsStringIgnoringCase('click and collect', RateEngine::COLLECT_NAME);
    }

    public function testCollectRatesCarryOnlyTheFieldsShopifyAccepts(): void
    {
        $rates = self::quote(self::engine(), RateEngine::METHOD_COLLECT);

        $this->assertSame(RateEngine::SHOPIFY_FIELDS, array_keys($rates[0]));
    }

    public function testNoMethodStillQuotesEverything(): void
    {
        $codes = self::codes(self::quote(self::engine(), null));

        $this->assertContains('BDD-GRAB', $codes);
        $this->assertContains('BDD-REG19', $codes);
    }

    public function testAnUnknownMethodQuotesEverythingRatherThanNothing(): void
    {
        // A stale storefront build must leave a shopper able to check out.
        $codes = self::codes(self::quote(self::engine(), 'express-elephant'));

        $this->assertContains('BDD-GRAB', $codes);
        $this->assertContains('BDD-REG19', $codes);
    }

    // ------------------------------------------------------------------
    // Reading the method out of Shopify's rate request
    // ------------------------------------------------------------------

    private static function methodFrom(array $items): ?string
    {
        $method = new ReflectionMethod(CarrierRates::class, 'deliveryMethod');
        $method->setAccessible(true);

        return $method->invoke(new CarrierRates(), ['items' => $items]);
    }

    public function testMethodIsReadFromShopifysPropertyMap(): void
    {
        $this->assertSame('instant', self::methodFrom([
            ['grams' => 500, 'properties' => [CarrierRates::METHOD_PROPERTY => 'instant']],
        ]));
    }

    public function testMethodIsAlsoReadFromNameValuePairs(): void
    {
        $this->assertSame('standard', self::methodFrom([
            ['grams' => 500, 'properties' => [['name' => CarrierRates::METHOD_PROPERTY, 'value' => 'standard']]],
        ]));
    }

    public function testCollectIsReadOffTheLinesLikeAnyOtherMethod(): void
    {
        // The headless storefront stamps this on every line of a pickup cart.
        $this->assertSame('collect', self::methodFrom([
            ['grams' => 500, 'properties' => [CarrierRates::METHOD_PROPERTY => 'collect']],
        ]));
    }

    public function testAbsentPropertyMeansNoRestriction(): void
    {
        $this->assertNull(self::methodFrom([['grams' => 500, 'properties' => null]]));
        $this->assertNull(self::methodFrom([['grams' => 500]]));
    }

    public function testLinesThatDisagreeRestrictNothing(): void
    {
        // One cart is delivered one way. Lines claiming different methods
        // describe a cart no courier can serve, so no line gets to decide.
        $this->assertNull(self::methodFrom([
            ['properties' => [CarrierRates::METHOD_PROPERTY => 'standard']],
            ['properties' => [CarrierRates::METHOD_PROPERTY => 'instant']],
        ]));
    }

    public function testAgreeingLinesRestrictNormally(): void
    {
        $this->assertSame('standard', self::methodFrom([
            ['properties' => [CarrierRates::METHOD_PROPERTY => 'standard']],
            ['properties' => [CarrierRates::METHOD_PROPERTY => 'Standard']],
        ]));
    }

    // ------------------------------------------------------------------
    // The drop-off point the cart priced from
    // ------------------------------------------------------------------

    /**
     * GrabExpress is priced point-to-point. Shopify's rate request carries no
     * coordinates, so without the stamp the engine geocodes the typed address —
     * which is not where the shopper's pin is, and need not be where the cart
     * quoted from. Then the cart shows a fare checkout prices differently, or
     * cannot price at all.
     */
    private static function destinationFrom(array $items, array $destination = []): array
    {
        $method = new ReflectionMethod(CarrierRates::class, 'withDropOffPoint');
        $method->setAccessible(true);

        return $method->invoke(new CarrierRates(), $destination, ['items' => $items]);
    }

    public function testTheStampedPointOverridesGeocodingTheAddress(): void
    {
        $d = self::destinationFrom([
            ['properties' => [
                CarrierRates::LAT_PROPERTY => '-6.35066',
                CarrierRates::LNG_PROPERTY => '106.83654',
            ]],
        ], ['postal_code' => '12640']);

        $this->assertSame('-6.35066', $d['latitude']);
        $this->assertSame('106.83654', $d['longitude']);
        $this->assertSame('12640', $d['postal_code'], 'the rest of the destination is untouched');
    }

    public function testAHalfStampedPointIsIgnored(): void
    {
        // A latitude alone cannot place anything; geocode the address instead.
        $d = self::destinationFrom([
            ['properties' => [CarrierRates::LAT_PROPERTY => '-6.35066']],
        ]);

        $this->assertArrayNotHasKey('latitude', $d);
        $this->assertArrayNotHasKey('longitude', $d);
    }

    public function testAnOutOfRangePointIsIgnoredRatherThanQuotedFrom(): void
    {
        $d = self::destinationFrom([
            ['properties' => [
                CarrierRates::LAT_PROPERTY => '-999',
                CarrierRates::LNG_PROPERTY => '106.83654',
            ]],
        ]);

        $this->assertArrayNotHasKey('latitude', $d);
    }

    public function testNonNumericCoordinatesAreIgnored(): void
    {
        $d = self::destinationFrom([
            ['properties' => [
                CarrierRates::LAT_PROPERTY => 'null',
                CarrierRates::LNG_PROPERTY => 'undefined',
            ]],
        ]);

        $this->assertArrayNotHasKey('latitude', $d);
    }

    public function testLinesThatDisagreeOnThePointAreIgnored(): void
    {
        // One order is delivered to one place.
        $d = self::destinationFrom([
            ['properties' => [CarrierRates::LAT_PROPERTY => '-6.35066', CarrierRates::LNG_PROPERTY => '106.83654']],
            ['properties' => [CarrierRates::LAT_PROPERTY => '-6.20000', CarrierRates::LNG_PROPERTY => '106.83654']],
        ]);

        $this->assertArrayNotHasKey('latitude', $d);
    }

    public function testNoStampLeavesTheDestinationExactlyAsShopifySentIt(): void
    {
        $original = ['postal_code' => '12640', 'city' => 'jakarta selatan'];

        $this->assertSame($original, self::destinationFrom([['grams' => 500]], $original));
    }
}
