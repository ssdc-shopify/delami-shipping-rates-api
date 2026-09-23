<?php

namespace Tests\Unit;

use App\Libraries\Tracking\ShipmentLookup;
use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Order numbers are per store, so two stores can both have a #1001.
 *
 * The lookup used to take whichever row came first, and the shopper whose
 * order sorted second was told it did not exist. Every candidate is now
 * tried, and the one carrying the shopper's email wins — still with a single
 * "not found" for everything else.
 */
final class TrackingLookupScopeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private array $alpha;
    private array $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $stores = model(StoreModel::class);
        foreach (['alpha', 'beta'] as $slug) {
            $stores->insert(['slug' => $slug, 'name' => $slug, 'merchant_id' => '', 'shop_domain' => $slug . '.myshopify.com', 'active' => 1]);
            $this->{$slug} = $stores->find($stores->getInsertID());
        }

        // Both stores have an order #1001, shipped, for different shoppers.
        $this->shipped($this->alpha, 6_700_000_000_001, 'ana@example.com', 'MOCK-JNE-ALPHA');
        $this->shipped($this->beta, 6_700_000_000_002, 'budi@example.com', 'MOCK-JNE-BETA');
    }

    private function shipped(array $store, int $orderId, string $email, string $waybill): void
    {
        model(OrderModel::class)->insert([
            'store_id' => $store['id'], 'order_id' => $orderId, 'order_name' => '#1001',
            'order_number' => 1001, 'email' => $email, 'ship_city' => 'Bandung',
        ]);
        model(AirwaybillModel::class)->insert(['order_id' => $orderId, 'courier' => 'jne', 'waybill' => $waybill]);
    }

    private function waybill(?array $match): ?string
    {
        return $match['awb']['waybill'] ?? null;
    }

    public function testTheHostedPageFindsTheOrderThatMatchesTheEmail(): void
    {
        $lookup = new ShipmentLookup();

        $this->assertSame('MOCK-JNE-ALPHA', $this->waybill($lookup->find('#1001', 'ana@example.com')));
        $this->assertSame('MOCK-JNE-BETA', $this->waybill($lookup->find('#1001', 'budi@example.com')));
        $this->assertSame('MOCK-JNE-BETA', $this->waybill($lookup->find('1001', 'BUDI@example.com')));
    }

    public function testAStoresKeyFindsItsOwnOrderEvenWhenAnotherStoreSharesTheNumber(): void
    {
        $this->assertSame('MOCK-JNE-BETA', $this->waybill((new ShipmentLookup())->find('#1001', 'budi@example.com', $this->beta)));
    }

    public function testAStoresKeyNeverReadsAnotherStoresOrder(): void
    {
        $this->assertNull((new ShipmentLookup())->find('#1001', 'budi@example.com', $this->alpha));
        $this->assertNull((new ShipmentLookup())->find('MOCK-JNE-BETA', 'budi@example.com', $this->alpha));
    }

    public function testAWrongEmailStillFindsNothing(): void
    {
        $this->assertNull((new ShipmentLookup())->find('#1001', 'someone@example.com'));
    }
}
