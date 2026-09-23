<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A tracking URL column on airwaybills.
 *
 * The other couriers have a fixed tracking page, so fulfill() can build the
 * URL from a constant. GrabExpress returns a per-delivery trackingURL in its
 * create-delivery response, so it has to be stored on the row to reach
 * fulfill(), which runs separately and idempotently.
 */
class AddTrackingUrlToAirwaybills extends Migration
{
    public function up()
    {
        $this->forge->addColumn('airwaybills', [
            'tracking_url' => [
                'type'  => 'VARCHAR',
                'constraint' => 512,
                'null'  => true,
                'after' => 'waybill',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('airwaybills', 'tracking_url');
    }
}
