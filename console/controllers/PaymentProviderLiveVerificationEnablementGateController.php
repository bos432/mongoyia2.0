<?php

namespace console\controllers;

use common\services\mall\PaypalLiveVerificationEnablementGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderLiveVerificationEnablementGateController extends Controller
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
        $this->stdout("Mongoyia payment provider live verification enablement gate\n");
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
        $this->requireFileContains('common/services/mall/PaypalLiveVerificationEnablementGateService.php', [
            'class PaypalLiveVerificationEnablementGateService',
            'MONGOYIA_PAYPAL_LIVE_VERIFICATION_ENABLEMENT_GATE_V1',
            'paypal_live_verification_enablement_readiness_disabled_by_default',
            'enablement_allowed=false',
        ]);
        $this->requireFileContains('common/services/mall/PaypalWebhookVerificationDryRunService.php', [
            'class PaypalWebhookVerificationDryRunService',
            'MONGOYIA_PAYPAL_WEBHOOK_VERIFICATION_DRY_RUN_GATE_V1',
        ]);
        $this->requireFileContains('common/services/mall/PaypalWebhookAuditDryRunService.php', [
            'class PaypalWebhookAuditDryRunService',
            'MONGOYIA_PAYPAL_WEBHOOK_AUDIT_DRY_RUN_V1',
        ]);
        $this->requireFileContains('common/services/mall/PaypalExternalEvidenceManifestReviewResultApplyGateService.php', [
            'class PaypalExternalEvidenceManifestReviewResultApplyGateService',
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_RESULT_APPLY_GATE_V1',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveProviderImplementationEvidenceDryRunService.php', [
            'class PaypalLiveProviderImplementationEvidenceDryRunService',
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveProviderImplementationEvidenceSignoffGateService.php', [
            'class PaypalLiveProviderImplementationEvidenceSignoffGateService',
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveExecutionEvidenceReadinessGateService.php', [
            'class PaypalLiveExecutionEvidenceReadinessGateService',
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveExecutionEvidenceSignoffImportDryRunService.php', [
            'class PaypalLiveExecutionEvidenceSignoffImportDryRunService',
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderLiveVerificationEnablementGateController.php', [
            'class PaymentProviderLiveVerificationEnablementGateController',
            'Mongoyia payment provider live verification enablement gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_LIVE_VERIFICATION_ENABLEMENT_GATE_V1',
            'PayPal Live Verification Enablement Gate',
            'enablement_allowed=false',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderLiveVerificationEnablementGate',
            'PayPal live verification enablement gate Phase 6 closure',
            'payment-provider-live-verification-enablement-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderLiveVerificationEnablementGateController.php',
            'PaypalLiveVerificationEnablementGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderLiveVerificationEnablementGatePath',
            'mongoyia-payment-provider-live-verification-enablement-gate-*.md',
            'Payment provider live verification enablement gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-live-verification-enablement-gate/run',
            'mongoyia-payment-provider-live-verification-enablement-gate-*.md',
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

            $this->assertTotal($report, 'precondition_count', 15, 'PayPal live verification enablement gate has fifteen preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 13, 'Thirteen PayPal live verification preconditions are currently satisfied.');
            $this->assertTotal($report, 'unsatisfied_precondition_count', 2, 'Two PayPal live verification preconditions remain pending.');
            $this->assertTotal($report, 'pending_precondition_count', 2, 'Sandbox and live production evidence remain pending.');
            $this->assertTotal($report, 'evidence_pass_count', 8, 'All eight PayPal evidence reports are PASS.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal enablement gate does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal enablement gate does not write rows.');
            $this->assertTotal($report, 'enablement_allowed', 0, 'PayPal live verification enablement remains blocked.');
            $this->assertGateStatus($report, 'paypal_enabled_flag', 'disabled', 'PAYPAL_ENABLED remains false.');
            $this->assertGateStatus($report, 'external_manifest_review_result_apply_gate_evidence', 'pass', 'PayPal external manifest review-result apply gate evidence is PASS.');
            $this->assertGateStatus($report, 'live_provider_implementation_evidence_dry_run', 'pass', 'PayPal live provider implementation evidence dry-run is PASS.');
            $this->assertGateStatus($report, 'live_provider_implementation_evidence_signoff_gate', 'pass', 'PayPal live provider implementation evidence signoff gate is PASS.');
            $this->assertGateStatus($report, 'live_execution_evidence_readiness_gate', 'pass', 'PayPal live execution evidence readiness gate is PASS.');
            $this->assertGateStatus($report, 'live_execution_evidence_signoff_import_dry_run', 'pass', 'PayPal live execution evidence signoff import dry-run is PASS.');
            $this->assertGateStatus($report, 'sandbox_evidence', 'pending', 'PayPal sandbox evidence remains pending.');
            $this->assertGateStatus($report, 'enablement_decision', 'blocked', 'PayPal enablement decision remains blocked.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Live Verification Enablement Gate',
                '- Result: PASS',
                '- Enablement allowed: no',
                '| enablement_allowed | 0 |',
                'enablement_allowed=false is intentional for this increment.',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,satisfied,evidence,required_evidence',
                'sandbox_evidence,pending,0',
                'live_provider_implementation,pending,0',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal live verification enablement gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal live verification enablement gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-live-verification-enablement-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalLiveVerificationEnablementGateService
    {
        return new PaypalLiveVerificationEnablementGateService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal live verification enablement gate.');
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
        $this->ok("File keeps PayPal live verification boundary disabled: {$path}");
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
