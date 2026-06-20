<?php

namespace console\controllers;

use common\services\mall\CustomerServiceComplaintEvidenceUploadCleanupReadinessService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceComplaintEvidenceUploadCleanupReadinessController extends Controller
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
        $this->stdout("Mongoyia customer-service complaint evidence upload cleanup readiness\n");
        $this->checkFiles();
        $this->checkBackendBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceComplaintEvidenceUploadCleanupReadinessService())->run();
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
        $this->requireFileContains('common/services/mall/CustomerServiceComplaintEvidenceUploadCleanupReadinessService.php', [
            'class CustomerServiceComplaintEvidenceUploadCleanupReadinessService',
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_CLEANUP_READINESS_V1',
            'Mongoyia Customer Service Complaint Evidence Upload Cleanup Readiness',
            'cleanup_scope_contract',
            'cleanup_exclusion_contract',
        ]);
        $this->requireFileContains('console/controllers/CustomerServiceComplaintEvidenceUploadCleanupReadinessController.php', [
            'class CustomerServiceComplaintEvidenceUploadCleanupReadinessController',
            'Rollback-clean fixture',
            'customer-service complaint evidence upload cleanup readiness',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_CLEANUP_READINESS_V1',
            'data-mongoyia-customer-service-complaint-evidence-gate="reserved"',
            'data-mongoyia-customer-service-complaint-evidence-apply="disabled"',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceComplaintEvidenceUploadCleanupReadiness',
            'customer-service complaint evidence upload cleanup readiness Phase 6 closure',
            'customer-service-complaint-evidence-upload-cleanup-readiness/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceComplaintEvidenceUploadCleanupReadinessController.php',
            'CustomerServiceComplaintEvidenceUploadCleanupReadinessService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceComplaintEvidenceUploadCleanupReadinessPath',
            'mongoyia-customer-service-complaint-evidence-upload-cleanup-readiness-*.md',
            'Customer-service complaint evidence upload cleanup readiness result',
        ]);
        $this->requireFileContains('docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_CLEANUP_READINESS_V1',
            'customer-service complaint evidence upload cleanup readiness',
            'COMPLAINT_EVIDENCE_CLEANUP_APPLY',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'customer-service-complaint-evidence-upload-cleanup-readiness/run',
            'mongoyia-customer-service-complaint-evidence-upload-cleanup-readiness-*.md',
        ]);
    }

    private function checkBackendBoundary(): void
    {
        $this->section('Backend boundary');
        $this->requireFileNotContains('backend/modules/mall/controllers/KfController.php', [
            'actionComplaintEvidenceCleanup',
            'COMPLAINT_EVIDENCE_CLEANUP_APPLY',
            'UploadedFile',
        ]);
        $this->requireFileNotContains('backend/modules/mall/views/kf/ticket-view.php', [
            'enctype="multipart/form-data"',
            'type="file"',
            'data-mongoyia-customer-service-complaint-evidence-apply="enabled"',
            'COMPLAINT_EVIDENCE_CLEANUP_APPLY',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'disabled',
            '投诉证据写入待启用',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new CustomerServiceComplaintEvidenceUploadCleanupReadinessService();
            $report = $service->run();
            $storageRootExistsBefore = is_dir((string)$report['storageRoot']);

            $this->assertFalse((bool)$report['storageRootInsideWeb'], 'Planned cleanup storage root is outside public web root.');
            $this->assertSameInt(2, count($report['cleanupScopes'] ?? []), 'Cleanup readiness has two allowed cleanup scopes.');
            $this->assertSameInt(3, count($report['excludedScopes'] ?? []), 'Cleanup readiness has reviewed evidence, public web, and handover exclusions.');
            $this->assertPlanValue($report, 'mode', 'dry-run-first', 'Cleanup plan requires dry-run first.');
            $this->assertPlanValue($report, 'applyGuard', 'COMPLAINT_EVIDENCE_CLEANUP_APPLY', 'Cleanup plan requires explicit apply guard.');
            $this->assertGateStatus($report, 'backend_upload_controls', 'disabled', 'Backend upload controls remain disabled.');
            $this->assertGateStatus($report, 'cleanup_scope_contract', 'ready', 'Cleanup scope contract is ready.');
            $this->assertGateStatus($report, 'cleanup_exclusion_contract', 'ready', 'Cleanup exclusion contract is ready.');
            $this->assertGateStatus($report, 'apply_guard_contract', 'ready', 'Cleanup apply guard contract is ready.');
            $this->assertGateStatus($report, 'business_data_contract', 'ready', 'Cleanup business data contract is ready.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Complaint Evidence Upload Cleanup Readiness',
                '- Result: PASS',
                'cleanup_scope_contract',
                'Excluded Scope',
                'This report is a read-only cleanup readiness gate',
            ]);
            $this->assertFileContains($paths['csv'], [
                'type,name,pattern,action',
                'fixture-*',
                'runtime/handover',
            ]);
            $this->assertSameString($storageRootExistsBefore ? 'yes' : 'no', is_dir((string)$report['storageRoot']) ? 'yes' : 'no', 'Readiness did not create or remove the complaint evidence storage root.');
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Customer-service complaint evidence upload cleanup readiness fixture generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('Customer-service complaint evidence upload cleanup readiness fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-complaint-evidence-upload-cleanup-readiness-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceComplaintEvidenceUploadCleanupReadinessService();
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
        $this->ok('Tickets, events, orders, payments, chats, files, funds, and statistics were not mutated by upload cleanup readiness.');
    }

    private function assertPlanValue(array $report, string $key, string $expected, string $message): void
    {
        $actual = (string)($report['cleanupPlan'][$key] ?? '');
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

    private function assertSameString(string $expected, string $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail($message);
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
        $this->ok("File keeps disabled cleanup boundary: {$path}");
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
