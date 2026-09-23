<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When Shopify last changed each stored order.
 *
 * The order list is now built from the orders/create and orders/updated
 * webhooks, and Shopify does not promise to deliver those in order — a retry
 * can land after a newer update. Comparing this timestamp is what stops an
 * old delivery from overwriting a newer one.
 */
class AddShopifyUpdatedAtToOrders extends Migration
{
    public function up()
    {
        $this->forge->addColumn('orders', [
            'shopify_updated_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'ordered_at',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('orders', 'shopify_updated_at');
    }
}
