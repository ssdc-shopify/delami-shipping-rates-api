<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The airwaybills table is a mapping (local id ↔ Shopify order id) plus the
 * shipment data that only exists locally (waybill, SPX sort codes, status).
 * Order fields (name, number) are fetched live from Shopify when needed, so
 * the denormalized copies are dropped.
 */
class TrimAirwaybillOrderData extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('number_id', 'airwaybills')) {
            // Drop the unique index on number_id first, then the columns.
            //
            // The index name is asked for rather than assumed: addUniqueKey()
            // left it unnamed, and the drivers then name it differently —
            // MySQL as `number_id`, SQLite as `airwaybills_number_id`.
            foreach ($this->db->getIndexData('airwaybills') as $index) {
                if ($index->fields === ['number_id']) {
                    $this->forge->dropKey('airwaybills', $index->name, false);
                }
            }

            $this->forge->dropColumn('airwaybills', ['number_id', 'order_name']);
        }
    }

    public function down()
    {
        $this->forge->addColumn('airwaybills', [
            'order_name' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'order_id'],
            'number_id'  => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'order_name'],
        ]);
    }
}
