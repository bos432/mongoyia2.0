<?php

use yii\db\Migration;

class m260608_185000_mongoyia_chat_read_state extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        if ($schema === null) {
            echo "Table {{%chat}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['user_read_at'])) {
            $this->addColumn('{{%chat}}', 'user_read_at', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('用户已读时间')->after('uuid'));
        }

        if (!isset($schema->columns['merchant_read_at'])) {
            $this->addColumn('{{%chat}}', 'merchant_read_at', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('商家已读时间')->after('user_read_at'));
        }

        if (!$this->hasIndex('{{%chat}}', 'chat_k3')) {
            $this->createIndex('chat_k3', '{{%chat}}', ['uid', 'uuid', 'merchant_read_at']);
        }

        if (!$this->hasIndex('{{%chat}}', 'chat_k4')) {
            $this->createIndex('chat_k4', '{{%chat}}', ['uuid', 'uid', 'user_read_at']);
        }

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        if ($schema === null) {
            return true;
        }

        if ($this->hasIndex('{{%chat}}', 'chat_k4')) {
            $this->dropIndex('chat_k4', '{{%chat}}');
        }

        if ($this->hasIndex('{{%chat}}', 'chat_k3')) {
            $this->dropIndex('chat_k3', '{{%chat}}');
        }

        if (isset($schema->columns['merchant_read_at'])) {
            $this->dropColumn('{{%chat}}', 'merchant_read_at');
        }

        if (isset($schema->columns['user_read_at'])) {
            $this->dropColumn('{{%chat}}', 'user_read_at');
        }

        return true;
    }

    private function hasIndex($table, $indexName)
    {
        if ($this->db->driverName !== 'mysql') {
            return false;
        }

        $rawTable = $this->db->schema->getRawTableName($table);
        $rows = $this->db->createCommand('SHOW INDEX FROM ' . $this->db->quoteTableName($rawTable) . ' WHERE Key_name = :name', [
            ':name' => $indexName,
        ])->queryAll();

        return !empty($rows);
    }
}
