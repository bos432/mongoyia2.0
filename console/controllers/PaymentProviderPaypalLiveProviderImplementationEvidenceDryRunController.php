<?php

namespace console\controllers;

use common\services\mall\PaypalLiveProviderImplementationEvidenceDryRunService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalLiveProviderImplementationEvidenceDryRunController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal live provider implementation evidence dry-run\n");
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
        $this->requireFileContains('common/services/mall/PaypalLiveProviderImplementationEvidenceDryRunService.php', [
            'class PaypalLiveProviderImplementationEvidenceDryRunService',
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1',
            'paypal_live_provider_implementation_evidence_dry_run_no_runtime_enablement_no_persistence',
            'live_provider_implementation_ready',
            'paypal_enablement_allowed',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveAuditWriteImplementationGateService.php', [
            'class PaypalLiveAuditWriteImplementationGateService',
            'MONGOYIA_PAYPAL_LIVE_AUDIT_WRITE_IMPLEMENTATION_GATE_V1',
        ]);
        $this->requireFileContains('common/services/mall/PaypalExternalEvidenceManifestReviewResultApplyGateService.php', [
            'class PaypalExternalEvidenceManifestReviewResultApplyGateService',
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_RESULT_APPLY_GATE_V1',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalLiveProviderImplementationEvidenceDryRunController.php', [
            'class PaymentProviderPaypalLiveProviderImplementationEvidenceDryRunController',
            'Mongoyia payment provider PayPal live provider implementation evidence dry-run',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1',
            'PayPal Live Provider Implementation Evidence Dry Run',
            'live_provider_implementation_evidence_applied=0',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1',
            'PayPal Live Provider Implementation Evidence Dry Run',
            'live_provider_implementation_ready=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalLiveProviderImplementationEvidenceDryRun',
            'PayPal live provider implementation evidence dry-run Phase 6 closure',
            'payment-provider-paypal-live-provider-implementation-evidence-dry-run/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalLiveProviderImplementationEvidenceDryRunController.php',
            'PaypalLiveProviderImplementationEvidenceDryRunService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalLiveProviderImplementationEvidenceDryRunPath',
            'mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-*.md',
            'Payment provider PayPal live provider implementation evidence dry-run result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-live-provider-implementation-evidence-dry-run/run',
            'mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-*.md',
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

            $this->assertTotal($report, 'implementation_plan_row_count', 12, 'PayPal live provider implementation evidence dry-run has twelve plan rows.');
            $this->assertTotal($report, 'valid_implementation_plan_row_count', 12, 'All PayPal live provider implementation plan rows are valid.');
            $this->assertTotal($report, 'precondition_count', 8, 'PayPal live provider implementation evidence dry-run has eight preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 8, 'All PayPal live provider implementation evidence dry-run preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 4, 'PayPal live provider implementation evidence keeps four external pending markers.');
            $this->assertTotal($report, 'runtime_enablement_count', 0, 'PayPal live provider implementation evidence does not enable runtime.');
            $this->assertTotal($report, 'provider_call_count', 0, 'PayPal live provider implementation evidence does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal live provider implementation evidence does not write rows.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'PayPal live provider implementation evidence does not read artifacts.');
            $this->assertTotal($report, 'live_provider_implementation_evidence_valid', 1, 'PayPal live provider implementation evidence plan is valid.');
            $this->assertTotal($report, 'live_provider_implementation_evidence_applied', 0, 'PayPal live provider implementation evidence is not applied.');
            $this->assertTotal($report, 'live_provider_implementation_ready', 0, 'PayPal live provider implementation is not ready.');
            $this->assertTotal($report, 'paypal_enablement_allowed', 0, 'PayPal enablement is not allowed by this dry-run.');
            $this->assertGateStatus($report, 'live_audit_write_implementation_gate_report', 'pass', 'PayPal live audit write implementation gate report is PASS.');
            $this->assertGateStatus($report, 'external_manifest_review_result_apply_gate_report', 'pass', 'PayPal external manifest review-result apply gate report is PASS.');
            $this->assertGateStatus($report, 'live_provider_implementation_evidence_documentation', 'ready', 'PayPal live provider implementation evidence documentation is ready.');
            $this->assertGateStatus($report, 'live_provider_implementation_plan', 'valid', 'PayPal live provider implementation evidence plan is valid.');
            $this->assertGateStatus($report, 'acceptance_wiring', 'ready', 'PayPal live provider implementation evidence acceptance wiring is ready.');
            $this->assertGateStatus($report, 'live_provider_implementation_evidence_valid', 'ready', 'PayPal live provider implementation evidence validation gate is ready.');
            $this->assertGateStatus($report, 'live_provider_implementation_application', 'disabled', 'PayPal live provider implementation application remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');
            $this->assertGateStatus($report, 'paypal_enablement', 'disabled', 'PayPal enablement remains disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Live Provider Implementation Evidence Dry Run',
                '- Result: PASS',
                '- Live provider implementation evidence valid: yes',
                '- Live provider implementation evidence applied: no',
                '- Live provider implementation ready: no',
                '- PayPal enablement allowed: no',
                '| live_provider_implementation_evidence_valid | 1 |',
                'live_provider_implementation_ready=0 remains intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,area,status,runtime_enabled,write_enabled,provider_call_enabled,evidence_ref,requirement',
                'feature_flag_boundary,runtime,planned,0,0,0',
                'acceptance_regression_contract,acceptance,planned,0,0,0',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal live provider implementation evidence dry-run generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal live provider implementation evidence dry-run fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalLiveProviderImplementationEvidenceDryRunService
    {
        return new PaypalLiveProviderImplementationEvidenceDryRunService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal live provider implementation evidence dry-run.');
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
        $this->ok("File keeps PayPal live provider implementation evidence boundary disabled: {$path}");
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
