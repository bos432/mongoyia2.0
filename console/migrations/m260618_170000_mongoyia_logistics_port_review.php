<?php

use yii\db\Migration;

class m260618_170000_mongoyia_logistics_port_review extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null) {
            echo "Table {{%mall_order}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['logistics_review_status'])) {
            $this->addColumn('{{%mall_order}}', 'logistics_review_status', $this->tinyInteger()->notNull()->defaultValue(0)->comment('平台/口岸物流复核状态')->after('shipment_status'));
            $this->createIndex('mall_order_k_logistics_review_status', '{{%mall_order}}', 'logistics_review_status');
        }
        if (!isset($schema->columns['logistics_reviewed_at'])) {
            $this->addColumn('{{%mall_order}}', 'logistics_reviewed_at', $this->integer()->notNull()->defaultValue(0)->comment('平台/口岸物流复核时间')->after('logistics_review_status'));
            $this->createIndex('mall_order_k_logistics_reviewed_at', '{{%mall_order}}', 'logistics_reviewed_at');
        }
        if (!isset($schema->columns['logistics_reviewed_by'])) {
            $this->addColumn('{{%mall_order}}', 'logistics_reviewed_by', $this->integer()->notNull()->defaultValue(0)->comment('平台/口岸物流复核人')->after('logistics_reviewed_at'));
        }
        if (!isset($schema->columns['logistics_review_remark'])) {
            $this->addColumn('{{%mall_order}}', 'logistics_review_remark', $this->string(255)->notNull()->defaultValue('')->comment('平台/口岸物流复核备注')->after('logistics_reviewed_by'));
        }

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null) {
            return true;
        }

        if (isset($schema->columns['logistics_reviewed_at'])) {
            $this->dropIndex('mall_order_k_logistics_reviewed_at', '{{%mall_order}}');
            $this->dropColumn('{{%mall_order}}', 'logistics_reviewed_at');
        }
        if (isset($schema->columns['logistics_review_status'])) {
            $this->dropIndex('mall_order_k_logistics_review_status', '{{%mall_order}}');
            $this->dropColumn('{{%mall_order}}', 'logistics_review_status');
        }
        if (isset($schema->columns['logistics_reviewed_by'])) {
            $this->dropColumn('{{%mall_order}}', 'logistics_reviewed_by');
        }
        if (isset($schema->columns['logistics_review_remark'])) {
            $this->dropColumn('{{%mall_order}}', 'logistics_review_remark');
        }

        return true;
    }
}
