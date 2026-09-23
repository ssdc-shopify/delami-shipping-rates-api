<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        // Rate handles carried over from the legacy app (consumed by the
        // storefront checkout extension). Tokens are filled by the OAuth flow.
        $stores = [
            [
                'slug'                => 'exe',
                'name'                => 'Executive',
                'merchant_id'         => '58493632674',
                'shop_domain'         => 'delami-the-executive.myshopify.com',
            ],
            [
                'slug'                => 'cb',
                'name'                => 'Colorbox',
                'merchant_id'         => '42391208086',
                'shop_domain'         => null,
            ],
            [
                'slug'                => 'etc',
                'name'                => 'Etcetera',
                'merchant_id'         => '60856566009',
                'shop_domain'         => null,
            ],
            [
                'slug'                => 'jenahara',
                'name'                => 'Jenahara',
                'merchant_id'         => '55232528454',
                'shop_domain'         => null,
            ],
        ];

        foreach ($stores as $store) {
            $exists = $this->db->table('stores')->where('slug', $store['slug'])->get()->getRow();
            if ($exists === null) {
                $this->db->table('stores')->insert($store + [
                    'subsidi_ongkir' => 0,
                    'minimum_order'  => 0,
                    'active'         => 1,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        }
    }
}
