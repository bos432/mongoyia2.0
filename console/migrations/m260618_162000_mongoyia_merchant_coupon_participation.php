<?php

use yii\db\Migration;

class m260618_162000_mongoyia_merchant_coupon_participation extends Migration
{
    private $permissions = [
        5855 => [
            'parent_id' => 22,
            'name' => '商家优惠券',
            'path' => '/mall/merchant-coupon/index',
            'icon' => 'fas fa-ticket-alt',
            'level' => 3,
            'sort' => 63,
        ],
        5856 => [
            'parent_id' => 5855,
            'name' => '平台券参与操作',
            'path' => '/mall/merchant-coupon/*',
            'icon' => '',
            'level' => 4,
            'sort' => 50,
        ],
    ];

    public function safeUp()
    {
        $this->createParticipationTable();
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
        if ($this->db->schema->getTableSchema('{{%store_coupon_participation}}', true) !== null) {
            $this->dropTable('{{%store_coupon_participation}}');
        }

        $this->clearPermissionCache();
        return true;
    }

    private function createParticipationTable()
    {
        if ($this->db->schema->getTableSchema('{{%store_coupon_participation}}', true) !== null) {
            echo "Table {{%store_coupon_participation}} already exists, skip.\n";
            return;
        }

        $this->createTable('{{%store_coupon_participation}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('店铺'),
            'coupon_type_id' => $this->bigInteger()->unsigned()->notNull()->comment('平台优惠券类型'),
            'participation_status' => $this->string(32)->notNull()->defaultValue('joined')->comment('参与状态'),
            'remark' => $this->string(255)->notNull()->defaultValue('')->comment('备注'),
            'joined_at' => $this->integer()->notNull()->defaultValue(0)->comment('参与时间'),
            'left_at' => $this->integer()->notNull()->defaultValue(0)->comment('退出时间'),
            'type' => $this->integer()->notNull()->defaultValue(1)->comment('类型'),
            'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
            'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
            'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
            'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
            'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
            'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB COMMENT="店铺平台券参与记录"');

        $this->createIndex('store_coupon_participation_u0', '{{%store_coupon_participation}}', ['store_id', 'coupon_type_id'], true);
        $this->createIndex('store_coupon_participation_k0', '{{%store_coupon_participation}}', 'coupon_type_id');
        $this->createIndex('store_coupon_participation_k1', '{{%store_coupon_participation}}', 'participation_status');
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
