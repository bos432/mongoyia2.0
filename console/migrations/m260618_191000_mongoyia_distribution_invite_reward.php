<?php

use yii\db\Migration;

class m260618_191000_mongoyia_distribution_invite_reward extends Migration
{
    public function safeUp()
    {
        $this->addInviteRewardRuleColumn();
        $this->createInviteTable();
        $this->createInviteRewardTable();
        return true;
    }

    public function safeDown()
    {
        foreach (['{{%mall_distribution_invite_reward}}', '{{%mall_distribution_invite}}'] as $table) {
            if ($this->db->schema->getTableSchema($table, true) !== null) {
                $this->dropTable($table);
            }
        }

        $schema = $this->db->schema->getTableSchema('{{%mall_distribution_rule}}', true);
        if ($schema !== null && isset($schema->columns['invite_reward_amount'])) {
            $this->dropColumn('{{%mall_distribution_rule}}', 'invite_reward_amount');
        }

        return true;
    }

    private function addInviteRewardRuleColumn()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_distribution_rule}}', true);
        if ($schema === null) {
            echo "Table {{%mall_distribution_rule}} not found, skip invite reward column.\n";
            return;
        }
        if (isset($schema->columns['invite_reward_amount'])) {
            echo "Column invite_reward_amount already exists, skip.\n";
            return;
        }

        $this->addColumn('{{%mall_distribution_rule}}', 'invite_reward_amount', $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('邀请奖励金额')->after('min_order_amount'));
    }

    private function createInviteTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_invite}}', true) !== null) {
            echo "Table {{%mall_distribution_invite}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_invite}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'distributor_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('邀请分销员'),
            'invited_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('被邀请用户'),
            'source' => $this->string(32)->notNull()->defaultValue('fxid')->comment('来源'),
            'invite_status' => $this->string(32)->notNull()->defaultValue('active')->comment('邀请状态'),
            'first_order_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('首单'),
            'first_order_at' => $this->integer()->notNull()->defaultValue(0)->comment('首单时间'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销邀请关系"');

        $this->createIndex('mall_distribution_invite_u0', '{{%mall_distribution_invite}}', 'invited_user_id', true);
        $this->createIndex('mall_distribution_invite_k0', '{{%mall_distribution_invite}}', ['distributor_user_id', 'invite_status']);
    }

    private function createInviteRewardTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_invite_reward}}', true) !== null) {
            echo "Table {{%mall_distribution_invite_reward}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_invite_reward}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'invite_id' => $this->bigInteger()->unsigned()->notNull()->comment('邀请关系'),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('店铺'),
            'order_id' => $this->bigInteger()->unsigned()->notNull()->comment('触发订单'),
            'order_sn' => $this->string(64)->notNull()->defaultValue('')->comment('订单号'),
            'distributor_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('奖励分销员'),
            'invited_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('被邀请用户'),
            'reward_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('奖励金额'),
            'reward_status' => $this->string(32)->notNull()->defaultValue('pending')->comment('奖励状态'),
            'source' => $this->string(32)->notNull()->defaultValue('first_order')->comment('来源'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'settled_at' => $this->integer()->notNull()->defaultValue(0)->comment('结算时间'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销邀请奖励"');

        $this->createIndex('mall_distribution_invite_reward_u0', '{{%mall_distribution_invite_reward}}', 'order_id', true);
        $this->createIndex('mall_distribution_invite_reward_k0', '{{%mall_distribution_invite_reward}}', ['distributor_user_id', 'reward_status']);
        $this->createIndex('mall_distribution_invite_reward_k1', '{{%mall_distribution_invite_reward}}', ['invite_id', 'reward_status']);
    }
}
