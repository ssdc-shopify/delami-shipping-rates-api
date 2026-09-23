<?php

namespace Tests\Unit;

use App\Libraries\Tracking\TrackingService;
use App\Models\OrderModel;
use App\Models\StoreModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use ReflectionMethod;

/**
 * The smaller fixes from the 2026-09-23 audit, one test each.
 */
final class AuditFixesTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Start every test logged out: Shield's authenticator is shared across
        // the run and would otherwise carry one test's login into the next.
        Services::resetSingle('auth');
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

    private function store(string $slug): int
    {
        $stores = model(StoreModel::class);
        $stores->insert(['slug' => $slug, 'name' => $slug, 'merchant_id' => '', 'shop_domain' => $slug . '.myshopify.com', 'active' => 1, 'api_key' => 'client']);

        return (int) $stores->getInsertID();
    }

    /** The install redirect names the shop and the app's Client ID. */
    public function testTheInstallRouteNeedsAnAdminLogin(): void
    {
        $id = $this->store('alpha');

        $result = $this->get('shopify/install?store=' . $id);

        $result->assertRedirect();
        $this->assertStringNotContainsString('myshopify.com', $result->getRedirectUrl());
    }

    /** With no store named, an AWB is booked against the order's own store. */
    public function testGenerateAwbUsesTheOrdersOwnStore(): void
    {
        $this->store('alpha'); // the store the old fallback would have picked
        $beta = $this->store('beta');
        model(OrderModel::class)->insert(['store_id' => $beta, 'order_id' => 6_800_000_000_001, 'order_name' => '#1', 'financial_status' => 'paid']);

        $this->actingAs($this->admin())->post('admin/awb/generate/6800000000001', [csrf_token() => csrf_hash()]);

        // Neither store is authorized, so booking stops at Shopify — naming
        // the store it tried, which must be beta.
        $this->assertStringContainsString('beta.myshopify.com', (string) session('error'));
    }

    /** Two webhooks for a new order can both miss the row and both insert. */
    public function testALostInsertRaceBecomesAnUpdate(): void
    {
        model(OrderModel::class)->insert(['order_id' => 6_800_000_000_002, 'order_name' => '#2', 'financial_status' => 'pending']);

        // A model that, like the losing request, does not see the row at first.
        $racing = new class extends OrderModel {
            private int $lookups = 0;

            public function findByOrderId(int|string $orderId): ?array
            {
                return $this->lookups++ === 0 ? null : parent::findByOrderId($orderId);
            }
        };

        $row = $racing->upsertByOrderId(['order_id' => 6_800_000_000_002, 'order_name' => '#2', 'financial_status' => 'paid']);

        $this->assertSame('paid', $row['financial_status']);
        $this->assertSame(1, model(OrderModel::class)->where('order_id', 6_800_000_000_002)->countAllResults());
    }

    /** A date-only courier scan must not borrow the current time of day. */
    public function testADateOnlyScanIsNotGivenTheCurrentTime(): void
    {
        $parse = new ReflectionMethod(TrackingService::class, 'parseDate');

        $this->assertSame('2020-06-09 00:00:00', $parse->invoke(new TrackingService(), '09-06-2020'));
        $this->assertSame('2026-07-20 14:49:40', $parse->invoke(new TrackingService(), '2026-07-20 14:49:40'));
        $this->assertSame('2026-07-20 14:49:00', $parse->invoke(new TrackingService(), '20-07-2026 14:49'));
    }

    public function testTrackingLookupColumnsAreIndexed(): void
    {
        $indexed = static fn (string $table) => array_merge(...array_values(array_map(
            static fn ($index) => $index->fields,
            db_connect()->getIndexData($table),
        )));

        $this->assertContains('waybill', $indexed('airwaybills'));
        $this->assertContains('order_number', $indexed('orders'));
        $this->assertContains('order_name', $indexed('orders'));
    }

    /** The admin's CDN assets are pinned by hash. */
    public function testAdminAssetsCarrySubresourceIntegrity(): void
    {
        $result = $this->actingAs($this->admin())->get('admin/settings');

        $result->assertSee('integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"');
        $result->assertSee('integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"');
    }
}
