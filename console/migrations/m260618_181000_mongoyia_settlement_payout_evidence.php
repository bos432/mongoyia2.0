<?php

use yii\db\Migration;

class m260618_181000_mongoyia_settlement_payout_evidence extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_settlement_payout_evidence}}', true) !== null) {
            echo "Table {{%mall_settlement_payout_evidence}} already exists, skip.\n";
            return true;
        }

        $this->createTable('{{%mall_settlement_payout_evidence}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('店铺'),
            'draft_id' => $this->bigInteger()->unsigned()->notNull()->comment('结算草案'),
            'draft_sn' => $this->string(64)->notNull()->defaultValue('')->comment('结算草案号'),
            'amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('打款金额'),
            'currency' => $this->string(16)->notNull()->defaultValue('MNT')->comment('币种'),
            'channel' => $this->string(32)->notNull()->defaultValue('offline')->comment('打款渠道'),
            'transaction_no' => $this->string(128)->notNull()->defaultValue('')->comment('线下流水号'),
            'evidence_file' => $this->string(255)->notNull()->defaultValue('')->comment('凭证文件'),
            'evidence_status' => $this->string(32)->notNull()->defaultValue('recorded')->comment('凭证状态'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'recorded_at' => $this->integer()->notNull()->defaultValue(0)->comment('记录时间'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="结算打款凭证"');

        $this->createIndex('mall_settlement_payout_evidence_u0', '{{%mall_settlement_payout_evidence}}', ['draft_id', 'status'], true);
        $this->createIndex('mall_settlement_payout_evidence_k0', '{{%mall_settlement_payout_evidence}}', ['store_id', 'recorded_at']);
        $this->createIndex('mall_settlement_payout_evidence_k1', '{{%mall_settlement_payout_evidence}}', 'transaction_no');

        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%mall_settlement_payout_evidence}}', true) === null) {
            return true;
        }

        $this->dropTable('{{%mall_settlement_payout_evidence}}');
        return true;
    }
}
