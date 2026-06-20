<?php

use yii\db\Migration;

class m260618_151000_mongoyia_merchant_backend_permissions extends Migration
{
    private $permissions = [
        5846 => [
            'parent_id' => 22,
            'name' => '商家入驻审核',
            'path' => '/mall/merchant-application/index',
            'icon' => 'fas fa-store',
            'level' => 3,
            'sort' => 58,
        ],
        5847 => [
            'parent_id' => 5846,
            'name' => '审核操作',
            'path' => '/mall/merchant-application/*',
            'icon' => '',
            'level' => 4,
            'sort' => 50,
        ],
        5848 => [
            'parent_id' => 22,
            'name' => '店铺类目授权',
            'path' => '/mall/store-category-auth/index',
            'icon' => 'fas fa-clipboard-check',
            'level' => 3,
            'sort' => 59,
        ],
        5849 => [
            'parent_id' => 5848,
            'name' => '授权操作',
            'path' => '/mall/store-category-auth/*',
            'icon' => '',
            'level' => 4,
            'sort' => 50,
        ],
        5850 => [
            'parent_id' => 240,
            'name' => '商品审核',
            'path' => '/mall/product/approve*',
            'icon' => '',
            'level' => 4,
            'sort' => 57,
        ],
        5851 => [
            'parent_id' => 240,
            'name' => '商品驳回',
            'path' => '/mall/product/reject*',
            'icon' => '',
            'level' => 4,
            'sort' => 58,
        ],
    ];

    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) === null) {
            echo "Table {{%base_permission}} not found, skip.\n";
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

        if ($this->db->schema->getTableSchema('{{%base_role_permission}}', true) !== null) {
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

        $this->clearPermissionCache();
        return true;
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
