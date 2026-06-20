<?php

use yii\db\Migration;

class m260608_183000_mongoyia_order_product_stats_permission extends Migration
{
    private $permissionId = 5843;
    private $roleIds = [50, 55];

    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%base_role_permission}}', true) === null) {
            echo "Table {{%base_role_permission}} not found, skip.\n";
            return true;
        }

        $permissionExists = (new \yii\db\Query())
            ->from('{{%base_permission}}')
            ->where(['id' => $this->permissionId])
            ->exists($this->db);
        if (!$permissionExists) {
            echo "Permission {$this->permissionId} not found, skip.\n";
            return true;
        }

        $now = time();
        foreach ($this->roleIds as $roleId) {
            $exists = (new \yii\db\Query())
                ->from('{{%base_role_permission}}')
                ->where(['role_id' => $roleId, 'permission_id' => $this->permissionId])
                ->exists($this->db);
            if ($exists) {
                continue;
            }

            $this->insert('{{%base_role_permission}}', [
                'store_id' => 1,
                'name' => '',
                'role_id' => $roleId,
                'permission_id' => $this->permissionId,
                'type' => 1,
                'sort' => 50,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);
        }

        $this->clearPermissionCache();
        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%base_role_permission}}', true) !== null) {
            $this->delete('{{%base_role_permission}}', [
                'role_id' => $this->roleIds,
                'permission_id' => $this->permissionId,
            ]);
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
