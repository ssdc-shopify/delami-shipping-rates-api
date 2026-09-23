<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAirwaybillsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'comment' => 'Shopify order legacy id'],
            'order_name'      => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'comment' => 'Shopify order name e.g. #1001'],
            'number_id'       => ['type' => 'VARCHAR', 'constraint' => 30, 'comment' => 'internal ref: 95 + order_number'],
            'courier'         => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'jne', 'comment' => 'jne|ljr|ninja|spx'],
            'waybill'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'status'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'comment' => '0=pending 1=fulfilled'],
            'spx_order_ref'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'comment' => 'order id sent to SPX'],
            'sort_code'       => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'comment' => 'SPX first sort code'],
            'third_sort_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'comment' => 'SPX zone name'],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('order_id');
        $this->forge->addUniqueKey('number_id');
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->createTable('airwaybills');
    }

    public function down()
    {
        $this->forge->dropTable('airwaybills');
    }
}
