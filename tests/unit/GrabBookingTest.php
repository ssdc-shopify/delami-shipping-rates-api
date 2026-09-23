<?php

namespace Tests\Unit;

use App\Libraries\Awb\AwbService;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * A Grab booking is priced on the drop-off point. The pin the shopper set is
 * carried onto the order as the "coordinates" custom attribute, and that is
 * what booking must use — the geocoded address is only a fallback.
 */
final class GrabBookingTest extends CIUnitTestCase
{
    private function dropCoordinates(array $order): array
    {
        $service = new AwbService(['id' => 1, 'slug' => 'test', 'active' => 1]);
        $method  = new ReflectionMethod(AwbService::class, 'grabDropCoordinates');
        $method->setAccessible(true);

        return $method->invoke($service, $order);
    }

    public function testReadsThePinFromTheOrderCoordinatesAttribute(): void
    {
        [$lat, $lng] = $this->dropCoordinates([
            'customAttributes' => [
                ['key' => 'source', 'value' => 'mock-storefront'],
                ['key' => 'coordinates', 'value' => '-6.2285501,106.8337856'],
            ],
            'shippingAddress' => ['address1' => 'ignored when a pin exists'],
        ]);

        $this->assertSame(-6.2285501, $lat);
        $this->assertSame(106.8337856, $lng);
    }

    public function testTrimsWhitespaceInTheAttribute(): void
    {
        [$lat, $lng] = $this->dropCoordinates([
            'customAttributes' => [['key' => 'coordinates', 'value' => ' -6.20 , 106.80 ']],
            'shippingAddress'  => [],
        ]);

        $this->assertSame(-6.20, $lat);
        $this->assertSame(106.80, $lng);
    }

    public function testMalformedAttributeAndNoAddressYieldsNoCoordinates(): void
    {
        // Non-numeric attribute → ignored → geocode; a blank address geocodes
        // to nothing (no network call), so booking gets no coordinates.
        [$lat, $lng] = $this->dropCoordinates([
            'customAttributes' => [['key' => 'coordinates', 'value' => 'not,coords']],
            'shippingAddress'  => [],
        ]);

        $this->assertNull($lat);
        $this->assertNull($lng);
    }
}
