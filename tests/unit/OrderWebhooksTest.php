<?php

namespace Tests\Unit;

use App\Libraries\Shopify\AdminClient;
use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The per-store switch for whether this site receives a store's order webhooks.
 *
 * A store connected to two copies of this app (a dev tunnel and production)
 * sends each of them every order. Switching one off must stop that site
 * storing the store's orders, must not touch the other site's subscriptions,
 * and must never take the uninstall webhook down with it.
 */
final class OrderWebhooksTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    // Every namespace: the admin login comes from Shield.
    protected $namespace = null;

    private const SHOP     = 'webhook-test.myshopify.com';
    private const SECRET   = 'shpss_test_secret';
    private const ORDER_ID = 6_500_000_000_123;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed key, so the stored app secret decrypts whatever .env holds.
        $config      = config('Encryption');
        $config->key = str_repeat("\x42", 32);
        Services::injectMock('encrypter', Services::encrypter($config, false));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('encrypter');
        parent::tearDown();
    }

    private function store(bool $orderWebhooks): array
    {
        $stores = model(StoreModel::class);
        $stores->insert([
            'slug'           => 'hooktest',
            'name'           => 'hooktest',
            'merchant_id'    => '',
            'shop_domain'    => self::SHOP,
            'active'         => 1,
            'order_webhooks' => $orderWebhooks ? 1 : 0,
        ]);
        $id = (int) $stores->getInsertID();
        $stores->saveAppCredentials($id, 'client-id', self::SECRET);

        return $stores->find($id);
    }

    /** An order this app has shipped — the only kind the receiver keeps in sync. */
    private function shippedOrder(): void
    {
        model(AirwaybillModel::class)->insert([
            'order_id' => self::ORDER_ID,
            'courier'  => AirwaybillModel::COURIER_JNE,
            'waybill'  => 'MOCK-JNE-951234',
            'status'   => AirwaybillModel::STATUS_FULFILLED,
        ]);
    }

    /** POST a webhook exactly as Shopify signs it. */
    private function deliver(string $topic, array $payload)
    {
        $body = json_encode($payload);

        return $this->withHeaders([
            'X-Shopify-Shop-Domain' => self::SHOP,
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            'Content-Type'          => 'application/json',
        ])->withBody($body)->post('shopify/webhooks/' . $topic);
    }

    private function orderPayload(array $overrides = []): array
    {
        return $overrides + [
            'id'               => self::ORDER_ID,
            'name'             => '#1234',
            'email'            => 'shopper@example.com',
            'financial_status' => 'paid',
            'shipping_address' => ['city' => 'Bandung'],
            'updated_at'       => '2026-09-23T10:00:00+07:00',
        ];
    }

    private function admin(): User
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'ops', 'active' => 1]);
        $users->save($user);

        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'ops@delamibrands.com', 'password' => 'correct-horse-battery']);
        $user->addGroup('admin');

        return $user;
    }

    // ------------------------------------------------------------------
    // The receiver
    // ------------------------------------------------------------------

    /** The order list is built from these: every order is stored, shipped or not. */
    public function testANewOrderIsStoredForTheOrderList(): void
    {
        $store = $this->store(true);

        $this->deliver('orders-create', $this->orderPayload())->assertOK();

        $row = model(OrderModel::class)->findByOrderId(self::ORDER_ID);
        $this->assertSame('shopper@example.com', $row['email'] ?? null);
        $this->assertSame((int) $store['id'], (int) $row['store_id']);
        // REST says null for unfulfilled; stored in the same words the
        // Generate AWB snapshot uses.
        $this->assertSame('unfulfilled', $row['fulfillment_status']);
    }

    public function testAnUpdateRefreshesTheStoredOrder(): void
    {
        $this->store(true);
        $this->shippedOrder();

        $this->deliver('orders-create', $this->orderPayload())->assertOK();
        $this->deliver('orders-updated', $this->orderPayload([
            'fulfillment_status' => 'partial',
            'updated_at'         => '2026-09-23T11:00:00+07:00',
        ]))->assertOK();

        $this->assertSame('partially_fulfilled', model(OrderModel::class)->findByOrderId(self::ORDER_ID)['fulfillment_status']);
    }

    /** Shopify can deliver out of order; a late, older update must not win. */
    public function testAnOlderDeliveryDoesNotOverwriteANewerOne(): void
    {
        $this->store(true);

        $this->deliver('orders-updated', $this->orderPayload([
            'financial_status' => 'refunded',
            'updated_at'       => '2026-09-23T12:00:00+07:00',
        ]))->assertOK();
        $this->deliver('orders-create', $this->orderPayload([
            'financial_status' => 'paid',
            'updated_at'       => '2026-09-23T10:00:00+07:00',
        ]))->assertOK();

        $this->assertSame('refunded', model(OrderModel::class)->findByOrderId(self::ORDER_ID)['financial_status']);
    }

    public function testADisabledStoresOrderWebhooksAreAcknowledgedButNotStored(): void
    {
        $this->store(false);
        $this->shippedOrder();

        // 200, not an error: an error only makes Shopify retry and, in the
        // end, flag the endpoint as failing.
        $this->deliver('orders-updated', $this->orderPayload())->assertOK();

        $this->assertNull(model(OrderModel::class)->findByOrderId(self::ORDER_ID));
    }

    public function testTheUninstallWebhookStillWorksWhenOrdersAreOff(): void
    {
        $store = $this->store(false);
        model(StoreModel::class)->update($store['id'], ['access_token' => 'encrypted-token']);

        $this->deliver('app-uninstalled', ['id' => 1])->assertOK();

        $this->assertNull(model(StoreModel::class)->find($store['id'])['access_token']);
    }

    public function testAStoreFromBeforeTheSwitchReceivesOrders(): void
    {
        $this->assertTrue(StoreModel::receivesOrderWebhooks(['slug' => 'legacy']));
    }

    // ------------------------------------------------------------------
    // Shopify registration
    // ------------------------------------------------------------------

    public function testRegisteringWithOrdersOffLeavesOnlyTheUninstallTopic(): void
    {
        $client = new class extends AdminClient {
            public array $registered = [];

            public function __construct() {}

            public function registerWebhook(string $topic, string $callbackUrl): array
            {
                $this->registered[] = $topic;

                return [];
            }
        };

        $client->registerAppWebhooks(false);
        $this->assertSame(['APP_UNINSTALLED'], $client->registered);

        $client->registered = [];
        $client->registerAppWebhooks(true);
        $this->assertSame(['APP_UNINSTALLED', 'ORDERS_CREATE', 'ORDERS_UPDATED'], $client->registered);
    }

    /**
     * The same store is subscribed by this site AND another deployment of the
     * app. Only this site's subscriptions may be deleted.
     */
    public function testUnregisteringRemovesOnlyThisSitesSubscriptions(): void
    {
        $client = new class extends AdminClient {
            public array $deleted = [];

            public function __construct() {}

            public function graphql(string $query, array $variables = []): array
            {
                if (str_contains($query, 'webhookSubscriptionDelete')) {
                    $this->deleted[] = $variables['id'];

                    return ['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => $variables['id'], 'userErrors' => []]];
                }

                return ['webhookSubscriptions' => ['nodes' => [
                    ['id' => 'gid://ours/create', 'topic' => 'ORDERS_CREATE', 'uri' => url_to('shopify-webhook', 'orders-create')],
                    ['id' => 'gid://ours/update', 'topic' => 'ORDERS_UPDATED', 'uri' => url_to('shopify-webhook', 'orders-updated')],
                    ['id' => 'gid://prod/create', 'topic' => 'ORDERS_CREATE', 'uri' => 'https://prod.example.com/shopify/webhooks/orders-create'],
                    ['id' => 'gid://prod/update', 'topic' => 'ORDERS_UPDATED', 'uri' => 'https://prod.example.com/shopify/webhooks/orders-updated'],
                ]]];
            }
        };

        $results = $client->unregisterOrderWebhooks();

        $this->assertSame(['gid://ours/create', 'gid://ours/update'], $client->deleted);
        $this->assertSame(['ORDERS_CREATE' => 'removed', 'ORDERS_UPDATED' => 'removed'], $results);
    }

    public function testUnregisteringWhenThisSiteHoldsNoneDeletesNothing(): void
    {
        $client = new class extends AdminClient {
            public array $deleted = [];

            public function __construct() {}

            public function graphql(string $query, array $variables = []): array
            {
                if (str_contains($query, 'webhookSubscriptionDelete')) {
                    $this->deleted[] = $variables['id'];
                }

                return ['webhookSubscriptions' => ['nodes' => [
                    ['id' => 'gid://prod/create', 'topic' => 'ORDERS_CREATE', 'uri' => 'https://prod.example.com/shopify/webhooks/orders-create'],
                ]]];
            }
        };

        $results = $client->unregisterOrderWebhooks();

        $this->assertSame([], $client->deleted);
        $this->assertSame(['ORDERS_CREATE' => 'not registered', 'ORDERS_UPDATED' => 'not registered'], $results);
    }

    // ------------------------------------------------------------------
    // Admin → Stores
    // ------------------------------------------------------------------

    public function testTheStoresPageShowsTheSwitchAndItsState(): void
    {
        $this->store(false);

        $result = $this->actingAs($this->admin())->get('admin/stores');

        $result->assertOK();
        $result->assertSee('Webhooks on this site');
        $result->assertSee('order webhooks off');
        $result->assertSee('Turn on');
        $result->assertSee('Re-register with Shopify');
    }

    public function testTheSwitchIsSavedForAStoreNotYetAuthorized(): void
    {
        $store = $this->store(true);
        $admin = $this->admin();

        $this->actingAs($admin)->post('admin/stores/order-webhooks/' . $store['id'], [
            csrf_token() => csrf_hash(),
            'enabled'    => '0',
        ])->assertRedirectTo(site_url('admin/stores'));

        $this->assertFalse(StoreModel::receivesOrderWebhooks(model(StoreModel::class)->find($store['id'])));

        $this->actingAs($admin)->post('admin/stores/order-webhooks/' . $store['id'], [
            csrf_token() => csrf_hash(),
            'enabled'    => '1',
        ]);

        $this->assertTrue(StoreModel::receivesOrderWebhooks(model(StoreModel::class)->find($store['id'])));
    }
}
