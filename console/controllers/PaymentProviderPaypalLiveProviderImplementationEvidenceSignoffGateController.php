<?php

namespace console\controllers;

use common\services\mall\PaypalLiveProviderImplementationEvidenceSignoffGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGateController extends Controller
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
        $this->stdout("Mongoyia payment provider PayPal live provider implementation evidence signoff gate\n");
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
        $this->requireFileContains('common/services/mall/PaypalLiveProviderImplementationEvidenceSignoffGateService.php', [
            'class PaypalLiveProviderImplementationEvidenceSignoffGateService',
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1',
            'paypal_live_provider_implementation_evidence_signoff_gate_read_only_no_runtime_no_artifact_access',
            'implementation_evidence_signoff_ready',
            'paypal_enablement_allowed',
        ]);
        $this->requireFileContains('common/services/mall/PaypalLiveProviderImplementationEvidenceDryRunService.php', [
            'class PaypalLiveProviderImplementationEvidenceDryRunService',
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1',
            'live_provider_implementation_evidence_valid',
        ]);
        $this->requireFileContains('console/controllers/PaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGateController.php', [
            'class PaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGateController',
            'Mongoyia payment provider PayPal live provider implementation evidence signoff gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-provider-contract.md', [
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1',
            'PayPal Live Provider Implementation Evidence Signoff Gate',
            'implementation_evidence_signoff_ready=1',
        ]);
        $this->requireFileContains('docs/mongoyia-payment-sandbox-evidence.md', [
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1',
            'PayPal Live Provider Implementation Evidence Signoff Gate',
            'implementation_evidence_accepted=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipPaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGate',
            'PayPal live provider implementation evidence signoff gate Phase 6 closure',
            'payment-provider-paypal-live-provider-implementation-evidence-signoff-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'PaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGateController.php',
            'PaypalLiveProviderImplementationEvidenceSignoffGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'paymentProviderPaypalLiveProviderImplementationEvidenceSignoffGatePath',
            'mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-*.md',
            'Payment provider PayPal live provider implementation evidence signoff gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'payment-provider-paypal-live-provider-implementation-evidence-signoff-gate/run',
            'mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-*.md',
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

            $this->assertTotal($report, 'signoff_row_count', 3, 'PayPal live provider implementation signoff has three reviewer rows.');
            $this->assertTotal($report, 'approved_plan_count', 3, 'All PayPal live provider implementation signoff rows approve the plan.');
            $this->assertTotal($report, 'covered_required_role_count', 3, 'Business, security, and technical signoff roles are covered.');
            $this->assertTotal($report, 'precondition_count', 7, 'PayPal live provider implementation signoff gate has seven preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 7, 'All PayPal live provider implementation signoff local preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 3, 'PayPal live provider implementation signoff keeps three external pending markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'PayPal live provider implementation signoff does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'PayPal live provider implementation signoff does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'PayPal live provider implementation signoff does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'PayPal live provider implementation signoff does not call providers.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'PayPal live provider implementation signoff does not write rows.');
            $this->assertTotal($report, 'implementation_evidence_signoff_ready', 1, 'PayPal implementation evidence signoff metadata is ready.');
            $this->assertTotal($report, 'implementation_evidence_accepted', 0, 'PayPal implementation evidence is not accepted by this gate.');
            $this->assertTotal($report, 'live_provider_implementation_ready', 0, 'PayPal live provider runtime implementation is not ready.');
            $this->assertTotal($report, 'paypal_enablement_allowed', 0, 'PayPal enablement is not allowed by this gate.');
            $this->assertGateStatus($report, 'live_provider_implementation_evidence_dry_run_report', 'pass', 'PayPal live provider implementation evidence dry-run report is PASS.');
            $this->assertGateStatus($report, 'implementation_evidence_signoff_documentation', 'ready', 'PayPal implementation evidence signoff documentation is ready.');
            $this->assertGateStatus($report, 'implementation_evidence_signoff_contract', 'ready', 'PayPal implementation evidence signoff contract is ready.');
            $this->assertGateStatus($report, 'acceptance_wiring', 'ready', 'PayPal implementation evidence signoff acceptance wiring is ready.');
            $this->assertGateStatus($report, 'implementation_evidence_signoff_ready', 'ready', 'PayPal implementation evidence signoff gate is ready.');
            $this->assertGateStatus($report, 'implementation_evidence_acceptance', 'pending', 'PayPal implementation evidence acceptance remains pending.');
            $this->assertGateStatus($report, 'live_provider_implementation', 'disabled', 'PayPal live provider implementation remains disabled.');
            $this->assertGateStatus($report, 'paypal_enablement', 'disabled', 'PayPal enablement remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Provider calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia PayPal Live Provider Implementation Evidence Signoff Gate',
                '- Result: PASS',
                '- Implementation evidence signoff ready: yes',
                '- Implementation evidence accepted: no',
                '- Live provider implementation ready: no',
                '- PayPal enablement allowed: no',
                '| implementation_evidence_signoff_ready | 1 |',
                'implementation_evidence_signoff_ready=1 means the local signoff metadata is valid',
            ]);
            $this->assertFileContains($paths['csv'], [
                'implementation_evidence_ref,source_report_ref,test_host,cleanup_ref,ticket_ref,reviewer_role,decision,signoff_status,signoff_reason,reviewed_at',
                'business,approve_plan,ready_for_manual_signoff',
                'technical,approve_plan,ready_for_manual_signoff',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('PayPal live provider implementation evidence signoff gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('PayPal live provider implementation evidence signoff gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): PaypalLiveProviderImplementationEvidenceSignoffGateService
    {
        return new PaypalLiveProviderImplementationEvidenceSignoffGateService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by PayPal live provider implementation evidence signoff gate.');
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
        $this->ok("File keeps PayPal live provider implementation evidence signoff boundary disabled: {$path}");
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
