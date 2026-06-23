<?php

use yii\db\Migration;

class m260623_166000_mongoyia_account_security_code extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_account_security_code}}', true) !== null) {
            return true;
        }

        $this->createTable('{{%mall_account_security_code}}', [
            'id' => $this->primaryKey(),
            'store_id' => $this->integer()->notNull()->defaultValue(0)->comment('Store ID'),
            'user_id' => $this->integer()->notNull()->defaultValue(0)->comment('Target user ID'),
            'channel' => $this->string(32)->notNull()->defaultValue('')->comment('email/mobile'),
            'purpose' => $this->string(32)->notNull()->defaultValue('login')->comment('Code purpose'),
            'target_hash' => $this->string(64)->notNull()->defaultValue('')->comment('Hashed delivery target'),
            'target_masked' => $this->string(190)->notNull()->defaultValue('')->comment('Masked delivery target'),
            'code_hash' => $this->string(255)->notNull()->defaultValue('')->comment('Password-hashed security code'),
            'expires_at' => $this->integer()->notNull()->defaultValue(0)->comment('Expires at'),
            'attempt_count' => $this->integer()->notNull()->defaultValue(0)->comment('Attempt count'),
            'max_attempts' => $this->integer()->notNull()->defaultValue(5)->comment('Max attempts'),
            'lock_minutes' => $this->integer()->notNull()->defaultValue(15)->comment('Lock minutes'),
            'lock_until' => $this->integer()->notNull()->defaultValue(0)->comment('Locked until'),
            'delivery_status' => $this->string(32)->notNull()->defaultValue('pending')->comment('Delivery status'),
            'verify_status' => $this->string(32)->notNull()->defaultValue('')->comment('Verification status'),
            'error_summary' => $this->string(500)->notNull()->defaultValue('')->comment('Sanitized error summary'),
            'consumed_at' => $this->integer()->notNull()->defaultValue(0)->comment('Consumed at'),
            'sent_at' => $this->integer()->notNull()->defaultValue(0)->comment('Sent at'),
            'sort' => $this->integer()->notNull()->defaultValue(50),
            'status' => $this->smallInteger()->notNull()->defaultValue(1),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
            'created_by' => $this->integer()->notNull()->defaultValue(0),
            'updated_by' => $this->integer()->notNull()->defaultValue(0),
        ]);

        $this->createIndex('idx_mall_account_security_code_target', '{{%mall_account_security_code}}', ['channel', 'purpose', 'target_hash', 'status', 'consumed_at']);
        $this->createIndex('idx_mall_account_security_code_user', '{{%mall_account_security_code}}', ['user_id', 'channel', 'created_at']);
        $this->createIndex('idx_mall_account_security_code_delivery', '{{%mall_account_security_code}}', ['delivery_status', 'sent_at']);
        $this->createIndex('idx_mall_account_security_code_verify', '{{%mall_account_security_code}}', ['verify_status', 'lock_until']);
        $this->createIndex('idx_mall_account_security_code_expiry', '{{%mall_account_security_code}}', ['expires_at', 'consumed_at']);

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_account_security_code}}', true) !== null) {
            $this->dropTable('{{%mall_account_security_code}}');
        }

        return true;
    }
}
