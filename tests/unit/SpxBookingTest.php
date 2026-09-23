<?php

namespace Tests\Unit;

use App\Libraries\Awb\AwbService;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * SPX's reply to a booking, read without ever booking twice.
 *
 * "order id has been used" is what SPX answers when an earlier attempt for the
 * same order already succeeded — typically one whose reply was lost. Booking
 * again under another reference, as this used to, shipped a second parcel.
 */
final class SpxBookingTest extends CIUnitTestCase
{
    public function testASuccessfulBookingReturnsTheOrder(): void
    {
        $booked = AwbService::spxBookedOrder([
            'data' => ['orders' => [['tracking_no' => 'SPXID0123456789', 'r_first_sort_code' => 'A1']]],
        ], 'SPXID#1001');

        $this->assertSame('SPXID0123456789', $booked['tracking_no']);
    }

    public function testAnAlreadyUsedOrderIdStopsInsteadOfBookingAgain(): void
    {
        try {
            AwbService::spxBookedOrder([
                'data' => ['fail_list' => [['message' => 'order id has been used']]],
            ], 'SPXID#1001');
            $this->fail('An already-used SPX order id must stop the booking.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No second parcel was booked', $e->getMessage());
            $this->assertStringContainsString('SPXID#1001', $e->getMessage());
        }
    }

    public function testAnyOtherFailureIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SPX order creation failed');

        AwbService::spxBookedOrder(['data' => ['fail_list' => [['message' => 'invalid address']]]], 'SPXID#1001');
    }

    public function testNoReplyAtAllIsAFailure(): void
    {
        $this->expectException(RuntimeException::class);

        AwbService::spxBookedOrder(null, 'SPXID#1001');
    }
}
