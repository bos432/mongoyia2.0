<?php

use yii\db\Migration;

class m260623_210000_mongoyia_distribution_material_phase15 extends Migration
{
    public function safeUp()
    {
        $this->extendMaterialTable();
        $this->createDownloadLogTable();
        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_material_download_log}}', true) !== null) {
            $this->dropTable('{{%mall_distribution_material_download_log}}');
        }

        $table = $this->db->schema->getTableSchema('{{%mall_distribution_material}}', true);
        if ($table !== null) {
            foreach (['copy_count', 'download_count', 'download_enabled', 'qr_code_url', 'asset_url', 'language'] as $column) {
                if (isset($table->columns[$column])) {
                    $this->dropColumn('{{%mall_distribution_material}}', $column);
                }
            }
        }

        return true;
    }

    private function extendMaterialTable(): void
    {
        $table = $this->db->schema->getTableSchema('{{%mall_distribution_material}}', true);
        if ($table === null) {
            echo "Table {{%mall_distribution_material}} not found, skip material extension.\n";
            return;
        }

        $columns = [
            'language' => $this->string(16)->notNull()->defaultValue('zh-CN')->comment('语言'),
            'asset_url' => $this->string(255)->notNull()->defaultValue('')->comment('素材文件链接'),
            'qr_code_url' => $this->string(255)->notNull()->defaultValue('')->comment('二维码链接'),
            'download_enabled' => $this->integer()->notNull()->defaultValue(1)->comment('允许下载'),
            'download_count' => $this->integer()->notNull()->defaultValue(0)->comment('下载次数'),
            'copy_count' => $this->integer()->notNull()->defaultValue(0)->comment('复制/打开次数'),
        ];

        foreach ($columns as $name => $definition) {
            if (!isset($table->columns[$name])) {
                $this->addColumn('{{%mall_distribution_material}}', $name, $definition);
            }
        }

        $this->createIndex('mall_distribution_material_phase15_k1', '{{%mall_distribution_material}}', ['language', 'material_status', 'status']);
    }

    private function createDownloadLogTable(): void
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_material_download_log}}', true) !== null) {
            echo "Table {{%mall_distribution_material_download_log}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_material_download_log}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'material_id' => $this->bigInteger()->unsigned()->notNull()->comment('素材'),
            'distributor_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('分销员用户'),
            'language' => $this->string(16)->notNull()->defaultValue('zh-CN')->comment('语言'),
            'action_type' => $this->string(32)->notNull()->defaultValue('download')->comment('动作'),
            'channel' => $this->string(64)->notNull()->defaultValue('frontend')->comment('渠道'),
            'user_agent_hash' => $this->string(64)->notNull()->defaultValue('')->comment('UA摘要'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销素材下载复制日志"');

        $this->createIndex('mall_distribution_material_download_log_k0', '{{%mall_distribution_material_download_log}}', ['material_id', 'action_type']);
        $this->createIndex('mall_distribution_material_download_log_k1', '{{%mall_distribution_material_download_log}}', ['distributor_user_id', 'created_at']);
    }
}
