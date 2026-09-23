<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Every order query is now scoped to a store — the list, the AWB tab and its
 * count all filter on orders.store_id, which had no index of its own.
 */
class IndexOrdersStoreId extends Migration
{
    public function up()
    {
        // Composite: the AWB tab filters by store and sorts by ordered_at, so
        // one index serves both the lookup and the ordering.
        //
        // Built through the Forge rather than raw SQL: DROP INDEX takes an ON
        // clause in MySQL and rejects it in SQLite, so hand-written DDL here
        // would only ever run on one of the two supported drivers.
        $this->forge->addKey(['store_id', 'ordered_at'], false, false, 'store_ordered_at');
        $this->forge->processIndexes('orders');
    }

    public function down()
    {
        $this->forge->dropKey('orders', 'store_ordered_at', false);
    }
}
