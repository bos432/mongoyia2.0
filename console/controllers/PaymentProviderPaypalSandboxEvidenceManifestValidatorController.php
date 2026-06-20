<?php

namespace console\controllers;

use common\services\mall\PaypalSandboxEvidenceManifestValidatorService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalSandboxEvidenceManifestValidatorController extends Controller
{
    public $outputDir = '';
    public $manifestPath = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'outputDir',
            'manifestPath',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia payment provider PayPal sandbox evidence manifest validator\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $rows = $this->manifestPath !== '' ? $this->readManifestRows($this->manifestPath) : null;
            $source = $this->manifestPath !== '' ? $this->manifestPath : 'fixture';
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
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceManifestValidatorService.php', [
            'class PaypalSandboxEvidenceManifestValidatorService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1',
            'paypal_sandbox_evidence_manifest_validator_dry_run_no_import',
            'validateManifestRows',
            'artifact_sha256',
            'redaction_status',
        ]);
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceSignoffGateService.php', [
            'class PaypalSandboxEvidenceSignoffGateService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1',
            'signoff_ready=0',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalSandboxEvidenceManifestValidatorController.php', [
            'class PaymentProviderPaypalSandboxEvidenceManifestValidatorController',
            'Mongoyia payment provider PayPal sandbox evidence manifest validator',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1',
            'PayPal Sandbox Evidence Manifest Validator',
            'manifest_accepted=0',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1',
            'PayPal Sandbox Evidence Manifest Validator',
            'artifact_sha256',
            'redaction_status',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalSandboxEvidenceManifestValidator',
            'PayPal sandbox evidence manifest validator Phase 6 closure',
            'payment-provider-paypal-sandbox-evidence-manifest-validator/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalSandboxEvidenceManifestValidatorController.php',
            'PaypalSandboxEvidenceManifestValidatorService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalSandboxEvidenceManifestValidatorPath',
            'mongoyia-payment-provider-paypal-sandbox-evidence-manifest-validator-*.md',
            'Payment provider PayPal sandbox evidence manifest validator result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-sandbox-evidence-manifest-validator/run',
            'mongoyia-payment-provider-paypal-sandbox-evidence-manifest-validator-*.md',
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

            $this->assertTotal($report, 'manifest_row_count', 11, 'PayPal sandbox manifest validator has eleven manifest rows.');
            $this->assertTotal($report, 'manifest_field_count', 9, 'PayPal sandbox manifest validator has nine manifest fields.');
            $this->assertTotal($report, 'required_case_count', 11, 'PayPal sandbox manifest validator has eleven required case keys.');
            $this->assertTotal($report, 'valid_row_count', 11, 'All fixture manifest rows pass validation.');
            $this->assertTotal($report, 'invalid_row_count', 0, 'No fixture manifest rows fail validation.');
            $this->assertTotal($report, 'missing_case_count', 0, 'No required PayPal sandbox evidence case is missing.');
            $this->assertTotal($report, 'duplicate_case_count', 0, 'No duplicate PayPal sandbox evidence case exists.');
            $this->assertTotal($report, 'unknown_case_count', 0, 'No unknown PayPal sandbox evidence case exists.');
            $this->assertTotal($report, 'secret_marker_count', 0, 'No secret-like marker exists in the manifest.');
            $this->assertTotal($report, 'imported_artifact_count', 0, 'PayPal sandbox manifest validator does not import artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal sandbox manifest validator does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal sandbox manifest validator does not write rows.');
            $this->assertTotal($report, 'validator_ready', 1, 'PayPal sandbox manifest validator is ready.');
            $this->assertTotal($report, 'manifest_accepted', 0, 'PayPal sandbox manifest is not accepted by this dry-run gate.');
            $this->assertTotal($report, 'signoff_ready', 0, 'PayPal sandbox evidence signoff remains not ready.');
            $this->assertGateStatus($report, 'manifest_validation', 'pass', 'PayPal sandbox manifest validation is PASS.');
            $this->assertGateStatus($report, 'sandbox_evidence_signoff_gate_report', 'pass', 'PayPal sandbox evidence signoff gate report is PASS.');
            $this->assertGateStatus($report, 'manifest_validator_documentation', 'ready', 'PayPal sandbox manifest validator documentation is ready.');
            $this->assertGateStatus($report, 'manifest_schema_contract', 'ready', 'PayPal sandbox manifest schema contract is ready.');
            $this->assertGateStatus($report, 'artifact_import', 'disabled', 'Artifact import remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Sandbox Evidence Manifest Validator',
                '- Result: PASS',
                '- Validator ready: yes',
                '- Manifest accepted: no',
                '| invalid_row_count | 0 |',
                'Referenced artifacts are not read, copied, imported, hashed, or stored by this command.',
            ]);
            $this->assertFileContains($paths['csv'], [
                'row,case_key,status,artifact_ref,artifact_sha256,redaction_status,reviewer,reviewed_at,environment_host,validation_status,issues',
                'sandbox_credentials_reference',
                'cleanup_evidence',
                ',pass,',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal sandbox evidence manifest validator generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal sandbox evidence manifest validator fixture failed: ' . $e->getMessage());
        }
    }

    private function readManifestRows(string $path): array
    {
        $fullPath = Yii::getAlias($path);
        if (!is_file($fullPath)) {
            $this->fail("Manifest file {$path} not found.");
            return [];
        }
        $content = trim((string)file_get_contents($fullPath));
        if ($content === '') {
            return [];
        }
        if (strpos($content, '[') === 0) {
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                $this->fail("Manifest JSON {$path} is invalid.");
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-sandbox-evidence-manifest-validator-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalSandboxEvidenceManifestValidatorService
    {
        return new PaypalSandboxEvidenceManifestValidatorService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal sandbox evidence manifest validator.');
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
        $this->ok("File keeps PayPal sandbox evidence manifest validator boundary disabled: {$path}");
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
