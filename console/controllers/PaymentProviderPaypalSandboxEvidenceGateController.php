<?php

namespace console\controllers;

use common\services\mall\PaypalSandboxEvidenceGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalSandboxEvidenceGateController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal sandbox evidence gate\n");
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
        $this->requireFileContains('common/services/mall/PaypalSandboxEvidenceGateService.php', [
            'class PaypalSandboxEvidenceGateService',
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1',
            'paypal_sandbox_evidence_contract_only',
            'sandbox_evidence_ready=0',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalSandboxEvidenceGateController.php', [
            'class PaymentProviderPaypalSandboxEvidenceGateController',
            'Mongoyia payment provider PayPal sandbox evidence gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1',
            'PayPal Sandbox Evidence Gate',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1',
            'PayPal Sandbox Evidence Gate',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalSandboxEvidenceGate',
            'PayPal sandbox evidence gate Phase 6 closure',
            'payment-provider-paypal-sandbox-evidence-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalSandboxEvidenceGateController.php',
            'PaypalSandboxEvidenceGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalSandboxEvidenceGatePath',
            'mongoyia-payment-provider-paypal-sandbox-evidence-gate-*.md',
            'Payment provider PayPal sandbox evidence gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-sandbox-evidence-gate/run',
            'mongoyia-payment-provider-paypal-sandbox-evidence-gate-*.md',
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

            $this->assertTotal($report, 'requirement_count', 11, 'PayPal sandbox evidence gate has eleven required evidence cases.');
            $this->assertTotal($report, 'pending_requirement_count', 11, 'All PayPal sandbox evidence cases remain external pending.');
            $this->assertTotal($report, 'local_precondition_count', 7, 'PayPal sandbox evidence gate has seven local preconditions.');
            $this->assertTotal($report, 'local_precondition_pass_count', 7, 'All PayPal sandbox evidence local preconditions pass.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal sandbox evidence gate does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal sandbox evidence gate does not write rows.');
            $this->assertTotal($report, 'sandbox_evidence_ready', 0, 'PayPal sandbox evidence is not ready without external evidence.');
            $this->assertGateStatus($report, 'sandbox_evidence_contract', 'ready', 'PayPal sandbox evidence contract is ready.');
            $this->assertGateStatus($report, 'sandbox_evidence', 'pending_external', 'PayPal sandbox evidence remains external pending.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Sandbox Evidence Gate',
                '- Result: PASS',
                '- Sandbox evidence ready: no',
                '| sandbox_evidence_ready | 0 |',
                'sandbox_evidence_ready=0 is intentional until real HTTPS PayPal sandbox evidence is attached.',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,evidence_ready,required_evidence',
                'sandbox_credentials_reference,pending_external,0',
                'cleanup_evidence,pending_external,0',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal sandbox evidence gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal sandbox evidence gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-sandbox-evidence-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalSandboxEvidenceGateService
    {
        return new PaypalSandboxEvidenceGateService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal sandbox evidence gate.');
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
        $this->ok("File keeps PayPal sandbox evidence boundary disabled: {$path}");
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
