<?php

use yii\db\Migration;

class m260608_180000_mongoyia_payment_attempt extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_payment_attempt}}', true) !== null) {
            echo "Table {{%mall_payment_attempt}} already exists, skip.\n";
            return true;
        }

        $this->createTable('{{%mall_payment_attempt}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('商家'),
            'order_id' => $this->bigInteger()->unsigned()->notNull()->comment('父订单'),
            'user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('用户'),
            'provider' => $this->string(32)->notNull()->defaultValue('')->comment('支付渠道'),
            'event' => $this->string(32)->notNull()->defaultValue('')->comment('事件'),
            'merchant_transaction_id' => $this->string(64)->notNull()->defaultValue('')->comment('商户交易号'),
            'gateway_transaction_id' => $this->string(128)->notNull()->defaultValue('')->comment('网关交易号'),
            'amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('金额'),
            'currency' => $this->string(16)->notNull()->defaultValue('')->comment('币种'),
            'request_method' => $this->string(16)->notNull()->defaultValue('')->comment('请求方法'),
            'request_ip' => $this->string(64)->notNull()->defaultValue('')->comment('请求IP'),
            'payload' => $this->text()->null()->comment('请求/响应摘要'),
            'result' => $this->string(32)->notNull()->defaultValue('pending')->comment('处理结果'),
            'error_message' => $this->string(255)->notNull()->defaultValue('')->comment('错误信息'),
            'processed_at' => $this->integer()->notNull()->defaultValue(0)->comment('处理时间'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="支付尝试/回调日志"');

        $this->createIndex('mall_payment_attempt_k0', '{{%mall_payment_attempt}}', 'order_id');
        $this->createIndex('mall_payment_attempt_k1', '{{%mall_payment_attempt}}', ['provider', 'event']);
        $this->createIndex('mall_payment_attempt_k2', '{{%mall_payment_attempt}}', 'merchant_transaction_id');
        $this->createIndex('mall_payment_attempt_k3', '{{%mall_payment_attempt}}', 'created_at');

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_payment_attempt}}', true) === null) {
            return true;
        }

        $this->dropTable('{{%mall_payment_attempt}}');
        return true;
    }
}
