<?php

namespace Tests\Unit;

use App\Libraries\Couriers\JneClient;
use App\Libraries\Tracking\TrackingService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * A slow or broken courier, while a shopper waits on the tracking page.
 *
 * Every courier call is capped at couriers.trackTimeout. When a courier that
 * gave scans before gives nothing now, it is down — scans do not disappear —
 * so the last scans are shown, marked stale, with when they were fetched.
 */
final class TrackingResilienceTest extends CIUnitTestCase
{
    private const ROW = ['courier' => 'jne', 'waybill' => 'JP0123456789', 'created_at' => '2026-09-20 10:00:00'];

    /** A TrackingService whose JNE answers from a script, one reply per call. */
    private static function service(array $replies): TrackingService
    {
        return new class ($replies) extends TrackingService {
            public array $timeouts = [];

            public function __construct(private array $replies)
            {
                parent::__construct();
            }

            protected function jne(): JneClient
            {
                $jne = parent::jne(); // applies couriers.trackTimeout
                $this->timeouts[] = (new \ReflectionProperty(JneClient::class, 'timeout'))->getValue($jne);

                $reply = array_shift($this->replies);

                return new class ($reply) extends JneClient {
                    public function __construct(private ?array $reply)
                    {
                        parent::__construct();
                    }

                    public function trace(string $awb): ?array
                    {
                        return $this->reply;
                    }
                };
            }
        };
    }

    private static function scans(): array
    {
        return ['history' => [
            ['date' => '20-09-2026 11:00', 'desc' => 'SHIPMENT RECEIVED BY JNE COUNTER OFFICER AT [BEKASI]'],
            ['date' => '20-09-2026 18:30', 'desc' => 'DEPARTED FROM TRANSIT [JAKARTA]'],
        ]];
    }

    /** Five minutes passing: the short cache entry expires, the fallback does not. */
    private static function expireFreshCache(): void
    {
        service('cache')->delete('track_' . md5('jne|' . self::ROW['waybill']));
    }

    public function testACourierAnswerIsFreshWithItsFetchTime(): void
    {
        $result = self::service([self::scans()])->track(self::ROW);

        $this->assertFalse($result['stale']);
        $this->assertCount(2, $result['events']);
        $this->assertSame('in_transit', $result['stage']);
    }

    public function testACourierThatGoesDownShowsTheLastScansMarkedStale(): void
    {
        $tracking = self::service([self::scans(), null]);

        $first = $tracking->track(self::ROW);
        self::expireFreshCache();
        $second = $tracking->track(self::ROW);

        $this->assertTrue($second['stale']);
        $this->assertSame($first['events'], $second['events']);
        $this->assertSame($first['checkedAt'], $second['checkedAt'], 'checkedAt is when the scans were fetched');
        $this->assertStringContainsString('last scans received', (string) $second['note']);
    }

    public function testWithNoEarlierScansAnOutageIsJustNoScans(): void
    {
        $result = self::service([null])->track(self::ROW);

        $this->assertFalse($result['stale']);
        $this->assertSame([], $result['events']);
    }

    public function testACachedAnswerKeepsTheTimeItWasFetched(): void
    {
        $tracking = self::service([self::scans()]);

        $first  = $tracking->track(self::ROW);
        $second = $tracking->track(self::ROW); // served from the 5-minute cache

        $this->assertSame($first['checkedAt'], $second['checkedAt']);
        $this->assertFalse($second['stale']);
    }

    public function testEveryCourierCallIsCappedAtTheTrackingTimeout(): void
    {
        $tracking = self::service([self::scans()]);

        $tracking->track(self::ROW);

        $this->assertSame([(float) config('Couriers')->trackTimeout], $tracking->timeouts);
        $this->assertLessThanOrEqual(10, config('Couriers')->trackTimeout);
    }
}
