<?php

namespace console\controllers;

use common\services\mall\PaypalSandboxEvidenceRedactionChecklistService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalSandboxEvidenceRedactionChecklistController extends Controller
{
    public $outputDir = '';
    public $checklistPath = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'outputDir',
            'checklistPath',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia payment provider PayPal sandbox evidence redaction checklist\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $rows = $this->checklistPath !== '' ? $this->readChecklistRows($this->checklistPath) : null;
            $source = $this->checklistPath !== '' ? $this->checklistPath : 'fixture';
            $report = $this->service()->run($rows, $source);
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
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceRedactionChecklistService.php', [
            'class PaypalSandboxEvidenceRedactionChecklistService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1',
            'paypal_sandbox_evidence_redaction_checklist_dry_run_no_artifact_access',
            'validateChecklistRows',
            'evidence_bundle_accepted',
        ]);
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceManifestValidatorService.php', [
            'class PaypalSandboxEvidenceManifestValidatorService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1',
            'manifest_accepted',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalSandboxEvidenceRedactionChecklistController.php', [
            'class PaymentProviderPaypalSandboxEvidenceRedactionChecklistController',
            'Mongoyia payment provider PayPal sandbox evidence redaction checklist',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1',
            'PayPal Sandbox Evidence Redaction Checklist',
            'evidence_bundle_accepted=0',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1',
            'PayPal Sandbox Evidence Redaction Checklist',
            'evidence_bundle_accepted=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalSandboxEvidenceRedactionChecklist',
            'PayPal sandbox evidence redaction checklist Phase 6 closure',
            'payment-provider-paypal-sandbox-evidence-redaction-checklist/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalSandboxEvidenceRedactionChecklistController.php',
            'PaypalSandboxEvidenceRedactionChecklistService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalSandboxEvidenceRedactionChecklistPath',
            'mongoyia-payment-provider-paypal-sandbox-evidence-redaction-checklist-*.md',
            'Payment provider PayPal sandbox evidence redaction checklist result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-sandbox-evidence-redaction-checklist/run',
            'mongoyia-payment-provider-paypal-sandbox-evidence-redaction-checklist-*.md',
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
            $report = $this->service()->run(null, 'fixture');

            $this->assertTotal($report, 'checklist_control_count', 12, 'PayPal redaction checklist has twelve controls.');
            $this->assertTotal($report, 'checklist_field_count', 9, 'PayPal redaction checklist has nine fields.');
            $this->assertTotal($report, 'required_control_count', 12, 'PayPal redaction checklist has twelve required controls.');
            $this->assertTotal($report, 'ready_control_count', 12, 'All fixture redaction controls are ready.');
            $this->assertTotal($report, 'valid_row_count', 12, 'All fixture redaction checklist rows pass validation.');
            $this->assertTotal($report, 'invalid_row_count', 0, 'No fixture redaction checklist row fails validation.');
            $this->assertTotal($report, 'missing_control_count', 0, 'No required redaction control is missing.');
            $this->assertTotal($report, 'duplicate_control_count', 0, 'No duplicate redaction control exists.');
            $this->assertTotal($report, 'unknown_control_count', 0, 'No unknown redaction control exists.');
            $this->assertTotal($report, 'secret_marker_count', 0, 'No raw secret-like marker exists in the redaction checklist.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'PayPal redaction checklist does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'PayPal redaction checklist does not import artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal redaction checklist does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal redaction checklist does not write rows.');
            $this->assertTotal($report, 'checklist_ready', 1, 'PayPal redaction checklist is ready.');
            $this->assertTotal($report, 'evidence_bundle_accepted', 0, 'PayPal evidence bundle is not accepted by this dry-run gate.');
            $this->assertGateStatus($report, 'checklist_validation', 'pass', 'PayPal redaction checklist validation is PASS.');
            $this->assertGateStatus($report, 'manifest_validator_report', 'pass', 'PayPal manifest validator report is PASS.');
            $this->assertGateStatus($report, 'redaction_checklist_documentation', 'ready', 'PayPal redaction checklist documentation is ready.');
            $this->assertGateStatus($report, 'redaction_control_contract', 'ready', 'PayPal redaction control contract is ready.');
            $this->assertGateStatus($report, 'checklist_schema_contract', 'ready', 'PayPal redaction checklist schema is ready.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Sandbox Evidence Redaction Checklist',
                '- Result: PASS',
                '- Checklist ready: yes',
                '- Evidence bundle accepted: no',
                '| evidence_bundle_accepted | 0 |',
                'The checklist does not read, copy, hash, import, or store evidence artifacts.',
            ]);
            $this->assertFileContains($paths['csv'], [
                'row,control_key,status,redaction_scope,required_redaction,allowed_evidence,forbidden_markers,reviewer,reviewed_at,validation_status,issues',
                'paypal_client_secret,ready,credentials',
                'internal_network_paths,ready,infrastructure',
                ',pass,',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal sandbox evidence redaction checklist generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal sandbox evidence redaction checklist fixture failed: ' . $e->getMessage());
        }
    }

    private function readChecklistRows(string $path): array
    {
        $fullPath = Yii::getAlias($path);
        if (!is_file($fullPath)) {
            $this->fail("Checklist file {$path} not found.");
            return [];
        }
        $content = trim((string)file_get_contents($fullPath));
        if ($content === '') {
            return [];
        }
        if (strpos($content, '[') === 0) {
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                $this->fail("Checklist JSON {$path} is invalid.");
                return [];
            }
            return $decoded;
        }

        $lines = preg_split('/\r\n|\n|\r/', $content);
        $header = str_getcsv((string)array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if (trim((string)$line) === '') {
                continue;
            }
            $values = str_getcsv((string)$line);
            $rows[] = array_combine($header, array_pad($values, count($header), ''));
        }

        return $rows;
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-sandbox-evidence-redaction-checklist-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalSandboxEvidenceRedactionChecklistService
    {
        return new PaypalSandboxEvidenceRedactionChecklistService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal sandbox evidence redaction checklist.');
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
        $this->ok("File keeps PayPal sandbox evidence redaction checklist boundary disabled: {$path}");
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
