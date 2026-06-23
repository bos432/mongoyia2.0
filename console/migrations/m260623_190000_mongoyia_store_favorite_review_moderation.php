<?php

use yii\db\Migration;

class m260623_190000_mongoyia_store_favorite_review_moderation extends Migration
{
    private $permissions = [
        2437 => [
            'parent_id' => 243,
            'name' => '评价审核通过',
            'path' => '/mall/review/approve',
            'sort' => 57,
        ],
        2438 => [
            'parent_id' => 243,
            'name' => '评价审核驳回',
            'path' => '/mall/review/reject',
            'sort' => 58,
        ],
        2439 => [
            'parent_id' => 243,
            'name' => '评价违规标记',
            'path' => '/mall/review/mark-violation',
            'sort' => 59,
        ],
        2457 => [
            'parent_id' => 245,
            'name' => '店铺收藏',
            'path' => '/mall/store-favorite/index',
            'sort' => 57,
        ],
    ];

    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_store_favorite}}', true) === null) {
            $this->createTable('{{%mall_store_favorite}}', [
                'id' => $this->bigPrimaryKey()->unsigned(),
                'store_id' => $this->bigInteger()->unsigned()->notNull()->comment('商家'),
                'user_id' => $this->bigInteger()->unsigned()->notNull()->comment('用户'),
                'name' => $this->string(255)->notNull()->defaultValue('')->comment('店铺名称'),
                'sort' => $this->integer()->notNull()->defaultValue(50)->comment('排序'),
                'status' => $this->integer()->notNull()->defaultValue(1)->comment('状态'),
                'created_at' => $this->integer()->notNull()->defaultValue(1)->comment('创建时间'),
                'updated_at' => $this->integer()->notNull()->defaultValue(1)->comment('更新时间'),
                'created_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('创建用户'),
                'updated_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(1)->comment('更新用户'),
            ]);
            $this->createIndex('mall_store_favorite_k0', '{{%mall_store_favorite}}', ['user_id', 'store_id']);
            $this->createIndex('mall_store_favorite_k1', '{{%mall_store_favorite}}', ['store_id', 'status']);
            $this->addForeignKey('mall_store_favorite_fk0', '{{%mall_store_favorite}}', 'user_id', '{{%user}}', 'id', 'NO ACTION', 'NO ACTION');
            $this->addForeignKey('mall_store_favorite_fk1', '{{%mall_store_favorite}}', 'store_id', '{{%store}}', 'id', 'NO ACTION', 'NO ACTION');
        }

        $reviewSchema = $this->db->schema->getTableSchema('{{%mall_review}}', true);
        if ($reviewSchema !== null) {
            if (!isset($reviewSchema->columns['moderation_status'])) {
                $this->addColumn('{{%mall_review}}', 'moderation_status', $this->string(32)->notNull()->defaultValue('approved')->comment('审核状态')->after('status'));
            }
            $reviewSchema = $this->db->schema->getTableSchema('{{%mall_review}}', true);
            if (!isset($reviewSchema->columns['moderation_remark'])) {
                $this->addColumn('{{%mall_review}}', 'moderation_remark', $this->string(1000)->notNull()->defaultValue('')->comment('审核备注')->after('moderation_status'));
            }
            $reviewSchema = $this->db->schema->getTableSchema('{{%mall_review}}', true);
            if (!isset($reviewSchema->columns['moderated_at'])) {
                $this->addColumn('{{%mall_review}}', 'moderated_at', $this->integer()->notNull()->defaultValue(0)->comment('审核时间')->after('moderation_remark'));
            }
            $reviewSchema = $this->db->schema->getTableSchema('{{%mall_review}}', true);
            if (!isset($reviewSchema->columns['moderated_by'])) {
                $this->addColumn('{{%mall_review}}', 'moderated_by', $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('审核人')->after('moderated_at'));
            }
            $this->createIndexIfMissing('{{%mall_review}}', 'mall_review_moderation_status_k0', ['moderation_status', 'status']);
        } else {
            echo "Table {{%mall_review}} not found, skip review moderation fields.\n";
        }

        $this->createPermissions();
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

        $reviewSchema = $this->db->schema->getTableSchema('{{%mall_review}}', true);
        if ($reviewSchema !== null) {
            $this->dropIndexIfExists('{{%mall_review}}', 'mall_review_moderation_status_k0');
            foreach (['moderated_by', 'moderated_at', 'moderation_remark', 'moderation_status'] as $column) {
                $reviewSchema = $this->db->schema->getTableSchema('{{%mall_review}}', true);
                if ($reviewSchema !== null && isset($reviewSchema->columns[$column])) {
                    $this->dropColumn('{{%mall_review}}', $column);
                }
            }
        }

        if ($this->db->schema->getTableSchema('{{%mall_store_favorite}}', true) !== null) {
            $this->dropForeignKeyIfExists('{{%mall_store_favorite}}', 'mall_store_favorite_fk1');
            $this->dropForeignKeyIfExists('{{%mall_store_favorite}}', 'mall_store_favorite_fk0');
            $this->dropTable('{{%mall_store_favorite}}');
        }

        $this->clearPermissionCache();
        return true;
    }

    private function createPermissions(): void
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
                    'brief' => 'Phase 14 favorite/review moderation action',
                    'path' => $item['path'],
                    'icon' => '',
                    'tree' => '',
                    'level' => 4,
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

    private function createIndexIfMissing(string $table, string $name, array $columns): void
    {
        try {
            $this->createIndex($name, $table, $columns);
        } catch (\Throwable $e) {
            echo "Index {$name} create skipped: " . $e->getMessage() . "\n";
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        try {
            $this->dropIndex($name, $table);
        } catch (\Throwable $e) {
            echo "Index {$name} drop skipped: " . $e->getMessage() . "\n";
        }
    }

    private function dropForeignKeyIfExists(string $table, string $name): void
    {
        try {
            $this->dropForeignKey($name, $table);
        } catch (\Throwable $e) {
            echo "Foreign key {$name} drop skipped: " . $e->getMessage() . "\n";
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
