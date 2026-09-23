<?php

namespace Tests\Unit;

use App\Libraries\Couriers\GeocodeClient;
use App\Libraries\Couriers\GrabClient;
use App\Libraries\Shipping\RateEngine;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Couriers as CouriersConfig;
use ReflectionMethod;

/**
 * GRABEXPRESS - INSTANT is coordinate-based and on-demand: it must stay silent
 * unless the caller supplies drop-off coordinates — the Shopify checkout
 * callback never does — and, when it fires, map Grab's single fare into the
 * same subunit/subsidy shape as every other rate line.
 */
final class GrabRateTest extends CIUnitTestCase
{
    /**
     * A RateEngine whose GrabClient returns a fixed quote, so grabRate can be
     * exercised without hitting Grab's API.
     */
    private static function engine(?array $grabQuote, ?array $geocodeResult = null): RateEngine
    {
        $grab = new class ($grabQuote) extends GrabClient {
            public bool $wasCalled = false;

            public function __construct(private ?array $grabQuote)
            {
                parent::__construct();
            }

            public function quote(array $origin, array $destination, array $packages): ?array
            {
                $this->wasCalled = true;

                return $this->grabQuote;
            }
        };

        // Fake geocoder so the native-checkout path is testable without a call.
        $geocoder = new class ($geocodeResult) extends GeocodeClient {
            public function __construct(private ?array $result)
            {
                parent::__construct();
            }

            public function geocode(string $address): ?array
            {
                return $this->result;
            }
        };

        return new RateEngine(null, null, $grab, $geocoder);
    }

    /** The fake GrabClient behind an engine, to assert whether it was called. */
    private static function client(RateEngine $engine): GrabClient
    {
        $prop = new \ReflectionProperty(RateEngine::class, 'grab');
        $prop->setAccessible(true);

        return $prop->getValue($engine);
    }

    private static function grab(RateEngine $engine, array $destination, int $subsidy = 0): ?array
    {
        $method = new ReflectionMethod(RateEngine::class, 'grabRate');
        $method->setAccessible(true);

        // (destination, weightKg, cartTotal, subsidy)
        return $method->invoke($engine, $destination, 1, 150_000.0, $subsidy);
    }

    /** A minimal Grab quote object as the Delivery API returns it. */
    private static function quote(int $amount, string $type = 'INSTANT'): array
    {
        return ['amount' => $amount, 'service' => ['type' => $type, 'name' => 'GrabExpress']];
    }

    public function testNoCoordinatesMeansNoGrabAndNoCall(): void
    {
        // If this made a call the fake would answer, but a missing lat/lng must
        // short-circuit before that — this is what keeps real checkout silent.
        $engine = self::engine(self::quote(25_000));

        $this->assertNull(self::grab($engine, ['postal_code' => '14420', 'city' => 'jakarta utara']));
    }

    public function testFareBecomesASubunitGrabLine(): void
    {
        $engine = self::engine(self::quote(25_000));

        $rate = self::grab($engine, [
            'latitude'  => '-6.2285501',
            'longitude' => '106.8337856',
            'cityCode'  => 'CGK',
        ]);

        $this->assertNotNull($rate);
        $this->assertSame('BDD-GRAB', $rate['service_code']);
        $this->assertSame(2_500_000, $rate['total_price'], 'Rp 25.000 in subunits');
        $this->assertSame('IDR', $rate['currency']);
        $this->assertSame('GRABEXPRESS - INSTANT.', $rate['service_name']);
    }

    public function testSubsidyIsAppliedLikeEveryOtherCourier(): void
    {
        $engine = self::engine(self::quote(25_000));

        $rate = self::grab($engine, [
            'latitude'  => '-6.2285501',
            'longitude' => '106.8337856',
        ], 5_000);

        $this->assertSame(2_000_000, $rate['total_price'], 'Rp 20.000 after Rp 5.000 subsidy');
        $this->assertSame(5_000, $rate['subsidy']);
        $this->assertSame(25_000, $rate['price_gross']);
        $this->assertStringContainsString('Subsidi Rp 5.000', $rate['service_name']);
    }

