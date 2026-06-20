<?php

namespace console\controllers;

use common\services\mall\PaypalLiveExecutionEvidenceSignoffImportDryRunService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal live execution evidence signoff import dry-run\n");
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
        $this->requireFileContains('common/services/mall/PaypalLiveExecutionEvidenceSignoffImportDryRunService.php', [
            'class PaypalLiveExecutionEvidenceSignoffImportDryRunService',
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1',
            'paypal_live_execution_evidence_signoff_import_dry_run_no_persistence_no_artifact_access',
            'live_execution_signoff_input_valid',
            'paypal_enablement_allowed',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveExecutionEvidenceReadinessGateService.php', [
            'class PaypalLiveExecutionEvidenceReadinessGateService',
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1',
            'real_sandbox_live_evidence_ready',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunController.php', [
            'class PaymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunController',
            'Mongoyia payment provider PayPal live execution evidence signoff import dry-run',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1',
            'PayPal Live Execution Evidence Signoff Import Dry Run',
            'live_execution_signoff_input_valid=1',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_SIGNOFF_IMPORT_DRY_RUN_V1',
            'PayPal Live Execution Evidence Signoff Import Dry Run',
            'signoff_import_applied=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRun',
            'PayPal live execution evidence signoff import dry-run Phase 6 closure',
            'payment-provider-paypal-live-execution-evidence-signoff-import-dry-run/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunController.php',
            'PaypalLiveExecutionEvidenceSignoffImportDryRunService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalLiveExecutionEvidenceSignoffImportDryRunPath',
            'mongoyia-payment-provider-paypal-live-execution-evidence-signoff-import-dry-run-*.md',
            'Payment provider PayPal live execution evidence signoff import dry-run result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-live-execution-evidence-signoff-import-dry-run/run',
            'mongoyia-payment-provider-paypal-live-execution-evidence-signoff-import-dry-run-*.md',
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

            $this->assertTotal($report, 'template_field_count', 12, 'PayPal live execution signoff import template has twelve fields.');
            $this->assertTotal($report, 'fixture_row_count', 4, 'PayPal live execution signoff import fixture has four reviewer rows.');
            $this->assertTotal($report, 'valid_fixture_row_count', 4, 'All PayPal live execution signoff import fixture rows are valid.');
            $this->assertTotal($report, 'required_role_count', 4, 'PayPal live execution signoff import requires four reviewer roles.');
            $this->assertTotal($report, 'covered_required_role_count', 4, 'PayPal live execution signoff import covers all reviewer roles.');
            $this->assertTotal($report, 'precondition_count', 8, 'PayPal live execution signoff import dry-run has eight preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 8, 'All PayPal live execution signoff import local preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 4, 'PayPal live execution signoff import keeps four external evidence/signature pending markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'PayPal live execution signoff import dry-run does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'PayPal live execution signoff import dry-run does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'PayPal live execution signoff import dry-run does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal live execution signoff import dry-run does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal live execution signoff import dry-run does not write rows.');
            $this->assertTotal($report, 'live_execution_signoff_input_valid', 1, 'PayPal live execution signoff input is valid.');
            $this->assertTotal($report, 'signoff_import_applied', 0, 'PayPal live execution signoff import is not applied by this dry-run.');
            $this->assertTotal($report, 'sandbox_execution_evidence_accepted', 0, 'PayPal sandbox execution evidence is not accepted by this dry-run.');
            $this->assertTotal($report, 'live_production_evidence_accepted', 0, 'PayPal live production evidence is not accepted by this dry-run.');
            $this->assertTotal($report, 'paypal_enablement_allowed', 0, 'PayPal enablement is not allowed by this dry-run.');
            $this->assertGateStatus($report, 'live_execution_evidence_readiness_gate_report', 'pass', 'PayPal live execution evidence readiness gate report is PASS.');
            $this->assertGateStatus($report, 'live_execution_signoff_import_documentation', 'ready', 'PayPal live execution signoff import documentation is ready.');
            $this->assertGateStatus($report, 'live_execution_signoff_import_template_contract', 'ready', 'PayPal live execution signoff import template contract is ready.');
            $this->assertGateStatus($report, 'live_execution_signoff_import_fixture_rows', 'valid', 'PayPal live execution signoff import fixture rows are valid.');
            $this->assertGateStatus($report, 'acceptance_wiring', 'ready', 'PayPal live execution signoff import acceptance wiring is ready.');
            $this->assertGateStatus($report, 'live_execution_signoff_input_valid', 'ready', 'PayPal live execution signoff input gate is ready.');
            $this->assertGateStatus($report, 'signoff_import_application', 'disabled', 'PayPal live execution signoff import application remains disabled.');
            $this->assertGateStatus($report, 'sandbox_execution_evidence_acceptance', 'pending', 'PayPal sandbox execution evidence acceptance remains pending.');
            $this->assertGateStatus($report, 'live_production_evidence_acceptance', 'pending', 'PayPal live production evidence acceptance remains pending.');
            $this->assertGateStatus($report, 'paypal_enablement', 'disabled', 'PayPal enablement remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Live Execution Evidence Signoff Import Dry Run',
                '- Result: PASS',
                '- Live execution signoff input valid: yes',
                '- Signoff import applied: no',
                '- Sandbox execution evidence accepted: no',
                '- Live production evidence accepted: no',
                '| live_execution_signoff_input_valid | 1 |',
                'signoff_import_applied=0 remains intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'evidence_bundle_id,source_report_ref,environment_scope,reviewer_role,reviewer_ref,decision,reason,reviewed_at,cleanup_ref,rollback_ref,ticket_ref,notes',
                'paypal-live-execution-bundle-TEST-001,report:paypal-live-execution-evidence-readiness-gate,sandbox_live,business',
                'reviewer:ops-owner',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal live execution evidence signoff import dry-run generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal live execution evidence signoff import dry-run fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-live-execution-evidence-signoff-import-dry-run-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalLiveExecutionEvidenceSignoffImportDryRunService
    {
        return new PaypalLiveExecutionEvidenceSignoffImportDryRunService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal live execution evidence signoff import dry-run.');
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
        $this->ok("File keeps PayPal live execution signoff import boundary disabled: {$path}");
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
