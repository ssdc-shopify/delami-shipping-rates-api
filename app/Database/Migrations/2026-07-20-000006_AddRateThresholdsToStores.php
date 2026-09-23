<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Courier rate thresholds are a merchandising decision, not deployment
 * config, so they belong per store and editable in the admin rather than
 * in .env. NULL means "inherit the Config\Couriers default".
 */
class AddRateThresholdsToStores extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stores', [
            'jne_max_cart' => [
                'type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'minimum_order',
                'comment' => 'IDR: JNE offered up to this cart value; 0 = no cap, NULL = default',
            ],
            'spx_min_cart' => [
                'type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'jne_max_cart',
                'comment' => 'IDR: SPX offered from this cart value up; NULL = default',
            ],
            'insurance_min_cart' => [
                'type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'spx_min_cart',
                'comment' => 'IDR: insurance added from this cart value up; NULL = default',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('stores', ['jne_max_cart', 'spx_min_cart', 'insurance_min_cart']);
    }
}
