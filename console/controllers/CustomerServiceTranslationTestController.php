<?php

namespace console\controllers;

use common\models\mall\OperationalConfig;
use common\services\mall\CustomerServiceTranslationService;
use common\services\mall\OperationalConfigService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceTranslationTestController extends Controller
{
    public $fixture = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['fixture']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service translation check\n");

        $this->checkSchema();
        $this->checkMarkers();
        if ($this->fixture && $this->failures === 0) {
            $this->runFixture();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%chat}}', [
            'original_content',
            'source_language',
            'target_language',
            'translated_content',
            'translation_status',
            'translation_provider',
            'translation_error',
            'translated_at',
        ]);
        $this->requireColumns('{{%mall_operational_config}}', [
            'category',
            'provider',
            'code',
            'value_ciphertext',
            'value_hash',
            'last_check_status',
        ]);
    }

    private function checkMarkers(): void
    {
        $this->section('Source markers');
        $this->requireFileContains('@app/../console/migrations/m260623_090100_mongoyia_customer_service_translation.php', [
            'original_content',
            'translation_status',
            'translation_provider',
            'chat_k5',
        ]);
        $this->requireFileContains('@app/../common/services/mall/CustomerServiceTranslationService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TRANSLATION_V1',
            'openai_compatible',
            'google_compatible',
            'messageMetadata',
            'provider_test',
            'Translation provider is disabled',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OperationalConfigController.php', [
            'MONGOYIA_OPERATIONAL_CONFIG_BACKEND_POST_VERB_GUARD_V1',
            'CustomerServiceTranslationService',
            'actionSaveTranslation',
            'actionCheckTranslation',
            'actionTestTranslation',
            "'save-translation'",
            "'check-translation'",
            "'test-translation'",
            "['post']",
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/operational-config/index.php', [
            'data-mongoyia-customer-service-translation-config',
            '客服翻译配置',
            '保存翻译配置',
            '测试翻译',
        ]);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/ChatController.php', [
            'CustomerServiceTranslationService',
            'actionTranslate',
            'defaultStaffWorkLanguage',
            'messageMetadata',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/KfController.php', [
            'CustomerServiceTranslationService',
            'actionTranslate',
            'defaultStaffWorkLanguage',
            'messageMetadata',
        ]);
        $this->requireFileContains('@app/../web/resources/mall/default/views/chat/index.php', [
            'translationUrl',
            'staffWorkLanguage',
            'translateMessage',
            'translated_content',
            'message-original',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/kf/index.php', [
            'translationUrl',
            'staffWorkLanguage',
            'translateMessage',
            'translated_content',
            'message-original',
        ]);
        $this->requireFileContains('@app/../deploy/im-backend/main.py', [
            'has_chat_translation_columns',
            'chat_translation_select',
            'validate_translation_metadata',
            'translation_status',
            'broadcast_data.update(translation_metadata)',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Fixture encryption/fallback');
        $tx = Yii::$app->db->beginTransaction();
        try {
            $apiKey = 'codex-translation-key-' . time();
            $service = new CustomerServiceTranslationService(new OperationalConfigService('codex-translation-test-master-key'));

            $result = $service->saveProvider('openai_compatible', [
                'enabled' => '1',
                'base_url' => 'https://translation.example.test/v1',
                'api_key' => $apiKey,
                'model' => 'codex-translation-fixture',
                'timeout_seconds' => '5',
                'staff_work_language' => 'mn',
            ]);
            if (($result['result'] ?? '') !== 'PASS') {
                $this->fail('OpenAI-compatible fixture should pass required-field detection: ' . ($result['message'] ?? ''));
            } else {
                $this->ok('OpenAI-compatible fixture passes required-field detection.');
            }

            $model = OperationalConfig::find()->where([
                'category' => 'translation',
                'provider' => 'openai_compatible',
                'code' => 'api_key',
                'environment' => 'default',
            ])->one();
            if (!$model || (string)$model->value_plain !== '') {
                $this->fail('Translation API Key fixture was not stored as encrypted sensitive config.');
            } elseif (strpos((string)$model->value_ciphertext, $apiKey) !== false) {
                $this->fail('Translation API Key ciphertext leaked the raw key.');
            } else {
                $this->ok('Translation API Key fixture is encrypted and not stored in plaintext.');
            }

            $fallback = $service->translate('Hello customer service', 'en', 'mn', 'google_compatible');
            if (($fallback['status'] ?? '') !== CustomerServiceTranslationService::STATUS_FAILED || ($fallback['translated_text'] ?? '') !== 'Hello customer service') {
                $this->fail('Disabled provider fallback should keep original text with failed status.');
            } else {
                $this->ok('Disabled provider fallback keeps original text and does not block message flow.');
            }

            $metadata = $service->messageMetadata($fallback);
            foreach (['original_content', 'source_language', 'target_language', 'translated_content', 'translation_status', 'translation_provider', 'translation_error', 'translated_at'] as $key) {
                if (!array_key_exists($key, $metadata)) {
                    $this->fail('Translation metadata missing key ' . $key . '.');
                    break;
                }
            }
            if ($this->failures === 0) {
                $this->ok('Translation metadata maps to chat table columns.');
            }

            if ($service->detectLanguage('你好') !== 'zh-CN' || $service->detectLanguage('hello') !== 'en') {
                $this->fail('Language detection fixture did not classify zh-CN/en samples.');
            } else {
                $this->ok('Language detection handles zh-CN/en samples.');
            }

            $tx->rollBack();
            $this->ok('Customer-service translation fixture rows rolled back.');
        } catch (\Throwable $e) {
            $tx->rollBack();
            $this->fail('Customer-service translation fixture failed: ' . $e->getMessage());
        }
    }

    private function requireColumns(string $table, array $columns): void
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        if (!$schema) {
            $this->fail("Missing table {$table}. Run the required migrations.");
            return;
        }
        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                $this->fail("Table {$table} missing column {$column}.");
                return;
            }
        }
        $this->ok("Table {$table} has required columns.");
    }

    private function requireFileContains(string $alias, array $needles): void
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
