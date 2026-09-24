<?php

namespace Tests\Unit;

use App\Libraries\Shopify\AdminClient;
use App\Libraries\Tracking\ShipmentLookup;
use App\Libraries\Tracking\TrackingPayload;
use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use App\Models\StoreModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;

/**
 * Tracking for stores whose orders this site does not receive by webhook.
 *
 * Order webhooks are a per-store, per-site switch, so the local orders table
 * cannot be the only source: Shopify is asked for the order, and the courier
 * and tracking number come from its fulfillment when this site booked none.
 */
final class TrackingShopifyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    /** What the fake Shopify holds, by store slug: list of order nodes. */
    private array $shopify = [];

    /** Every Shopify call made: [store slug, method, argument]. */
    private array $calls = [];

    private bool $shopifyDown = false;

    private function store(string $slug, bool $orderWebhooks): array
    {
        $stores = model(StoreModel::class);
        $stores->insert([
            'slug' => $slug, 'name' => $slug, 'merchant_id' => '', 'active' => 1,
            'shop_domain' => $slug . '.myshopify.com', 'access_token' => 'token',
            'order_webhooks' => $orderWebhooks ? 1 : 0,
        ]);

        return $stores->find($stores->getInsertID());
    }

    /** A Shopify order node, as AdminClient's tracking queries return it. */
    private static function node(int $id, string $name, array $overrides = []): array
    {
        return $overrides + [
            'legacyResourceId'         => (string) $id,
            'name'                     => $name,
            'email'                    => 'Shopper@Example.com',
            'createdAt'                => '2026-09-20T03:00:00Z',
            'cancelledAt'              => null,
            'displayFinancialStatus'   => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'shippingLine'             => ['title' => 'JNE - REGULER. (Subsidi Rp 5.000)', 'code' => 'BDD-REG19'],
            'shippingAddress'          => ['name' => 'Budi', 'city' => 'Bandung', 'province' => 'Jawa Barat'],
            'fulfillments'             => [],
        ];
    }

    private static function fulfilled(string $company, string $number, string $url = 'https://courier.example/t/1'): array
    {
        return [
            'displayFulfillmentStatus' => 'FULFILLED',
            'fulfillments'             => [[
                'createdAt'    => '2026-09-21T05:00:00Z',
                'trackingInfo' => [['company' => $company, 'number' => $number, 'url' => $url]],
            ]],
        ];
    }

    private function lookup(): ShipmentLookup
    {
        $test = $this;

        return new ShipmentLookup(static fn (array $store) => new class ($test, $store) extends AdminClient {
            public function __construct(private TrackingShopifyTest $test, private array $store) {}

            public function ordersForTracking(string $name): array
            {
                return $this->test->answer($this->store['slug'], 'search', $name);
            }

            public function orderForTracking(string $legacyId): ?array
            {
                return $this->test->answer($this->store['slug'], 'order', $legacyId)[0] ?? null;
            }

            public function ordersByTrackingNumber(string $number): array
            {
                return $this->test->answer($this->store['slug'], 'text', $number);
            }
        });
    }

    /** The fake Shopify's answer — also records the call. */
    public function answer(string $slug, string $method, string $arg): array
    {
        $this->calls[] = [$slug, $method, $arg];

        if ($this->shopifyDown) {
            throw new RuntimeException('Shopify GraphQL HTTP 503');
        }

        return array_values(array_filter($this->shopify[$slug] ?? [], static fn (array $n) => match ($method) {
            'search' => $n['name'] === $arg,
            // Free text is loose on purpose: it returns every order mentioning
            // the text anywhere, as Shopify's does — the caller must verify.
            'text'   => str_contains(json_encode($n), $arg),
            default  => $n['legacyResourceId'] === $arg,
        }));
    }

    // ------------------------------------------------------------------

    public function testAnOrderThisSiteNeverReceivedIsFoundOnShopify(): void
    {
        $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1001')];

        $match = $this->lookup()->find('#1001', 'shopper@example.com');

        $this->assertSame('processing', $match['summary']['status']);
        $this->assertSame('JNE - REGULER', $match['summary']['service']);
        $this->assertSame('This order does not have a tracking number yet.', $match['summary']['note']);
    }

    public function testTheCourierAndNumberComeFromShopifysFulfillment(): void
    {
        $alpha = $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1001', self::fulfilled('JNE', 'MOCK-JNE-770001', 'https://jne.example/t/770001'))];

        $match = $this->lookup()->find('1001', 'shopper@example.com', $alpha);
        $body  = TrackingPayload::build($match);

        $this->assertSame('shipped', $body['order']['status']);
        $this->assertNull($body['order']['note']);
        $this->assertSame('jne', $body['shipment']['courier']);
        $this->assertSame('MOCK-JNE-770001', $body['shipment']['waybill']);
        $this->assertSame('https://jne.example/t/770001', $body['shipment']['trackingUrl'], "Shopify's link wins");
        $this->assertNotEmpty($body['shipment']['events']);
    }

    /** A carrier this app cannot trace is named and linked, never guessed as JNE. */
    public function testAnUnknownCarrierIsLinkedOutNotGuessed(): void
    {
        $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1001', self::fulfilled('J&T Express', 'JT0001', 'https://jt.example/JT0001') + [
            'shippingLine' => ['title' => 'Shipping', 'code' => ''],
        ])];

        $body = TrackingPayload::build($this->lookup()->find('#1001', 'shopper@example.com'));

        $this->assertSame('other', $body['shipment']['courier']);
        $this->assertSame('J&T Express', $body['shipment']['courierName']);
        $this->assertSame('unavailable', $body['shipment']['source']);
        $this->assertSame('https://jt.example/JT0001', $body['shipment']['trackingUrl']);
        $this->assertSame([], $body['shipment']['events']);
    }

    public function testFulfilledWithoutATrackingNumberSaysSo(): void
    {
        $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1001', [
            'displayFulfillmentStatus' => 'FULFILLED',
            'fulfillments'             => [['createdAt' => '2026-09-21T05:00:00Z', 'trackingInfo' => []]],
        ])];

        $body = TrackingPayload::build($this->lookup()->find('#1001', 'shopper@example.com'));

        $this->assertSame('shipped', $body['order']['status']);
        $this->assertNull($body['shipment']);
        $this->assertSame('This order does not have a tracking number yet.', $body['order']['note']);
    }

    public function testAWrongEmailIsStillNotFound(): void
    {
        $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1001')];

        $this->assertNull($this->lookup()->find('#1001', 'someone@example.com'));
    }

    public function testAnUnreachableShopifyReadsAsNotFound(): void
    {
        $this->store('alpha', false);
        $this->shopifyDown = true;

        $this->assertNull($this->lookup()->find('#1001', 'shopper@example.com'));
    }

    /** Webhooks off: a stored row may be stale, so Shopify's copy wins. */
    public function testWithWebhooksOffShopifysCopyOfAStoredOrderWins(): void
    {
        $alpha = $this->store('alpha', false);
        model(OrderModel::class)->insert([
            'store_id' => $alpha['id'], 'order_id' => 5001, 'order_name' => '#1001', 'order_number' => 1001,
            'email' => 'shopper@example.com', 'financial_status' => 'paid',
        ]);
        $this->shopify['alpha'] = [self::node(5001, '#1001', ['cancelledAt' => '2026-09-22T01:00:00Z'])];

        $this->assertSame('cancelled', $this->lookup()->find('#1001', 'shopper@example.com')['summary']['status']);
    }

    /** Webhooks on: the stored row is current, and Shopify is not asked at all. */
    public function testWithWebhooksOnTheStoredOrderIsUsedWithoutAskingShopify(): void
    {
        $alpha = $this->store('alpha', true);
        model(OrderModel::class)->insert([
            'store_id' => $alpha['id'], 'order_id' => 5001, 'order_name' => '#1001', 'order_number' => 1001,
            'email' => 'shopper@example.com', 'financial_status' => 'paid',
        ]);
        model(AirwaybillModel::class)->insert(['order_id' => 5001, 'courier' => 'jne', 'waybill' => 'MOCK-JNE-951001']);

        $this->assertSame('shipped', $this->lookup()->find('#1001', 'shopper@example.com')['summary']['status']);
        $this->assertSame([], $this->calls);

        // …and a wrong email for that stored order does not go searching Shopify either.
        $this->assertNull($this->lookup()->find('#1001', 'someone@example.com'));
        $this->assertSame([], $this->calls);
    }

    /** A store whose local copy says fulfilled, but booked elsewhere: Shopify has the number. */
    public function testAnOrderShippedBySomethingElseGetsItsTrackingFromShopify(): void
    {
        $alpha = $this->store('alpha', true);
        model(OrderModel::class)->insert([
            'store_id' => $alpha['id'], 'order_id' => 5001, 'order_name' => '#1001', 'order_number' => 1001,
            'email' => 'shopper@example.com', 'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled',
        ]);
        $this->shopify['alpha'] = [self::node(5001, '#1001', self::fulfilled('Ninja Xpress', 'MOCK-NINJA-1') + [
            'shippingLine' => ['title' => 'NINJA XPRESS - REGULER.', 'code' => 'BDD-NINJA'],
        ])];

        $match = $this->lookup()->find('#1001', 'shopper@example.com');

        $this->assertSame('ninja', $match['awb']['courier']);
        $this->assertSame('MOCK-NINJA-1', $match['awb']['waybill']);
    }

    /** Shopify's carrier field is often blank; the delivery method still names the courier. */
    public function testTheDeliveryMethodNamesTheCourierWhenTheCarrierIsBlank(): void
    {
        $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1122', self::fulfilled('', 'MOCK-GRAB-951122') + [
            'shippingLine' => ['title' => 'GRABEXPRESS - INSTANT.', 'code' => 'BDD-GRAB'],
        ])];

        $this->assertSame('grab', $this->lookup()->find('#1122', 'shopper@example.com')['awb']['courier']);
    }

    /** What the shopper chose — and what this app books with — outranks the carrier typed. */
    public function testTheDeliveryMethodOutranksTheCarrierField(): void
    {
        $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1001', self::fulfilled('Ninja Xpress', 'CGK0001'))];

        $this->assertSame('jne', $this->lookup()->find('#1001', 'shopper@example.com')['awb']['courier']);
    }

    /** A delivery method naming no courier leaves the carrier field to decide. */
    public function testTheCarrierFieldAnswersWhenTheDeliveryMethodNamesNone(): void
    {
        $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1001', self::fulfilled('Ninja Xpress', 'NV0001') + [
            'shippingLine' => ['title' => 'Shipping', 'code' => ''],
        ])];

        $this->assertSame('ninja', $this->lookup()->find('#1001', 'shopper@example.com')['awb']['courier']);
    }

    public function testTheHostedPageSearchesEveryConnectedStore(): void
    {
        $this->store('alpha', false);
        $this->store('beta', false);
        $this->shopify['beta'] = [self::node(6001, '#2001')];

        $match = $this->lookup()->find('#2001', 'shopper@example.com');

        $this->assertSame('#2001', $match['summary']['orderName']);
    }

    public function testAStoresKeyOnlySearchesThatStore(): void
    {
        $alpha = $this->store('alpha', false);
        $this->store('beta', false);
        $this->shopify['beta'] = [self::node(6001, '#2001')];

        $this->assertNull($this->lookup()->find('#2001', 'shopper@example.com', $alpha));
        $this->assertSame(['alpha'], array_unique(array_column($this->calls, 0)));
    }

    /** The tracking number from a shipping confirmation finds the order too. */
    public function testATrackingNumberFindsTheOrderOnShopify(): void
    {
        $this->store('alpha', false);
        $this->shopify['alpha'] = [self::node(5001, '#1001', self::fulfilled('SPX', 'SPXID069982453238', 'https://spx.co.id/en/track') + [
            'shippingLine' => ['title' => 'SPX - HEMAT.', 'code' => 'BDD-SPX-HEMAT'],
        ])];

        $match = $this->lookup()->find('SPXID069982453238', 'shopper@example.com');

        $this->assertSame('#1001', $match['summary']['orderName']);
        $this->assertSame('spx', $match['awb']['courier']);
        $this->assertSame('SPXID069982453238', $match['awb']['waybill']);
    }

    /** Free text can match anything; only an exact tracking number counts. */
    public function testAFreeTextHitWithoutThatTrackingNumberDoesNotCount(): void
    {
        $this->store('alpha', false);
        // Mentions the text in its shipping title, but its tracking number differs.
        $this->shopify['alpha'] = [self::node(5001, '#1001', self::fulfilled('SPX', 'SPXID000000000001') + [
            'shippingLine' => ['title' => 'SPXID06998 promo', 'code' => 'x'],
        ])];

        $this->assertNull($this->lookup()->find('SPXID06998', 'shopper@example.com'));
    }

    /** Anything but a plain token never reaches Shopify's search as syntax. */
    public function testSearchSyntaxInAReferenceIsNotSentAsAQuery(): void
    {
        $client = new class extends AdminClient {
            public array $queries = [];

            public function __construct() {}

            public function graphql(string $query, array $variables = []): array
            {
                $this->queries[] = $variables;

                return ['orders' => ['nodes' => []]];
            }
        };

        $this->assertSame([], $client->ordersByTrackingNumber('x OR email:*'));
        $this->assertSame([], $client->queries);
    }

    /** "No such order" is remembered briefly, so guessing does not drain the API budget. */
    public function testAMissingOrderIsNotSearchedForTwiceInAMinute(): void
    {
        $this->store('alpha', false);

        $this->lookup()->find('#9999', 'shopper@example.com');
        $first = count($this->calls); // by name, then by tracking number

        $this->lookup()->find('#9999', 'shopper@example.com');

        $this->assertSame(2, $first);
        $this->assertCount($first, $this->calls, 'the repeat must not reach Shopify');
    }
}
