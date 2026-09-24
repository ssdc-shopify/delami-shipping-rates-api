<?php

namespace Tests\Unit;

use App\Libraries\Tracking\TrackingService;
use App\Models\AirwaybillModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Normalizing courier tracking.
 *
 * Every courier phrases its scans differently, so the customer-facing page is
 * only as good as the mapping from their wording onto one timeline. These
 * pin the decisions that are easy to get subtly wrong: which stage a scan
 * belongs to, which scan wins the headline, and how dates are read.
 */
final class TrackingServiceTest extends CIUnitTestCase
{
    private function service(): TrackingService
    {
        return new TrackingService(config('Couriers'));
    }

    // ------------------------------------------------------------------
    // Courier from the delivery method
    // ------------------------------------------------------------------

    /** Titles as Shopify's Delivery method column shows them, with no service code to go on. */
    public function testReadsTheCourierFromTheDeliveryMethodTitle(): void
    {
        $courier = static fn (string $title) => TrackingService::courierFromDeliveryMethod(['title' => $title, 'code' => '']);

        $this->assertSame(AirwaybillModel::COURIER_GRAB, $courier('GRABEXPRESS - INSTANT.'));
        $this->assertSame(AirwaybillModel::COURIER_JNE, $courier('JNE - REGULER. (Subsidi Rp 5.000)'));
        $this->assertSame(AirwaybillModel::COURIER_NINJA, $courier('NINJA XPRESS - REGULER.'));
        $this->assertSame(AirwaybillModel::COURIER_SPX, $courier('SPX - HEMAT. (FREE 5.000)'));
        $this->assertSame(AirwaybillModel::COURIER_SPX, $courier('SPX - REGULER.'));

        // Nothing names a courier at the head of these: not ours to guess.
        $this->assertNull($courier('Shipping'));
        $this->assertNull($courier('Shipping not required'));
        $this->assertNull($courier('Click and Collect'));
        $this->assertNull($courier('Kurir Toko - dikirim via JNE'), 'only the part before the dash names the courier');
    }

    public function testTheServiceCodeWinsOverTheTitle(): void
    {
        $this->assertSame(
            AirwaybillModel::COURIER_NINJA,
            TrackingService::courierFromDeliveryMethod(['title' => 'JNE - REGULER.', 'code' => 'BDD-NINJA'])
        );
        $this->assertNull(TrackingService::courierFromDeliveryMethod([]));
    }

    // ------------------------------------------------------------------
    // Stage inference
    // ------------------------------------------------------------------

    public function testReadsRealCourierWordingOntoTheRightStage(): void
    {
        $service = $this->service();

        // JNE, as it actually shouts them.
        $this->assertSame(TrackingService::STAGE_PICKED_UP, $service->stageFor('SHIPMENT RECEIVED BY JNE COUNTER OFFICER AT [BEKASI]'));
        $this->assertSame(TrackingService::STAGE_IN_TRANSIT, $service->stageFor('RECEIVED AT SORTING CENTER [BEKASI]'));
        $this->assertSame(TrackingService::STAGE_OUT_FOR_DELIVERY, $service->stageFor('WITH DELIVERY COURIER [BANDUNG]'));
        $this->assertSame(TrackingService::STAGE_DELIVERED, $service->stageFor('DELIVERED TO [BUDI | 12-06-2026]'));

        // Ninja / LJR / Grab.
        $this->assertSame(TrackingService::STAGE_DELIVERED, $service->stageFor('Completed'));
        $this->assertSame(TrackingService::STAGE_OUT_FOR_DELIVERY, $service->stageFor('IN_DELIVERY'));
        $this->assertSame(TrackingService::STAGE_PICKED_UP, $service->stageFor('ALLOCATING'));
        $this->assertSame(TrackingService::STAGE_EXCEPTION, $service->stageFor('RETURNED TO SHIPPER'));
    }

