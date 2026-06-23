<?php

use yii\db\Migration;

class m260623_200000_mongoyia_distribution_support_content extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_support_content}}', true) !== null) {
            echo "Table {{%mall_distribution_support_content}} already exists, skip.\n";
            return true;
        }

        $this->createTable('{{%mall_distribution_support_content}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'content_type' => $this->string(32)->notNull()->defaultValue('training')->comment('内容类型'),
            'language' => $this->string(16)->notNull()->defaultValue('zh-CN')->comment('语言'),
            'category' => $this->string(64)->notNull()->defaultValue('')->comment('分类'),
            'title' => $this->string(160)->notNull()->defaultValue('')->comment('标题'),
            'body' => $this->text()->null()->comment('正文'),
            'support_url' => $this->string(255)->notNull()->defaultValue('')->comment('外部链接'),
            'content_status' => $this->string(32)->notNull()->defaultValue('active')->comment('内容状态'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销培训FAQ内容"');

        $this->createIndex('mall_distribution_support_content_k0', '{{%mall_distribution_support_content}}', ['content_type', 'language', 'content_status', 'status']);
        $this->createIndex('mall_distribution_support_content_k1', '{{%mall_distribution_support_content}}', ['sort', 'id']);

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_support_content}}', true) !== null) {
            $this->dropTable('{{%mall_distribution_support_content}}');
        }

        return true;
    }
}
