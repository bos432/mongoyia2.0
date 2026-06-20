<?php

namespace console\controllers;

use common\services\mall\CustomerServiceComplaintEvidenceUploadEnablementGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceComplaintEvidenceUploadEnablementGateController extends Controller
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
        $this->stdout("Mongoyia customer-service complaint evidence upload enablement gate\n");
        $this->checkFiles();
        $this->checkBackendBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceComplaintEvidenceUploadEnablementGateService())->run();
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
        $this->requireFileContains('common/services/mall/CustomerServiceComplaintEvidenceUploadEnablementGateService.php', [
            'class CustomerServiceComplaintEvidenceUploadEnablementGateService',
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_ENABLEMENT_GATE_V1',
            'Mongoyia Customer Service Complaint Evidence Upload Enablement Gate',
            'backend_action_contract',
            'precondition_chain',
        ]);
        $this->requireFileContains('console/controllers/CustomerServiceComplaintEvidenceUploadEnablementGateController.php', [
            'class CustomerServiceComplaintEvidenceUploadEnablementGateController',
            'Rollback-clean fixture',
            'customer-service complaint evidence upload enablement gate',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_ENABLEMENT_GATE_V1',
            'data-mongoyia-customer-service-complaint-evidence-upload="disabled"',
            'data-mongoyia-customer-service-complaint-evidence-apply="disabled"',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceComplaintEvidenceUploadEnablementGate',
            'customer-service complaint evidence upload enablement gate Phase 6 closure',
            'customer-service-complaint-evidence-upload-enablement-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceComplaintEvidenceUploadEnablementGateController.php',
            'CustomerServiceComplaintEvidenceUploadEnablementGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceComplaintEvidenceUploadEnablementGatePath',
            'mongoyia-customer-service-complaint-evidence-upload-enablement-gate-*.md',
            'Customer-service complaint evidence upload enablement gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_ENABLEMENT_GATE_V1',
            'customer-service complaint evidence upload enablement gate',
            '/mall/kf/complaint-evidence-upload',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'customer-service-complaint-evidence-upload-enablement-gate/run',
            'mongoyia-customer-service-complaint-evidence-upload-enablement-gate-*.md',
        ]);
    }

    private function checkBackendBoundary(): void
    {
        $this->section('Backend boundary');
        $this->requireFileNotContains('backend/modules/mall/controllers/KfController.php', [
            'actionComplaintEvidenceUpload',
            'UploadedFile',
            'COMPLAINT_EVIDENCE_UPLOAD_ENABLE',
        ]);
        $this->requireFileNotContains('backend/modules/mall/views/kf/ticket-view.php', [
            'enctype="multipart/form-data"',
            'type="file"',
            'data-mongoyia-customer-service-complaint-evidence-upload="enabled"',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'disabled',
            '投诉证据上传待启用',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new CustomerServiceComplaintEvidenceUploadEnablementGateService();
            $report = $service->run();

            $this->assertFalse((bool)$report['controlsEnabled'], 'Backend upload controls remain disabled.');
            $this->assertSameInt(4, count($report['contracts'] ?? []), 'Enablement gate has four required contracts.');
            $this->assertSameInt(4, count($report['preconditions'] ?? []), 'Enablement gate has four precondition commands.');
            $this->assertPlanValue($report, 'mode', 'gate-only', 'Enablement plan is gate-only.');
            $this->assertPlanBool($report, 'backendActionPresent', false, 'Enablement plan keeps backend action absent.');
            $this->assertPlanBool($report, 'fileInputPresent', false, 'Enablement plan keeps file input absent.');
            $this->assertPlanBool($report, 'auditEventRequired', true, 'Enablement plan requires audit events before real upload.');
            $this->assertPlanBool($report, 'cleanupRequired', true, 'Enablement plan requires cleanup before real upload.');
            $this->assertGateStatus($report, 'backend_upload_controls', 'disabled', 'Backend upload controls gate is disabled.');
            $this->assertGateStatus($report, 'backend_action_contract', 'ready', 'Backend action contract is ready.');
            $this->assertGateStatus($report, 'ui_disabled_marker_contract', 'ready', 'UI disabled marker contract is ready.');
            $this->assertGateStatus($report, 'audit_event_contract', 'ready', 'Audit event contract is ready.');
            $this->assertGateStatus($report, 'rollback_cleanup_contract', 'ready', 'Rollback cleanup contract is ready.');
            $this->assertGateStatus($report, 'precondition_chain', 'ready', 'Precondition chain is ready.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Complaint Evidence Upload Enablement Gate',
                '- Result: PASS',
                'backend_action_contract',
                'Required Preconditions',
                'This report is a read-only enablement gate',
            ]);
            $this->assertFileContains($paths['csv'], [
                'type,name,marker_or_command,details',
                '/mall/kf/complaint-evidence-upload',
                'customer-service-complaint-evidence-upload-cleanup-readiness/run --fixture=1',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Customer-service complaint evidence upload enablement gate fixture generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('Customer-service complaint evidence upload enablement gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-complaint-evidence-upload-enablement-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceComplaintEvidenceUploadEnablementGateService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function businessTableCounts(): array
    {
        $counts = [];
        foreach ([
            '{{%mall_customer_service_ticket}}',
            '{{%mall_customer_service_event}}',
            '{{%mall_order}}',
            '{{%mall_order_product}}',
            '{{%mall_payment_attempt}}',
            '{{%base_message}}',
            '{{%chat_message}}',
            '{{%base_fund_log}}',
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
        $this->ok('Tickets, events, orders, payments, chats, files, funds, and statistics were not mutated by upload enablement gate.');
    }

    private function assertPlanValue(array $report, string $key, string $expected, string $message): void
    {
        $actual = (string)($report['enablementPlan'][$key] ?? '');
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertPlanBool(array $report, string $key, bool $expected, string $message): void
    {
        $actual = (bool)($report['enablementPlan'][$key] ?? !$expected);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected " . ($expected ? 'true' : 'false') . ', got ' . ($actual ? 'true' : 'false') . '.');
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

    private function assertFalse(bool $condition, string $message): void
    {
        if ($condition) {
            $this->fail($message);
            return;
        }
        $this->ok($message);
    }

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
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
        $this->ok("File keeps disabled upload boundary: {$path}");
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