    /**
     * "RECEIVED AT SORTING CENTER" carries a pickup word and a transit word.
     * Only the order the keyword table is checked in reads it as transit —
     * which is why that order is asserted rather than left to chance.
     */
    public function testTransitWinsOverPickupWhenAScanCarriesBoth(): void
    {
        $this->assertSame(
            TrackingService::STAGE_IN_TRANSIT,
            $this->service()->stageFor('RECEIVED AT ORIGIN GATEWAY [JAKARTA]'),
        );
    }

    /** An unrecognized scan still means the parcel is somewhere in the network. */
    public function testUnknownWordingFallsBackToInTransit(): void
    {
        $this->assertSame(TrackingService::STAGE_IN_TRANSIT, $this->service()->stageFor('MANIFESTED 4471'));
    }

    // ------------------------------------------------------------------
    // Headline stage
    // ------------------------------------------------------------------

    /**
     * Couriers routinely append an administrative scan after the POD. Reading
     * only the newest row would un-deliver a delivered parcel.
     */
    public function testDeliveredWinsEvenWhenALaterScanFollowsIt(): void
    {
        $events = [
            ['at' => '2026-06-12 18:00:00', 'stage' => TrackingService::STAGE_IN_TRANSIT],
            ['at' => '2026-06-12 14:00:00', 'stage' => TrackingService::STAGE_DELIVERED],
        ];

        $this->assertSame(TrackingService::STAGE_DELIVERED, $this->service()->overallStage($events));
    }

    public function testNoScansYetReadsAsBookedRatherThanUnknown(): void
    {
        $this->assertSame(TrackingService::STAGE_BOOKED, $this->service()->overallStage([]));
    }

    // ------------------------------------------------------------------
    // Mock shipments
    // ------------------------------------------------------------------

    /**
     * mockAwb is the default, so a MOCK- waybill has to produce a usable page
     * without any courier being called.
     */
    public function testMockWaybillProducesATimelineWithoutCallingACourier(): void
    {
        $result = $this->service()->track([
            'courier'    => AirwaybillModel::COURIER_JNE,
            'waybill'    => 'MOCK-JNE-951001',
            'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
        ], ['destination' => 'Bandung, Jawa Barat']);

        $this->assertSame('mock', $result['source']);
        $this->assertSame(TrackingService::STAGE_DELIVERED, $result['stage']);
        $this->assertNotEmpty($result['events']);

        // Newest first: a five-day-old mock has run its whole course.
        $this->assertSame('Delivered to recipient', $result['events'][0]['description']);
        $this->assertSame('Bandung, Jawa Barat', $result['events'][0]['location']);
    }

    /** A mock booked minutes ago has been booked and nothing more. */
    public function testAFreshMockShipmentHasOnlyItsBookingScan(): void
    {
        $result = $this->service()->track([
            'courier'    => AirwaybillModel::COURIER_NINJA,
            'waybill'    => 'MOCK-NINJA-951002',
            'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
        ]);

        $this->assertCount(1, $result['events']);
        $this->assertSame(TrackingService::STAGE_BOOKED, $result['stage']);
    }

    // ------------------------------------------------------------------
    // Tracking links
    // ------------------------------------------------------------------

    public function testGrabUsesThePerDeliveryUrlItReturnedAtBooking(): void
    {
        $result = $this->service()->track([
            'courier'      => AirwaybillModel::COURIER_GRAB,
            'waybill'      => 'MOCK-GRAB-951003',
            'tracking_url' => 'https://grab.example/track/abc123',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('https://grab.example/track/abc123', $result['trackingUrl']);
    }

    /** SPX publishes no deep-link parameter, so no waybill is invented into one. */
    public function testSpxLinksToTheTrackerWithoutFabricatingADeepLink(): void
    {
        $result = $this->service()->track([
            'courier'    => AirwaybillModel::COURIER_SPX,
            'waybill'    => 'MOCK-SPX-951004',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('https://spx.co.id/en/track', $result['trackingUrl']);
    }
}
