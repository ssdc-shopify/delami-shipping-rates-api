<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Indexes for the columns a tracking lookup searches by.
 *
 * A shopper's reference is a waybill, an order number or an order name, and
 * none of those columns was indexed, so every lookup scanned the table — the
 * orders table in particular, which now grows with every order webhook.
 *
 * Named explicitly: an unnamed index is called differently by MySQL and by
 * SQLite, so down() could only ever drop it on one of the two drivers.
 */
class AddTrackingLookupIndexes extends Migration
{
    private const INDEXES = [
        'airwaybills' => ['airwaybills_waybill' => 'waybill'],
        'orders'      => [
            'orders_order_number' => 'order_number',
            'orders_order_name'   => 'order_name',
        ],
    ];

    public function up()
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $column) {
                $this->forge->addKey($column, false, false, $name);
            }
            $this->forge->processIndexes($table);
        }
    }

    public function down()
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->forge->dropKey($table, $name, false);
            }
        }
    }
}
