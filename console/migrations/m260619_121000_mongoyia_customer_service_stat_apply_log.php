<?php

use yii\db\Migration;

class m260619_121000_mongoyia_customer_service_stat_apply_log extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_customer_service_stat_apply_log}}', true) !== null) {
            echo "Table {{%mall_customer_service_stat_apply_log}} already exists, skip.\n";
            return true;
        }

        $this->createTable('{{%mall_customer_service_stat_apply_log}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'batch_sn' => $this->string(64)->notNull()->defaultValue('')->comment('批次号'),
            'stat_date' => $this->integer()->notNull()->comment('统计日期Ymd'),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('店铺'),
            'service_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('客服用户'),
            'operation' => $this->string(16)->notNull()->defaultValue('skip')->comment('操作'),
            'stat_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('统计行'),
            'source_ticket_count' => $this->integer()->notNull()->defaultValue(0)->comment('来源工单数'),
            'before_json' => $this->text()->null()->comment('变更前JSON'),
            'after_json' => $this->text()->null()->comment('变更后JSON'),
            'diff_summary' => $this->text()->null()->comment('差异摘要'),
            'operator_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('操作人'),
            'applied_at' => $this->integer()->notNull()->defaultValue(0)->comment('应用时间'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="客服统计写入审计"');

        $this->createIndex('mall_customer_service_stat_apply_log_k0', '{{%mall_customer_service_stat_apply_log}}', 'batch_sn');
        $this->createIndex('mall_customer_service_stat_apply_log_k1', '{{%mall_customer_service_stat_apply_log}}', ['store_id', 'stat_date']);
        $this->createIndex('mall_customer_service_stat_apply_log_k2', '{{%mall_customer_service_stat_apply_log}}', ['operator_user_id', 'applied_at']);

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_customer_service_stat_apply_log}}', true) === null) {
            return true;
        }

        $this->dropTable('{{%mall_customer_service_stat_apply_log}}');
        return true;
    }
}
