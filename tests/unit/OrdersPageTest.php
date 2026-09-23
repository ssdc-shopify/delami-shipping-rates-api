<?php

namespace Tests\Unit;

use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Admin → Orders: one list per store, built from the orders Shopify has sent
 * this site by webhook. No Shopify call is made to render it.
 */
final class OrdersPageTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    // Every namespace: the admin login comes from Shield.
    protected $namespace = null;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $users = model(UserModel::class);
        $user  = new User(['username' => 'ops', 'active' => 1]);
        $users->save($user);

        $this->admin = $users->findById($users->getInsertID());
        $this->admin->createEmailIdentity(['email' => 'ops@delamibrands.com', 'password' => 'correct-horse-battery']);
        $this->admin->addGroup('admin');
    }

    private function store(string $slug, bool $orderWebhooks = true): int
    {
        $stores = model(StoreModel::class);
        $stores->insert([
            'slug'           => $slug,
            'name'           => $slug,
            'merchant_id'    => '',
            'shop_domain'    => $slug . '.myshopify.com',
            'active'         => 1,
            'order_webhooks' => $orderWebhooks ? 1 : 0,
        ]);

        return (int) $stores->getInsertID();
    }

    private function order(int $storeId, int $number, array $overrides = []): int
    {
        $orderId = 6_600_000_000_000 + $number;

        model(OrderModel::class)->insert($overrides + [
            'store_id'           => $storeId,
            'order_id'           => $orderId,
            'order_name'         => '#' . $number,
            'order_number'       => $number,
            'email'              => "buyer{$number}@example.com",
            'customer_name'      => "Buyer {$number}",
            'financial_status'   => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'total_price'        => 150000,
            'ordered_at'         => '2026-09-23 10:00:00',
        ]);

        return $orderId;
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->admin)->get('admin/orders' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    public function testThereIsOneListAndNoTabs(): void
    {
        $this->order($this->store('alpha'), 1001);

        $result = $this->page();

        $result->assertOK();
        $result->assertSee('#1001');
        $result->assertDontSee('Paid with AWB');
        $result->assertDontSee('nav-tabs');
    }

    public function testOnlyTheSelectedStoresOrdersAreListed(): void
    {
        $this->order($this->store('alpha'), 1001);
        $this->order($this->store('beta'), 2002);

        $result = $this->page(['store' => 'beta']);

        $result->assertSee('#2002');
        $result->assertDontSee('#1001');
    }

    public function testSearchFindsByOrderNumberEmailAndWaybill(): void
    {
        $store = $this->store('alpha');
        $this->order($store, 1001);
        $shipped = $this->order($store, 1002);
        model(AirwaybillModel::class)->insert([
            'order_id' => $shipped,
            'courier'  => AirwaybillModel::COURIER_JNE,
            'waybill'  => 'MOCK-JNE-951002',
            'status'   => AirwaybillModel::STATUS_FULFILLED,
        ]);

        $byNumber = $this->page(['q' => '#1001']);
        $byNumber->assertSee('#1001');
        $byNumber->assertDontSee('#1002');

        $this->page(['q' => 'buyer1002@example.com'])->assertSee('#1002');
        $this->page(['q' => 'MOCK-JNE-951002'])->assertSee('#1002');
    }

    /**
     * The Generate AWB and Print label buttons are hidden from the list. The
     * routes behind them are untouched and still answer — only the buttons
     * are gone.
     */
    public function testTheListShowsNoGenerateOrPrintButtons(): void
    {
        $store   = $this->store('alpha');
        $toShip  = $this->order($store, 1001);
        $shipped = $this->order($store, 1002);
        model(AirwaybillModel::class)->insert([
            'order_id' => $shipped,
            'courier'  => AirwaybillModel::COURIER_SPX,
            'waybill'  => 'MOCK-SPX-951002',
            'status'   => AirwaybillModel::STATUS_FULFILLED,
        ]);

        $result = $this->page();

        $result->assertDontSee('admin/awb/generate/' . $toShip);
        $result->assertDontSee('admin/awb/print/' . $shipped);
        $result->assertDontSee('Generate AWB');
        $result->assertDontSee('Print label');
        // The waybill itself is still shown.
        $result->assertSee('MOCK-SPX-951002');
    }

    /** Hidden is not removed: both routes are still wired to their actions. */
    public function testTheGenerateAndPrintRoutesStillAnswer(): void
    {
        $orderId = $this->order($this->store('alpha'), 1001);

        // Neither can reach Shopify here (the store was never authorized), so
        // each ends in its own error handling — which is the point: a 404
        // would mean the route itself had gone.
        $this->actingAs($this->admin)->get('admin/awb/print/' . $orderId)->assertOK();

        $this->actingAs($this->admin)->post('admin/awb/generate/' . $orderId, [
            csrf_token() => csrf_hash(),
            'store'      => 'alpha',
        ])->assertRedirect();
    }

    public function testAStoreWithOrderWebhooksOffSaysSo(): void
    {
        $this->store('alpha', false);

        $this->page()->assertSee('Order webhooks are off for this store on this site');
    }

    public function testAnEmptyListExplainsWhereOrdersComeFrom(): void
    {
        $this->store('alpha');

        $this->page()->assertSee('No orders received yet.');
    }
}
