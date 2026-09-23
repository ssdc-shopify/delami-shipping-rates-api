<?php

namespace Tests\Unit;

use App\Libraries\Awb\AwbService;
use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Courier routing is driven by the service_code of the rate the shopper
 * chose on the cart page, which Shopify stores as shipping_line.code.
 */
final class CourierRoutingTest extends CIUnitTestCase
{
    private function service(): AwbService
    {
        return new AwbService(['id' => 1, 'slug' => 'test']);
    }

    private static function order(string $code, string $title = '', string $tags = ''): array
    {
        return [
            'name'         => '#1001',
            'tags'         => $tags,
            'shippingLine' => ['code' => $code, 'title' => $title],
        ];
    }

    public function testRoutesEachServiceCodeToItsCourier(): void
    {
        $service = $this->service();

        $this->assertSame(AirwaybillModel::COURIER_SPX, $service->routeCourier(self::order('BDD-SPX-HEMAT', 'SPX - HEMAT.')));
        $this->assertSame(AirwaybillModel::COURIER_SPX, $service->routeCourier(self::order('BDD-SPX-REGULER', 'SPX - REGULER.')));
        $this->assertSame(AirwaybillModel::COURIER_NINJA, $service->routeCourier(self::order('BDD-NINJA', 'NINJA XPRESS - REGULER.')));
        $this->assertSame(AirwaybillModel::COURIER_JNE, $service->routeCourier(self::order('BDD-REG19', 'JNE - REGULER.')));
    }

    public function testSubsidySuffixInTitleDoesNotChangeRouting(): void
    {
        // RateEngine appends a subsidy note to service_name when one applies.
        $this->assertSame(
            AirwaybillModel::COURIER_SPX,
            $this->service()->routeCourier(self::order('BDD-SPX-HEMAT', 'SPX - HEMAT. (Subsidi Rp 15.000)'))
        );
        $this->assertSame(
            AirwaybillModel::COURIER_SPX,
            $this->service()->routeCourier(self::order('BDD-SPX-HEMAT', 'SPX - HEMAT. (Gratis ongkir)'))
        );
    }

    public function testClickAndCollectOverridesTheChosenRate(): void
    {
        $this->assertSame(
            AirwaybillModel::COURIER_NINJA,
            $this->service()->routeCourier(self::order('BDD-SPX-HEMAT', 'SPX - HEMAT.', 'sale,clickncollect'))
        );
    }

    public function testUnknownCodeFallsBackToTitleMatching(): void
    {
        $service = $this->service();

        $this->assertSame(AirwaybillModel::COURIER_SPX, $service->routeCourier(self::order('SOMETHING-NEW', 'SPX - KILAT.')));
        $this->assertSame(AirwaybillModel::COURIER_JNE, $service->routeCourier(self::order('', '')));
    }

    public function testSpxServiceTypeComesFromTheChosenRate(): void
    {
        $service = $this->service();

        $this->assertSame(1, $service->spxServiceType(self::order('BDD-SPX-REGULER')));
        $this->assertSame(1, $service->spxServiceType(self::order('BDD-SPX-HEMAT')));
        $this->assertSame(1, $service->spxServiceType(self::order('UNKNOWN')));
    }

    public function testStoreThresholdOverridesTheConfigDefault(): void
    {
        $defaults = new \Config\Couriers();

        // Blank / missing on the store row -> config default.
        $this->assertSame(
            $defaults->spxMinCart,
            \App\Models\StoreModel::threshold([], 'spx_min_cart', 'spxMinCart'),
        );
        $this->assertSame(
            $defaults->spxMinCart,
            \App\Models\StoreModel::threshold(['spx_min_cart' => null], 'spx_min_cart', 'spxMinCart'),
        );

        // A per-store value wins.
        $this->assertSame(
            75_000,
            \App\Models\StoreModel::threshold(['spx_min_cart' => 75_000], 'spx_min_cart', 'spxMinCart'),
        );

        // Zero is a real value (e.g. "no JNE cap"), not "unset".
        $this->assertSame(
            0,
            \App\Models\StoreModel::threshold(['jne_max_cart' => 0], 'jne_max_cart', 'jneMaxCart'),
        );
    }

    public function testMockAwbModeIsOnByDefault(): void
    {
        // Fail-safe: a fresh/misconfigured environment must never be able to
        // book a real shipment or fulfill a real order.
        $this->assertTrue((new \Config\Couriers())->mockAwb);
    }

    public function testWebhookPayloadMapsTheChosenRateOntoTheOrderRow(): void
    {
        $row = OrderModel::fromWebhookPayload([
            'id'                     => 6579891503266,
            'name'                   => '#1001',
            'order_number'           => 1001,
            'email'                  => 'buyer@example.com',
            'financial_status'       => 'paid',
            'currency'               => 'IDR',
            'current_total_price'    => '350000.00',
            'current_subtotal_price' => '340000.00',
            'total_weight'           => 1200,
            'created_at'             => '2026-07-19T10:15:00+07:00',
            'customer'               => ['first_name' => 'Siti', 'last_name' => 'Rahayu'],
            'shipping_lines'         => [['title' => 'SPX - HEMAT.', 'code' => 'BDD-SPX-HEMAT', 'price' => '7500.00']],
            'shipping_address'       => ['name' => 'Siti Rahayu', 'zip' => '17116', 'city' => 'Bekasi'],
        ], 1);

        $this->assertSame(6579891503266, $row['order_id']);
        $this->assertSame('BDD-SPX-HEMAT', $row['shipping_code']);
        $this->assertSame('SPX - HEMAT.', $row['shipping_title']);
        $this->assertSame(7500.0, $row['shipping_price']);
        $this->assertSame(350000.0, $row['total_price']);
        $this->assertSame(1200, $row['total_weight']);
        $this->assertSame('Siti Rahayu', $row['customer_name']);
        $this->assertSame('2026-07-19 10:15:00', $row['ordered_at']);
    }
}
