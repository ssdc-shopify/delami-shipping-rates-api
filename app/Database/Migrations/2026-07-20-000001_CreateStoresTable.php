<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStoresTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'slug'             => ['type' => 'VARCHAR', 'constraint' => 20],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 100],
            'merchant_id'      => ['type' => 'VARCHAR', 'constraint' => 30, 'comment' => 'Shopify shop id'],
            'shop_domain'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'comment' => 'x.myshopify.com'],
            'access_token'     => ['type' => 'TEXT', 'null' => true, 'comment' => 'encrypted offline token from OAuth'],
            'scopes'           => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'installed_at'     => ['type' => 'DATETIME', 'null' => true],
            'uninstalled_at'   => ['type' => 'DATETIME', 'null' => true],
            'subsidi_ongkir'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'IDR subsidy per order'],
            'minimum_order'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'IDR cart total required for subsidy'],
            'jne_rate_handle'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'jne_rate_handle_low' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'comment' => 'handle used below the JNE cart threshold (etc store)'],
            'ninja_rate_handle' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'active'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('stores');
    }

    public function down()
    {
        $this->forge->dropTable('stores');
    }
}
