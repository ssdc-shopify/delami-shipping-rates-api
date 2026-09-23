<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Publishable per-store key for the storefront rate endpoint.
 *
 * Deliberately NOT encrypted like access_token/api_secret: it is looked up by
 * value on every cart quote, and it is published inside the Hydrogen bundle
 * and the Expo app anyway. It exists to identify and throttle a caller, not to
 * keep anything secret — cart shipping prices are public data. It is separate
 * from shopify.carrierCallbackToken so that leaking it never exposes the
 * checkout callback, and so it can be rotated per store.
 */
class AddStorefrontKeyToStores extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stores', [
            'storefront_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'api_secret',
            ],
        ]);

        // Named explicitly: an unnamed index is called `storefront_key` by
        // MySQL and `stores_storefront_key` by SQLite, so down() could only
        // ever drop it on one of the two drivers.
        $this->forge->addKey('storefront_key', false, true, 'stores_storefront_key');
        $this->forge->processIndexes('stores');
    }

    public function down()
    {
        $this->forge->dropKey('stores', 'stores_storefront_key', false);
        $this->forge->dropColumn('stores', 'storefront_key');
    }
}
