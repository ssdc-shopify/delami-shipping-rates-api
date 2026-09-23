<?php

namespace Tests\Unit;

use App\Libraries\Awb\AwbService;
use App\Libraries\Awb\MockMode;
use App\Libraries\Shopify\AdminClient;
use App\Models\AirwaybillModel;
use CodeIgniter\Settings\Settings;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use ReflectionProperty;
use RuntimeException;

/**
 * Whether the customer gets a shipping confirmation comes down to the
 * notifyCustomer flag on fulfillmentCreate, and to a fulfillment actually
 * being created. Both are pinned here.
 */
final class FulfillmentNotifyTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock mode is stored in the settings table. Back the Settings service
        // with memory instead, so this test needs no database.
        $config           = config('Settings');
        $config->handlers = ['array'];
        Services::injectMock('settings', new Settings($config));
    }

    protected function tearDown(): void
    {
        // The service is shared for the whole run; leaving the in-memory one
        // in place would carry this test's mode into every later test.
        Services::resetSingle('settings');
        parent::tearDown();
    }

    private function serviceWith(AdminClient $client, bool $mockAwb): AwbService
    {
        $service = new AwbService(['id' => 1, 'slug' => 'test']);

        MockMode::set($mockAwb, 'test');

        // The client is built lazily and held privately; swap it for a spy
        // rather than widening the production API for the sake of a test.
        $property = new ReflectionProperty(AwbService::class, 'shopify');
        $property->setAccessible(true);
        $property->setValue($service, $client);

        return $service;
    }

    /** A row that is ready to be fulfilled. */
    private static function row(): array
    {
        return [
            'id'       => 1,
            'order_id' => 6_300_000_000_000,
            'waybill'  => '0791260004663052',
            'courier'  => AirwaybillModel::COURIER_JNE,
            'status'   => AirwaybillModel::STATUS_PENDING,
        ];
    }

    public function testLiveModeNotifiesTheCustomer(): void
    {
        $spy = new class extends AdminClient {
            public ?bool $notify = null;

            // Bypass the real constructor: this stub never opens a connection.
            public function __construct() {}

            public function fulfillWithTracking(string $legacyOrderId, string $company, string $number, string $url, bool $notify = true): array
            {
                $this->notify = $notify;

                throw new RuntimeException('stop before the DB write');
            }
        };

        $this->serviceWith($spy, false)->fulfill(self::row());

        $this->assertTrue($spy->notify, 'live mode must email the shipping confirmation');
    }

    public function testMockModeDoesNotNotifyTheCustomer(): void
    {
        $spy = new class extends AdminClient {
            public ?bool $notify = null;

            // Bypass the real constructor: this stub never opens a connection.
            public function __construct() {}

            public function fulfillWithTracking(string $legacyOrderId, string $company, string $number, string $url, bool $notify = true): array
            {
                $this->notify = $notify;

                throw new RuntimeException('stop before the DB write');
            }
        };

        $this->serviceWith($spy, true)->fulfill(self::row());

        $this->assertFalse($spy->notify, 'a mock shipment must never email the customer');
    }

    public function testASkippedFulfillmentIsReportedRatherThanTreatedAsSuccess(): void
    {
        $client = new class extends AdminClient {
            public function __construct() {}

            public function fulfillWithTracking(string $legacyOrderId, string $company, string $number, string $url, bool $notify = true): array
            {
                // What AdminClient returns when the order has no open
                // fulfillment order — e.g. it is on hold.
                return ['skipped' => 'no open fulfillment orders'];
            }
        };

        $issue = $this->serviceWith($client, false)->fulfill(self::row());

        $this->assertNotNull($issue, 'a skipped fulfilment must not pass as success');
        $this->assertStringContainsString('not notified', $issue);
    }

    public function testAFailedFulfillmentIsReported(): void
    {
        $client = new class extends AdminClient {
            public function __construct() {}

            public function fulfillWithTracking(string $legacyOrderId, string $company, string $number, string $url, bool $notify = true): array
            {
                throw new RuntimeException('401 Unauthorized');
            }
        };

        $issue = $this->serviceWith($client, false)->fulfill(self::row());

        $this->assertNotNull($issue);
        $this->assertStringContainsString('401 Unauthorized', $issue);
    }

    public function testAnAlreadyFulfilledRowIsLeftAlone(): void
    {
        $client = new class extends AdminClient {
            public function __construct() {}

            public function fulfillWithTracking(string $legacyOrderId, string $company, string $number, string $url, bool $notify = true): array
            {
                throw new RuntimeException('must not be called for a fulfilled row');
            }
        };

        $row           = self::row();
        $row['status'] = AirwaybillModel::STATUS_FULFILLED;

        $this->assertNull($this->serviceWith($client, false)->fulfill($row));
    }
}
