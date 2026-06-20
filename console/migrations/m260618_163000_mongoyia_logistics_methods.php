<?php

use yii\db\Migration;

class m260618_163000_mongoyia_logistics_methods extends Migration
{
    private $permissions = [
        5857 => [
            'parent_id' => 22,
            'name' => '物流方式',
            'path' => '/mall/logistics-method/index',
            'icon' => 'fas fa-truck',
            'level' => 3,
            'sort' => 64,
        ],
        5858 => [
            'parent_id' => 5857,
            'name' => '物流方式操作',
            'path' => '/mall/logistics-method/*',
            'icon' => '',
            'level' => 4,
            'sort' => 50,
        ],
    ];

    public function safeUp()
    {
        $this->createLogisticsMethodTable();
        $this->createStoreLogisticsMethodTable();
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
        if ($this->db->schema->getTableSchema('{{%store_logistics_method}}', true) !== null) {
            $this->dropTable('{{%store_logistics_method}}');
        }
        if ($this->db->schema->getTableSchema('{{%logistics_method}}', true) !== null) {
            $this->dropTable('{{%logistics_method}}');
        }

        $this->clearPermissionCache();
        return true;
    }

    private function createLogisticsMethodTable()
    {
        if ($this->db->schema->getTableSchema('{{%logistics_method}}', true) !== null) {
            echo "Table {{%logistics_method}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%logistics_method}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('平台店铺'),
            'name' => $this->string(255)->notNull()->comment('物流方式'),
            'code' => $this->string(255)->notNull()->defaultValue('')->comment('代码'),
            'provider' => $this->string(255)->notNull()->defaultValue('')->comment('承运商'),
            'base_fee' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('基础费用'),
            'fee_per_kg' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('每公斤费用'),
            'fee_per_volume' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('每体积费用'),
            'tracking_url' => $this->string(255)->notNull()->defaultValue('')->comment('查询链接'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="物流方式"');

        $this->createIndex('logistics_method_k0', '{{%logistics_method}}', 'status');
        $this->createIndex('logistics_method_k1', '{{%logistics_method}}', 'code');
        $this->createIndex('logistics_method_k2', '{{%logistics_method}}', 'store_id');
    }

    private function createStoreLogisticsMethodTable()
    {
        if ($this->db->schema->getTableSchema('{{%store_logistics_method}}', true) !== null) {
            echo "Table {{%store_logistics_method}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%store_logistics_method}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('店铺'),
            'logistics_method_id' => $this->bigInteger()->unsigned()->notNull()->comment('物流方式'),
            'selection_status' => $this->string(32)->notNull()->defaultValue('enabled')->comment('选择状态'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'selected_at' => $this->integer()->notNull()->defaultValue(0)->comment('选择时间'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="店铺物流方式"');

        $this->createIndex('store_logistics_method_u0', '{{%store_logistics_method}}', ['store_id', 'logistics_method_id'], true);
        $this->createIndex('store_logistics_method_k0', '{{%store_logistics_method}}', 'logistics_method_id');
        $this->createIndex('store_logistics_method_k1', '{{%store_logistics_method}}', 'selection_status');
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

        $this->grantToRoles($now);
    }

    private function grantToRoles(int $now)
    {
        if ($this->db->schema->getTableSchema('{{%base_role_permission}}', true) === null) {
            return;
        }

        $roleIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%base_role}}')
            ->where(['status' => 1])
            ->andWhere(['or', ['<=', 'id', 49], ['id' => 50], ['id' => 55]])
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
