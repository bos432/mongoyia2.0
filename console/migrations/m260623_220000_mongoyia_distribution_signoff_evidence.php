<?php

use yii\db\Migration;

class m260623_220000_mongoyia_distribution_signoff_evidence extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_signoff_evidence}}', true) !== null) {
            echo "Table {{%mall_distribution_signoff_evidence}} already exists, skip.\n";
            return true;
        }

        $this->createTable('{{%mall_distribution_signoff_evidence}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'evidence_type' => $this->string(48)->notNull()->defaultValue('withdraw_payout')->comment('证据类型'),
            'reference_type' => $this->string(48)->notNull()->defaultValue('manual')->comment('关联类型'),
            'reference_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('关联ID'),
            'distributor_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('分销员'),
            'amount' => $this->decimal(12, 2)->notNull()->defaultValue(0)->comment('金额'),
            'evidence_title' => $this->string(160)->notNull()->defaultValue('')->comment('证据标题'),
            'evidence_url' => $this->string(255)->notNull()->defaultValue('')->comment('证据链接'),
            'evidence_note' => $this->text()->null()->comment('证据说明'),
            'signoff_status' => $this->string(32)->notNull()->defaultValue('pending')->comment('签核状态'),
            'reviewer_role' => $this->string(64)->notNull()->defaultValue('')->comment('审核角色'),
            'reviewed_at' => $this->integer()->notNull()->defaultValue(0)->comment('审核时间'),
            'reviewed_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('审核人'),
            'review_remark' => $this->string(255)->notNull()->defaultValue('')->comment('审核备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销签核证据"');

        $this->createIndex('mall_distribution_signoff_evidence_k0', '{{%mall_distribution_signoff_evidence}}', ['evidence_type', 'signoff_status', 'status']);
        $this->createIndex('mall_distribution_signoff_evidence_k1', '{{%mall_distribution_signoff_evidence}}', ['distributor_user_id', 'created_at']);
        $this->createIndex('mall_distribution_signoff_evidence_k2', '{{%mall_distribution_signoff_evidence}}', ['reference_type', 'reference_id']);

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_signoff_evidence}}', true) !== null) {
            $this->dropTable('{{%mall_distribution_signoff_evidence}}');
        }

        return true;
    }
}
