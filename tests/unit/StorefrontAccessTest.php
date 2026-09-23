<?php

namespace Tests\Unit;

use App\Models\StoreModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Who may call the storefront endpoints, and how often.
 *
 * Browsers and app builds carry only the publishable key and are limited per
 * client IP. A storefront calling from its own server also sends the secret
 * server key and is limited per store, because all of its shoppers arrive
 * from one IP. A wrong secret is refused, never quietly downgraded.
 */
final class StorefrontAccessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $publishable;
    private string $secret;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();

        // The throttler service holds the cache it was built with, and the
        // test cache is replaced for every test — without this, one test's
        // request count carries into the next.
        Services::resetSingle('throttler');

        $stores = model(StoreModel::class);
        $stores->insert([
            'slug' => 'alpha', 'name' => 'alpha', 'merchant_id' => '',
            'shop_domain' => 'alpha.myshopify.com', 'active' => 1,
        ]);
        $this->storeId     = (int) $stores->getInsertID();
        $this->publishable = $stores->rotateStorefrontKey($this->storeId);
        $this->secret      = $stores->rotateServerKey($this->storeId);
    }

    private function hit(string $endpoint, string $body, array $headers = [])
    {
        return $this->withHeaders(['Content-Type' => 'application/json', 'X-Storefront-Key' => $this->publishable] + $headers)
            ->withBody($body)
            ->post('api/storefront/' . $endpoint);
    }

    /** Hammer an endpoint until one past its limit; returns the last response. */
    private function exceed(string $endpoint, string $body, int $limit, array $headers = [])
    {
        for ($i = 0; $i < $limit; $i++) {
            $this->hit($endpoint, $body, $headers);
        }

        return $this->hit($endpoint, $body, $headers);
    }

    private const UNKNOWN_ORDER = '{"reference":"#99999","email":"nobody@example.com"}';

    public function testABrowserIsLimitedPerIp(): void
    {
        $result = $this->exceed('track', self::UNKNOWN_ORDER, 15);

        $result->assertStatus(429);
        $this->assertNotSame('', $result->response()->getHeaderLine('Retry-After'));
    }

    public function testAVerifiedServerIsNotHeldToTheBrowserLimit(): void
    {
        $result = $this->exceed('track', self::UNKNOWN_ORDER, 15, ['X-Storefront-Secret' => $this->secret]);

        $result->assertStatus(404); // answered, not throttled
    }

    public function testAWrongSecretIsRefusedNotDowngraded(): void
    {
        $this->hit('track', self::UNKNOWN_ORDER, ['X-Storefront-Secret' => 'sk_wrong'])->assertStatus(401);
    }

    public function testGeocodingHasTheTightestBrowserLimit(): void
    {
        // An empty body is rejected with 400 — but only after the throttle
        // has counted it, so it spends no Google quota while filling the bucket.
        $this->assertSame(20, \App\Controllers\Api\StorefrontRates::STOREFRONT_LIMITS['geocode'][0]);
        $this->exceed('geocode', '{}', 20)->assertStatus(429);
    }

    public function testOnlyAHashOfTheServerKeyIsStored(): void
    {
        $row = model(StoreModel::class)->find($this->storeId);

        $this->assertStringNotContainsString($this->secret, (string) $row['server_key_hash']);
        $this->assertTrue(StoreModel::isServerKey($row, $this->secret));
    }

    public function testRotatingTheServerKeyRevokesTheOldOne(): void
    {
        $stores = model(StoreModel::class);
        $new    = $stores->rotateServerKey($this->storeId);
        $row    = $stores->find($this->storeId);

        $this->assertFalse(StoreModel::isServerKey($row, $this->secret));
        $this->assertTrue(StoreModel::isServerKey($row, $new));
    }

    public function testAStoreWithoutAServerKeyAcceptsNoSecret(): void
    {
        $this->assertFalse(StoreModel::isServerKey(['server_key_hash' => null], 'sk_anything'));
        $this->assertFalse(StoreModel::isServerKey(['server_key_hash' => hash('sha256', '')], ''));
    }

    /**
     * @dataProvider jsonEndpoints
     */
    public function testMalformedJsonIsTheCallersMistake(string $endpoint): void
    {
        $this->hit($endpoint, '{"reference":')->assertStatus(400);
    }

    public static function jsonEndpoints(): iterable
    {
        yield 'rates'   => ['rates'];
        yield 'geocode' => ['geocode'];
        yield 'track'   => ['track'];
    }

    public function testMalformedJsonToTheCheckoutCallbackIsA400(): void
    {
        config('Shopify')->carrierCallbackToken = 'test-callback-token';

        $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody('{"rate":')
            ->post('carrier/rates/alpha?token=test-callback-token')
            ->assertStatus(400);
    }
}
