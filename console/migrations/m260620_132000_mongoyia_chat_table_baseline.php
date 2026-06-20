<?php

use yii\db\Migration;

class m260620_132000_mongoyia_chat_table_baseline extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        if ($schema === null) {
            $this->createTable('{{%chat}}', [
                'id' => $this->primaryKey()->unsigned(),
                'from' => $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('发送方类型'),
                'uid' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('商家/客服用户'),
                'product_id' => $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('商品'),
                'store_id' => $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('店铺'),
                'content' => $this->text()->comment('内容'),
                'type' => $this->integer()->notNull()->defaultValue(1)->comment('消息类型'),
                'time' => $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('发送时间'),
                'uuid' => $this->string(128)->notNull()->defaultValue('')->comment('用户唯一标识'),
                'user_read_at' => $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('用户已读时间'),
                'merchant_read_at' => $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('商家已读时间'),
            ]);
        } else {
            $this->ensureColumn($schema, 'product_id', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('商品')->after('uid'));
            $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
            $this->ensureColumn($schema, 'store_id', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('店铺')->after('product_id'));
            $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
            $this->ensureColumn($schema, 'user_read_at', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('用户已读时间')->after('uuid'));
            $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
            $this->ensureColumn($schema, 'merchant_read_at', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('商家已读时间')->after('user_read_at'));
        }

        $this->ensureIndex('chat_k0', ['uid', 'uuid']);
        $this->ensureIndex('chat_k1', 'product_id');
        $this->ensureIndex('chat_k2', 'store_id');
        $this->ensureIndex('chat_k3', ['uid', 'uuid', 'merchant_read_at']);
        $this->ensureIndex('chat_k4', ['uuid', 'uid', 'user_read_at']);

        return true;
    }

    public function safeDown()
    {
        echo "Chat table baseline migration is non-destructive; no rows are removed.\n";
        return true;
    }

    private function ensureColumn($schema, $column, $definition)
    {
        if ($schema !== null && !isset($schema->columns[$column])) {
            $this->addColumn('{{%chat}}', $column, $definition);
        }
    }

    private function ensureIndex($indexName, $columns)
    {
        if (!$this->hasIndex('{{%chat}}', $indexName)) {
            $this->createIndex($indexName, '{{%chat}}', $columns);
        }
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
