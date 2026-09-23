<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Local copy of the Shopify orders this app ships.
 *
 * The rate the shopper picked on the headless cart page reaches us as the
 * order's shipping line (service_name -> shipping_title, service_code ->
 * shipping_code). Persisting it here means courier routing and the admin
 * order list no longer depend on a live Shopify fetch.
 */
class CreateOrdersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'store_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'order_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'comment' => 'Shopify order legacy id'],
            'order_name'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'comment' => 'e.g. #1001'],
            'order_number'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],

            'email'             => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'customer_name'     => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],

            'financial_status'  => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'fulfillment_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'cancelled_at'      => ['type' => 'DATETIME', 'null' => true],

            'currency'          => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'total_price'       => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'subtotal_price'    => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'total_weight'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'grams'],

            // The chosen carrier rate, straight from the CarrierService response.
            'shipping_title'    => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true, 'comment' => 'rate service_name'],
            'shipping_code'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'comment' => 'rate service_code'],
            'shipping_price'    => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],

            'ship_name'         => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'ship_phone'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'ship_address1'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ship_address2'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ship_city'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'ship_province'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'ship_zip'          => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],

            'tags'              => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'ordered_at'        => ['type' => 'DATETIME', 'null' => true, 'comment' => 'Shopify created_at'],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('order_id');
        $this->forge->addKey('ordered_at');
        $this->forge->addKey('shipping_code');
        $this->forge->createTable('orders');
    }

    public function down()
    {
        $this->forge->dropTable('orders');
    }
}
