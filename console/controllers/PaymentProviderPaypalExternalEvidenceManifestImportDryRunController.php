<?php

namespace console\controllers;

use common\services\mall\PaypalExternalEvidenceManifestImportDryRunService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalExternalEvidenceManifestImportDryRunController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal external evidence manifest import dry-run\n");
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
        $this->requireFileContains('common/services/mall/PaypalExternalEvidenceManifestImportDryRunService.php', [
            'class PaypalExternalEvidenceManifestImportDryRunService',
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_IMPORT_DRY_RUN_V1',
            'paypal_external_evidence_manifest_import_dry_run_read_only_no_artifact_access',
            'manifest_import_executed',
            'paypal_enablement_allowed',
        ]);
        $this->requireFileContains('common/services/mall/PaypalExternalEvidenceCollectionGateService.php', [
            'class PaypalExternalEvidenceCollectionGateService',
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_COLLECTION_GATE_V1',
            'external_collection_started',
            'paypal_enablement_allowed',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalExternalEvidenceManifestImportDryRunController.php', [
            'class PaymentProviderPaypalExternalEvidenceManifestImportDryRunController',
            'Mongoyia payment provider PayPal external evidence manifest import dry-run',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_IMPORT_DRY_RUN_V1',
            'PayPal External Evidence Manifest Import Dry Run',
            'manifest_import_executed=0',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_IMPORT_DRY_RUN_V1',
            'PayPal External Evidence Manifest Import Dry Run',
            'paypal_enablement_allowed=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalExternalEvidenceManifestImportDryRun',
            'PayPal external evidence manifest import dry-run Phase 6 closure',
            'payment-provider-paypal-external-evidence-manifest-import-dry-run/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalExternalEvidenceManifestImportDryRunController.php',
            'PaypalExternalEvidenceManifestImportDryRunService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalExternalEvidenceManifestImportDryRunPath',
            'mongoyia-payment-provider-paypal-external-evidence-manifest-import-dry-run-*.md',
            'Payment provider PayPal external evidence manifest import dry-run result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-external-evidence-manifest-import-dry-run/run',
            'mongoyia-payment-provider-paypal-external-evidence-manifest-import-dry-run-*.md',
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

            $this->assertTotal($report, 'manifest_row_count', 11, 'PayPal manifest import dry-run has eleven manifest rows.');
            $this->assertTotal($report, 'valid_manifest_row_count', 11, 'All PayPal manifest import dry-run rows are valid.');
            $this->assertTotal($report, 'required_case_count', 11, 'PayPal manifest import dry-run has eleven required cases.');
            $this->assertTotal($report, 'covered_required_case_count', 11, 'PayPal manifest import dry-run covers all required cases.');
            $this->assertTotal($report, 'precondition_count', 7, 'PayPal manifest import dry-run has seven preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 7, 'All PayPal manifest import dry-run preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 5, 'PayPal manifest import dry-run keeps five external pending markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'PayPal manifest import dry-run does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'PayPal manifest import dry-run does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'PayPal manifest import dry-run does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal manifest import dry-run does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal manifest import dry-run does not write rows.');
            $this->assertTotal($report, 'manifest_input_valid', 1, 'PayPal external evidence manifest input is valid.');
            $this->assertTotal($report, 'manifest_import_allowed', 0, 'PayPal manifest import is not allowed by this dry-run.');
            $this->assertTotal($report, 'manifest_import_executed', 0, 'PayPal manifest import is not executed by this dry-run.');
            $this->assertTotal($report, 'evidence_bundle_accepted', 0, 'PayPal evidence bundle is not accepted by this dry-run.');
            $this->assertTotal($report, 'paypal_enablement_allowed', 0, 'PayPal enablement is not allowed by this dry-run.');
            $this->assertGateStatus($report, 'external_collection_gate_report', 'pass', 'PayPal external collection gate report is PASS.');
            $this->assertGateStatus($report, 'manifest_import_documentation', 'ready', 'PayPal manifest import documentation is ready.');
            $this->assertGateStatus($report, 'manifest_import_rows', 'valid', 'PayPal manifest import rows are valid.');
            $this->assertGateStatus($report, 'acceptance_wiring', 'ready', 'PayPal manifest import acceptance wiring is ready.');
            $this->assertGateStatus($report, 'manifest_input_valid', 'ready', 'PayPal manifest input validation gate is ready.');
            $this->assertGateStatus($report, 'manifest_import', 'disabled', 'PayPal manifest import remains disabled.');
            $this->assertGateStatus($report, 'evidence_bundle_acceptance', 'pending', 'PayPal evidence bundle acceptance remains pending.');
            $this->assertGateStatus($report, 'paypal_enablement', 'disabled', 'PayPal enablement remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal External Evidence Manifest Import Dry Run',
                '- Result: PASS',
                '- Manifest input valid: yes',
                '- Manifest import allowed: no',
                '- Manifest import executed: no',
                '- Evidence bundle accepted: no',
                '- PayPal enablement allowed: no',
                '| manifest_input_valid | 1 |',
                'manifest_import_executed=0 remains intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'case_key,collection_ref,test_host,artifact_ref,artifact_sha256,redaction_status,collection_status,collected_at,collector_role,cleanup_ref,ticket_ref,notes',
                'sandbox_credential_reference,collection-ref:PAYPAL-SBX-001',
                'generated_test_data_cleanup,collection-ref:PAYPAL-SBX-011',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal external evidence manifest import dry-run generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal external evidence manifest import dry-run fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-external-evidence-manifest-import-dry-run-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalExternalEvidenceManifestImportDryRunService
    {
        return new PaypalExternalEvidenceManifestImportDryRunService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal external evidence manifest import dry-run.');
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
        $this->ok("File keeps PayPal external evidence manifest import boundary disabled: {$path}");
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
