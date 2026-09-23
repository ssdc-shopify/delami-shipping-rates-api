<?php

namespace Tests\Unit;

use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * POST /api/storefront/track — the order, and the parcel once there is one.
 *
 * Every order reaches this app by webhook when it is placed, so an order that
 * has not shipped is answered with its status and `shipment: null` instead of
 * a 404. The email is still the lock: a wrong one is answered exactly as an
 * order that does not exist.
 */
final class TrackingApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $key;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('throttler');

        $stores = model(StoreModel::class);
        $stores->insert(['slug' => 'alpha', 'name' => 'alpha', 'merchant_id' => '', 'shop_domain' => 'alpha.myshopify.com', 'active' => 1]);
        $this->storeId = (int) $stores->getInsertID();
        $this->key     = $stores->rotateStorefrontKey($this->storeId);
    }

    private function order(int $number, array $overrides = []): int
    {
        $orderId = 6_900_000_000_000 + $number;

        model(OrderModel::class)->insert($overrides + [
            'store_id'         => $this->storeId,
            'order_id'         => $orderId,
            'order_name'       => '#' . $number,
            'order_number'     => $number,
            'email'            => 'shopper@example.com',
            'financial_status' => 'paid',
            'ship_name'        => 'Budi Santoso',
            'ship_city'        => 'Bandung',
            'ship_province'    => 'Jawa Barat',
            'shipping_title'   => 'SPX - HEMAT. (Subsidi Rp 5.000)',
            'shipping_code'    => 'BDD-SPX-HEMAT',
            'ordered_at'       => '2026-09-20 10:15:00',
        ]);

        return $orderId;
    }

    private function track(string $reference, string $email = 'shopper@example.com')
    {
        return $this->withHeaders(['Content-Type' => 'application/json', 'X-Storefront-Key' => $this->key])
            ->withBody(json_encode(['reference' => $reference, 'email' => $email]))
            ->post('api/storefront/track');
    }

    private static function json($result): array
    {
        return json_decode($result->getJSON(), true);
    }

    public function testAPaidUnshippedOrderIsBeingPrepared(): void
    {
        $this->order(1001);

        $result = $this->track('#1001');
        $result->assertOK();
        $body = self::json($result);

        $this->assertNull($body['shipment']);
        $this->assertSame('processing', $body['order']['status']);
        $this->assertSame('Being prepared', $body['order']['statusLabel']);
        $this->assertSame('SPX - HEMAT', $body['order']['service']);
        $this->assertSame('BDD-SPX-HEMAT', $body['order']['serviceCode']);
        $this->assertSame('2026-09-20 10:15:00', $body['order']['orderedAt']);
        $this->assertNull($body['order']['bookedAt']);
    }

    public function testAnUnpaidOrderIsAwaitingPayment(): void
    {
        $this->order(1002, ['financial_status' => 'pending']);

        $this->assertSame('awaiting_payment', self::json($this->track('#1002'))['order']['status']);
    }

    public function testACancelledOrderSaysSo(): void
    {
        $this->order(1003, ['cancelled_at' => '2026-09-21 09:00:00']);

        $body = self::json($this->track('#1003'));

        $this->assertSame('cancelled', $body['order']['status']);
        $this->assertNull($body['shipment']);
    }

    public function testAShippedOrderCarriesItsParcel(): void
    {
        $orderId = $this->order(1004);
        model(AirwaybillModel::class)->insert([
            'order_id' => $orderId, 'courier' => 'spx', 'waybill' => 'MOCK-SPX-951004',
            'status'   => AirwaybillModel::STATUS_FULFILLED,
        ]);

        $body = self::json($this->track('#1004'));

        $this->assertSame('shipped', $body['order']['status']);
        $this->assertNotNull($body['order']['bookedAt']);
        $this->assertSame('MOCK-SPX-951004', $body['shipment']['waybill']);
        $this->assertSame('mock', $body['shipment']['source']);
        $this->assertFalse($body['shipment']['stale']);
        $this->assertNotEmpty($body['stages']);
    }

    /** An airway bill row with no waybill yet is not a shipment. */
    public function testAPendingBookingIsStillBeingPrepared(): void
    {
        $orderId = $this->order(1005);
        model(AirwaybillModel::class)->insert(['order_id' => $orderId, 'courier' => 'jne', 'status' => AirwaybillModel::STATUS_PENDING]);

        $body = self::json($this->track('#1005'));

        $this->assertSame('processing', $body['order']['status']);
        $this->assertNull($body['shipment']);
    }

    public function testAWrongEmailIsIndistinguishableFromNoOrder(): void
    {
        $this->order(1006);

        $wrong   = $this->track('#1006', 'someone@example.com');
        $missing = $this->track('#9999', 'someone@example.com');

        $wrong->assertStatus(404);
        $missing->assertStatus(404);
        $this->assertSame($missing->getJSON(), $wrong->getJSON());
    }
}
