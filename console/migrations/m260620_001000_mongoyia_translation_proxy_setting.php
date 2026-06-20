<?php

use yii\db\Migration;

class m260620_001000_mongoyia_translation_proxy_setting extends Migration
{
    private const PARENT_CODE = 'mongoyia_translation';
    private const PROXY_CODE = 'google_translate_proxy';

    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%base_setting_type}}', true) === null) {
            echo "Table {{%base_setting_type}} not found, skip.\n";
            return true;
        }

        $now = time();
        $parentId = $this->upsertSettingType(self::PARENT_CODE, [
            'store_id' => 1,
            'parent_id' => 0,
            'app_id' => 'backend',
            'name' => '翻译配置',
            'brief' => 'Mongoyia translation service settings',
            'support_role' => 3,
            'support_system' => 1,
            'type' => 'text',
            'value_range' => '',
            'value_default' => '',
            'grade' => 50,
            'sort' => 48,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $this->upsertSettingType(self::PROXY_CODE, [
            'store_id' => 1,
            'parent_id' => $parentId,
            'app_id' => 'backend',
            'name' => 'Google翻译代理',
            'brief' => 'Optional Google Translate proxy URL for server-side translation previews and fill commands.',
            'support_role' => 3,
            'support_system' => 1,
            'type' => 'password',
            'value_range' => '',
            'value_default' => '',
            'grade' => 50,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $this->clearSettingCache();
        return true;
    }

    public function safeDown()
    {
        if ($this->db->schema->getTableSchema('{{%base_setting}}', true) !== null) {
            $this->delete('{{%base_setting}}', ['code' => self::PROXY_CODE]);
        }

        if ($this->db->schema->getTableSchema('{{%base_setting_type}}', true) !== null) {
            $this->delete('{{%base_setting_type}}', ['code' => self::PROXY_CODE]);
            $this->delete('{{%base_setting_type}}', ['code' => self::PARENT_CODE]);
        }

        $this->clearSettingCache();
        return true;
    }

    private function upsertSettingType(string $code, array $attributes): int
    {
        $row = (new \yii\db\Query())
            ->select(['id'])
            ->from('{{%base_setting_type}}')
            ->where(['code' => $code])
            ->one($this->db);

        $attributes['code'] = $code;
        if ($row) {
            unset($attributes['created_at'], $attributes['created_by']);
            $attributes['updated_at'] = time();
            $this->update('{{%base_setting_type}}', $attributes, ['id' => (int)$row['id']]);
            return (int)$row['id'];
        }

        $this->insert('{{%base_setting_type}}', $attributes);
        return (int)$this->db->getLastInsertID();
    }

    private function clearSettingCache(): void
    {
        try {
            if (class_exists('Yii') && \Yii::$app->has('cacheSystem')) {
                \Yii::$app->cacheSystem->clearAllSetting();
            }
        } catch (\Throwable $e) {
            echo "Setting cache clear skipped: " . $e->getMessage() . "\n";
        }
    }
}
