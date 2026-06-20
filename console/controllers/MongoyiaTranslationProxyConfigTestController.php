<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaTranslationProxyConfigTestController extends Controller
{
    private const PARENT_CODE = 'mongoyia_translation';
    private const PROXY_CODE = 'google_translate_proxy';

    public $fixture = false;
    public $storeId = 5;
    public $sampleProxy = 'http://127.0.0.1:18080';

    private $failures = 0;
    private $warnings = 0;
    private $createdSettingTypeCodes = [];
    private $fixtureSettingId = 0;
    private $originalSetting = null;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'fixture',
            'storeId',
            'sampleProxy',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia translation proxy backend config check\n");

        if (!$this->tableExists('{{%base_setting_type}}') || !$this->tableExists('{{%base_setting}}')) {
            $this->fail('Required setting tables are missing.');
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            if ($this->fixture) {
                $this->ensureFixtureSettingTypes();
            }

            $this->checkSettingTypes();
            $this->checkBackendPagePrerequisites();
            $this->checkTranslateCommandResolver();

            if ($this->fixture) {
                $this->checkFixtureReadPath();
            } else {
                $this->warn('Fixture write/read path was skipped; run with --fixture=1 for rollback-clean value verification.');
            }
        } finally {
            if ($this->fixture) {
                $this->cleanupFixture();
            }
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkSettingTypes(): void
    {
        $this->section('Setting type metadata');
        $parent = $this->settingType(self::PARENT_CODE);
        $child = $this->settingType(self::PROXY_CODE);

        if (!$parent) {
            $this->fail('Missing translation setting group.');
            return;
        }
        $this->ok('Translation setting group exists.');

        if (!$child) {
            $this->fail('Missing Google Translate proxy setting item.');
            return;
        }
        $this->ok('Google Translate proxy setting item exists.');

        if ((int)$child['parent_id'] !== (int)$parent['id']) {
            $this->fail('Google Translate proxy setting item is not under the translation group.');
        } else {
            $this->ok('Google Translate proxy setting item is under the translation group.');
        }

        if ((int)$child['status'] !== 1) {
            $this->fail('Google Translate proxy setting item is not active.');
        } else {
            $this->ok('Google Translate proxy setting item is active.');
        }

        if (!in_array((string)$child['type'], ['password', 'text', 'textarea'], true)) {
            $this->fail('Google Translate proxy setting item uses an unsupported input type.');
        } else {
            $this->ok('Google Translate proxy setting item uses a supported input type.');
        }

        if ((((int)$child['support_role']) & 3) === 0) {
            $this->fail('Google Translate proxy setting item is not visible to platform admin roles.');
        } else {
            $this->ok('Google Translate proxy setting item is visible to platform admin roles.');
        }

        if ((((int)$child['support_system']) & 1) === 0) {
            $this->fail('Google Translate proxy setting item is not available in the backend system.');
        } else {
            $this->ok('Google Translate proxy setting item is available in the backend system.');
        }
    }

    private function checkBackendPagePrerequisites(): void
    {
        $this->section('Backend setting page');

        $controller = dirname(__DIR__, 2) . '/backend/modules/base/controllers/SettingController.php';
        $passwordView = dirname(__DIR__, 2) . '/backend/modules/base/views/setting/password.php';

        if (is_file($controller) && strpos(file_get_contents($controller), 'actionEditAll') !== false) {
            $this->ok('Backend setting edit-all action exists.');
        } else {
            $this->fail('Backend setting edit-all action is missing.');
        }

        if (is_file($passwordView)) {
            $this->ok('Backend password setting input view exists.');
        } else {
            $this->fail('Backend password setting input view is missing.');
        }

        if (!$this->tableExists('{{%base_permission}}')) {
            $this->warn('Permission table is missing; backend route permission check skipped.');
            return;
        }

        $exists = (new \yii\db\Query())
            ->from('{{%base_permission}}')
            ->where(['status' => 1])
            ->andWhere(['or', ['path' => '/base/setting/edit-all'], ['path' => '/base/setting/edit*']])
            ->exists(Yii::$app->db);

        if ($exists) {
            $this->ok('Backend setting edit permission exists.');
        } else {
            $this->fail('Backend setting edit permission is missing.');
        }
    }

    private function checkTranslateCommandResolver(): void
    {
        $this->section('Translation command resolver');

        $controller = __DIR__ . '/MallTranslateController.php';
        $content = is_file($controller) ? file_get_contents($controller) : '';

        if (strpos($content, 'resolveBackendProxySetting') !== false && strpos($content, self::PROXY_CODE) !== false) {
            $this->ok('mall-translate/fill can resolve proxy from backend setting after explicit and env values.');
        } else {
            $this->fail('mall-translate/fill backend proxy resolver is missing.');
        }
    }

    private function checkFixtureReadPath(): void
    {
        $this->section('Rollback-clean setting read path');

        $child = $this->settingType(self::PROXY_CODE);
        if (!$child) {
            $this->fail('Cannot verify read path without proxy setting type.');
            return;
        }

        $this->backupSetting();
        $now = time();
        if ($this->originalSetting) {
            $this->fixtureSettingId = (int)$this->originalSetting['id'];
            Yii::$app->db->createCommand()->update('{{%base_setting}}', [
                'value' => $this->sampleProxy,
                'updated_at' => $now,
                'updated_by' => 1,
            ], ['id' => $this->fixtureSettingId])->execute();
        } else {
            Yii::$app->db->createCommand()->insert('{{%base_setting}}', [
                'store_id' => (int)$this->storeId,
                'app_id' => Yii::$app->id,
                'setting_type_id' => (int)$child['id'],
                'name' => $child['name'],
                'code' => self::PROXY_CODE,
                'value' => $this->sampleProxy,
                'grade' => 50,
                'type' => 1,
                'sort' => 50,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ])->execute();
            $this->fixtureSettingId = (int)Yii::$app->db->getLastInsertID();
        }

        $this->clearSettingCache();
        $value = trim((string)Yii::$app->settingSystem->getValue(self::PROXY_CODE, (int)$this->storeId));
        if ($value === $this->sampleProxy) {
            $this->ok('Backend setting value can be read by settingSystem.');
        } else {
            $this->fail('Backend setting value cannot be read by settingSystem.');
        }
    }

    private function ensureFixtureSettingTypes(): void
    {
        $parent = $this->settingType(self::PARENT_CODE);
        if (!$parent) {
            Yii::$app->db->createCommand()->insert('{{%base_setting_type}}', $this->settingTypeRow(self::PARENT_CODE, 0, '翻译配置', 'text'))->execute();
            $this->createdSettingTypeCodes[] = self::PARENT_CODE;
            $parent = $this->settingType(self::PARENT_CODE);
        }

        if (!$this->settingType(self::PROXY_CODE)) {
            Yii::$app->db->createCommand()->insert('{{%base_setting_type}}', $this->settingTypeRow(self::PROXY_CODE, (int)$parent['id'], 'Google翻译代理', 'password'))->execute();
            $this->createdSettingTypeCodes[] = self::PROXY_CODE;
        }

        $this->clearSettingCache();
    }

    private function settingTypeRow(string $code, int $parentId, string $name, string $type): array
    {
        $now = time();
        return [
            'store_id' => 1,
            'parent_id' => $parentId,
            'app_id' => 'backend',
            'name' => $name,
            'code' => $code,
            'brief' => $code === self::PROXY_CODE ? 'Optional Google Translate proxy URL.' : 'Mongoyia translation service settings',
            'support_role' => 3,
            'support_system' => 1,
            'type' => $type,
            'value_range' => '',
            'value_default' => '',
            'grade' => 50,
            'sort' => $code === self::PROXY_CODE ? 50 : 48,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    private function backupSetting(): void
    {
        $this->originalSetting = (new \yii\db\Query())
            ->from('{{%base_setting}}')
            ->where(['store_id' => (int)$this->storeId, 'code' => self::PROXY_CODE])
            ->one(Yii::$app->db) ?: null;
    }

    private function cleanupFixture(): void
    {
        if ($this->fixtureSettingId > 0) {
            if ($this->originalSetting) {
                Yii::$app->db->createCommand()->update('{{%base_setting}}', [
                    'app_id' => $this->originalSetting['app_id'],
                    'setting_type_id' => $this->originalSetting['setting_type_id'],
                    'name' => $this->originalSetting['name'],
                    'value' => $this->originalSetting['value'],
                    'grade' => $this->originalSetting['grade'],
                    'type' => $this->originalSetting['type'],
                    'sort' => $this->originalSetting['sort'],
                    'status' => $this->originalSetting['status'],
                    'updated_at' => $this->originalSetting['updated_at'],
                    'updated_by' => $this->originalSetting['updated_by'],
                ], ['id' => $this->fixtureSettingId])->execute();
            } else {
                Yii::$app->db->createCommand()->delete('{{%base_setting}}', ['id' => $this->fixtureSettingId])->execute();
            }
        }

        foreach (array_reverse($this->createdSettingTypeCodes) as $code) {
            Yii::$app->db->createCommand()->delete('{{%base_setting_type}}', ['code' => $code])->execute();
        }

        $this->clearSettingCache();
    }

    private function settingType(string $code): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%base_setting_type}}')
            ->where(['code' => $code])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }

    private function clearSettingCache(): void
    {
        if (Yii::$app->has('cacheSystem')) {
            Yii::$app->cacheSystem->clearAllSetting();
        }
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function warn(string $message): void
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