    public function testNoUsableFareYieldsNoRate(): void
    {
        $this->assertNull(self::grab(self::engine(null), self::coords()), 'no quote returned');
        $this->assertNull(self::grab(self::engine(self::quote(0)), self::coords()), 'zero fare');
        $this->assertNull(self::grab(self::engine(['service' => ['type' => 'INSTANT']]), self::coords()), 'no amount field');
    }

    public function testNativeCheckoutGeocodesTheAddressWhenNoPin(): void
    {
        // No coordinates, but a street address — the native-checkout path.
        // The geocoder resolves it, so GrabExpress is still offered.
        $engine = self::engine(self::quote(25_000), [-6.2289146, 106.8333846]);

        $rate = self::grab($engine, [
            'address1'    => 'Jl. H. R. Rasuna Said 7',
            'city'        => 'Jakarta Selatan',
            'postal_code' => '12950',
        ]);

        $this->assertNotNull($rate);
        $this->assertSame('BDD-GRAB', $rate['service_code']);
    }

    public function testCityAndZipOnlyIsTooCoarseForGrab(): void
    {
        // Standard-courier shape: a zip and city but no street address and no
        // pin. Too coarse to price an instant courier, so Grab is not offered.
        $engine = self::engine(self::quote(25_000), [-6.2, 106.8]);

        $this->assertNull(self::grab($engine, ['city' => 'Jakarta Selatan', 'postal_code' => '12950']));
    }

    public function testGeocodeMissMeansNoGrab(): void
    {
        // Address present but the geocoder finds nothing → no coordinates → no rate.
        $engine = self::engine(self::quote(25_000), null);

        $this->assertNull(self::grab($engine, ['address1' => 'Nowhere', 'city' => 'Atlantis']));
    }

    public function testOutOfRangeByStraightLineNeverCallsGrab(): void
    {
        // Surabaya — hundreds of km from the Bekasi warehouse. The reach check
        // must reject it before spending a token/quote call.
        $engine = self::engine(self::quote(25_000));

        $this->assertNull(self::grab($engine, ['latitude' => '-7.2575', 'longitude' => '112.7521']));
        $this->assertFalse(self::client($engine)->wasCalled, 'Grab must not be called when out of range');
    }

    public function testTooFarByRoadYieldsNoRate(): void
    {
        // In straight-line range, but Grab reports a road distance over 40 km.
        $quote = self::quote(25_000) + ['distance' => 45_000];

        $this->assertNull(self::grab(self::engine($quote), self::coords()));
    }

    public function testDisabledByConfigNeverFires(): void
    {
        $config = new CouriersConfig();
        $config->grabEnabled = false;

        $grab = new class extends GrabClient {
            public function quote(array $origin, array $destination, array $packages): ?array
            {
                return ['amount' => 25_000];
            }
        };

        $engine = new RateEngine(null, $config, $grab);

        $this->assertNull(self::grab($engine, self::coords()));
    }

    public function testInternalKeysAreNotPartOfTheShopifyContract(): void
    {
        $rate = self::grab(self::engine(self::quote(25_000)), self::coords(), 5_000);
        $sent = array_intersect_key($rate, array_flip(RateEngine::SHOPIFY_FIELDS));

        $this->assertArrayNotHasKey('subsidy', $sent);
        $this->assertArrayNotHasKey('price_gross', $sent);
        $this->assertSame(RateEngine::SHOPIFY_FIELDS, array_keys($sent));
    }

    private static function coords(): array
    {
        return ['latitude' => '-6.2285501', 'longitude' => '106.8337856'];
    }

    /**
     * A store row with the subsidy fields the engine reads. No zip lookups
     * happen in these cases, so nothing else is needed.
     */
    private static function store(): array
    {
        return ['subsidi_ongkir' => 0, 'minimum_order' => 0];
    }

    public function testQuoteReturnsGrabWithCoordinatesAndNoZip(): void
    {
        $engine = self::engine(self::quote(25_000));

        $rates = $engine->quote(self::store(), self::coords(), 1000, 150_000.0);

        $this->assertCount(1, $rates, 'only Grab can answer without a zip');
        $this->assertSame('BDD-GRAB', $rates[0]['service_code']);
    }

    public function testQuoteReturnsNothingWithNeitherZipNorCoordinates(): void
    {
        $engine = self::engine(self::quote(25_000));

        $this->assertSame([], $engine->quote(self::store(), ['city' => 'jakarta'], 1000, 150_000.0));
    }
}
