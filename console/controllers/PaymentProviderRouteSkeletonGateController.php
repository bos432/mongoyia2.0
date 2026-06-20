<?php

namespace console\controllers;

use common\services\mall\PaypalRouteSkeletonGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderRouteSkeletonGateController extends Controller
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
        $this->stdout("Mongoyia payment provider route skeleton gate\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new PaypalRouteSkeletonGateService())->run();
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
        $this->requireFileContains('common/services/mall/PaypalRouteSkeletonGateService.php', [
            'class PaypalRouteSkeletonGateService',
            'MONGOYIA_PAYPAL_ROUTE_SKELETON_GATE_V1',
            'actionPaypalWebhook',
            'provider=paypal payment_attempt fixtures',
            'disabled_by_default_routes',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderRouteSkeletonGateController.php', [
            'class PaymentProviderRouteSkeletonGateController',
            'Mongoyia payment provider route skeleton gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_ROUTE_SKELETON_GATE_V1',
            'PayPal Route Skeleton Gate',
            'provider=paypal',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderRouteSkeletonGate',
            'PayPal route skeleton gate Phase 6 closure',
            'payment-provider-route-skeleton-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderRouteSkeletonGateController.php',
            'PaypalRouteSkeletonGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderRouteSkeletonGatePath',
            'mongoyia-payment-provider-route-skeleton-gate-*.md',
            'Payment provider route skeleton gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-route-skeleton-gate/run',
            'mongoyia-payment-provider-route-skeleton-gate-*.md',
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
            "env('PAYPAL_ENABLED'",
            'PayPal payment is disabled',
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
            'public function actionSucceeded',
            'PaymentAttempt::RESULT_IGNORED',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new PaypalRouteSkeletonGateService();
            $report = $service->run();

            $this->assertTotal($report, 'route_count', 4, 'PayPal route skeleton has four reserved routes.');
            $this->assertTotal($report, 'disabled_route_count', 4, 'All PayPal route skeletons remain disabled.');
            $this->assertTotal($report, 'audit_field_count', 12, 'PayPal audit contract has twelve fields.');
            $this->assertTotal($report, 'cleanup_scope_count', 5, 'PayPal cleanup contract has five scopes.');
            $this->assertTotal($report, 'precondition_count', 6, 'PayPal enablement contract has six preconditions.');
            $this->assertTotal($report, 'unsatisfied_precondition_count', 5, 'Only disabled-by-default PayPal route handler precondition is satisfied.');
            $this->assertGateStatus($report, 'paypal_runtime', 'disabled', 'PayPal runtime remains disabled.');
            $this->assertGateStatus($report, 'route_skeletons', 'ready', 'PayPal route skeletons are documented.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Route Skeleton Gate',
                '- Result: PASS',
                '| route_count | 4 |',
                '| disabled_route_count | 4 |',
                'PayPal route handlers are present in `PaymentController` but return safe disabled responses while `PAYPAL_ENABLED=false`.',
            ]);
            $this->assertFileContains($paths['csv'], [
                'name,method,path,future_action,enabled,audit_events',
                '/mall/payment/paypal-webhook',
                'actionPaypalWebhook',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal route skeleton gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal route skeleton gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-route-skeleton-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new PaypalRouteSkeletonGateService();
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal route skeleton gate.');
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
        $this->ok("File keeps PayPal route boundary disabled: {$path}");
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
