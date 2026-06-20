<?php

use yii\db\Migration;

class m260608_170000_mongoyia_order_stock_refunded_at extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null) {
            echo "Table {{%mall_order}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['stock_refunded_at'])) {
            $this->addColumn('{{%mall_order}}', 'stock_refunded_at', $this->integer()->notNull()->defaultValue(0)->comment('库存返还时间')->after('stock_deducted_at'));
        }

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null || !isset($schema->columns['stock_refunded_at'])) {
            return true;
        }

        $this->dropColumn('{{%mall_order}}', 'stock_refunded_at');

        return true;
    }
}
