<?php

use yii\db\Migration;

class m260608_182000_mongoyia_payment_attempt_business_key extends Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_payment_attempt}}', true);
        if ($schema === null) {
            echo "Table {{%mall_payment_attempt}} not found, skip.\n";
            return true;
        }

        if (!isset($schema->columns['business_key'])) {
            $this->addColumn('{{%mall_payment_attempt}}', 'business_key', $this->string(160)->notNull()->defaultValue('')->comment('业务标识')->after('event'));
        }
        if (!isset($schema->columns['payload_hash'])) {
            $this->addColumn('{{%mall_payment_attempt}}', 'payload_hash', $this->string(64)->notNull()->defaultValue('')->comment('报文哈希')->after('payload'));
        }

        if (!$this->hasIndex('{{%mall_payment_attempt}}', 'mall_payment_attempt_k4')) {
            $this->createIndex('mall_payment_attempt_k4', '{{%mall_payment_attempt}}', 'business_key');
        }
        if (!$this->hasIndex('{{%mall_payment_attempt}}', 'mall_payment_attempt_k5')) {
            $this->createIndex('mall_payment_attempt_k5', '{{%mall_payment_attempt}}', 'payload_hash');
        }

        $rows = (new \yii\db\Query())
            ->select(['id', 'provider', 'event', 'order_id', 'merchant_transaction_id', 'payload'])
            ->from('{{%mall_payment_attempt}}')
            ->all($this->db);
        foreach ($rows as $row) {
            $merchantId = $row['merchant_transaction_id'] ?: ('order-' . $row['order_id']);
            $businessKey = implode(':', [$row['provider'], $row['event'], $merchantId]);
            $payloadHash = $row['payload'] === null || $row['payload'] === '' ? '' : hash('sha256', (string)$row['payload']);
            $this->update('{{%mall_payment_attempt}}', [
                'business_key' => $businessKey,
                'payload_hash' => $payloadHash,
            ], ['id' => $row['id']]);
        }

        return true;
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_payment_attempt}}', true);
        if ($schema === null) {
            return true;
        }

        if ($this->hasIndex('{{%mall_payment_attempt}}', 'mall_payment_attempt_k5')) {
            $this->dropIndex('mall_payment_attempt_k5', '{{%mall_payment_attempt}}');
        }
        if ($this->hasIndex('{{%mall_payment_attempt}}', 'mall_payment_attempt_k4')) {
            $this->dropIndex('mall_payment_attempt_k4', '{{%mall_payment_attempt}}');
        }
        if (isset($schema->columns['payload_hash'])) {
            $this->dropColumn('{{%mall_payment_attempt}}', 'payload_hash');
        }
        if (isset($schema->columns['business_key'])) {
            $this->dropColumn('{{%mall_payment_attempt}}', 'business_key');
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
