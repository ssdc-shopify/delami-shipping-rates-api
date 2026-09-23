<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-store switch for whether this site receives the store's order webhooks.
 *
 * One Shopify store can be connected to more than one copy of this app — a
 * development tunnel and production, say — and each registers its own webhook
 * address, so each is sent every order. This lets an operator decide which
 * sites get a store's order data. On by default: every store received them
 * before this column existed.
 */
class AddOrderWebhooksToStores extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stores', [
            'order_webhooks' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'after'      => 'active',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('stores', 'order_webhooks');
    }
}
