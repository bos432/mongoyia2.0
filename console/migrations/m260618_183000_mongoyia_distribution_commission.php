<?php

use yii\db\Migration;

class m260618_183000_mongoyia_distribution_commission extends Migration
{
    public function safeUp()
    {
        $this->createRuleTable();
        $this->createCommissionTable();
        $this->createWithdrawTable();
        return true;
    }

    public function safeDown()
    {
        foreach (['{{%mall_distribution_withdraw}}', '{{%mall_distribution_commission}}', '{{%mall_distribution_rule}}'] as $table) {
            if ($this->db->schema->getTableSchema($table, true) !== null) {
                $this->dropTable($table);
            }
        }
        return true;
    }

    private function createRuleTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_rule}}', true) !== null) {
            echo "Table {{%mall_distribution_rule}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_rule}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('店铺'),
            'name' => $this->string(128)->notNull()->defaultValue('')->comment('规则名称'),
            'commission_rate' => $this->decimal(5, 2)->notNull()->defaultValue(0)->comment('佣金比例'),
            'min_order_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('最低订单金额'),
            'rule_status' => $this->string(32)->notNull()->defaultValue('active')->comment('规则状态'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销规则"');

        $this->createIndex('mall_distribution_rule_k0', '{{%mall_distribution_rule}}', ['store_id', 'rule_status']);
    }

    private function createCommissionTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_commission}}', true) !== null) {
            echo "Table {{%mall_distribution_commission}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_commission}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('店铺'),
            'order_id' => $this->bigInteger()->unsigned()->notNull()->comment('订单'),
            'order_sn' => $this->string(64)->notNull()->defaultValue('')->comment('订单号'),
            'distributor_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('分销员用户'),
            'buyer_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('买家用户'),
            'order_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('订单金额'),
            'commission_rate' => $this->decimal(5, 2)->notNull()->defaultValue(0)->comment('佣金比例'),
            'commission_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('佣金金额'),
            'commission_status' => $this->string(32)->notNull()->defaultValue('pending')->comment('佣金状态'),
            'source' => $this->string(32)->notNull()->defaultValue('order_fx')->comment('来源'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'settled_at' => $this->integer()->notNull()->defaultValue(0)->comment('结算时间'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销佣金记录"');

        $this->createIndex('mall_distribution_commission_u0', '{{%mall_distribution_commission}}', 'order_id', true);
        $this->createIndex('mall_distribution_commission_k0', '{{%mall_distribution_commission}}', ['store_id', 'commission_status']);
        $this->createIndex('mall_distribution_commission_k1', '{{%mall_distribution_commission}}', ['distributor_user_id', 'commission_status']);
    }

    private function createWithdrawTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_withdraw}}', true) !== null) {
            echo "Table {{%mall_distribution_withdraw}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_withdraw}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'distributor_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('分销员用户'),
            'amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('提现金额'),
            'withdraw_status' => $this->string(32)->notNull()->defaultValue('pending')->comment('提现状态'),
            'apply_remark' => $this->string(255)->notNull()->defaultValue('')->comment('申请备注'),
            'audit_remark' => $this->string(255)->notNull()->defaultValue('')->comment('审核备注'),
            'audited_at' => $this->integer()->notNull()->defaultValue(0)->comment('审核时间'),
            'audited_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('审核人'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销提现申请"');

        $this->createIndex('mall_distribution_withdraw_k0', '{{%mall_distribution_withdraw}}', ['distributor_user_id', 'withdraw_status']);
    }
}
