<?php

namespace console\controllers;

use common\services\mall\PaypalSandboxEvidenceReviewResultApplyGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalSandboxEvidenceReviewResultApplyGateController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal sandbox evidence review-result apply gate\n");
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
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceReviewResultApplyGateService.php', [
            'class PaypalSandboxEvidenceReviewResultApplyGateService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1',
            'paypal_sandbox_evidence_review_result_apply_gate_read_only_no_persistence_no_artifact_access',
            'review_result_apply_executed',
            'paypal_enablement_allowed',
        ]);
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceSignoffImportDryRunService.php', [
            'class PaypalSandboxEvidenceSignoffImportDryRunService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1',
            'signoff_import_applied',
            'paypal_enablement_allowed',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalSandboxEvidenceReviewResultApplyGateController.php', [
            'class PaymentProviderPaypalSandboxEvidenceReviewResultApplyGateController',
            'Mongoyia payment provider PayPal sandbox evidence review-result apply gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1',
            'PayPal Sandbox Evidence Review Result Apply Gate',
            'review_result_apply_executed=0',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1',
            'PayPal Sandbox Evidence Review Result Apply Gate',
            'evidence_bundle_accepted=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalSandboxEvidenceReviewResultApplyGate',
            'PayPal sandbox evidence review-result apply gate Phase 6 closure',
            'payment-provider-paypal-sandbox-evidence-review-result-apply-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalSandboxEvidenceReviewResultApplyGateController.php',
            'PaypalSandboxEvidenceReviewResultApplyGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalSandboxEvidenceReviewResultApplyGatePath',
            'mongoyia-payment-provider-paypal-sandbox-evidence-review-result-apply-gate-*.md',
            'Payment provider PayPal sandbox evidence review-result apply gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-sandbox-evidence-review-result-apply-gate/run',
            'mongoyia-payment-provider-paypal-sandbox-evidence-review-result-apply-gate-*.md',
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

            $this->assertTotal($report, 'review_result_row_count', 3, 'PayPal review-result apply gate has three reviewer rows.');
            $this->assertTotal($report, 'valid_review_result_row_count', 3, 'All PayPal review-result rows are valid.');
            $this->assertTotal($report, 'approve_count', 3, 'All PayPal review-result rows are approved in the fixture.');
            $this->assertTotal($report, 'reject_count', 0, 'PayPal review-result apply fixture has no reject rows.');
            $this->assertTotal($report, 'needs_rework_count', 0, 'PayPal review-result apply fixture has no rework rows.');
            $this->assertTotal($report, 'required_role_count', 3, 'PayPal review-result apply requires three reviewer roles.');
            $this->assertTotal($report, 'covered_required_role_count', 3, 'PayPal review-result apply covers all reviewer roles.');
            $this->assertTotal($report, 'apply_plan_row_count', 1, 'PayPal review-result apply gate has one dry-run plan row.');
            $this->assertTotal($report, 'precondition_count', 8, 'PayPal review-result apply gate has eight preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 8, 'All PayPal review-result apply local preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 4, 'PayPal review-result apply gate keeps four external pending markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'PayPal review-result apply gate does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'PayPal review-result apply gate does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'PayPal review-result apply gate does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal review-result apply gate does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal review-result apply gate does not write rows.');
            $this->assertTotal($report, 'review_result_valid', 1, 'PayPal review-result metadata is valid.');
            $this->assertTotal($report, 'review_result_apply_allowed', 0, 'PayPal review-result apply is not allowed by this gate.');
            $this->assertTotal($report, 'review_result_apply_executed', 0, 'PayPal review-result apply is not executed by this gate.');
            $this->assertTotal($report, 'evidence_bundle_accepted', 0, 'PayPal evidence bundle is not accepted by this gate.');
            $this->assertTotal($report, 'paypal_enablement_allowed', 0, 'PayPal enablement is not allowed by this gate.');
            $this->assertGateStatus($report, 'signoff_import_dry_run_report', 'pass', 'PayPal signoff import dry-run report is PASS.');
            $this->assertGateStatus($report, 'review_result_documentation', 'ready', 'PayPal review-result apply documentation is ready.');
            $this->assertGateStatus($report, 'review_result_apply_contract', 'ready', 'PayPal review-result apply contract is ready.');
            $this->assertGateStatus($report, 'review_result_fixture_rows', 'valid', 'PayPal review-result fixture rows are valid.');
            $this->assertGateStatus($report, 'acceptance_wiring', 'ready', 'PayPal review-result apply acceptance wiring is ready.');
            $this->assertGateStatus($report, 'review_result_valid', 'ready', 'PayPal review-result validation gate is ready.');
            $this->assertGateStatus($report, 'review_result_apply', 'disabled', 'PayPal review-result apply remains disabled.');
            $this->assertGateStatus($report, 'evidence_bundle_acceptance', 'pending', 'PayPal evidence bundle acceptance remains pending.');
            $this->assertGateStatus($report, 'paypal_enablement', 'disabled', 'PayPal enablement remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Sandbox Evidence Review Result Apply Gate',
                '- Result: PASS',
                '- Review result valid: yes',
                '- Review result apply allowed: no',
                '- Review result apply executed: no',
                '- Evidence bundle accepted: no',
                '- PayPal enablement allowed: no',
                '| review_result_valid | 1 |',
                'review_result_apply_executed=0 remains intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'bundle_id,review_result_ref,test_host,manifest_ref,cleanup_ref,ticket_ref,artifact_hash_ref,reviewer_role,decision,result_status,result_reason,reviewed_at',
                'paypal-sandbox-bundle-TEST-001,review-result:PAYPAL-SBX-001',
                'technical,approve,ready_for_external_apply',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal sandbox evidence review-result apply gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal sandbox evidence review-result apply gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-sandbox-evidence-review-result-apply-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalSandboxEvidenceReviewResultApplyGateService
    {
        return new PaypalSandboxEvidenceReviewResultApplyGateService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal sandbox evidence review-result apply gate.');
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
        $this->ok("File keeps PayPal sandbox evidence review-result apply boundary disabled: {$path}");
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
