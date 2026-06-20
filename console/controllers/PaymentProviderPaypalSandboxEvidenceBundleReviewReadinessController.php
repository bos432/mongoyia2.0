<?php

namespace console\controllers;

use common\services\mall\PaypalSandboxEvidenceBundleReviewReadinessService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalSandboxEvidenceBundleReviewReadinessController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal sandbox evidence bundle review readiness\n");
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
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceBundleReviewReadinessService.php', [
            'class PaypalSandboxEvidenceBundleReviewReadinessService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_READINESS_V1',
            'paypal_sandbox_evidence_bundle_review_readiness_dry_run_no_artifact_access',
            'bundle_review_ready',
            'evidence_bundle_accepted',
        ]);
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceManifestValidatorService.php', [
            'class PaypalSandboxEvidenceManifestValidatorService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1',
            'manifest_accepted',
        ]);
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceRedactionChecklistService.php', [
            'class PaypalSandboxEvidenceRedactionChecklistService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1',
            'evidence_bundle_accepted',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalSandboxEvidenceBundleReviewReadinessController.php', [
            'class PaymentProviderPaypalSandboxEvidenceBundleReviewReadinessController',
            'Mongoyia payment provider PayPal sandbox evidence bundle review readiness',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_READINESS_V1',
            'PayPal Sandbox Evidence Bundle Review Readiness',
            'bundle_review_ready=1',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_READINESS_V1',
            'PayPal Sandbox Evidence Bundle Review Readiness',
            'evidence_bundle_accepted=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalSandboxEvidenceBundleReviewReadiness',
            'PayPal sandbox evidence bundle review readiness Phase 6 closure',
            'payment-provider-paypal-sandbox-evidence-bundle-review-readiness/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalSandboxEvidenceBundleReviewReadinessController.php',
            'PaypalSandboxEvidenceBundleReviewReadinessService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalSandboxEvidenceBundleReviewReadinessPath',
            'mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-readiness-*.md',
            'Payment provider PayPal sandbox evidence bundle review readiness result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-sandbox-evidence-bundle-review-readiness/run',
            'mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-readiness-*.md',
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

            $this->assertTotal($report, 'review_item_count', 10, 'PayPal bundle review has ten readiness items.');
            $this->assertTotal($report, 'ready_review_item_count', 10, 'All PayPal bundle review items are ready.');
            $this->assertTotal($report, 'precondition_count', 7, 'PayPal bundle review has seven preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 7, 'All PayPal bundle review local preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 1, 'PayPal bundle review keeps one external evidence pending marker.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'PayPal bundle review does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'PayPal bundle review does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'PayPal bundle review does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal bundle review does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal bundle review does not write rows.');
            $this->assertTotal($report, 'bundle_review_ready', 1, 'PayPal bundle review readiness is ready.');
            $this->assertTotal($report, 'evidence_bundle_accepted', 0, 'PayPal evidence bundle is not accepted by this dry-run gate.');
            $this->assertGateStatus($report, 'manifest_validator_report', 'pass', 'PayPal manifest validator report is PASS.');
            $this->assertGateStatus($report, 'redaction_checklist_report', 'pass', 'PayPal redaction checklist report is PASS.');
            $this->assertGateStatus($report, 'bundle_review_documentation', 'ready', 'PayPal bundle review documentation is ready.');
            $this->assertGateStatus($report, 'bundle_review_contract', 'ready', 'PayPal bundle review contract is ready.');
            $this->assertGateStatus($report, 'bundle_review_ready', 'ready', 'PayPal bundle review gate is ready.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Sandbox Evidence Bundle Review Readiness',
                '- Result: PASS',
                '- Bundle review ready: yes',
                '- Evidence bundle accepted: no',
                '| bundle_review_ready | 1 |',
                'bundle_review_ready=1 means the local review process is ready',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,owner_role,required_evidence',
                'manifest_validator_pass,ready,technical',
                'final_signoff_pending,ready,business',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal sandbox evidence bundle review readiness generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal sandbox evidence bundle review readiness fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-sandbox-evidence-bundle-review-readiness-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalSandboxEvidenceBundleReviewReadinessService
    {
        return new PaypalSandboxEvidenceBundleReviewReadinessService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal sandbox evidence bundle review readiness.');
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
        $this->ok("File keeps PayPal sandbox evidence bundle review readiness boundary disabled: {$path}");
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
