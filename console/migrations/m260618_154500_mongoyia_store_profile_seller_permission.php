<?php

use yii\db\Migration;

class m260618_154500_mongoyia_store_profile_seller_permission extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) === null ||
            $this->db->schema->getTableSchema('{{%base_role_permission}}', true) === null) {
            echo "Permission tables not found, skip.\n";
            return true;
        }

        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/store-profile/edit', 'status' => 1])
            ->scalar($this->db);
        if ($permissionId <= 0) {
            echo "Store profile permission not found, skip.\n";
            return true;
        }

        $now = time();
        foreach ([50] as $roleId) {
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

        $this->clearPermissionCache();
        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) === null ||
            $this->db->schema->getTableSchema('{{%base_role_permission}}', true) === null) {
            return true;
        }

        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/store-profile/edit'])
            ->scalar($this->db);
        if ($permissionId > 0) {
            $this->delete('{{%base_role_permission}}', ['role_id' => 50, 'permission_id' => $permissionId]);
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
