<?php

use yii\db\Migration;

class m260623_165000_mongoyia_social_identity extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_social_identity}}', true) !== null) {
            return true;
        }

        $this->createTable('{{%mall_social_identity}}', [
            'id' => $this->primaryKey(),
            'store_id' => $this->integer()->notNull()->defaultValue(0)->comment('Store ID'),
            'user_id' => $this->integer()->notNull()->defaultValue(0)->comment('Local user ID'),
            'provider' => $this->string(32)->notNull()->defaultValue('')->comment('Provider'),
            'provider_user_id' => $this->string(190)->notNull()->defaultValue('')->comment('Provider stable user ID'),
            'email' => $this->string(255)->notNull()->defaultValue('')->comment('Provider email'),
            'email_verified' => $this->smallInteger()->notNull()->defaultValue(0)->comment('Email verified'),
            'display_name' => $this->string(255)->notNull()->defaultValue('')->comment('Display name'),
            'avatar_url' => $this->string(1022)->notNull()->defaultValue('')->comment('Avatar URL'),
            'profile_json' => $this->text()->null()->comment('Redacted profile JSON'),
            'last_login_at' => $this->integer()->notNull()->defaultValue(0)->comment('Last login at'),
            'sort' => $this->integer()->notNull()->defaultValue(50),
            'status' => $this->smallInteger()->notNull()->defaultValue(1),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
            'created_by' => $this->integer()->notNull()->defaultValue(0),
            'updated_by' => $this->integer()->notNull()->defaultValue(0),
        ]);
        $this->createIndex('idx_mall_social_identity_user', '{{%mall_social_identity}}', ['user_id', 'provider', 'status']);
        $this->createIndex('idx_mall_social_identity_provider', '{{%mall_social_identity}}', ['provider', 'provider_user_id', 'status']);
        $this->createIndex('idx_mall_social_identity_email', '{{%mall_social_identity}}', ['email']);

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_social_identity}}', true) !== null) {
            $this->dropTable('{{%mall_social_identity}}');
        }

        return true;
    }
}
