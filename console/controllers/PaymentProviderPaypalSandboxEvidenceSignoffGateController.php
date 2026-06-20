<?php

namespace console\controllers;

use common\services\mall\PaypalSandboxEvidenceSignoffGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalSandboxEvidenceSignoffGateController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal sandbox evidence signoff gate\n");
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
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceSignoffGateService.php', [
            'class PaypalSandboxEvidenceSignoffGateService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1',
            'paypal_sandbox_evidence_signoff_readiness_no_import',
            'signoff_ready=0',
        ]);
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceGateService.php', [
            'class PaypalSandboxEvidenceGateService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1',
            'sandbox_evidence_ready=0',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveAuditWriteImplementationGateService.php', [
            'class PaypalLiveAuditWriteImplementationGateService',
            'MONGOYIA_PAYPAL_LIVE_AUDIT_WRITE_IMPLEMENTATION_GATE_V1',
            'live_audit_write_enabled=0',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalSandboxEvidenceSignoffGateController.php', [
            'class PaymentProviderPaypalSandboxEvidenceSignoffGateController',
            'Mongoyia payment provider PayPal sandbox evidence signoff gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1',
            'PayPal Sandbox Evidence Signoff Gate',
            'signoff_ready=0',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1',
            'artifact_sha256',
            'redaction_status',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalSandboxEvidenceSignoffGate',
            'PayPal sandbox evidence signoff gate Phase 6 closure',
            'payment-provider-paypal-sandbox-evidence-signoff-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalSandboxEvidenceSignoffGateController.php',
            'PaypalSandboxEvidenceSignoffGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalSandboxEvidenceSignoffGatePath',
            'mongoyia-payment-provider-paypal-sandbox-evidence-signoff-gate-*.md',
            'Payment provider PayPal sandbox evidence signoff gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-sandbox-evidence-signoff-gate/run',
            'mongoyia-payment-provider-paypal-sandbox-evidence-signoff-gate-*.md',
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

            $this->assertTotal($report, 'evidence_case_count', 11, 'PayPal sandbox signoff gate has eleven evidence cases.');
            $this->assertTotal($report, 'manifest_field_count', 9, 'PayPal sandbox signoff manifest has nine fields.');
            $this->assertTotal($report, 'precondition_count', 9, 'PayPal sandbox signoff gate has nine preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 8, 'Eight PayPal sandbox signoff preconditions are satisfied.');
            $this->assertTotal($report, 'pending_precondition_count', 1, 'PayPal sandbox signoff remains one external pending precondition.');
            $this->assertTotal($report, 'imported_artifact_count', 0, 'PayPal sandbox signoff gate does not import artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal sandbox signoff gate does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal sandbox signoff gate does not write rows.');
            $this->assertTotal($report, 'sandbox_evidence_ready', 0, 'PayPal sandbox evidence remains not ready.');
            $this->assertTotal($report, 'signoff_ready', 0, 'PayPal sandbox evidence signoff remains not ready.');
            $this->assertGateStatus($report, 'sandbox_evidence_gate_report', 'pass', 'PayPal sandbox evidence gate report is PASS.');
            $this->assertGateStatus($report, 'live_audit_write_gate_report', 'pass', 'PayPal live audit write gate report is PASS.');
            $this->assertGateStatus($report, 'evidence_case_contract', 'ready', 'PayPal evidence case contract is ready.');
            $this->assertGateStatus($report, 'manifest_schema_contract', 'ready', 'PayPal signoff manifest schema is ready.');
            $this->assertGateStatus($report, 'redaction_policy_contract', 'ready', 'PayPal redaction policy contract is ready.');
            $this->assertGateStatus($report, 'signoff_ready', 'pending_external', 'PayPal sandbox signoff remains external pending.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Sandbox Evidence Signoff Gate',
                '- Result: PASS',
                '- Signoff ready: no',
                '| signoff_ready | 0 |',
                'signoff_ready=0 is intentional until real HTTPS PayPal sandbox evidence and reviewer signoff are attached.',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,artifact_type,owner_role,required_evidence',
                'sandbox_credentials_reference,pending_external,credential_reference',
                'cleanup_evidence,pending_external,cleanup',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal sandbox evidence signoff gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal sandbox evidence signoff gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-sandbox-evidence-signoff-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalSandboxEvidenceSignoffGateService
    {
        return new PaypalSandboxEvidenceSignoffGateService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal sandbox evidence signoff gate.');
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
        $this->ok("File keeps PayPal sandbox evidence signoff boundary disabled: {$path}");
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
