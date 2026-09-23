<?php

namespace Tests\Unit;

use App\Models\AirwaybillModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The claim that stops one order becoming two parcels.
 *
 * Two operators pressing Generate AWB at the same moment both read a row with
 * an empty waybill; without this, both then call the courier and book a real
 * shipment each, of which only one waybill is ever recorded.
 */
final class AwbBookingClaimTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private function row(array $overrides = []): int
    {
        $model = model(AirwaybillModel::class);
        $model->insert([
            'order_id' => 6_300_000_000_001,
            'courier'  => AirwaybillModel::COURIER_JNE,
            'status'   => AirwaybillModel::STATUS_PENDING,
        ] + $overrides);

        return (int) $model->getInsertID();
    }

    /** The whole point: the second caller must be turned away. */
    public function testOnlyOneCallerCanClaimABooking(): void
    {
        $id = $this->row();

        $this->assertTrue(model(AirwaybillModel::class)->claimForBooking($id));
        $this->assertFalse(
            model(AirwaybillModel::class)->claimForBooking($id),
            'A second concurrent press must not be allowed to call the courier.',
        );
    }

    /** An order that already shipped is never re-booked, claim or no claim. */
    public function testAnAlreadyBookedRowCannotBeClaimed(): void
    {
        $id = $this->row(['waybill' => 'JNE123456789']);

        $this->assertFalse(model(AirwaybillModel::class)->claimForBooking($id));
    }

    /**
     * A request killed mid-booking (fatal, deploy, OOM) would otherwise wedge
     * the order permanently, so a stale claim has to become takeable again.
     */
    public function testAStaleClaimExpires(): void
    {
        $id    = $this->row();
        $model = model(AirwaybillModel::class);

        $this->assertTrue($model->claimForBooking($id));

        // Backdate past the window rather than sleeping through it.
        $model->update($id, [
            'booking_started_at' => date(
                'Y-m-d H:i:s',
                time() - AirwaybillModel::BOOKING_CLAIM_SECONDS - 60,
            ),
        ]);

        $this->assertTrue($model->claimForBooking($id), 'A dead request must not wedge the order forever.');
    }

    /** A claim just taken is not stale, however close to the boundary. */
    public function testAFreshClaimDoesNotExpireEarly(): void
    {
        $id    = $this->row();
        $model = model(AirwaybillModel::class);

        $model->claimForBooking($id);
        $model->update($id, [
            'booking_started_at' => date(
                'Y-m-d H:i:s',
                time() - AirwaybillModel::BOOKING_CLAIM_SECONDS + 30,
            ),
        ]);

        $this->assertFalse($model->claimForBooking($id));
    }

    public function testReleasingAClaimAllowsAnImmediateRetry(): void
    {
        $id    = $this->row();
        $model = model(AirwaybillModel::class);

        $model->claimForBooking($id);
        $model->releaseBooking($id);

        $this->assertTrue($model->claimForBooking($id));
    }

    /**
     * Release must never reopen a booked row: if a waybill arrived, the claim
     * is irrelevant and the row must stay closed to further bookings.
     */
    public function testReleaseCannotReopenABookedRow(): void
    {
        $id    = $this->row();
        $model = model(AirwaybillModel::class);

        $model->claimForBooking($id);
        $model->update($id, ['waybill' => 'JNE999']);
        $model->releaseBooking($id);

        $this->assertFalse($model->claimForBooking($id));
    }
}
