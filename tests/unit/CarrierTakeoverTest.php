<?php

namespace Tests\Unit;

use App\Libraries\Shopify\AdminClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Registering the checkout rate callback when a store is shared by sites.
 *
 * A dev tunnel and production register the same carrier-service name on the
 * same store. Re-pointing "the service with this name" silently moved every
 * checkout's rates to whichever site pressed the button last. A service on
 * another host is now left alone unless the operator explicitly takes it over.
 */
final class CarrierTakeoverTest extends CIUnitTestCase
{
    private const OURS   = 'https://ours.example.com/carrier/rates/alpha?token=t';
    private const THEIRS = 'https://prod.example.com/carrier/rates/alpha?token=t';

    /** An AdminClient whose Shopify holds $existing, recording mutations. */
    private static function shopify(?array $existing): AdminClient
    {
        return new class ($existing) extends AdminClient {
            public array $mutations = [];

            public function __construct(private ?array $existing) {}

            public function graphql(string $query, array $variables = []): array
            {
                if (str_contains($query, 'carrierServices(')) {
                    return ['carrierServices' => ['nodes' => $this->existing === null ? [] : [$this->existing]]];
                }

                $this->mutations[] = str_contains($query, 'carrierServiceCreate') ? 'create' : 'update';
                $service           = ['id' => 'gid://1', 'name' => 'Delami', 'callbackUrl' => $variables['input']['callbackUrl'], 'active' => true];

                return ['carrierServiceCreate' => ['carrierService' => $service, 'userErrors' => []],
                    'carrierServiceUpdate'     => ['carrierService' => $service, 'userErrors' => []]];
            }
        };
    }

    private static function service(string $url): array
    {
        return ['id' => 'gid://1', 'name' => 'Delami', 'callbackUrl' => $url, 'active' => true];
    }

    public function testANewServiceIsCreated(): void
    {
        $shopify = self::shopify(null);

        [, $action] = $shopify->ensureCarrierService('Delami', self::OURS);

        $this->assertSame('created', $action);
    }

    public function testAnotherSitesServiceIsLeftAlone(): void
    {
        $shopify = self::shopify(self::service(self::THEIRS));

        [$service, $action] = $shopify->ensureCarrierService('Delami', self::OURS);

        $this->assertSame('foreign', $action);
        $this->assertSame(self::THEIRS, $service['callbackUrl']);
        $this->assertSame([], $shopify->mutations, 'nothing may be written without a take-over');
    }

    public function testTakingOverMovesItHere(): void
    {
        $shopify = self::shopify(self::service(self::THEIRS));

        [$service, $action] = $shopify->ensureCarrierService('Delami', self::OURS, true);

        $this->assertSame('updated', $action);
        $this->assertSame(self::OURS, $service['callbackUrl']);
    }

    /** Same site, new token or store path: an ordinary re-point, no prompt. */
    public function testOurOwnServiceIsRepointedFreely(): void
    {
        $shopify = self::shopify(self::service('https://ours.example.com/carrier/rates/alpha?token=old'));

        [, $action] = $shopify->ensureCarrierService('Delami', self::OURS);

        $this->assertSame('updated', $action);
    }

    public function testAnUnchangedServiceIsNotRewritten(): void
    {
        $shopify = self::shopify(self::service(self::OURS));

        [, $action] = $shopify->ensureCarrierService('Delami', self::OURS);

        $this->assertSame('unchanged', $action);
        $this->assertSame([], $shopify->mutations);
    }

    public function testOriginsCompareSchemeHostAndPort(): void
    {
        $this->assertSame('https://ours.example.com', AdminClient::origin(self::OURS));
        $this->assertNotSame(AdminClient::origin('https://a.example.com:8443/x'), AdminClient::origin('https://a.example.com/x'));
        $this->assertSame(AdminClient::origin('HTTPS://Ours.Example.com/a'), AdminClient::origin('https://ours.example.com/b'));
    }
}
