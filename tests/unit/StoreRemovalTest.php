<?php

namespace Tests\Unit;

use App\Libraries\Shopify\AdminClient;
use App\Libraries\Stores\StoreRemoval;
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
use RuntimeException;

/**
 * Deleting a store: what points at this site on Shopify goes first, then the
 * store's orders, airway bills and the store itself — and nothing of any
 * other store, or of any other site.
 */
final class StoreRemovalTest extends CIUnitTestCase
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
        Services::resetSingle('auth');
    }

    private function store(string $slug, bool $installed = false): array
    {
        $stores = model(StoreModel::class);
        $stores->insert([
            'slug' => $slug, 'name' => $slug, 'merchant_id' => '', 'active' => 1,
            'shop_domain' => $slug . '.myshopify.com', 'access_token' => $installed ? 'encrypted-token' : null,
        ]);

        return $stores->find($stores->getInsertID());
    }

    private function shipped(array $store, int $orderId, ?string $waybill = 'MOCK-JNE-1'): void
    {
        model(OrderModel::class)->insert(['store_id' => $store['id'], 'order_id' => $orderId, 'order_name' => '#' . $orderId]);
        model(AirwaybillModel::class)->insert(['order_id' => $orderId, 'courier' => 'jne', 'waybill' => $waybill]);
    }

    /** A fake Shopify for the store, recording what was asked of it. */
    private static function shopify(?\Throwable $failure = null): AdminClient
    {
        return new class ($failure) extends AdminClient {
            public array $unregistered = [];
            public array $carrierRemoval = [];

            public function __construct(private ?\Throwable $failure) {}

            public function unregisterWebhooks(array $topics): array
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                $this->unregistered = $topics;

                return array_fill_keys($topics, 'removed');
            }

            public function removeCarrierService(string $name, string $callbackUrl): string
            {
                $this->carrierRemoval = [$name, $callbackUrl];

                return 'removed';
            }
        };
    }

    // ------------------------------------------------------------------
    // StoreRemoval
    // ------------------------------------------------------------------

    public function testItDeletesTheStoreWithItsOrdersAndAirwayBillsOnly(): void
    {
        $alpha = $this->store('alpha');
        $beta  = $this->store('beta');
        $this->shipped($alpha, 1001);
        $this->shipped($alpha, 1002, null); // a booking that never got a waybill
        $this->shipped($beta, 2001);

        $report = (new StoreRemoval())->remove($alpha);

        $this->assertSame(2, $report['orders']);
        $this->assertSame(2, $report['shipments']);
        $this->assertNull(model(StoreModel::class)->find($alpha['id']));
        $this->assertNull(model(OrderModel::class)->findByOrderId(1001));
        $this->assertNull(model(AirwaybillModel::class)->findByOrderId(1002));

        // Beta is untouched.
        $this->assertNotNull(model(StoreModel::class)->find($beta['id']));
        $this->assertNotNull(model(OrderModel::class)->findByOrderId(2001));
        $this->assertNotNull(model(AirwaybillModel::class)->findByOrderId(2001));
    }

    public function testANotInstalledStoreLeavesShopifyAlone(): void
    {
        $called = false;
        $report = (new StoreRemoval(function () use (&$called) {
            $called = true;

            return self::shopify();
        }))->remove($this->store('alpha'));

        $this->assertFalse($called);
        $this->assertSame(['skipped' => 'not installed'], $report['shopify']);
    }

    public function testAnInstalledStoreIsDetachedFromThisSiteOnShopify(): void
    {
        $shopify = self::shopify();

        $report = (new StoreRemoval(static fn () => $shopify))->remove($this->store('alpha', installed: true));

        $this->assertSame(array_keys(config('Shopify')->webhookTopics), $shopify->unregistered, 'every topic this app registers');
        $this->assertSame(config('Shopify')->carrierServiceName, $shopify->carrierRemoval[0]);
        $this->assertStringContainsString('/carrier/rates/alpha', $shopify->carrierRemoval[1]);
        $this->assertSame('removed', $report['shopify']['carrier']);
    }

    public function testAnUnreachableShopifyDoesNotBlockTheDelete(): void
    {
        $alpha  = $this->store('alpha', installed: true);
        $report = (new StoreRemoval(static fn () => self::shopify(new RuntimeException('401 Unauthorized'))))->remove($alpha);

        $this->assertSame('401 Unauthorized', $report['shopify']['error']);
        $this->assertNull(model(StoreModel::class)->find($alpha['id']));
    }

    // ------------------------------------------------------------------
    // AdminClient: only what points at this site
    // ------------------------------------------------------------------

    public function testOnlyThisSitesCarrierServiceIsRemoved(): void
    {
        $shopifyWith = static fn (string $callback) => new class ($callback) extends AdminClient {
            public array $deleted = [];

            public function __construct(private string $callback) {}

            public function graphql(string $query, array $variables = []): array
            {
                if (str_contains($query, 'carrierServiceDelete')) {
                    $this->deleted[] = $variables['id'];

                    return ['carrierServiceDelete' => ['deletedId' => $variables['id'], 'userErrors' => []]];
                }

                return ['carrierServices' => ['nodes' => [
                    ['id' => 'gid://cs/1', 'name' => 'Delami', 'callbackUrl' => $this->callback, 'active' => true],
                ]]];
            }
        };

        $ours = $shopifyWith('https://ours.example.com/carrier/rates/alpha?token=t');
        $this->assertSame('removed', $ours->removeCarrierService('Delami', 'https://ours.example.com/carrier/rates/alpha?token=x'));
        $this->assertSame(['gid://cs/1'], $ours->deleted);

        $theirs = $shopifyWith('https://prod.example.com/carrier/rates/alpha?token=t');
        $this->assertSame('other-site', $theirs->removeCarrierService('Delami', 'https://ours.example.com/carrier/rates/alpha?token=x'));
        $this->assertSame([], $theirs->deleted);

        $this->assertSame('none', $ours->removeCarrierService('Someone else', 'https://ours.example.com/x'));
    }

    // ------------------------------------------------------------------
    // Admin → Stores
    // ------------------------------------------------------------------

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

    public function testNothingIsDeletedWithoutTypingTheSlug(): void
    {
        $alpha = $this->store('alpha');
        $admin = $this->admin();

        foreach (['', 'Alpha', 'alpha-2', 'beta'] as $typed) {
            $this->actingAs($admin)->post('admin/stores/delete/' . $alpha['id'], [
                csrf_token()   => csrf_hash(),
                'confirm_slug' => $typed,
            ])->assertRedirectTo(site_url('admin/stores'));
        }

        $this->assertNotNull(model(StoreModel::class)->find($alpha['id']));
    }

    public function testTypingTheSlugDeletesTheStore(): void
    {
        $alpha = $this->store('alpha');
        $this->shipped($alpha, 1001);

        $this->actingAs($this->admin())->post('admin/stores/delete/' . $alpha['id'], [
            csrf_token()   => csrf_hash(),
            'confirm_slug' => ' alpha ',
        ]);

        $this->assertNull(model(StoreModel::class)->find($alpha['id']));
        $this->assertStringContainsString('Store alpha deleted, with 1 order(s)', (string) session('message'));
    }

    public function testTheStoresPageOffersIt(): void
    {
        $this->store('alpha');

        $result = $this->actingAs($this->admin())->get('admin/stores');

        $result->assertSee('Delete store');
        $result->assertSee('Type alpha to confirm');
    }

    public function testDeletingIsAdminOnly(): void
    {
        $alpha = $this->store('alpha');

        // A valid CSRF token, so it is the login check that turns this away.
        $this->post('admin/stores/delete/' . $alpha['id'], [csrf_token() => csrf_hash(), 'confirm_slug' => 'alpha'])
            ->assertRedirect();

        $this->assertNotNull(model(StoreModel::class)->find($alpha['id']));
    }
}
