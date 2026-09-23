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
use Config\Services;

/**
 * Admin → Track Simulator: the tracking twin of the Rate Simulator.
 *
 * "By waybill" shows a courier's answer; "As a shopper" shows exactly what
 * /api/storefront/track would return — proved here by comparing the two.
 */
final class TrackSimulatorTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private User $admin;
    private array $alpha;
    private array $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('auth');
        Services::resetSingle('throttler');

        $users = model(UserModel::class);
        $user  = new User(['username' => 'ops', 'active' => 1]);
        $users->save($user);
        $this->admin = $users->findById($users->getInsertID());
        $this->admin->createEmailIdentity(['email' => 'ops@delamibrands.com', 'password' => 'correct-horse-battery']);
        $this->admin->addGroup('admin');

        $stores = model(StoreModel::class);
        foreach (['alpha', 'beta'] as $slug) {
            $stores->insert(['slug' => $slug, 'name' => $slug, 'merchant_id' => '', 'shop_domain' => $slug . '.myshopify.com', 'active' => 1]);
            $this->{$slug} = $stores->find($stores->getInsertID());
        }

        model(OrderModel::class)->insert([
            'store_id' => $this->alpha['id'], 'order_id' => 7_100_000_000_001, 'order_name' => '#1001', 'order_number' => 1001,
            'email' => 'shopper@example.com', 'financial_status' => 'paid', 'ship_city' => 'Bandung',
            'shipping_title' => 'JNE - REGULER.', 'ordered_at' => '2026-09-20 10:00:00',
        ]);
        model(AirwaybillModel::class)->insert([
            'order_id' => 7_100_000_000_001, 'courier' => 'jne', 'waybill' => 'MOCK-JNE-951001',
            'status'   => AirwaybillModel::STATUS_FULFILLED,
        ]);
    }

    private function simulate(array $form)
    {
        return $this->actingAs($this->admin)->post('admin/track-simulator', [csrf_token() => csrf_hash()] + $form);
    }

    public function testThePageIsAdminOnly(): void
    {
        $this->get('admin/track-simulator')->assertRedirect();
    }

    public function testThePageOffersBothWaysIn(): void
    {
        $result = $this->actingAs($this->admin)->get('admin/track-simulator');

        $result->assertOK();
        $result->assertSee('By waybill');
        $result->assertSee('As a shopper');
    }

    public function testTracingAWaybillShowsTheCourierAnswer(): void
    {
        $result = $this->simulate(['mode' => 'waybill', 'courier' => 'jne', 'waybill' => 'MOCK-JNE-951999']);

        $result->assertOK();
        $result->assertSee('Courier answer');
        $result->assertSee('source: mock');
        $result->assertSee('Shipment booked');
    }

    public function testAWaybillWithoutACourierIsRefused(): void
    {
        $this->simulate(['mode' => 'waybill', 'courier' => 'fedex', 'waybill' => 'X1'])
            ->assertSee('Choose a courier and enter a waybill.');
    }

    public function testShopperModeShowsExactlyWhatTheApiReturns(): void
    {
        // The API, called as a storefront would.
        $key = model(StoreModel::class)->rotateStorefrontKey((int) $this->alpha['id']);
        $api = $this->withHeaders(['Content-Type' => 'application/json', 'X-Storefront-Key' => $key])
            ->withBody(json_encode(['reference' => '#1001', 'email' => 'shopper@example.com']))
            ->post('api/storefront/track');
        $fromApi = json_decode($api->getJSON(), true);

        // The simulator, for the same store and lookup.
        $page = $this->simulate(['mode' => 'shopper', 'store' => 'alpha', 'reference' => '#1001', 'email' => 'shopper@example.com']);
        $page->assertSee('HTTP 200');
        preg_match('#<pre[^>]*>(.*?)</pre>#s', $page->getBody(), $m);
        $fromPage = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        // checkedAt is the moment of each lookup; everything else must match.
        unset($fromApi['shipment']['checkedAt'], $fromPage['shipment']['checkedAt']);
        $this->assertSame($fromApi, $fromPage);
        $this->assertSame('shipped', $fromPage['order']['status']);
    }

    public function testShopperModeAnswers404ForAWrongEmail(): void
    {
        $result = $this->simulate(['mode' => 'shopper', 'store' => 'alpha', 'reference' => '#1001', 'email' => 'someone@example.com']);

        $result->assertSee('HTTP 404');
        $result->assertSee('no shipment found for that reference and email');
    }

    public function testShopperModeIsScopedToTheStoreLikeAKey(): void
    {
        $this->simulate(['mode' => 'shopper', 'store' => 'beta', 'reference' => '#1001', 'email' => 'shopper@example.com'])
            ->assertSee('HTTP 404');
    }

    public function testAnUnshippedOrderShowsNoParcelYet(): void
    {
        model(OrderModel::class)->insert([
            'store_id' => $this->alpha['id'], 'order_id' => 7_100_000_000_002, 'order_name' => '#1002', 'order_number' => 1002,
            'email' => 'shopper@example.com', 'financial_status' => 'paid',
        ]);

        $result = $this->simulate(['mode' => 'shopper', 'store' => 'alpha', 'reference' => '#1002', 'email' => 'shopper@example.com']);

        $result->assertSee('Being prepared');
        $result->assertSee('This order does not have a tracking number yet.');
    }
}
