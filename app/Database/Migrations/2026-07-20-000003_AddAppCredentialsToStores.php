<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAppCredentialsToStores extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stores', [
            'api_key'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'shop_domain', 'comment' => 'Shopify app Client ID'],
            'api_secret' => ['type' => 'TEXT', 'null' => true, 'after' => 'api_key', 'comment' => 'Shopify app Client Secret (encrypted)'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('stores', ['api_key', 'api_secret']);
    }
}
