<?php

namespace Tests\Unit;

use App\Libraries\Couriers\WidgetProxyClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use ReflectionProperty;

/**
 * How long the courier proxy's replies are remembered.
 *
 * A failed lookup used to be cached exactly like an answer — a day, for a
 * postcode — so one proxy timeout removed JNE, Ninja and SPX from checkout for
 * that postcode until the entry expired. A failure is now remembered only
 * briefly; a real answer, including "no coverage", keeps its full TTL.
 */
final class ProxyCacheTest extends CIUnitTestCase
{
    /** A proxy client that answers from $replies instead of the network. */
    private static function proxy(array $replies): WidgetProxyClient
    {
        return new class ($replies) extends WidgetProxyClient {
            public array $asked = [];

            public function __construct(private array $replies)
            {
                parent::__construct();
            }

            protected function fetchMany(array $urls, float $timeout): array
            {
                $this->asked[] = ['urls' => $urls, 'timeout' => $timeout];

                return array_map(fn (string $url) => $this->reply($url), $urls);
            }

            protected function fetch(string $url, float $timeout): array
            {
                $this->asked[] = ['urls' => [$url], 'timeout' => $timeout];

                return $this->reply($url);
            }

            private function reply(string $url): array
            {
                foreach ($this->replies as $path => $reply) {
                    if (str_ends_with($url, $path)) {
                        return $reply;
                    }
                }

                return ['code' => 0, 'body' => false];
            }
        };
    }

    /** Seconds until the cached entry for a proxy path expires. */
    private static function ttlOf(string $path): int
    {
        $cache = service('cache');
        self::assertInstanceOf(MockCache::class, $cache);

        $expirations = (new ReflectionProperty(MockCache::class, 'expirations'))->getValue($cache);

        return $expirations['proxy_' . md5($path)] - time();
    }

    public function testARealAnswerIsCachedForItsFullTtl(): void
    {
        $proxy = self::proxy(['get_nxid/12950' => ['code' => 200, 'body' => '{"nxid":"IDJKT"}']]);

        $result = $proxy->getMany(['ninja' => 'get_nxid/12950'], 86400);

        $this->assertSame(['nxid' => 'IDJKT'], $result['ninja']);
        $this->assertGreaterThan(86000, self::ttlOf('get_nxid/12950'));
    }

    /** "No coverage" is an answer too, and worth remembering. */
    public function testAJsonNullAnswerIsAlsoCachedForItsFullTtl(): void
    {
        $proxy = self::proxy(['get_nxid/99999' => ['code' => 200, 'body' => 'null']]);

        $this->assertNull($proxy->getMany(['ninja' => 'get_nxid/99999'], 86400)['ninja']);
        $this->assertGreaterThan(86000, self::ttlOf('get_nxid/99999'));
    }

    /**
     * @dataProvider failures
     */
    public function testAFailureIsCachedOnlyBriefly(array $reply): void
    {
        $proxy = self::proxy(['get_nxid/12950' => $reply]);

        $this->assertNull($proxy->getMany(['ninja' => 'get_nxid/12950'], 86400)['ninja']);
        $this->assertLessThanOrEqual(config('Couriers')->failureCacheTtl, self::ttlOf('get_nxid/12950'));
    }

    public static function failures(): iterable
    {
        yield 'timeout / no connection' => [['code' => 0, 'body' => false]];
        yield 'server error'            => [['code' => 502, 'body' => 'Bad Gateway']];
        yield 'HTML error page on 200'  => [['code' => 200, 'body' => '<html>maintenance</html>']];
        yield 'empty 200'               => [['code' => 200, 'body' => '']];
    }

    /** The single-lookup path follows the same rule. */
    public function testSingleLookupsDistinguishFailureFromAnswer(): void
    {
        $proxy = self::proxy(['get_code_destination/12950' => ['code' => 503, 'body' => 'down']]);

        $this->assertNull($proxy->jneDestination('12950'));
        $this->assertLessThanOrEqual(config('Couriers')->failureCacheTtl, self::ttlOf('get_code_destination/12950'));
    }

    /** A cached answer is served without asking again. */
    public function testACachedAnswerIsNotFetchedAgain(): void
    {
        $proxy = self::proxy(['get_nxid/12950' => ['code' => 200, 'body' => '{"nxid":"IDJKT"}']]);

        $proxy->getMany(['ninja' => 'get_nxid/12950'], 3600);
        $proxy->getMany(['ninja' => 'get_nxid/12950'], 3600);

        $this->assertCount(1, $proxy->asked);
    }

    /** The rate engine's time budget reaches the request as its timeout. */
    public function testTheTimeoutSetIsTheOneUsed(): void
    {
        $proxy = self::proxy(['get_nxid/40115' => ['code' => 200, 'body' => '{}']]);

        $proxy->setTimeout(1.25);
        $proxy->getMany(['ninja' => 'get_nxid/40115'], 3600);

        $this->assertSame(1.25, $proxy->asked[0]['timeout']);
    }
}
