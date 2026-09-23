<?php

namespace Tests\Unit;

use App\Libraries\Couriers\GeocodeClient;
use App\Libraries\Couriers\GrabClient;
use App\Libraries\Couriers\WidgetProxyClient;
use App\Libraries\Shipping\RateEngine;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The checkout callback's time budget.
 *
 * Shopify waits at most 10s (5s past 1,500 requests a minute, 3s past 3,000),
 * and a late reply is no reply. Each upstream call used to get a flat 8s, one
 * after another. Every call is now capped at what is left of one budget, the
 * parcel couriers go first, and GrabExpress gets what remains.
 *
 * Time is simulated: each fake upstream call advances a fake clock.
 */
final class RateBudgetTest extends CIUnitTestCase
{
    private const STORE = [
        'id' => 1, 'slug' => 'test', 'subsidi_ongkir' => 0, 'minimum_order' => 0,
        'jne_max_cart' => 0, 'spx_min_cart' => 0, 'insurance_min_cart' => 99_999_999,
    ];

    private const ADDRESS = [
        'postal_code' => '12950', 'city' => 'Jakarta Selatan',
        'address1' => 'Jl. H. R. Rasuna Said 7', 'country' => 'ID',
    ];

    /** Seconds each fake upstream call "takes". */
    private float $callCost = 0.0;

    /** Fake time, in seconds. */
    private float $now = 1_000.0;

    /** @var list<array{string, ?float}> every upstream call and the timeout it was given */
    private array $calls = [];

    private function engine(): RateEngine
    {
        $test = $this;

        $proxy = new class ($test) extends WidgetProxyClient {
            private ?float $given = null;

            public function __construct(private RateBudgetTest $test)
            {
                parent::__construct();
            }

            public function setTimeout(?float $seconds): void
            {
                $this->given = $seconds;
            }

            public function getMany(array $paths, int $ttl): array
            {
                $this->test->record('proxy', $this->given);

                return isset($paths['spx'])
                    ? ['jne' => ['code_destination' => 'CGK10000'], 'ninja' => ['nxid' => 'IDJKT'], 'spx' => ['city' => 'JAKARTA']]
                    : ['jne' => ['rates' => 10_000], 'ninja' => ['rates' => 12_000], 'spxHemat' => ['ratefinal' => 9_000], 'spxReguler' => ['ratefinal' => 11_000]];
            }
        };

        $grab = new class ($test) extends GrabClient {
            private ?float $given = null;

            public function __construct(private RateBudgetTest $test)
            {
                parent::__construct();
            }

            public function setTimeout(?float $seconds): void
            {
                $this->given = $seconds;
            }

            public function quote(array $origin, array $destination, array $packages): ?array
            {
                $this->test->record('grab', $this->given);

                return ['amount' => 65_000, 'distance' => 12_000, 'service' => ['type' => 'INSTANT']];
            }
        };

        $geocoder = new class ($test) extends GeocodeClient {
            private ?float $given = null;

            public function __construct(private RateBudgetTest $test)
            {
                parent::__construct();
            }

            public function setTimeout(?float $seconds): void
            {
                $this->given = $seconds;
            }

            public function geocode(string $address): ?array
            {
                $this->test->record('geocode', $this->given);

                return [-6.2285501, 106.8337856];
            }
        };

        return new RateEngine($proxy, null, $grab, $geocoder, fn (): float => $this->now);
    }

    /** Called by the fakes: note the call, then let fake time pass. */
    public function record(string $upstream, ?float $timeout): void
    {
        $this->calls[] = [$upstream, $timeout];
        $this->now += $this->callCost;
    }

    private function quote(?string $method = null): array
    {
        return array_column($this->engine()->quote(self::STORE, self::ADDRESS, 1000, 150_000.0, $method), 'service_code');
    }

    public function testEveryCallIsCappedAtTheBudget(): void
    {
        $this->quote();

        $budget = config('Couriers')->rateBudgetSeconds;
        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as [$upstream, $timeout]) {
            $this->assertNotNull($timeout, "{$upstream} must be given a timeout");
            $this->assertLessThanOrEqual($budget, $timeout, "{$upstream} was allowed longer than the whole budget");
        }
    }

    public function testEachCallGetsOnlyWhatIsLeft(): void
    {
        $this->callCost = 1.0;

        $this->quote();

        // proxy wave 1, proxy wave 2, geocode, grab — each a second later.
        $timeouts = array_column($this->calls, 1);
        $budget   = config('Couriers')->rateBudgetSeconds;
        $this->assertEqualsWithDelta([$budget, $budget - 1, $budget - 2, $budget - 3], $timeouts, 0.001);
    }

    public function testParcelCouriersGoFirstAndGrabUsesWhatRemains(): void
    {
        $this->quote();

        $this->assertSame(['proxy', 'proxy', 'geocode', 'grab'], array_column($this->calls, 0));
    }

    public function testASlowUpstreamLeavesGrabOutRatherThanOverrunning(): void
    {
        // Each proxy wave eats most of the budget.
        $this->callCost = config('Couriers')->rateBudgetSeconds / 2;

        $codes = $this->quote();

        $this->assertNotContains('BDD-GRAB', $codes, 'Grab must be skipped once the budget is spent');
        $this->assertContains('BDD-SPX-HEMAT', $codes, 'the parcel couriers that did answer are still offered');
        $this->assertNotContains('geocode', array_column($this->calls, 0));
    }

    public function testAnExhaustedBudgetSkipsTheSecondWaveToo(): void
    {
        $this->callCost = config('Couriers')->rateBudgetSeconds;

        $this->assertSame([], $this->quote());
        $this->assertSame(['proxy'], array_column($this->calls, 0));
    }

    public function testInstantGivesGrabTheWholeBudget(): void
    {
        $codes = $this->quote('instant');

        $this->assertSame(['BDD-GRAB'], $codes);
        $this->assertSame(['geocode', 'grab'], array_column($this->calls, 0));
        $this->assertEqualsWithDelta(config('Couriers')->rateBudgetSeconds, $this->calls[0][1], 0.001);
    }

    /** "Standard" and "standard" are the same choice on either endpoint. */
    public function testTheMethodIsCaseInsensitive(): void
    {
        $this->assertNotContains('BDD-GRAB', $this->quote(' Standard '));
        $this->assertSame(['BDD-GRAB'], $this->quote('INSTANT'));
        $this->assertSame(['BDD-CNC'], $this->quote('Collect'));
    }
}
