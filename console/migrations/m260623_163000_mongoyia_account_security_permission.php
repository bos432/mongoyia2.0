<?php

use yii\db\Migration;

class m260623_163000_mongoyia_account_security_permission extends Migration
{
    private $permissions = [
        7066 => [
            'parent_id' => 7060,
            'name' => '账号安全策略',
            'path' => '/mall/operational-config/account-security*',
            'icon' => 'fas fa-user-lock',
            'level' => 4,
            'sort' => 99,
        ],
        7067 => [
            'parent_id' => 7066,
            'name' => '保存账号安全策略',
            'path' => '/mall/operational-config/save-account-security*',
            'icon' => '',
            'level' => 5,
            'sort' => 50,
        ],
        7068 => [
            'parent_id' => 7066,
            'name' => '检测账号安全策略',
            'path' => '/mall/operational-config/check-account-security*',
            'icon' => '',
            'level' => 5,
            'sort' => 51,
        ],
    ];

    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) === null) {
            echo "Table {{%base_permission}} not found, skip permission.\n";
            return true;
        }

        $now = time();
        foreach ($this->permissions as $id => $item) {
            $exists = (new \yii\db\Query())
                ->from('{{%base_permission}}')
                ->where(['or', ['id' => $id], ['path' => $item['path']]])
                ->exists($this->db);
            if ($exists) {
                continue;
            }

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

        $this->grantToRoles($now);
        $this->clearPermissionCache();
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

        $this->clearPermissionCache();
        return true;
    }

    private function grantToRoles(int $now): void
    {
        if ($this->db->schema->getTableSchema('{{%base_role_permission}}', true) === null ||
            $this->db->schema->getTableSchema('{{%base_role}}', true) === null) {
            return;
        }

        $roleIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%base_role}}')
            ->where(['status' => 1])
            ->andWhere(['or', ['<=', 'id', 50], ['id' => 55]])
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

    private function clearPermissionCache(): void
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
