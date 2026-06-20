<?php

use yii\db\Migration;

class m260608_160000_mongoyia_order_stock_deducted_at extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null) {
            echo "Table {{%mall_order}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['stock_deducted_at'])) {
            $this->addColumn('{{%mall_order}}', 'stock_deducted_at', $this->integer()->notNull()->defaultValue(0)->comment('库存扣减时间')->after('paid_at'));
        }

        // Existing orders were created by the old checkout-time deduction flow.
        $this->update('{{%mall_order}}', ['stock_deducted_at' => new \yii\db\Expression('IF(paid_at > 0, paid_at, created_at)')], ['stock_deducted_at' => 0]);

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null || !isset($schema->columns['stock_deducted_at'])) {
            return true;
        }

        $this->dropColumn('{{%mall_order}}', 'stock_deducted_at');

        return true;
    }
}
