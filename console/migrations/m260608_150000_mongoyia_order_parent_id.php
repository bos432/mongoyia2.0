<?php

use yii\db\Migration;

class m260608_150000_mongoyia_order_parent_id extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null) {
            echo "Table {{%mall_order}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['parent_id'])) {
            $this->addColumn('{{%mall_order}}', 'parent_id', $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('父订单')->after('store_id'));
        }

        if (!$this->hasIndex('{{%mall_order}}', 'mall_order_k0')) {
            $this->createIndex('mall_order_k0', '{{%mall_order}}', 'parent_id');
        }

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_order}}', true);
        if ($schema === null || !isset($schema->columns['parent_id'])) {
            return true;
        }

        if ($this->hasIndex('{{%mall_order}}', 'mall_order_k0')) {
            $this->dropIndex('mall_order_k0', '{{%mall_order}}');
        }
        $this->dropColumn('{{%mall_order}}', 'parent_id');

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
