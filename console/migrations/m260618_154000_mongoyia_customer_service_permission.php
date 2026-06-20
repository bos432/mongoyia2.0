<?php

use yii\db\Migration;

class m260618_154000_mongoyia_customer_service_permission extends Migration
{
    private $permissionId = 5853;
    private $path = '/mall/kf/index';

    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) === null) {
            echo "Table {{%base_permission}} not found, skip.\n";
            return true;
        }

        $now = time();
        $exists = (new \yii\db\Query())
            ->from('{{%base_permission}}')
            ->where(['or', ['id' => $this->permissionId], ['path' => $this->path]])
            ->exists($this->db);
        if (!$exists) {
            $this->insert('{{%base_permission}}', [
                'id' => $this->permissionId,
                'store_id' => 1,
                'parent_id' => 22,
                'name' => '客服工作台',
                'app_id' => 'backend',
                'brief' => '',
                'path' => $this->path,
                'icon' => 'fas fa-headset',
                'tree' => '',
                'level' => 3,
                'target' => 0,
                'type' => 1,
                'sort' => 61,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);
        }

        $actualPermissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => $this->path])
            ->scalar($this->db);
        if ($actualPermissionId <= 0) {
            $actualPermissionId = $this->permissionId;
        }

        $this->grantToActiveRoles($actualPermissionId, $now);
        $this->clearPermissionCache();
        return true;
    }

    public function safeDown()
    {
        $ids = (new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['or', ['id' => $this->permissionId], ['path' => $this->path]])
            ->column($this->db);

        if ($ids && $this->db->schema->getTableSchema('{{%base_role_permission}}', true) !== null) {
            $this->delete('{{%base_role_permission}}', ['permission_id' => $ids]);
        }
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) !== null) {
            $this->delete('{{%base_permission}}', ['id' => $ids]);
        }

        $this->clearPermissionCache();
        return true;
    }

    private function grantToActiveRoles(int $permissionId, int $now)
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
