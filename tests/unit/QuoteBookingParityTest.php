<?php

namespace Tests\Unit;

use App\Libraries\Shipping\RateEngine;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The quote and the booking must price the same parcel the same way.
 *
 * Both used to be right on their own and wrong together: the shopper was
 * charged on one rule and the courier booked on another, so the gap only
 * showed up as a reweigh surcharge or an uninsured claim.
 */
final class QuoteBookingParityTest extends CIUnitTestCase
{
    /**
     * @dataProvider weights
     */
    public function testBillableWeightAlwaysRoundsUp(int $grams, int $expected): void
    {
        $this->assertSame($expected, RateEngine::billableWeightKg($grams));
    }

    public static function weights(): array
    {
        return [
            'nothing still bills 1kg'   => [0, 1],
            'under 1kg bills 1kg'       => [400, 1],
            'exactly 1kg'               => [1000, 1],
            // The old JNE tier rule allowed 0.2kg of grace and declared 1kg
            // here, while the shopper was charged for 2kg.
            'just over 1kg'             => [1150, 2],
            // The old Ninja/SPX rule used round() and declared 1kg.
            'mid-band rounds up'        => [1400, 2],
            'just under the next whole' => [1999, 2],
            'exactly 2kg'               => [2000, 2],
        ];
    }

    /**
     * A cart landing exactly on the threshold must be insured, because that
     * is the cart the shopper was charged insurance on. RateEngine is the
     * side that defines it; AwbService::isInsured() mirrors this.
     */
    public function testInsuranceIsChargedFromTheThresholdInclusive(): void
    {
        $engine = new RateEngine();

        $this->assertSame(0, $engine->insurance('jne', 499_999, 500_000), 'below the threshold');
        $this->assertGreaterThan(0, $engine->insurance('jne', 500_000, 500_000), 'exactly on it');
        $this->assertGreaterThan(0, $engine->insurance('jne', 500_001, 500_000), 'above it');
    }
}
