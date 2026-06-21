<?php

use yii\db\Migration;

class m260621_164000_mongoyia_customer_service_rating extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_customer_service_rating}}', true) !== null) {
            echo "Table {{%mall_customer_service_rating}} already exists, skip.\n";
            return true;
        }

        $this->createTable('{{%mall_customer_service_rating}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('Store'),
            'product_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('Product'),
            'order_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('Order'),
            'ticket_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('Ticket'),
            'customer_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('Customer user'),
            'customer_uuid' => $this->string(128)->notNull()->defaultValue('')->comment('Customer UUID'),
            'chat_uuid' => $this->string(128)->notNull()->defaultValue('')->comment('Chat UUID'),
            'rating' => $this->string(32)->notNull()->defaultValue('')->comment('Rating'),
            'rating_score' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('Rating score'),
            'reason' => $this->string(255)->notNull()->defaultValue('')->comment('Reason'),
            'remark' => $this->text()->null()->comment('Remark'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('Status'),
            'created_at' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
        ]);
        $this->createIndex('idx_cs_rating_chat_customer', '{{%mall_customer_service_rating}}', ['chat_uuid', 'customer_uuid', 'status']);
        $this->createIndex('idx_cs_rating_store_created', '{{%mall_customer_service_rating}}', ['store_id', 'created_at']);
        $this->createIndex('idx_cs_rating_ticket', '{{%mall_customer_service_rating}}', ['ticket_id', 'status']);

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_customer_service_rating}}', true) !== null) {
            $this->dropTable('{{%mall_customer_service_rating}}');
        }
        return true;
    }
}
