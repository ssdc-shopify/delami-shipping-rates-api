<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * One row per Shopify shop.
 *
 * Stores::connect() looks a shop up by domain and inserts when it finds
 * nothing, so two operators connecting the same shop at once produce two
 * rows — each with its own access token, its own thresholds, and its own
 * storefront key. Orders then route by whichever row was found first, and
 * editing settings on one silently has no effect on the other.
 *
 * NULL is left free: a store row exists before it is ever installed, and in
 * both MySQL and SQLite a unique index permits repeated NULLs.
 */
class UniqueShopDomainOnStores extends Migration
{
    public function up()
    {
        // A duplicate already in the table would make the index fail to build
        // with a driver-specific error. Say what is wrong instead, because
        // merging two stores means choosing which token and which settings
        // survive — not a decision a migration should make silently.
        $duplicates = $this->db->table('stores')
            ->select('shop_domain, COUNT(*) AS total')
            ->where('shop_domain IS NOT NULL', null, false)
            ->groupBy('shop_domain')
            ->having('total > 1', null, false)
            ->get()
            ->getResultArray();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'These shop domains appear on more than one store row: '
                . implode(', ', array_column($duplicates, 'shop_domain'))
                . '. Merge or deactivate the duplicates before running this migration.',
            );
        }

        // Named explicitly — an unnamed index is called `shop_domain` by
        // MySQL and `stores_shop_domain` by SQLite, so down() would only work
        // on one of the two drivers.
        $this->forge->addKey('shop_domain', false, true, 'stores_shop_domain');
        $this->forge->processIndexes('stores');
    }

    public function down()
    {
        $this->forge->dropKey('stores', 'stores_shop_domain', false);
    }
}
