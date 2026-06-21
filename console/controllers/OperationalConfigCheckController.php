<?php

namespace console\controllers;

use common\models\mall\OperationalConfig;
use common\models\mall\OperationalConfigAudit;
use common\models\mall\OperationalConfigCheck;
use common\services\mall\OperationalConfigService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigCheckController extends Controller
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
        $this->stdout("Mongoyia operational config foundation check\n");

        $this->checkSchema();
        $this->checkPermission();
        $this->checkBackendFiles();
        $this->checkMasterKeyBoundary();

        if ($this->fixture && $this->failures === 0) {
            $this->runFixture();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_operational_config}}', [
            'id',
            'store_id',
            'category',
            'provider',
            'code',
            'environment',
            'is_enabled',
            'is_sensitive',
            'value_plain',
            'value_ciphertext',
            'value_hash',
            'last_check_status',
        ]);
        $this->requireColumns('{{%mall_operational_config_audit}}', [
            'id',
            'config_id',
            'category',
            'provider',
            'code',
            'action',
            'old_redacted',
            'new_redacted',
            'operator_user_id',
        ]);
        $this->requireColumns('{{%mall_operational_config_check}}', [
            'id',
            'category',
            'provider',
            'check_key',
            'result',
            'message',
            'details_json',
            'checked_at',
        ]);
    }

    private function checkPermission()
    {
        $this->section('Backend permission');
        if (Yii::$app->db->schema->getTableSchema('{{%base_permission}}', true) === null) {
            $this->warn('base_permission table missing; backend permission cannot be verified in this environment.');
            return;
        }

        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/operational-config/index', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active backend permission /mall/operational-config/index.');
            return;
        }
        $this->ok('Permission exists: /mall/operational-config/index');
    }

    private function checkBackendFiles()
    {
        $this->section('Backend files');
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OperationalConfigController.php', [
            'class OperationalConfigController',
            'OperationalConfigService',
            'isMallPlatformOperator',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/operational-config/index.php', [
            '运营配置中心',
            'OP_CONFIG_MASTER_KEY',
            '脱敏值',
            '最近检测',
        ]);
        $this->requireFileContains('@app/../common/services/mall/OperationalConfigService.php', [
            'MONGOYIA_OPERATIONAL_CONFIG_FOUNDATION_V1',
            'OP_CONFIG_MASTER_KEY',
            'openssl_encrypt',
            'value_ciphertext',
            'redactValue',
        ]);
    }

    private function checkMasterKeyBoundary()
    {
        $this->section('Master key boundary');
        $env = function_exists('env') ? (string)env('OP_CONFIG_MASTER_KEY', '') : (string)getenv('OP_CONFIG_MASTER_KEY');
        if ($env === '') {
            $this->warn('OP_CONFIG_MASTER_KEY is not configured in this environment; fixture uses a local test-only master key.');
            return;
        }

        $this->ok('OP_CONFIG_MASTER_KEY is present for runtime sensitive config encryption.');
    }

    private function runFixture()
    {
        $this->section('Fixture encryption/audit/check');
        $service = new OperationalConfigService('codex-fixture-master-key-do-not-use-in-production');
        $provider = 'fixture_codex';
        $secret = 'codex-secret-' . time();
        $createdIds = [];

        $tx = Yii::$app->db->beginTransaction();
        try {
            $config = $service->save([
                'store_id' => 0,
                'category' => 'payment',
                'provider' => $provider,
                'code' => 'api_secret',
                'label' => 'Fixture API Secret',
                'environment' => 'test',
                'is_enabled' => 1,
                'is_sensitive' => 1,
                'value' => $secret,
                'metadata' => ['fixture' => 1],
            ]);
            $createdIds[] = (int)$config->id;

            if ((string)$config->value_plain !== '') {
                $this->fail('Sensitive fixture config wrote plaintext value.');
            } else {
                $this->ok('Sensitive fixture config did not write plaintext.');
            }
            if (strpos((string)$config->value_ciphertext, $secret) !== false) {
                $this->fail('Sensitive fixture ciphertext contains the raw secret.');
            } else {
                $this->ok('Sensitive fixture ciphertext does not contain the raw secret.');
            }
            $roundTrip = $service->getValue('payment', $provider, 'api_secret', 'test', 0);
            if ($roundTrip !== $secret) {
                $this->fail('Encrypted fixture value did not decrypt to the original secret.');
            } else {
                $this->ok('Encrypted fixture value round-trips through OperationalConfigService.');
            }

            $auditCount = (int)OperationalConfigAudit::find()->where(['config_id' => $config->id, 'action' => 'save'])->count();
            if ($auditCount <= 0) {
                $this->fail('Sensitive fixture save did not create an audit row.');
            } else {
                $this->ok('Sensitive fixture save created an audit row.');
            }

            $check = $service->recordCheck([
                'category' => 'payment',
                'provider' => $provider,
                'check_key' => 'api_secret',
                'result' => 'PASS',
                'message' => 'Fixture encrypted config check passed',
                'details' => ['fixture' => 1],
            ]);
            if ((int)$check->id <= 0) {
                $this->fail('Fixture check result was not saved.');
            } else {
                $this->ok('Fixture check result was saved.');
            }

            $redactedRows = $service->redactedRows(['provider' => $provider]);
            $redacted = $redactedRows[0]['redacted_value'] ?? '';
            if ($redacted === '' || strpos($redacted, $secret) !== false) {
                $this->fail('Redacted fixture output leaked the raw secret.');
            } else {
                $this->ok('Redacted fixture output hides the raw secret.');
            }

            $tx->rollBack();
            $this->ok('Operational config fixture rows rolled back.');
        } catch (\Throwable $e) {
            $tx->rollBack();
            $this->fail('Operational config fixture failed: ' . $e->getMessage());
        }
    }

    private function requireColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        if (!$schema) {
            $this->fail("Missing table {$table}. Run migration m260621_010000_mongoyia_operational_config_foundation.");
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

    private function requireFileContains(string $alias, array $needles)
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function section(string $name)
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function warn(string $message)
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
