<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Remove the three unused rate-handle columns.
 *
 * They are a leftover from the CI3 app, which matched Shopify's own saved
 * shipping rates by an opaque handle. This app does not: it returns rates
 * through the CarrierService callback and identifies the shopper's choice by
 * service_code, which is what routeCourier() and the AWB pipeline use.
 *
 * The columns fed a `rate_handle` key on every quote that nothing ever read —
 * it was not even in RateEngine::SHOPIFY_FIELDS, so the callback stripped it
 * before Shopify saw it. SPX made the point plainly by passing its own price
 * as the "handle".
 */
class DropRateHandlesFromStores extends Migration
{
    private const COLUMNS = ['jne_rate_handle', 'jne_rate_handle_low', 'ninja_rate_handle'];

    public function up()
    {
        foreach (self::COLUMNS as $column) {
            if ($this->db->fieldExists($column, 'stores')) {
                $this->forge->dropColumn('stores', $column);
            }
        }
    }

    public function down()
    {
        // Restores the shape, not the values: the handles were opaque Shopify
        // identifiers that only the CI3 app could produce.
        $this->forge->addColumn('stores', [
            'jne_rate_handle'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'jne_rate_handle_low' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ninja_rate_handle'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
    }
}
