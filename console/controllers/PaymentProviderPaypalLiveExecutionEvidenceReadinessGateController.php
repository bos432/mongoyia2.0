<?php

namespace console\controllers;

use common\services\mall\PaypalLiveExecutionEvidenceReadinessGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalLiveExecutionEvidenceReadinessGateController extends Controller
{
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia payment provider PayPal live execution evidence readiness gate\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = $this->service()->run();
            $this->recordReportIssues($report);
            $paths = $this->writeExport($report, false);
            $this->stdout("Markdown: {$paths['md']}\nCSV: {$paths['csv']}\n");
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles(): void
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/PaypalLiveExecutionEvidenceReadinessGateService.php', [
            'class PaypalLiveExecutionEvidenceReadinessGateService',
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1',
            'paypal_live_execution_evidence_readiness_read_only_no_provider_no_artifact_access',
            'real_sandbox_live_evidence_ready',
            'paypal_enablement_allowed',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveProviderImplementationEvidenceSignoffGateService.php', [
            'class PaypalLiveProviderImplementationEvidenceSignoffGateService',
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalLiveExecutionEvidenceReadinessGateController.php', [
            'class PaymentProviderPaypalLiveExecutionEvidenceReadinessGateController',
            'Mongoyia payment provider PayPal live execution evidence readiness gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1',
            'PayPal Live Execution Evidence Readiness Gate',
            'real_sandbox_live_evidence_ready=1',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1',
            'PayPal Live Execution Evidence Readiness Gate',
            'sandbox_execution_evidence_accepted=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalLiveExecutionEvidenceReadinessGate',
            'PayPal live execution evidence readiness gate Phase 6 closure',
            'payment-provider-paypal-live-execution-evidence-readiness-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalLiveExecutionEvidenceReadinessGateController.php',
            'PaypalLiveExecutionEvidenceReadinessGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalLiveExecutionEvidenceReadinessGatePath',
            'mongoyia-payment-provider-paypal-live-execution-evidence-readiness-gate-*.md',
            'Payment provider PayPal live execution evidence readiness gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-live-execution-evidence-readiness-gate/run',
            'mongoyia-payment-provider-paypal-live-execution-evidence-readiness-gate-*.md',
        ]);
    }

    private function checkRuntimeBoundary(): void
    {
        $this->section('Runtime boundary');
        $this->requireFileContains('frontend/modules/mall/controllers/PaymentController.php', [
            'public function actionPaypalWebhook',
            'PAYPAL_DISABLED',
            'paypalDisabledRoute',
            "env('PAYPAL_ENABLED'",
        ]);
        $this->requireFileNotContains('frontend/modules/mall/controllers/PaymentController.php', [
            'PAYPAL_CLIENT_SECRET',
            'PAYPAL_WEBHOOK_ID',
            'api-m.paypal.com',
            'api-m.sandbox.paypal.com',
        ]);
        $this->requireFileNotContains('web/resources/mall/default/views/payment/index.php', [
            '/mall/payment/paypal',
            'Pay with PayPal',
            'PAYPAL_CLIENT_ID',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = $this->service()->run();

            $this->assertTotal($report, 'evidence_row_count', 12, 'PayPal live execution evidence readiness has twelve checklist rows.');
            $this->assertTotal($report, 'sandbox_evidence_row_count', 8, 'Eight PayPal sandbox execution evidence rows are covered.');
            $this->assertTotal($report, 'live_evidence_row_count', 4, 'Four PayPal live production readiness evidence rows are covered.');
            $this->assertTotal($report, 'valid_evidence_row_count', 12, 'All PayPal live execution evidence rows are valid safe references.');
            $this->assertTotal($report, 'precondition_count', 7, 'PayPal live execution evidence readiness gate has seven preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 7, 'All PayPal live execution evidence readiness preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 5, 'PayPal live execution evidence readiness keeps five external pending markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'PayPal live execution evidence readiness does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'PayPal live execution evidence readiness does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'PayPal live execution evidence readiness does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal live execution evidence readiness does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal live execution evidence readiness does not write rows.');
            $this->assertTotal($report, 'real_sandbox_live_evidence_ready', 1, 'PayPal sandbox/live evidence checklist is ready.');
            $this->assertTotal($report, 'evidence_collection_started', 0, 'PayPal evidence collection is not started by this gate.');
            $this->assertTotal($report, 'sandbox_execution_evidence_accepted', 0, 'PayPal sandbox execution evidence is not accepted by this gate.');
            $this->assertTotal($report, 'live_production_evidence_accepted', 0, 'PayPal live production evidence is not accepted by this gate.');
            $this->assertTotal($report, 'paypal_enablement_allowed', 0, 'PayPal enablement is not allowed by this gate.');
            $this->assertGateStatus($report, 'live_provider_implementation_evidence_signoff_gate_report', 'pass', 'PayPal implementation evidence signoff gate report is PASS.');
            $this->assertGateStatus($report, 'live_execution_evidence_documentation', 'ready', 'PayPal live execution evidence readiness documentation is ready.');
            $this->assertGateStatus($report, 'live_execution_evidence_checklist', 'ready', 'PayPal live execution evidence checklist is ready.');
            $this->assertGateStatus($report, 'acceptance_wiring', 'ready', 'PayPal live execution evidence readiness acceptance wiring is ready.');
            $this->assertGateStatus($report, 'real_sandbox_live_evidence_ready', 'ready', 'PayPal live execution evidence readiness gate is ready.');
            $this->assertGateStatus($report, 'evidence_collection_start', 'disabled', 'PayPal evidence collection remains disabled.');
            $this->assertGateStatus($report, 'sandbox_execution_evidence_acceptance', 'pending', 'PayPal sandbox execution evidence acceptance remains pending.');
            $this->assertGateStatus($report, 'live_production_evidence_acceptance', 'pending', 'PayPal live production evidence acceptance remains pending.');
            $this->assertGateStatus($report, 'paypal_enablement', 'disabled', 'PayPal enablement remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Live Execution Evidence Readiness Gate',
                '- Result: PASS',
                '- Real sandbox/live evidence ready: yes',
                '- Evidence collection started: no',
                '- Sandbox execution evidence accepted: no',
                '- Live production evidence accepted: no',
                '| real_sandbox_live_evidence_ready | 1 |',
                'real_sandbox_live_evidence_ready=1 means the redacted evidence checklist is ready',
            ]);
            $this->assertFileContains($paths['csv'], [
                'evidence_key,evidence_scope,owner_role,host_ref,evidence_ref,ticket_ref,cleanup_ref,readiness_status,redaction_status,artifact_access_allowed,provider_call_allowed,write_allowed,required_evidence',
                'sandbox_checkout_success_ref,sandbox,business',
                'live_cutover_rollback_signoff_ref,live,business',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal live execution evidence readiness gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal live execution evidence readiness gate fixture failed: ' . $e->getMessage());
        }
    }

    private function writeExport(array $report, bool $fixture): array
    {
        $dir = (string)$this->outputDir !== ''
            ? Yii::getAlias((string)$this->outputDir)
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'handover';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stamp = date('Ymd-His') . ($fixture ? '-fixture-' . mt_rand(1000, 9999) : '');
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-live-execution-evidence-readiness-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalLiveExecutionEvidenceReadinessGateService
    {
        return new PaypalLiveExecutionEvidenceReadinessGateService(dirname(__DIR__, 2));
    }

    private function businessTableCounts(): array
    {
        $counts = [];
        foreach ([
            '{{%mall_order}}',
            '{{%mall_order_product}}',
            '{{%mall_payment_attempt}}',
            '{{%base_message}}',
            '{{%chat_message}}',
            '{{%base_fund_log}}',
            '{{%mall_customer_service_ticket}}',
            '{{%mall_customer_service_event}}',
            '{{%mall_customer_service_stat_daily}}',
        ] as $table) {
            if (Yii::$app->db->schema->getTableSchema($table, true) === null) {
                continue;
            }
            $counts[$table] = (int)(new \yii\db\Query())->from($table)->count('*', Yii::$app->db);
        }

        return $counts;
    }

    private function assertBusinessCountsUnchanged(array $before): void
    {
        foreach ($before as $table => $expected) {
            $actual = (int)(new \yii\db\Query())->from($table)->count('*', Yii::$app->db);
            if ($actual !== $expected) {
                $this->fail("Business table {$table} changed. Expected {$expected}, got {$actual}.");
                return;
            }
        }
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal live execution evidence readiness gate.');
    }

    private function assertTotal(array $report, string $key, int $expected, string $message): void
    {
        $actual = (int)($report['totals'][$key] ?? -1);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertGateStatus(array $report, string $key, string $expected, string $message): void
    {
        foreach (($report['gateChecks'] ?? []) as $check) {
            if ((string)$check['key'] !== $key) {
                continue;
            }
            if ((string)$check['status'] !== $expected) {
                $this->fail("{$message} Expected {$expected}, got {$check['status']}.");
                return;
            }
            $this->ok($message);
            return;
        }
        $this->fail("{$message} Gate {$key} missing.");
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $fullPath = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function requireFileNotContains(string $path, array $needles): void
    {
        $fullPath = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                $this->fail("File {$path} should not contain '{$needle}'.");
                return;
            }
        }
        $this->ok("File keeps PayPal live execution evidence readiness boundary disabled: {$path}");
    }

    private function assertFileContains(string $path, array $needles): void
    {
        if (!is_file($path)) {
            $this->fail("Missing export file {$path}.");
            return;
        }
        $content = (string)file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("Export file {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("Export file contains required markers: {$path}");
    }

    private function recordReportIssues(array $report): void
    {
        foreach (($report['issues'] ?? []) as $issue) {
            $this->warnings++;
            $this->stdout("WARN {$issue}\n");
        }
    }

    private function section(string $label): void
    {
        $this->stdout("\n[{$label}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stdout("FAIL {$message}\n");
    }
}
