<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Claim marker that stops one shipment being booked twice.
 *
 * airwaybills.order_id is already unique, so two concurrent presses of
 * Generate AWB cannot create two rows. What they could do is both read the
 * one row, both see an empty waybill, and both call the courier — booking two
 * real parcels for one order, of which only the second waybill survives.
 *
 * This column is taken atomically before the courier is called, so exactly
 * one request can be in flight per order.
 */
class AddBookingClaimToAirwaybills extends Migration
{
    public function up()
    {
        $this->forge->addColumn('airwaybills', [
            'booking_started_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'status',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('airwaybills', 'booking_started_at');
    }
}
