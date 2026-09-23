<?php

namespace Tests\Unit;

use App\Models\AirwaybillModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The public tracking page.
 *
 * It is the only unauthenticated page in the app, and it reads out a
 * customer's order. What it must not become is a way to walk the order
 * numbers: the reference alone is guessable, so the email is the lock, and a
 * wrong email has to be indistinguishable from a reference that never existed.
 */
final class TrackPageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const ORDER_ID = 6_400_000_000_777;

    protected function setUp(): void
    {
        parent::setUp();

        // The throttler counts per IP across a test run, and every test here
        // posts from the same one.
        service('cache')->clean();

        model(OrderModel::class)->insert([
            'order_id'     => self::ORDER_ID,
            'order_name'   => '#1001',
            'order_number' => 1001,
            'email'        => 'Shopper@Example.com',
            'ship_name'    => 'Budi Santoso',
            'ship_city'    => 'Bandung',
            'ship_province' => 'Jawa Barat',
        ]);

        model(AirwaybillModel::class)->insert([
            'order_id' => self::ORDER_ID,
            'courier'  => AirwaybillModel::COURIER_JNE,
            'waybill'  => 'MOCK-JNE-951001',
            'status'   => AirwaybillModel::STATUS_PENDING,
        ]);
    }

    /** A POST as the form makes it — CSRF token included, since the filter is on. */
    private function lookup(string $reference, string $email)
    {
        return $this->post('track', [
            csrf_token() => csrf_hash(),
            'reference'  => $reference,
            'email'      => $email,
        ]);
    }

    // ------------------------------------------------------------------
    // The happy path
    // ------------------------------------------------------------------

    public function testTheFormIsPubliclyReachable(): void
    {
        $result = $this->get('track');

        $result->assertOK();
        $result->assertSee('Track your order');
    }

    public function testOrderNumberAndEmailReturnTheShipment(): void
    {
        $result = $this->lookup('#1001', 'shopper@example.com');

        $result->assertOK();
        $result->assertSee('MOCK-JNE-951001');
        $result->assertSee('Bandung');
    }

    /** The number as printed, as typed without the hash, and the waybill. */
    public function testEveryReferenceAShopperMightPasteResolves(): void
    {
        foreach (['#1001', '1001', 'MOCK-JNE-951001'] as $reference) {
            $this->lookup($reference, 'shopper@example.com')
                ->assertSee('MOCK-JNE-951001');
        }
    }

    /** Shopify lower-cases nothing; the shopper types what they type. */
    public function testEmailMatchingIgnoresCase(): void
    {
        $this->lookup('#1001', 'SHOPPER@EXAMPLE.COM')->assertSee('MOCK-JNE-951001');
    }

    // ------------------------------------------------------------------
    // The lock
    // ------------------------------------------------------------------

    public function testTheRightOrderWithTheWrongEmailRevealsNothing(): void
    {
        $result = $this->lookup('#1001', 'someone-else@example.com');

        $result->assertOK();
        $result->assertDontSee('MOCK-JNE-951001');
        $result->assertDontSee('Budi Santoso');
        $result->assertDontSee('Bandung');
    }

    /**
     * The enumeration guard: a real order with a wrong email and a wholly
     * invented one must be answered identically, or the difference between
     * the two answers is itself the leak.
     */
    public function testAWrongEmailIsIndistinguishableFromAnUnknownOrder(): void
    {
        // The form repopulates what the shopper typed, so the reference itself
        // is masked out; everything else — status, wording, markup — has to
        // match, since any difference at all is the answer to "is #1001 real?".
        // Only the value the form echoes back is masked — not every mention of
        // the number, since the placeholder text happens to be "#1001" too.
        $normalize = static fn (string $reference, string $body): string => str_replace(
            'value="' . $reference . '"',
            'value="REFERENCE"',
            preg_replace('/<!-- DEBUG-VIEW[^>]*-->/', '', $body) ?? $body,
        );

        $wrongEmail  = $this->lookup('#1001', 'someone-else@example.com');
        $noSuchOrder = $this->lookup('#9999', 'someone-else@example.com');

        $this->assertSame(
            $wrongEmail->response()->getStatusCode(),
            $noSuchOrder->response()->getStatusCode(),
        );
        $this->assertSame(
            $normalize('#1001', (string) $wrongEmail->getBody()),
            $normalize('#9999', (string) $noSuchOrder->getBody()),
        );
    }

    public function testTheEmailIsRequired(): void
    {
        $result = $this->lookup('#1001', '');

        $result->assertOK();
        $result->assertDontSee('MOCK-JNE-951001');
        $result->assertSee('Enter both');
    }

    /** An order with no waybill yet is not trackable, correct email or not. */
    /**
     * An order that has not shipped is found — for the email on it — and
     * shown as being prepared, rather than told it does not exist. There is
     * no parcel yet, so no tracking link either.
     */
    public function testAnUnshippedOrderShowsAsBeingPreparedToItsOwner(): void
    {
        model(OrderModel::class)->insert([
            'order_id'         => 6_400_000_000_888,
            'order_name'       => '#1002',
            'order_number'     => 1002,
            'email'            => 'shopper@example.com',
            'financial_status' => 'paid',
            'shipping_title'   => 'JNE - REGULER. (Subsidi Rp 5.000)',
        ]);

        $result = $this->lookup('#1002', 'shopper@example.com');

        $result->assertSee('Being prepared');
        $result->assertSee('for JNE - REGULER.');
        $result->assertDontSee('Open on');
    }

    public function testAnUnshippedOrderIsStillHiddenFromAWrongEmail(): void
    {
        model(OrderModel::class)->insert([
            'order_id' => 6_400_000_000_889, 'order_name' => '#1003', 'order_number' => 1003,
            'email' => 'shopper@example.com', 'financial_status' => 'paid',
        ]);

        $result = $this->lookup('#1003', 'someone@example.com');

        $result->assertSee('We could not find an order');
        $result->assertDontSee('Being prepared');
    }
}
