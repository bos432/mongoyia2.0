<?php

use yii\db\Migration;

class m260618_174000_mongoyia_settlement_draft extends Migration
{
    public function safeUp()
    {
        $this->createDraftTable();
        $this->createDraftOrderTable();
        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_settlement_draft_order}}', true) !== null) {
            $this->dropTable('{{%mall_settlement_draft_order}}');
        }
        if ($this->db->schema->getTableSchema('{{%mall_settlement_draft}}', true) !== null) {
            $this->dropTable('{{%mall_settlement_draft}}');
        }
        return true;
    }

    private function createDraftTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_settlement_draft}}', true) !== null) {
            echo "Table {{%mall_settlement_draft}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_settlement_draft}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('店铺'),
            'sn' => $this->string(64)->notNull()->defaultValue('')->comment('结算草案号'),
            'order_count' => $this->integer()->notNull()->defaultValue(0)->comment('订单数'),
            'order_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('订单金额'),
            'shipment_fee_deducted' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('已扣物流费'),
            'net_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('拟结算金额'),
            'draft_status' => $this->string(32)->notNull()->defaultValue('draft')->comment('草案状态'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="结算草案"');

        $this->createIndex('mall_settlement_draft_u0', '{{%mall_settlement_draft}}', 'sn', true);
        $this->createIndex('mall_settlement_draft_k0', '{{%mall_settlement_draft}}', ['store_id', 'draft_status']);
        $this->createIndex('mall_settlement_draft_k1', '{{%mall_settlement_draft}}', 'created_at');
    }

    private function createDraftOrderTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_settlement_draft_order}}', true) !== null) {
            echo "Table {{%mall_settlement_draft_order}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_settlement_draft_order}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'draft_id' => $this->bigInteger()->unsigned()->notNull()->comment('结算草案'),
            'order_id' => $this->bigInteger()->unsigned()->notNull()->comment('订单'),
            'order_sn' => $this->string(64)->notNull()->defaultValue('')->comment('订单号'),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('店铺'),
            'order_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('订单金额'),
            'shipment_fee_deducted' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('已扣物流费'),
            'payment_status' => $this->integer()->notNull()->defaultValue(0)->comment('支付状态'),
            'shipment_status' => $this->integer()->notNull()->defaultValue(0)->comment('物流状态'),
            'logistics_review_status' => $this->integer()->notNull()->defaultValue(0)->comment('物流复核状态'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="结算草案订单"');

        $this->createIndex('mall_settlement_draft_order_k0', '{{%mall_settlement_draft_order}}', 'draft_id');
        $this->createIndex('mall_settlement_draft_order_k1', '{{%mall_settlement_draft_order}}', ['order_id', 'status']);
        $this->createIndex('mall_settlement_draft_order_k2', '{{%mall_settlement_draft_order}}', ['store_id', 'created_at']);
    }
}
