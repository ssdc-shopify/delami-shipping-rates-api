<?php

namespace Tests\Unit;

use App\Libraries\Shipping\RateEngine;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * Rate arithmetic: the subsidy, the pre-subsidy price the admin screens show,
 * and the subunit conversion Shopify's CarrierService expects.
 */
final class RatePricingTest extends CIUnitTestCase
{
    /**
     * buildRate is private because nothing outside the engine may assemble a
     * rate; the arithmetic it holds is still worth pinning down directly.
     *
     * @param int $subsidy Whole IDR the store puts in
     */
    private static function rate(float $perKg, int $weightKg, int $subsidy, int $insurance = 0): array
    {
        $method = new ReflectionMethod(RateEngine::class, 'buildRate');
        $method->setAccessible(true);

        return $method->invoke(
            new RateEngine(),
            'SPX - HEMAT.',
            'BDD-SPX-HEMAT',
            $perKg,
            '2',
            $weightKg,
            150_000.0,
            $subsidy,
            $insurance,
        );
    }

    public function testPriceIsInSubunitsAndNetOfTheSubsidy(): void
    {
        $rate = self::rate(20_000, 1, 5_000);

        $this->assertSame(1_500_000, $rate['total_price'], 'Rp 15.000 in subunits');
        $this->assertSame(5_000, $rate['subsidy']);
        $this->assertSame(20_000, $rate['price_gross'], 'what it would cost unsubsidised');
    }

    public function testGrossAlwaysEqualsChargedPlusSubsidy(): void
    {
        $rate = self::rate(20_000, 2, 7_500, 3_000);

        $this->assertSame(
            $rate['price_gross'],
            (int) ($rate['total_price'] / 100) + $rate['subsidy'],
            'the struck-through price must reconcile with what is charged',
        );
    }

    public function testSubsidyIsCappedAtTheShippingCostAndNeverGoesNegative(): void
    {
        // Rp 50.000 subsidy against Rp 12.000 of postage.
        $rate = self::rate(12_000, 1, 50_000);

        $this->assertSame(0, $rate['total_price'], 'shipping is free, not negative');
        $this->assertSame(12_000, $rate['subsidy'], 'only the postage is covered');
        $this->assertStringContainsString('Gratis ongkir', $rate['service_name']);
    }

    public function testSubsidyIsNamedNotCalledFree(): void
    {
        $rate = self::rate(20_000, 1, 5_000);

        $this->assertStringContainsString('Subsidi Rp 5.000', $rate['service_name']);
        $this->assertStringNotContainsString('FREE', $rate['service_name']);
    }

    public function testNoSubsidyLeavesTheTitleAlone(): void
    {
        $rate = self::rate(20_000, 1, 0);

        $this->assertSame('SPX - HEMAT.', $rate['service_name']);
        $this->assertSame(0, $rate['subsidy']);
    }

    public function testInternalKeysAreNotPartOfTheShopifyContract(): void
    {
        $rate = self::rate(20_000, 1, 5_000);
        $sent = array_intersect_key($rate, array_flip(RateEngine::SHOPIFY_FIELDS));

        $this->assertArrayNotHasKey('subsidy', $sent);
        $this->assertArrayNotHasKey('price_gross', $sent);
        $this->assertArrayNotHasKey('rate_handle', $sent);
        $this->assertSame(RateEngine::SHOPIFY_FIELDS, array_keys($sent));
    }
}
