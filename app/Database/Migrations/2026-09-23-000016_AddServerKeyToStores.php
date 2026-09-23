<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A secret key a storefront's own SERVER sends beside the publishable key.
 *
 * The publishable key is shared by every browser and app build, so requests
 * carrying only that are limited per client IP. A headless storefront that
 * calls from its server sends every shopper's request from one IP, and so
 * shared one small allowance across the whole site. The server key proves the
 * caller is that server and earns a per-store limit instead.
 *
 * Only a SHA-256 hash is stored; the key itself is shown once when issued.
 */
class AddServerKeyToStores extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stores', [
            'server_key_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'storefront_key',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('stores', 'server_key_hash');
    }
}
