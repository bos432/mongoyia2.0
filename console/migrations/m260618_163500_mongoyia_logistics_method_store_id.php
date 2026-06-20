<?php

use yii\db\Migration;

class m260618_163500_mongoyia_logistics_method_store_id extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%logistics_method}}', true);
        if ($schema === null) {
            echo "Table {{%logistics_method}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['store_id'])) {
            $this->addColumn('{{%logistics_method}}', 'store_id', $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('平台店铺')->after('id'));
            $this->createIndex('logistics_method_k2', '{{%logistics_method}}', 'store_id');
        }

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%logistics_method}}', true);
        if ($schema !== null && isset($schema->columns['store_id'])) {
            try {
                $this->dropIndex('logistics_method_k2', '{{%logistics_method}}');
            } catch (\Throwable $e) {
                echo "Index logistics_method_k2 drop skipped: " . $e->getMessage() . "\n";
            }
            $this->dropColumn('{{%logistics_method}}', 'store_id');
        }

        return true;
    }
}
