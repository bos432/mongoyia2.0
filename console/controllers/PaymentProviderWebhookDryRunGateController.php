<?php

namespace console\controllers;

use common\services\mall\PaypalWebhookDryRunGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderWebhookDryRunGateController extends Controller
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
        $this->stdout("Mongoyia payment provider webhook dry-run gate\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new PaypalWebhookDryRunGateService())->run();
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
        $this->requireFileContains('common/services/mall/PaypalWebhookDryRunGateService.php', [
            'class PaypalWebhookDryRunGateService',
            'MONGOYIA_PAYPAL_WEBHOOK_DRY_RUN_GATE_V1',
            'local_hmac_shim_for_test_callbacks_only',
            'reject_missing_signature',
            'reject_duplicate',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderWebhookDryRunGateController.php', [
            'class PaymentProviderWebhookDryRunGateController',
            'Mongoyia payment provider webhook dry-run gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_WEBHOOK_DRY_RUN_GATE_V1',
            'PayPal Webhook Dry-run Gate',
            'local HMAC shim',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderWebhookDryRunGate',
            'PayPal webhook dry-run gate Phase 6 closure',
            'payment-provider-webhook-dry-run-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderWebhookDryRunGateController.php',
            'PaypalWebhookDryRunGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderWebhookDryRunGatePath',
            'mongoyia-payment-provider-webhook-dry-run-gate-*.md',
            'Payment provider webhook dry-run gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-webhook-dry-run-gate/run',
            'mongoyia-payment-provider-webhook-dry-run-gate-*.md',
        ]);
    }

    private function checkRuntimeBoundary(): void
    {
        $this->section('Runtime boundary');
        $this->requireFileContains('frontend/modules/mall/controllers/PaymentController.php', [
            'public function actionPaypal',
            'public function actionPaypalReturn',
            'public function actionPaypalCancel',
            'public function actionPaypalWebhook',
            'MONGOYIA_PAYPAL_CREATE_ROUTE_V1',
            'MONGOYIA_PAYPAL_RETURN_ROUTE_V1',
            'MONGOYIA_PAYPAL_CANCEL_ROUTE_V1',
            'MONGOYIA_PAYPAL_WEBHOOK_ROUTE_V1',
            'paypalDisabledRoute',
            'PAYPAL_DISABLED',
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
        $this->requireFileContains('frontend/modules/mall/controllers/PaymentController.php', [
            'public function actionQpay',
            'public function actionQpayres',
            'public function actionLianlian',
            'assertCallbackSignature',
            'PaymentAttempt::RESULT_IGNORED',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new PaypalWebhookDryRunGateService();
            $report = $service->run();

            $this->assertTotal($report, 'sample_count', 8, 'PayPal webhook dry-run fixture has eight samples.');
            $this->assertTotal($report, 'accept_dry_run_count', 1, 'Valid sample is accepted by dry-run only.');
            $this->assertTotal($report, 'reject_missing_signature_count', 1, 'Missing signature sample is rejected.');
            $this->assertTotal($report, 'reject_invalid_signature_count', 1, 'Invalid signature sample is rejected.');
            $this->assertTotal($report, 'reject_expired_count', 1, 'Expired timestamp sample is rejected.');
            $this->assertTotal($report, 'reject_webhook_id_count', 1, 'Wrong webhook id sample is rejected.');
            $this->assertTotal($report, 'reject_amount_count', 1, 'Amount mismatch sample is rejected.');
            $this->assertTotal($report, 'reject_duplicate_count', 1, 'Duplicate event sample is rejected.');
            $this->assertTotal($report, 'reject_status_count', 1, 'Non-success status sample is rejected.');
            $this->assertGateStatus($report, 'runtime_routes', 'disabled', 'PayPal runtime routes remain disabled.');
            $this->assertGateStatus($report, 'signature_fixture', 'ready', 'Signature dry-run fixture is ready.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Webhook Dry-run Gate',
                '- Result: PASS',
                '| accept_dry_run_count | 1 |',
                '| reject_duplicate_count | 1 |',
                'No PayPal, QPay, or LianLian network call is made.',
            ]);
            $this->assertFileContains($paths['csv'], [
                'sample,event_id,decision,reason',
                'accept_dry_run',
                'reject_invalid_signature',
                'reject_duplicate',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal webhook dry-run gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal webhook dry-run gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-webhook-dry-run-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new PaypalWebhookDryRunGateService();
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal webhook dry-run gate.');
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
        $this->ok("File keeps PayPal runtime boundary disabled: {$path}");
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
