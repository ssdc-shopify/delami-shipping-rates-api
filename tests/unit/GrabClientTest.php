<?php

namespace Tests\Unit;

use App\Libraries\Couriers\GrabClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * GrabClient's token handling and timeouts.
 *
 * A token rejected by Grab is replaced and the call retried once, instead of
 * GrabExpress vanishing until the cached token expires. A booking — which
 * dispatches a real rider — waits long enough not to give up on a reply that
 * is merely slow, since giving up is how one order ends up with two riders.
 */
final class GrabClientTest extends CIUnitTestCase
{
    /** A client whose network is a script of replies, recording every request. */
    private static function client(array $script): GrabClient
    {
        return new class ($script) extends GrabClient {
            public array $requests = [];

            public function __construct(private array $script)
            {
                parent::__construct();
            }

            protected function request(string $method, string $url, array $headers, ?string $body, float $timeout): ?array
            {
                $this->requests[] = compact('method', 'url', 'headers', 'timeout');

                return array_shift($this->script);
            }
        };
    }

    private static function token(string $value): array
    {
        return ['code' => 200, 'body' => json_encode(['token' => 'Bearer ' . $value])];
    }

    private static function quoteReply(): array
    {
        return ['code' => 200, 'body' => json_encode(['quotes' => [['amount' => 65000]]])];
    }

    public function testARejectedTokenIsReplacedAndTheCallRetriedOnce(): void
    {
        $grab = self::client([
            self::token('stale'),
            ['code' => 401, 'body' => '{"message":"unauthorized"}'],
            self::token('fresh'),
            self::quoteReply(),
        ]);

        $quote = $grab->quote([], [], []);

        $this->assertSame(65000, $quote['amount']);
        $this->assertSame('Bearer fresh', $grab->requests[3]['headers']['Authorization']);
    }

    public function testTheRetryHappensOnlyOnce(): void
    {
        $grab = self::client([
            self::token('stale'),
            ['code' => 401, 'body' => '{}'],
            self::token('also-rejected'),
            ['code' => 401, 'body' => '{}'],
            self::quoteReply(), // must never be reached
        ]);

        $this->assertNull($grab->quote([], [], []));
        $this->assertCount(4, $grab->requests);
    }

    public function testATokenIsReusedAcrossCalls(): void
    {
        $grab = self::client([self::token('one'), self::quoteReply(), self::quoteReply()]);

        $grab->quote([], [], []);
        $grab->quote([], [], []);

        $this->assertCount(3, $grab->requests, 'the second quote must reuse the cached token');
    }

    public function testBookingWaitsForTheBookingTimeout(): void
    {
        $grab = self::client([
            self::token('t'),
            ['code' => 201, 'body' => json_encode(['deliveryID' => 'IN-1', 'trackingURL' => 'https://grab.example/t'])],
        ]);
        $grab->setTimeout(0.8); // a quote budget must not shorten a booking

        $booked = $grab->createDelivery([
            'merchantOrderID' => '#1001', 'cartTotal' => 150000, 'weightKg' => 1,
            'dropAddress' => 'Jl. Sudirman 1', 'dropLat' => -6.2, 'dropLng' => 106.8,
            'recipientFirst' => 'Budi', 'recipientPhone' => '0812',
        ]);

        $this->assertSame('IN-1', $booked['deliveryID']);
        $this->assertSame((float) config('Couriers')->grabBookingTimeout, $grab->requests[1]['timeout']);
        $this->assertGreaterThanOrEqual(30.0, $grab->requests[1]['timeout']);
    }

    public function testAQuoteUsesTheTimeoutItIsGiven(): void
    {
        $grab = self::client([self::token('t'), self::quoteReply()]);
        $grab->setTimeout(1.5);

        $grab->quote([], [], []);

        $this->assertSame(1.5, $grab->requests[1]['timeout']);
    }

    public function testNoTokenMeansNoCall(): void
    {
        $grab = self::client([['code' => 500, 'body' => 'down']]);

        $this->assertNull($grab->quote([], [], []));
        $this->assertCount(1, $grab->requests);
    }
}
