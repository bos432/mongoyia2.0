<?php

use yii\db\Migration;

class m260623_164000_mongoyia_notification_send_log extends Migration
{
    private $permissions = [
        7069 => [
            'parent_id' => 7060,
            'name' => '通知发送日志',
            'path' => '/mall/notification-log/index',
            'icon' => 'fas fa-bell',
            'level' => 4,
            'sort' => 101,
        ],
    ];

    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%mall_notification_send_log}}', true) === null) {
            $this->createTable('{{%mall_notification_send_log}}', [
                'id' => $this->primaryKey(),
                'store_id' => $this->integer()->notNull()->defaultValue(0)->comment('Store ID'),
                'user_id' => $this->integer()->notNull()->defaultValue(0)->comment('Recipient user ID'),
                'event_key' => $this->string(64)->notNull()->defaultValue('')->comment('Notification event key'),
                'channel' => $this->string(32)->notNull()->defaultValue('')->comment('Delivery channel'),
                'title' => $this->string(255)->notNull()->defaultValue('')->comment('Title'),
                'content' => $this->text()->null()->comment('Content'),
                'payload_json' => $this->text()->null()->comment('Sanitized payload JSON'),
                'delivery_status' => $this->string(32)->notNull()->defaultValue('pending')->comment('Delivery status'),
                'error_summary' => $this->string(500)->notNull()->defaultValue('')->comment('Error summary'),
                'message_id' => $this->integer()->notNull()->defaultValue(0)->comment('Base message ID'),
                'source' => $this->string(64)->notNull()->defaultValue('')->comment('Source'),
                'trace_id' => $this->string(128)->notNull()->defaultValue('')->comment('Trace ID'),
                'sent_at' => $this->integer()->notNull()->defaultValue(0)->comment('Sent at'),
                'sort' => $this->integer()->notNull()->defaultValue(50),
                'status' => $this->smallInteger()->notNull()->defaultValue(1),
                'created_at' => $this->integer()->notNull()->defaultValue(0),
                'updated_at' => $this->integer()->notNull()->defaultValue(0),
                'created_by' => $this->integer()->notNull()->defaultValue(0),
                'updated_by' => $this->integer()->notNull()->defaultValue(0),
            ]);
            $this->createIndex('idx_mall_notification_send_log_user', '{{%mall_notification_send_log}}', ['user_id', 'sent_at']);
            $this->createIndex('idx_mall_notification_send_log_store', '{{%mall_notification_send_log}}', ['store_id', 'sent_at']);
            $this->createIndex('idx_mall_notification_send_log_event', '{{%mall_notification_send_log}}', ['event_key', 'channel', 'delivery_status']);
            $this->createIndex('idx_mall_notification_send_log_trace', '{{%mall_notification_send_log}}', ['trace_id']);
        }

        $this->insertPermissions();
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
        if ($this->db->schema->getTableSchema('{{%mall_notification_send_log}}', true) !== null) {
            $this->dropTable('{{%mall_notification_send_log}}');
        }

        $this->clearPermissionCache();
        return true;
    }

    private function insertPermissions(): void
    {
        if ($this->db->schema->getTableSchema('{{%base_permission}}', true) === null) {
            echo "Table {{%base_permission}} not found, skip notification-log permission.\n";
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
