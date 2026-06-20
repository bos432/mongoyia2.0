<?php

use yii\db\Migration;

class m260618_190000_mongoyia_distribution_profile_material_risk extends Migration
{
    private $permissions = [
        5872 => [
            'parent_id' => 22,
            'name' => '分销员运营',
            'path' => '/mall/distribution-distributor/index',
            'icon' => 'fas fa-users',
            'level' => 3,
            'sort' => 74,
        ],
        5873 => [
            'parent_id' => 5872,
            'name' => '分销员运营操作',
            'path' => '/mall/distribution-distributor/*',
            'icon' => '',
            'level' => 4,
            'sort' => 50,
        ],
    ];

    public function safeUp()
    {
        $this->createProfileTable();
        $this->createMaterialTable();
        $this->createRiskTable();
        $this->createPermissions();
        $this->clearPermissionCache();
        return true;
    }

    public function safeDown()
    {
        $ids = array_keys($this->permissions);
        if ($this->db->schema->getTableSchema('{{%base_role_permission}}', true) !== null) {
            $this->delete('{{%base_role_permission}}', ['permission_id' => $ids]);
        }
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) !== null) {
            $this->delete('{{%base_permission}}', ['id' => $ids]);
        }
        foreach (['{{%mall_distribution_risk}}', '{{%mall_distribution_material}}', '{{%mall_distribution_profile}}'] as $table) {
            if ($this->db->schema->getTableSchema($table, true) !== null) {
                $this->dropTable($table);
            }
        }
        $this->clearPermissionCache();
        return true;
    }

    private function createProfileTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_profile}}', true) !== null) {
            echo "Table {{%mall_distribution_profile}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_profile}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'distributor_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('分销员用户'),
            'display_name' => $this->string(128)->notNull()->defaultValue('')->comment('展示名称'),
            'contact_mobile' => $this->string(64)->notNull()->defaultValue('')->comment('联系电话'),
            'contact_email' => $this->string(128)->notNull()->defaultValue('')->comment('联系邮箱'),
            'channel' => $this->string(128)->notNull()->defaultValue('')->comment('推广渠道'),
            'bio' => $this->string(255)->notNull()->defaultValue('')->comment('简介'),
            'profile_status' => $this->string(32)->notNull()->defaultValue('pending')->comment('资料状态'),
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
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销员资料"');

        $this->createIndex('mall_distribution_profile_u0', '{{%mall_distribution_profile}}', 'distributor_user_id', true);
        $this->createIndex('mall_distribution_profile_k0', '{{%mall_distribution_profile}}', ['profile_status', 'status']);
    }

    private function createMaterialTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_material}}', true) !== null) {
            echo "Table {{%mall_distribution_material}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_material}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'title' => $this->string(128)->notNull()->defaultValue('')->comment('标题'),
            'content' => $this->text()->null()->comment('内容'),
            'target_url' => $this->string(255)->notNull()->defaultValue('')->comment('目标链接'),
            'material_type' => $this->string(32)->notNull()->defaultValue('text')->comment('素材类型'),
            'material_status' => $this->string(32)->notNull()->defaultValue('active')->comment('素材状态'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销推广素材"');

        $this->createIndex('mall_distribution_material_k0', '{{%mall_distribution_material}}', ['material_status', 'status']);
    }

    private function createRiskTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_distribution_risk}}', true) !== null) {
            echo "Table {{%mall_distribution_risk}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_distribution_risk}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'distributor_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('分销员用户'),
            'risk_type' => $this->string(64)->notNull()->defaultValue('manual')->comment('风险类型'),
            'risk_level' => $this->string(32)->notNull()->defaultValue('medium')->comment('风险级别'),
            'content' => $this->string(255)->notNull()->defaultValue('')->comment('内容'),
            'risk_status' => $this->string(32)->notNull()->defaultValue('open')->comment('风险状态'),
            'handled_at' => $this->integer()->notNull()->defaultValue(0)->comment('处理时间'),
            'handled_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('处理人'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="分销风险记录"');

        $this->createIndex('mall_distribution_risk_k0', '{{%mall_distribution_risk}}', ['distributor_user_id', 'risk_status']);
    }

    private function createPermissions()
    {
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) === null) {
            echo "Table {{%base_permission}} not found, skip permissions.\n";
            return;
        }

        $now = time();
        foreach ($this->permissions as $id => $item) {
            $exists = (new \yii\db\Query())
                ->from('{{%base_permission}}')
                ->where(['or', ['id' => $id], ['path' => $item['path']]])
                ->exists($this->db);
            if (!$exists) {
                $this->insert('{{%base_permission}}', [
                    'id' => $id,
                    'store_id' => 1,
                    'parent_id' => $item['parent_id'],
                    'name' => $item['name'],
                    'app_id' => 'backend',
                    'brief' => '',
                    'path' => $item['path'],
                    'icon' => $item['icon'],
                    'tree' => '',
                    'level' => $item['level'],
                    'target' => 0,
                    'type' => 1,
                    'sort' => $item['sort'],
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => 1,
                    'updated_by' => 1,
                ]);
            }
        }

        $this->grantToPlatformRoles($now);
    }

    private function grantToPlatformRoles(int $now)
    {
        if ($this->db->schema->getTableSchema('{{%base_role_permission}}', true) === null) {
            return;
        }

        $roleIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%base_role}}')
            ->where(['status' => 1])
            ->andWhere(['or', ['<=', 'id', 49], ['id' => 55]])
            ->column($this->db);

        foreach ($roleIds as $roleId) {
            foreach (array_keys($this->permissions) as $permissionId) {
                $exists = (new \yii\db\Query())
                    ->from('{{%base_role_permission}}')
                    ->where(['role_id' => $roleId, 'permission_id' => $permissionId])
                    ->exists($this->db);
                if ($exists) {
                    continue;
                }

                $this->insert('{{%base_role_permission}}', [
                    'store_id' => 1,
                    'name' => '',
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'type' => 1,
                    'sort' => 50,
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => 1,
                    'updated_by' => 1,
                ]);
            }
        }
    }

    private function clearPermissionCache()
    {
        try {
            if (class_exists('Yii') && \Yii::$app->has('cacheSystem')) {
                \Yii::$app->cacheSystem->clearAllPermission();
                \Yii::$app->cacheSystem->clearAllUserRole();
            }
        } catch (\Throwable $e) {
            echo "Permission cache clear skipped: " . $e->getMessage() . "\n";
        }
    }
}
