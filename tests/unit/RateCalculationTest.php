<?php

namespace Tests\Unit;

use App\Libraries\Awb\AwbService;
use App\Libraries\Couriers\GeocodeClient;
use App\Libraries\Couriers\GrabClient;
use App\Libraries\Couriers\WidgetProxyClient;
use App\Libraries\Shipping\RateEngine;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The shipping price itself, pinned to the rupiah at every boundary.
 *
 * The store is configured exactly as sirclo-oms-ssdc-dev is in Admin → Stores:
 *
 *   subsidy Rp 5.000, applied when the cart EXCEEDS Rp 300.000
 *   JNE offered UP TO Rp 100.000 (inclusive; and always if Ninja can't serve)
 *   SPX offered FROM Rp 100.000 (inclusive)
 *   insurance FROM Rp 500 (inclusive)
 *
 * Courier tariffs are fixed per kg: JNE 10.000, Ninja 12.000, SPX HEMAT 9.000,
 * SPX REGULER 11.000. Insurance: JNE and SPX 0.2% rounded up, Ninja 0.25%
 * rounded up with a Rp 2.500 minimum. Every expected price below was worked
 * out by hand from those numbers, and is stated in whole rupiah.
 */
final class RateCalculationTest extends CIUnitTestCase
{
    private const STORE = [
        'id' => 5, 'slug' => 'sirclo-oms-ssdc-dev',
        'subsidi_ongkir' => 5_000, 'minimum_order' => 300_000,
        'jne_max_cart' => 100_000, 'spx_min_cart' => 100_000, 'insurance_min_cart' => 500,
    ];

    private const DESTINATION = ['postal_code' => '12950', 'city' => 'Jakarta Selatan', 'address1' => 'Jl. Rasuna Said 7'];

    private static function engine(bool $ninjaCovers = true): RateEngine
    {
        $proxy = new class ($ninjaCovers) extends WidgetProxyClient {
            public function __construct(private bool $ninjaCovers)
            {
                parent::__construct();
            }

            public function getMany(array $paths, int $ttl): array
            {
                if (isset($paths['spx'])) {
                    return [
                        'jne'   => ['code_destination' => 'CGK10000'],
                        'ninja' => $this->ninjaCovers ? ['nxid' => 'IDJKT'] : null,
                        'spx'   => ['city' => 'JAKARTA'],
                    ];
                }

                return [
                    'jne'        => ['rates' => 10_000, 'etd' => '2-3'],
                    'ninja'      => isset($paths['ninja']) ? ['rates' => 12_000, 'etd' => '1-2'] : null,
                    'spxHemat'   => ['ratefinal' => 9_000, 'sla' => '3-4'],
                    'spxReguler' => ['ratefinal' => 11_000, 'sla' => '2-3'],
                ];
            }
        };

        $grab = new class extends GrabClient {
            public function __construct()
            {
                parent::__construct();
            }

            public function quote(array $origin, array $destination, array $packages): ?array
            {
                return ['amount' => 65_000, 'distance' => 12_000, 'service' => ['type' => 'INSTANT']];
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

    /** service_code => whole rupiah, cheapest first — the order checkout shows. */
    private static function prices(float $cart, int $grams = 1000, array $store = self::STORE, bool $ninjaCovers = true, string $method = 'standard'): array
    {
        $prices = [];
        foreach (self::engine($ninjaCovers)->quote($store, self::DESTINATION, $grams, $cart, $method) as $rate) {
            $prices[$rate['service_code']] = intdiv($rate['total_price'], 100);
        }

        return $prices;
    }

    private static function rate(string $code, float $cart, array $store = self::STORE, string $method = 'standard'): array
    {
        foreach (self::engine()->quote($store, self::DESTINATION, 1000, $cart, $method) as $rate) {
            if ($rate['service_code'] === $code) {
                return $rate;
            }
        }
        self::fail("{$code} was not offered for a Rp {$cart} cart");
    }

    // ------------------------------------------------------------------
    // The Rp 100.000 line: JNE's ceiling and SPX's floor
    // ------------------------------------------------------------------

    public function testJustUnderTheLineOffersJneAndNinjaButNotSpx(): void
    {
        // JNE 10.000 + ins ceil(199,998) = 200; Ninja 12.000 + min ins 2.500.
        $this->assertSame(['BDD-REG19' => 10_200, 'BDD-NINJA' => 14_500], self::prices(99_999));
    }

    public function testOnTheLineBothJneAndSpxAreOffered(): void
    {
        // Both limits are inclusive. ins 0.2% of 100.000 = 200.
        $this->assertSame([
            'BDD-SPX-HEMAT'   => 9_200,
            'BDD-REG19'       => 10_200,
            'BDD-SPX-REGULER' => 11_200,
            'BDD-NINJA'       => 14_500,
        ], self::prices(100_000));
    }

    public function testJustOverTheLineDropsJne(): void
    {
        // ins ceil(200,002) = 201 — insurance always rounds UP.
        $this->assertSame([
            'BDD-SPX-HEMAT'   => 9_201,
            'BDD-SPX-REGULER' => 11_201,
            'BDD-NINJA'       => 14_500,
        ], self::prices(100_001));
    }

    public function testJneStaysWhenNinjaCannotServeTheAddress(): void
    {
        // Above JNE's ceiling, but with no Ninja coverage JNE is the fallback.
        $prices = self::prices(150_000, ninjaCovers: false);

        $this->assertSame(10_300, $prices['BDD-REG19']); // 10.000 + ins 300
        $this->assertArrayNotHasKey('BDD-NINJA', $prices);
    }

    // ------------------------------------------------------------------
    // The Rp 300.000 line: the subsidy
    // ------------------------------------------------------------------

    public function testOnTheSubsidyLineNoSubsidyApplies(): void
    {
        // "Must exceed": exactly 300.000 gets nothing. ins 600.
        $this->assertSame([
            'BDD-SPX-HEMAT'   => 9_600,
            'BDD-SPX-REGULER' => 11_600,
            'BDD-NINJA'       => 14_500,
        ], self::prices(300_000));
    }

    public function testJustOverTheSubsidyLineTakesFiveThousandOffPostageOnly(): void
    {
        // Postage minus 5.000; insurance (ceil 600,002 = 601; Ninja 751 → min
        // 2.500) is never subsidised.
        $this->assertSame([
            'BDD-SPX-HEMAT'   => 4_601,
            'BDD-SPX-REGULER' => 6_601,
            'BDD-NINJA'       => 9_500,
        ], self::prices(300_001));
    }

    public function testTheSubsidyIsNamedAndTheGrossIsKept(): void
    {
        $hemat = self::rate('BDD-SPX-HEMAT', 300_001);

        $this->assertSame('SPX - HEMAT. (Subsidi Rp 5.000)', $hemat['service_name']);
        $this->assertSame(5_000, $hemat['subsidy']);
        $this->assertSame(9_601, $hemat['price_gross']); // what it cost before the subsidy
        $this->assertSame('Estimasi sampai 3-4 hari (Weight: 1Kg), Biaya Asuransi Pengiriman Rp 601', $hemat['description']);
    }

    public function testASubsidyCoveringAllPostageIsFreeShippingPlusInsurance(): void
    {
        $store = ['subsidi_ongkir' => 10_000] + self::STORE;

        $hemat = self::rate('BDD-SPX-HEMAT', 300_001, $store);

        // Subsidy capped at the 9.000 postage; the shopper pays only insurance.
        $this->assertSame(601 * 100, $hemat['total_price']);
        $this->assertSame('SPX - HEMAT. (Gratis ongkir, subsidi Rp 9.000)', $hemat['service_name']);
        $this->assertSame(9_000, $hemat['subsidy']);
    }

    // ------------------------------------------------------------------
    // The Rp 500 line: insurance
    // ------------------------------------------------------------------

    public function testBelowTheInsuranceLineThereIsNoInsurance(): void
    {
        $this->assertSame(['BDD-REG19' => 10_000, 'BDD-NINJA' => 12_000], self::prices(499));
        $this->assertStringNotContainsString('Asuransi', self::rate('BDD-REG19', 499)['description']);
    }

    public function testOnTheInsuranceLineEveryCourierInsures(): void
    {
        // JNE ceil(1,0) = 1; Ninja's Rp 2.500 minimum applies to any insured cart.
        $this->assertSame(['BDD-REG19' => 10_001, 'BDD-NINJA' => 14_500], self::prices(500));
    }

    // ------------------------------------------------------------------
    // Weight
    // ------------------------------------------------------------------

    public function testOneGramOverAKiloBillsTwoKilos(): void
    {
        $this->assertSame(['BDD-REG19' => 20_200, 'BDD-NINJA' => 26_500], self::prices(99_999, 1001));
        $this->assertSame(['BDD-REG19' => 10_200, 'BDD-NINJA' => 14_500], self::prices(99_999, 1000));
    }

    public function testAnUnweighedCartStillBillsOneKilo(): void
    {
        $this->assertSame(['BDD-REG19' => 10_200, 'BDD-NINJA' => 14_500], self::prices(99_999, 0));
    }

    // ------------------------------------------------------------------
    // GrabExpress
    // ------------------------------------------------------------------

    public function testGrabIsAFlatFareSubsidisedButNeverInsured(): void
    {
        $grab = self::rate('BDD-GRAB', 300_001, method: 'instant');

        $this->assertSame(60_000 * 100, $grab['total_price']); // 65.000 − 5.000
        $this->assertSame('GRABEXPRESS - INSTANT. (Subsidi Rp 5.000)', $grab['service_name']);
        $this->assertStringNotContainsString('Asuransi', $grab['description']);
    }

    // ------------------------------------------------------------------
    // The basket, and booking's view of it
    // ------------------------------------------------------------------

    public function testBothEndpointsTotalTheBasketTheSameWay(): void
    {
        [$grams, $total] = RateEngine::cartFromItems([
            ['grams' => 500, 'price' => 5_000_000, 'quantity' => 2], // 2 × Rp 50.000
            ['grams' => 300, 'price' => 1_999_900],                   // quantity defaults to 1
            ['grams' => 900, 'price' => 9_900_000, 'quantity' => 0],  // removed line
            ['grams' => -50, 'price' => -100, 'quantity' => 1],       // garbage counts as 0
        ]);

        $this->assertSame(1300, $grams);
        $this->assertSame(119_999.0, $total);
    }

    /**
     * Booking decides insurance on the same figure the quote charged it on:
     * original unit prices × quantity — not the order total, which adds
     * shipping and subtracts discounts.
     */
    public function testBookingInsuresOnTheCartTheShopperWasQuotedOn(): void
    {
        $order = [
            'currentTotalPriceSet'    => ['shopMoney' => ['amount' => '520000.00']], // + shipping
            'currentSubtotalPriceSet' => ['shopMoney' => ['amount' => '470000.00']], // − a discount
            'lineItems'               => ['nodes' => [
                ['quantity' => 2, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '150000.00']]],
                ['quantity' => 1, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '195000.00']]],
            ]],
        ];

        $this->assertSame(495_000.0, (new AwbService(['id' => 1, 'slug' => 'test']))->quotedCartTotal($order));
    }

    public function testBookingFallsBackToTheSubtotalWhenLinesCarryNoPrice(): void
    {
        $order = [
            'currentSubtotalPriceSet' => ['shopMoney' => ['amount' => '470000.00']],
            'lineItems'               => ['nodes' => [['quantity' => 1]]],
        ];

        $this->assertSame(470_000.0, (new AwbService(['id' => 1, 'slug' => 'test']))->quotedCartTotal($order));
    }
}
