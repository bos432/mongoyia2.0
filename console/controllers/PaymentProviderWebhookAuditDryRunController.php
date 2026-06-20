<?php

namespace console\controllers;

use common\services\mall\PaypalWebhookAuditDryRunService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderWebhookAuditDryRunController extends Controller
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
        $this->stdout("Mongoyia payment provider webhook audit dry-run gate\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new PaypalWebhookAuditDryRunService())->run();
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
        $this->requireFileContains('common/services/mall/PaypalWebhookAuditDryRunService.php', [
            'class PaypalWebhookAuditDryRunService',
            'MONGOYIA_PAYPAL_WEBHOOK_AUDIT_DRY_RUN_V1',
            'payment_attempt_audit_dry_run_only',
            'PaymentAttempt::RESULT_IGNORED',
        ]);
        $this->requireFileContains('common/services/mall/PaypalWebhookDryRunGateService.php', [
            'class PaypalWebhookDryRunGateService',
            'MONGOYIA_PAYPAL_WEBHOOK_DRY_RUN_GATE_V1',
            'reject_duplicate',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderWebhookAuditDryRunController.php', [
            'class PaymentProviderWebhookAuditDryRunController',
            'Mongoyia payment provider webhook audit dry-run gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_WEBHOOK_AUDIT_DRY_RUN_V1',
            'PayPal Webhook Audit Dry-run Gate',
            'provider=paypal',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderWebhookAuditDryRun',
            'PayPal webhook audit dry-run gate Phase 6 closure',
            'payment-provider-webhook-audit-dry-run/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderWebhookAuditDryRunController.php',
            'PaypalWebhookAuditDryRunService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderWebhookAuditDryRunPath',
            'mongoyia-payment-provider-webhook-audit-dry-run-*.md',
            'Payment provider webhook audit dry-run result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-webhook-audit-dry-run/run',
            'mongoyia-payment-provider-webhook-audit-dry-run-*.md',
        ]);
    }

    private function checkRuntimeBoundary(): void
    {
        $this->section('Runtime boundary');
        $this->requireFileContains('frontend/modules/mall/controllers/PaymentController.php', [
            'public function actionPaypalWebhook',
            'PAYPAL_DISABLED',
            'paypalDisabledRoute',
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
            $report = (new PaypalWebhookAuditDryRunService())->run();

            $this->assertTotal($report, 'audit_plan_count', 8, 'PayPal webhook audit dry-run has eight planned rows.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal webhook audit dry-run does not write rows.');
            $this->assertTotal($report, 'success_count', 1, 'Valid webhook maps to one success audit row.');
            $this->assertTotal($report, 'failed_count', 6, 'Rejected webhooks map to six failed audit rows.');
            $this->assertTotal($report, 'ignored_count', 1, 'Duplicate webhook maps to one ignored audit row.');
            $this->assertTotal($report, 'provider_paypal_count', 8, 'All planned audit rows use provider=paypal.');
            $this->assertGateStatus($report, 'audit_contract', 'ready', 'PayPal webhook audit contract is ready.');
            $this->assertGateStatus($report, 'dry_run_only', 'ready', 'PayPal webhook audit gate is dry-run only.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Webhook Audit Dry-run Gate',
                '- Result: PASS',
                '| audit_plan_count | 8 |',
                '| dry_run_write_count | 0 |',
                'No `mall_payment_attempt` row is inserted, updated, or deleted.',
            ]);
            $this->assertFileContains($paths['csv'], [
                'sample,provider,event,result,merchant_transaction_id',
                'paypal,webhook,success',
                'paypal,webhook,ignored',
                'dry_run',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal webhook audit dry-run gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal webhook audit dry-run fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-webhook-audit-dry-run-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new PaypalWebhookAuditDryRunService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal webhook audit dry-run gate.');
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
        $this->ok("File keeps PayPal audit dry-run boundary disabled: {$path}");
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
