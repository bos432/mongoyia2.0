<?php

use yii\db\Migration;

class m260618_185000_mongoyia_distribution_withdraw_workflow extends Migration
{
    private $permissions = [
        5870 => [
            'parent_id' => 22,
            'name' => '分销提现审核',
            'path' => '/mall/distribution-withdraw/index',
            'icon' => 'fas fa-wallet',
            'level' => 3,
            'sort' => 73,
        ],
        5871 => [
            'parent_id' => 5870,
            'name' => '分销提现审核操作',
            'path' => '/mall/distribution-withdraw/*',
            'icon' => '',
            'level' => 4,
            'sort' => 50,
        ],
    ];

    public function safeUp()
    {
        $this->addWithdrawColumns();
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

        $schema = $this->db->schema->getTableSchema('{{%mall_distribution_withdraw}}', true);
        if ($schema !== null && isset($schema->columns['commission_ids'])) {
            $this->dropColumn('{{%mall_distribution_withdraw}}', 'commission_ids');
        }
        $this->clearPermissionCache();
        return true;
    }

    private function addWithdrawColumns()
    {
        $schema = $this->db->schema->getTableSchema('{{%mall_distribution_withdraw}}', true);
        if ($schema === null) {
            echo "Table {{%mall_distribution_withdraw}} not found, skip columns.\n";
            return;
        }
        if (!isset($schema->columns['commission_ids'])) {
            $this->addColumn('{{%mall_distribution_withdraw}}', 'commission_ids', $this->text()->null()->after('amount')->comment('佣金ID列表JSON'));
        }
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
