<?php

use yii\db\Migration;

class m260620_164000_mongoyia_product_visit_table_baseline extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_product_visit}}', true) !== null) {
            return true;
        }

        $this->createTable('{{%mall_product_visit}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'pid' => $this->bigInteger()->unsigned()->notNull()->comment('Product ID'),
            'uid' => $this->bigInteger()->unsigned()->null()->comment('User ID'),
            'time' => $this->integer()->notNull()->defaultValue(0)->comment('Visit time'),
        ]);

        $this->createIndex('mall_product_visit_k0', '{{%mall_product_visit}}', ['pid', 'time']);
        $this->createIndex('mall_product_visit_k1', '{{%mall_product_visit}}', ['uid', 'time']);

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_product_visit}}', true) === null) {
            return true;
        }

        $this->dropTable('{{%mall_product_visit}}');

        return true;
    }
}
