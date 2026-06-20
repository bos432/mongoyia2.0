<?php

use yii\db\Migration;

class m260618_165000_mongoyia_shipment_fee_deduction extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null) {
            echo "Table {{%mall_order}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['shipment_fee_deducted_at'])) {
            $this->addColumn('{{%mall_order}}', 'shipment_fee_deducted_at', $this->integer()->notNull()->defaultValue(0)->comment('物流费扣费时间')->after('shipment_fee'));
            $this->createIndex('mall_order_k_shipment_fee_deducted_at', '{{%mall_order}}', 'shipment_fee_deducted_at');
        }

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null || !isset($schema->columns['shipment_fee_deducted_at'])) {
            return true;
        }

        $this->dropIndex('mall_order_k_shipment_fee_deducted_at', '{{%mall_order}}');
        $this->dropColumn('{{%mall_order}}', 'shipment_fee_deducted_at');
        return true;
    }
}
