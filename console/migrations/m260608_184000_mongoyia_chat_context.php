<?php

use yii\db\Migration;

class m260608_184000_mongoyia_chat_context extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        if ($schema === null) {
            echo "Table {{%chat}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['product_id'])) {
            $this->addColumn('{{%chat}}', 'product_id', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('商品')->after('uid'));
        }

        if (!isset($schema->columns['store_id'])) {
            $this->addColumn('{{%chat}}', 'store_id', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('店铺')->after('product_id'));
        }

        if (!$this->hasIndex('{{%chat}}', 'chat_k0')) {
            $this->createIndex('chat_k0', '{{%chat}}', ['uid', 'uuid']);
        }

        if (!$this->hasIndex('{{%chat}}', 'chat_k1')) {
            $this->createIndex('chat_k1', '{{%chat}}', 'product_id');
        }

        if (!$this->hasIndex('{{%chat}}', 'chat_k2')) {
            $this->createIndex('chat_k2', '{{%chat}}', 'store_id');
        }

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        if ($schema === null) {
            return true;
        }

        if ($this->hasIndex('{{%chat}}', 'chat_k2')) {
            $this->dropIndex('chat_k2', '{{%chat}}');
        }

        if ($this->hasIndex('{{%chat}}', 'chat_k1')) {
            $this->dropIndex('chat_k1', '{{%chat}}');
        }

        if ($this->hasIndex('{{%chat}}', 'chat_k0')) {
            $this->dropIndex('chat_k0', '{{%chat}}');
        }

        if (isset($schema->columns['store_id'])) {
            $this->dropColumn('{{%chat}}', 'store_id');
        }

        if (isset($schema->columns['product_id'])) {
            $this->dropColumn('{{%chat}}', 'product_id');
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
