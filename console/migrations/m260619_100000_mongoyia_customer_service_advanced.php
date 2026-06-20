<?php

use yii\db\Migration;

class m260619_100000_mongoyia_customer_service_advanced extends Migration
{
    public function safeUp()
    {
        $this->createTicketTable();
        $this->createEventTable();
        $this->createStatDailyTable();
        return true;
    }

    public function safeDown()
    {
        foreach ([
            '{{%mall_customer_service_stat_daily}}',
            '{{%mall_customer_service_event}}',
            '{{%mall_customer_service_ticket}}',
        ] as $table) {
            if ($this->db->schema->getTableSchema($table, true) !== null) {
                $this->dropTable($table);
            }
        }

        return true;
    }

    private function createTicketTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_customer_service_ticket}}', true) !== null) {
            echo "Table {{%mall_customer_service_ticket}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_customer_service_ticket}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'ticket_sn' => $this->string(64)->notNull()->defaultValue('')->comment('工单编号'),
            'ticket_type' => $this->string(32)->notNull()->defaultValue('order_assist')->comment('工单类型'),
            'ticket_status' => $this->string(32)->notNull()->defaultValue('pending')->comment('工单状态'),
            'priority' => $this->string(32)->notNull()->defaultValue('normal')->comment('优先级'),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('店铺'),
            'product_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('商品'),
            'order_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('订单'),
            'order_sn' => $this->string(64)->notNull()->defaultValue('')->comment('订单号'),
            'customer_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('用户ID'),
            'customer_uuid' => $this->string(128)->notNull()->defaultValue('')->comment('游客会话ID'),
            'merchant_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('商家客服'),
            'platform_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('平台客服'),
            'chat_uuid' => $this->string(128)->notNull()->defaultValue('')->comment('聊天会话'),
            'title' => $this->string(255)->notNull()->defaultValue('')->comment('标题'),
            'content' => $this->text()->null()->comment('内容'),
            'result' => $this->text()->null()->comment('处理结果'),
            'evidence_json' => $this->text()->null()->comment('证据JSON'),
            'first_response_at' => $this->integer()->notNull()->defaultValue(0)->comment('首次响应时间'),
            'resolved_at' => $this->integer()->notNull()->defaultValue(0)->comment('解决时间'),
            'closed_at' => $this->integer()->notNull()->defaultValue(0)->comment('关闭时间'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="客服工单"');

        $this->createIndex('mall_customer_service_ticket_u0', '{{%mall_customer_service_ticket}}', 'ticket_sn', true);
        $this->createIndex('mall_customer_service_ticket_k0', '{{%mall_customer_service_ticket}}', ['store_id', 'ticket_status']);
        $this->createIndex('mall_customer_service_ticket_k1', '{{%mall_customer_service_ticket}}', ['ticket_type', 'ticket_status']);
        $this->createIndex('mall_customer_service_ticket_k2', '{{%mall_customer_service_ticket}}', ['order_id', 'ticket_type']);
        $this->createIndex('mall_customer_service_ticket_k3', '{{%mall_customer_service_ticket}}', ['customer_user_id', 'customer_uuid']);
        $this->createIndex('mall_customer_service_ticket_k4', '{{%mall_customer_service_ticket}}', ['merchant_user_id', 'platform_user_id']);
    }

    private function createEventTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_customer_service_event}}', true) !== null) {
            echo "Table {{%mall_customer_service_event}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_customer_service_event}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'ticket_id' => $this->bigInteger()->unsigned()->notNull()->comment('工单'),
            'event_type' => $this->string(32)->notNull()->defaultValue('note')->comment('事件类型'),
            'from_status' => $this->string(32)->notNull()->defaultValue('')->comment('原状态'),
            'to_status' => $this->string(32)->notNull()->defaultValue('')->comment('新状态'),
            'operator_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('操作人'),
            'operator_type' => $this->string(32)->notNull()->defaultValue('system')->comment('操作人类型'),
            'content' => $this->text()->null()->comment('内容'),
            'metadata_json' => $this->text()->null()->comment('元数据JSON'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="客服工单事件"');

        $this->createIndex('mall_customer_service_event_k0', '{{%mall_customer_service_event}}', ['ticket_id', 'event_type']);
        $this->createIndex('mall_customer_service_event_k1', '{{%mall_customer_service_event}}', ['operator_user_id', 'operator_type']);
    }

    private function createStatDailyTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_customer_service_stat_daily}}', true) !== null) {
            echo "Table {{%mall_customer_service_stat_daily}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_customer_service_stat_daily}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'stat_date' => $this->integer()->notNull()->comment('统计日期Ymd'),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('店铺'),
            'service_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('客服用户'),
            'session_count' => $this->integer()->notNull()->defaultValue(0)->comment('会话数'),
            'ticket_count' => $this->integer()->notNull()->defaultValue(0)->comment('工单数'),
            'order_assist_count' => $this->integer()->notNull()->defaultValue(0)->comment('订单协助数'),
            'complaint_count' => $this->integer()->notNull()->defaultValue(0)->comment('投诉数'),
            'resolved_count' => $this->integer()->notNull()->defaultValue(0)->comment('解决数'),
            'unresolved_count' => $this->integer()->notNull()->defaultValue(0)->comment('未解决数'),
            'first_response_seconds_total' => $this->integer()->notNull()->defaultValue(0)->comment('首次响应总秒数'),
            'resolved_seconds_total' => $this->integer()->notNull()->defaultValue(0)->comment('解决总秒数'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="客服日统计"');

        $this->createIndex('mall_customer_service_stat_daily_u0', '{{%mall_customer_service_stat_daily}}', ['stat_date', 'store_id', 'service_user_id'], true);
        $this->createIndex('mall_customer_service_stat_daily_k0', '{{%mall_customer_service_stat_daily}}', ['store_id', 'stat_date']);
        $this->createIndex('mall_customer_service_stat_daily_k1', '{{%mall_customer_service_stat_daily}}', ['service_user_id', 'stat_date']);
    }
}
