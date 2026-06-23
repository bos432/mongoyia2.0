<?php

namespace console\controllers;

use common\services\mall\OperationalConfigService;
use common\services\mall\OperationalProviderEvidenceService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigProviderEvidenceTestController extends Controller
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
        $this->stdout("Mongoyia operational provider evidence check\n");

        $this->checkMarkers();
        $this->checkDefinitions();
        if ($this->fixture && $this->failures === 0) {
            $this->runFixture();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkMarkers(): void
    {
        $this->section('Provider evidence markers');
        $this->requireFileContains('@app/../common/services/mall/OperationalProviderEvidenceService.php', [
            'MONGOYIA_OPERATIONAL_PROVIDER_EVIDENCE_V1',
            'provider_evidence',
            'redaction_confirmed',
            'looksSensitive',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OperationalConfigController.php', [
            'MONGOYIA_OPERATIONAL_CONFIG_BACKEND_POST_VERB_GUARD_V1',
            'public function behaviors()',
            'OperationalProviderEvidenceService',
            'actionSaveProviderEvidence',
            'actionCheckProviderEvidence',
            "'save-provider-evidence'",
            "'check-provider-evidence'",
            "['post']",
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/operational-config/index.php', [
            'data-mongoyia-operational-provider-evidence',
            '服务商证据验收',
            '保存证据并检测',
            '不要录入私钥',
        ]);
        $this->requireFileContains('@app/../console/controllers/OperationalConfigPhase10AcceptanceController.php', [
            'operational-config-provider-evidence-test/run',
            'Provider evidence acceptance',
        ]);
    }

    private function checkDefinitions(): void
    {
        $this->section('Provider evidence definitions');
        $service = new OperationalProviderEvidenceService(new OperationalConfigService('codex-provider-evidence-test-key'));
        $providers = $service->providerDefinitions();
        foreach (['qpay', 'lianlian', 'paypal', 'smtp', 'translation', 'alert'] as $provider) {
            if (empty($providers[$provider])) {
                $this->fail("Missing provider evidence definition: {$provider}");
                return;
            }
            foreach (['backend_config_checked', 'test_result_ref', 'redaction_confirmed', 'reviewer'] as $code) {
                if (!in_array($code, $providers[$provider]['required'], true)) {
                    $this->fail("Provider {$provider} missing required evidence field {$code}.");
                    return;
                }
            }
        }
        $this->ok('Provider evidence definitions cover payment, mail, translation, and alert providers.');
    }

    private function runFixture(): void
    {
        $this->section('Fixture save/check/redaction');
        try {
            $table = Yii::$app->db->schema->getTableSchema('{{%mall_operational_config}}', true);
        } catch (\Throwable $e) {
            $this->fail('Database unavailable for provider evidence fixture: ' . $e->getMessage());
            return;
        }
        if ($table === null) {
            $this->fail('Missing mall_operational_config table. Run migration m260621_010000_mongoyia_operational_config_foundation.');
            return;
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            $service = new OperationalProviderEvidenceService(new OperationalConfigService('codex-provider-evidence-test-key'));
            $result = $service->saveProvider('qpay', 'test', [
                'backend_config_checked' => '1',
                'callback_configured' => '1',
                'test_result_ref' => 'report:qpay-sandbox-smoke-20260623',
                'evidence_ref' => 'ticket:OPS-QPAY-20260623',
                'redaction_confirmed' => '1',
                'reviewer' => 'codex-fixture',
                'notes' => 'Fixture evidence reference only; no secret values.',
            ]);
            if (($result['result'] ?? '') !== 'PASS') {
                $this->fail('QPay provider evidence fixture should pass: ' . ($result['message'] ?? ''));
            } else {
                $this->ok('QPay provider evidence fixture passes required-field detection.');
            }

            $bad = $service->saveProvider('smtp', 'test', [
                'backend_config_checked' => '1',
                'test_result_ref' => 'api_key=SHOULD_NOT_BE_HERE',
                'redaction_confirmed' => '1',
                'reviewer' => 'codex-fixture',
            ]);
            if (($bad['result'] ?? '') !== 'FAIL') {
                $this->fail('Sensitive-looking provider evidence fixture should fail.');
            } else {
                $this->ok('Sensitive-looking provider evidence fixture is rejected.');
            }

            $tx->rollBack();
            $this->ok('Operational provider evidence fixture rows rolled back.');
        } catch (\Throwable $e) {
            $tx->rollBack();
            $this->fail('Operational provider evidence fixture failed: ' . $e->getMessage());
        }
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
