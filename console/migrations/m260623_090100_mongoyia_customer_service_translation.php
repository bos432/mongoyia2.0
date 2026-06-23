<?php

use yii\db\Migration;

class m260623_090100_mongoyia_customer_service_translation extends Migration
{
    private $columns = [
        'original_content' => ['after' => 'content', 'comment' => '翻译原文'],
        'source_language' => ['after' => 'original_content', 'comment' => '源语言'],
        'target_language' => ['after' => 'source_language', 'comment' => '目标语言'],
        'translated_content' => ['after' => 'target_language', 'comment' => '译文'],
        'translation_status' => ['after' => 'translated_content', 'comment' => '翻译状态'],
        'translation_provider' => ['after' => 'translation_status', 'comment' => '翻译提供方'],
        'translation_error' => ['after' => 'translation_provider', 'comment' => '翻译错误摘要'],
        'translated_at' => ['after' => 'translation_error', 'comment' => '翻译时间'],
    ];

    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        if ($schema === null) {
            echo "Table {{%chat}} not found, skip customer-service translation columns.\n";
            return true;
        }

        $this->ensureTextColumn($schema, 'original_content', 'content', '翻译原文');
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        $this->ensureStringColumn($schema, 'source_language', 'original_content', '源语言', 16);
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        $this->ensureStringColumn($schema, 'target_language', 'source_language', '目标语言', 16);
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        $this->ensureTextColumn($schema, 'translated_content', 'target_language', '译文');
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        $this->ensureStringColumn($schema, 'translation_status', 'translated_content', '翻译状态', 16, 'none');
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        $this->ensureStringColumn($schema, 'translation_provider', 'translation_status', '翻译提供方', 32);
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        $this->ensureStringColumn($schema, 'translation_error', 'translation_provider', '翻译错误摘要', 255);
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        if ($schema !== null && !isset($schema->columns['translated_at'])) {
            $this->addColumn('{{%chat}}', 'translated_at', $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('翻译时间')->after('translation_error'));
        }

        $this->ensureIndex('chat_k5', ['translation_status', 'translated_at']);
        $this->ensureIndex('chat_k6', ['translation_provider', 'translated_at']);

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
        if ($schema === null) {
            return true;
        }

        foreach (['chat_k6', 'chat_k5'] as $indexName) {
            if ($this->hasIndex('{{%chat}}', $indexName)) {
                $this->dropIndex($indexName, '{{%chat}}');
            }
        }

        foreach (array_reverse(array_keys($this->columns)) as $column) {
            $schema = $this->db->schema->getTableSchema('{{%chat}}', true);
            if ($schema !== null && isset($schema->columns[$column])) {
                $this->dropColumn('{{%chat}}', $column);
            }
        }

        return true;
    }

    private function ensureTextColumn($schema, string $column, string $after, string $comment): void
    {
        if ($schema !== null && !isset($schema->columns[$column])) {
            $this->addColumn('{{%chat}}', $column, $this->text()->null()->comment($comment)->after($after));
        }
    }

    private function ensureStringColumn($schema, string $column, string $after, string $comment, int $length, string $default = ''): void
    {
        if ($schema !== null && !isset($schema->columns[$column])) {
            $this->addColumn('{{%chat}}', $column, $this->string($length)->notNull()->defaultValue($default)->comment($comment)->after($after));
        }
    }

    private function ensureIndex(string $indexName, array $columns): void
    {
        if (!$this->hasIndex('{{%chat}}', $indexName)) {
            $this->createIndex($indexName, '{{%chat}}', $columns);
        }
    }

    private function hasIndex($table, string $indexName): bool
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
