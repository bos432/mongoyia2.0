<?php

use yii\db\Migration;

class m260621_010000_mongoyia_operational_config_foundation extends Migration
{
    private $permissions = [
        7060 => [
            'parent_id' => 22,
            'name' => '运营配置中心',
            'path' => '/mall/operational-config/index',
            'icon' => 'fas fa-tools',
            'level' => 3,
            'sort' => 95,
        ],
    ];

    public function safeUp()
    {
        $this->createConfigTable();
        $this->createAuditTable();
        $this->createCheckTable();
        $this->upsertPermission();
        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%base_role_permission}}', true) !== null) {
            $this->delete('{{%base_role_permission}}', ['permission_id' => array_keys($this->permissions)]);
        }
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) !== null) {
            $this->delete('{{%base_permission}}', ['id' => array_keys($this->permissions)]);
        }

        foreach ([
            '{{%mall_operational_config_check}}',
            '{{%mall_operational_config_audit}}',
            '{{%mall_operational_config}}',
        ] as $table) {
            if ($this->db->schema->getTableSchema($table, true) !== null) {
                $this->dropTable($table);
            }
        }

        $this->clearPermissionCache();
        return true;
    }

    private function createConfigTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_operational_config}}', true) !== null) {
            echo "Table {{%mall_operational_config}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_operational_config}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('店铺'),
            'category' => $this->string(32)->notNull()->defaultValue('payment')->comment('配置分类'),
            'provider' => $this->string(32)->notNull()->defaultValue('')->comment('提供方'),
            'code' => $this->string(64)->notNull()->comment('配置代码'),
            'label' => $this->string(128)->notNull()->defaultValue('')->comment('显示名称'),
            'environment' => $this->string(16)->notNull()->defaultValue('test')->comment('环境'),
            'is_enabled' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('启用'),
            'is_sensitive' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('敏感'),
            'value_plain' => $this->text()->null()->comment('非敏感值'),
            'value_ciphertext' => $this->text()->null()->comment('加密值'),
            'value_hash' => $this->string(64)->notNull()->defaultValue('')->comment('值摘要'),
            'metadata_json' => $this->text()->null()->comment('元数据JSON'),
            'last_checked_at' => $this->integer()->notNull()->defaultValue(0)->comment('最后检测时间'),
            'last_check_status' => $this->string(16)->notNull()->defaultValue('PENDING')->comment('最后检测状态'),
            'last_check_message' => $this->string(255)->notNull()->defaultValue('')->comment('最后检测消息'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="运营配置"');

        $this->createIndex('mall_operational_config_u0', '{{%mall_operational_config}}', ['store_id', 'category', 'provider', 'code', 'environment'], true);
        $this->createIndex('mall_operational_config_k0', '{{%mall_operational_config}}', ['category', 'provider', 'is_enabled']);
        $this->createIndex('mall_operational_config_k1', '{{%mall_operational_config}}', ['last_check_status', 'last_checked_at']);
    }

    private function createAuditTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_operational_config_audit}}', true) !== null) {
            echo "Table {{%mall_operational_config_audit}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_operational_config_audit}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'config_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('配置ID'),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('店铺'),
            'category' => $this->string(32)->notNull()->defaultValue('')->comment('配置分类'),
            'provider' => $this->string(32)->notNull()->defaultValue('')->comment('提供方'),
            'code' => $this->string(64)->notNull()->defaultValue('')->comment('配置代码'),
            'action' => $this->string(32)->notNull()->defaultValue('save')->comment('动作'),
            'old_redacted' => $this->text()->null()->comment('旧值脱敏'),
            'new_redacted' => $this->text()->null()->comment('新值脱敏'),
            'operator_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('操作人'),
            'request_ip' => $this->string(64)->notNull()->defaultValue('')->comment('IP'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="运营配置审计"');

        $this->createIndex('mall_operational_config_audit_k0', '{{%mall_operational_config_audit}}', ['config_id', 'created_at']);
        $this->createIndex('mall_operational_config_audit_k1', '{{%mall_operational_config_audit}}', ['category', 'provider', 'code']);
    }

    private function createCheckTable()
    {
        if ($this->db->schema->getTableSchema('{{%mall_operational_config_check}}', true) !== null) {
            echo "Table {{%mall_operational_config_check}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%mall_operational_config_check}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('店铺'),
            'category' => $this->string(32)->notNull()->defaultValue('')->comment('配置分类'),
            'provider' => $this->string(32)->notNull()->defaultValue('')->comment('提供方'),
            'check_key' => $this->string(64)->notNull()->defaultValue('')->comment('检测项'),
            'result' => $this->string(16)->notNull()->defaultValue('PENDING')->comment('结果'),
            'message' => $this->string(255)->notNull()->defaultValue('')->comment('消息'),
            'details_json' => $this->text()->null()->comment('详情JSON'),
            'checked_at' => $this->integer()->notNull()->defaultValue(0)->comment('检测时间'),
            'operator_user_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('操作人'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="运营配置检测"');

        $this->createIndex('mall_operational_config_check_k0', '{{%mall_operational_config_check}}', ['category', 'provider', 'checked_at']);
        $this->createIndex('mall_operational_config_check_k1', '{{%mall_operational_config_check}}', ['result', 'checked_at']);
    }

    private function upsertPermission()
    {
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) === null) {
            echo "Table {{%base_permission}} not found, skip permission.\n";
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
        $this->clearPermissionCache();
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
