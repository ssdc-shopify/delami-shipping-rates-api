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
 * Shopify's privacy webhooks now act, not just log.
 *
 * Every order's name, email, phone and address is stored here, so a
 * customers/redact must erase that customer's details and a shop/redact must
 * remove the shop's data — while the shipping records themselves survive.
 */
final class PrivacyWebhooksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const SECRET = 'shpss_privacy_test';

    protected function setUp(): void
    {
        parent::setUp();

        $config      = config('Encryption');
        $config->key = str_repeat("\x42", 32);
        Services::injectMock('encrypter', Services::encrypter($config, false));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('encrypter');
        parent::tearDown();
    }

    private function store(string $slug): int
    {
        $stores = model(StoreModel::class);
        $stores->insert([
            'slug' => $slug, 'name' => $slug, 'merchant_id' => '', 'active' => 1,
            'shop_domain' => $slug . '.myshopify.com', 'access_token' => 'token', 'storefront_key' => 'pk_' . $slug,
        ]);
        $id = (int) $stores->getInsertID();
        $stores->saveAppCredentials($id, 'client', self::SECRET);

        return $id;
    }

    private function order(int $storeId, int $orderId, string $email): void
    {
        model(OrderModel::class)->insert([
            'store_id' => $storeId, 'order_id' => $orderId, 'order_name' => '#' . $orderId,
            'email' => $email, 'customer_name' => 'Siti Rahayu', 'ship_name' => 'Siti Rahayu',
            'ship_phone' => '0812', 'ship_address1' => 'Jl. Melati 1', 'ship_address2' => 'RT 01',
            'ship_city' => 'Bekasi', 'ship_province' => 'Jawa Barat', 'ship_zip' => '17116',
            'total_price' => 150000,
        ]);
    }

    private function deliver(string $shop, string $topic, array $payload)
    {
        $body = json_encode($payload);

        return $this->withHeaders([
            'X-Shopify-Shop-Domain' => $shop,
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            'Content-Type'          => 'application/json',
        ])->withBody($body)->post('shopify/webhooks/' . $topic);
    }

    public function testACustomerRedactErasesTheirDetailsAndKeepsTheShipment(): void
    {
        $alpha = $this->store('alpha');
        $this->order($alpha, 1001, 'siti@example.com');
        $this->order($alpha, 1002, 'SITI@example.com'); // not listed, same person
        $this->order($alpha, 1003, 'budi@example.com');
        model(AirwaybillModel::class)->insert(['order_id' => 1001, 'courier' => 'jne', 'waybill' => 'MOCK-JNE-951001']);

        $this->deliver('alpha.myshopify.com', 'customers-redact', [
            'shop_domain'      => 'alpha.myshopify.com',
            'customer'         => ['id' => 55, 'email' => 'siti@example.com'],
            'orders_to_redact' => [1001],
        ])->assertOK();

        $orders = model(OrderModel::class);
        foreach ([1001, 1002] as $id) {
            $row = $orders->findByOrderId($id);
            foreach (OrderModel::PERSONAL_COLUMNS as $column) {
                $this->assertNull($row[$column], "order {$id}: {$column} must be erased");
            }
            $this->assertSame('Bekasi', $row['ship_city'], 'city identifies nobody and stays');
            $this->assertEquals(150000, $row['total_price']);
        }

        $this->assertSame('budi@example.com', $orders->findByOrderId(1003)['email'], 'another customer is untouched');
        $this->assertNotNull(model(AirwaybillModel::class)->findByOrderId(1001), 'the shipment record stays');
    }

    public function testARedactNeverReachesAnotherStore(): void
    {
        $alpha = $this->store('alpha');
        $beta  = $this->store('beta');
        $this->order($alpha, 1001, 'siti@example.com');
        $this->order($beta, 2001, 'siti@example.com');

        $this->deliver('alpha.myshopify.com', 'customers-redact', [
            'customer' => ['id' => 55, 'email' => 'siti@example.com'], 'orders_to_redact' => [1001],
        ])->assertOK();

        $this->assertSame('siti@example.com', model(OrderModel::class)->findByOrderId(2001)['email']);
    }

    public function testAShopRedactRemovesTheShopsOrdersAndCredentials(): void
    {
        $alpha = $this->store('alpha');
        $beta  = $this->store('beta');
        $this->order($alpha, 1001, 'a@example.com');
        $this->order($beta, 2001, 'b@example.com');

        $this->deliver('alpha.myshopify.com', 'shop-redact', ['shop_domain' => 'alpha.myshopify.com'])->assertOK();

        $this->assertNull(model(OrderModel::class)->findByOrderId(1001));
        $this->assertNotNull(model(OrderModel::class)->findByOrderId(2001), 'another shop keeps its orders');

        $store = model(StoreModel::class)->find($alpha);
        foreach (['access_token', 'api_key', 'api_secret', 'storefront_key', 'server_key_hash'] as $column) {
            $this->assertNull($store[$column], "{$column} must be cleared");
        }
        $this->assertSame(0, (int) $store['active']);
    }

    public function testADataRequestChangesNothing(): void
    {
        $alpha = $this->store('alpha');
        $this->order($alpha, 1001, 'siti@example.com');

        $this->deliver('alpha.myshopify.com', 'customers-data-request', [
            'customer' => ['id' => 55, 'email' => 'siti@example.com'], 'orders_requested' => [1001],
        ])->assertOK();

        $this->assertSame('siti@example.com', model(OrderModel::class)->findByOrderId(1001)['email']);
    }
}
