<?php

namespace console\controllers;

use common\services\mall\PaypalFinalGoNoGoGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalFinalGoNoGoGateController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal final go/no-go gate\n");
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
        $this->requireFileContains('common/services/mall/PaypalFinalGoNoGoGateService.php', [
            'class PaypalFinalGoNoGoGateService',
            'MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1',
            'paypal_final_go_no_go_read_only_no_enablement',
            'Final decision NO-GO is intentional',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveVerificationEnablementGateService.php', [
            'class PaypalLiveVerificationEnablementGateService',
            'MONGOYIA_PAYPAL_LIVE_VERIFICATION_ENABLEMENT_GATE_V1',
            'enablement_allowed=false',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalFinalGoNoGoGateController.php', [
            'class PaymentProviderPaypalFinalGoNoGoGateController',
            'Mongoyia payment provider PayPal final go/no-go gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1',
            'PayPal Final Go/No-Go Gate',
            'Final decision: NO-GO',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1',
            'PayPal Final Go/No-Go Gate',
            'go_allowed=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalFinalGoNoGoGate',
            'PayPal final go/no-go gate Phase 6 closure',
            'payment-provider-paypal-final-go-no-go-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalFinalGoNoGoGateController.php',
            'PaypalFinalGoNoGoGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalFinalGoNoGoGatePath',
            'mongoyia-payment-provider-paypal-final-go-no-go-gate-*.md',
            'Payment provider PayPal final go/no-go gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-final-go-no-go-gate/run',
            'mongoyia-payment-provider-paypal-final-go-no-go-gate-*.md',
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

            $this->assertTotal($report, 'gate_check_count', 9, 'PayPal final go/no-go gate has nine checks.');
            $this->assertTotal($report, 'satisfied_gate_check_count', 9, 'All PayPal final go/no-go checks pass for the current NO-GO state.');
            $this->assertTotal($report, 'no_go_reason_count', 2, 'PayPal final go/no-go gate keeps two NO-GO reasons.');
            $this->assertTotal($report, 'final_decision_no_go', 1, 'PayPal final decision is NO-GO.');
            $this->assertTotal($report, 'go_allowed', 0, 'PayPal GO is not allowed.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal final go/no-go gate does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal final go/no-go gate does not write rows.');
            $this->assertGateStatus($report, 'live_verification_enablement_gate_report', 'pass', 'PayPal live verification enablement gate report is PASS.');
            $this->assertGateStatus($report, 'enablement_allowed_state', 'no-go', 'PayPal enablement state remains NO-GO.');
            $this->assertGateStatus($report, 'pending_external_evidence_acceptance', 'pending', 'External evidence acceptance remains pending.');
            $this->assertGateStatus($report, 'runtime_implementation_pending', 'pending', 'Runtime implementation remains pending.');
            $this->assertGateStatus($report, 'paypal_runtime_disabled', 'disabled', 'PAYPAL_ENABLED remains false.');
            $this->assertGateStatus($report, 'paypal_ui_hidden', 'hidden', 'PayPal UI remains hidden.');
            $this->assertGateStatus($report, 'provider_api_boundary', 'disabled', 'Provider API boundary remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Final Go/No-Go Gate',
                '- Result: PASS',
                '- Final decision: NO-GO',
                '- Go allowed: no',
                '| final_decision_no_go | 1 |',
                'Final decision NO-GO is intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,details',
                'real_sandbox_live_evidence_acceptance_pending,pending',
                'runtime_implementation_pending,pending',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal final go/no-go gate generated read-only NO-GO evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal final go/no-go gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-final-go-no-go-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalFinalGoNoGoGateService
    {
        return new PaypalFinalGoNoGoGateService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal final go/no-go gate.');
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
        $this->ok("File keeps PayPal final go/no-go boundary disabled: {$path}");
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
