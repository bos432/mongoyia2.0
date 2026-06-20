<?php

namespace console\controllers;

use common\services\mall\CustomerServiceComplaintEvidenceUploadImplementationGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceComplaintEvidenceUploadImplementationGateController extends Controller
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
        $this->stdout("Mongoyia customer-service complaint evidence upload implementation gate\n");
        $this->checkFiles();
        $this->checkBackendBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceComplaintEvidenceUploadImplementationGateService())->run();
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
        $this->requireFileContains('common/services/mall/CustomerServiceComplaintEvidenceUploadImplementationGateService.php', [
            'class CustomerServiceComplaintEvidenceUploadImplementationGateService',
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_IMPLEMENTATION_GATE_V1',
            'Mongoyia Customer Service Complaint Evidence Upload Implementation Gate',
            'storage_root_outside_web',
            'audit_event_contract',
        ]);
        $this->requireFileContains('console/controllers/CustomerServiceComplaintEvidenceUploadImplementationGateController.php', [
            'class CustomerServiceComplaintEvidenceUploadImplementationGateController',
            'Rollback-clean fixture',
            'customer-service complaint evidence upload implementation gate',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_IMPLEMENTATION_GATE_V1',
            'data-mongoyia-customer-service-complaint-evidence-gate="reserved"',
            'data-mongoyia-customer-service-complaint-evidence-apply="disabled"',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceComplaintEvidenceUploadImplementationGate',
            'customer-service complaint evidence upload implementation gate Phase 6 closure',
            'customer-service-complaint-evidence-upload-implementation-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceComplaintEvidenceUploadImplementationGateController.php',
            'CustomerServiceComplaintEvidenceUploadImplementationGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceComplaintEvidenceUploadImplementationGatePath',
            'mongoyia-customer-service-complaint-evidence-upload-implementation-gate-*.md',
            'Customer-service complaint evidence upload implementation gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_IMPLEMENTATION_GATE_V1',
            'customer-service complaint evidence upload implementation gate',
            'storage root must stay outside the public web root',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'customer-service-complaint-evidence-upload-implementation-gate/run',
            'mongoyia-customer-service-complaint-evidence-upload-implementation-gate-*.md',
        ]);
    }

    private function checkBackendBoundary(): void
    {
        $this->section('Backend boundary');
        $this->requireFileNotContains('backend/modules/mall/controllers/KfController.php', [
            'actionComplaintEvidenceUpload',
            'UploadedFile',
            'COMPLAINT_EVIDENCE_UPLOAD',
        ]);
        $this->requireFileNotContains('backend/modules/mall/views/kf/ticket-view.php', [
            'enctype="multipart/form-data"',
            'type="file"',
            'data-mongoyia-customer-service-complaint-evidence-apply="enabled"',
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
            $service = new CustomerServiceComplaintEvidenceUploadImplementationGateService();
            $report = $service->run();

            $this->assertFalse((bool)$report['storageRootInsideWeb'], 'Planned storage root is outside public web root.');
            $this->assertSameInt(2, count($report['sampleFiles'] ?? []), 'Implementation gate has two planned sample evidence files.');
            $this->assertJsonContains((string)$report['evidenceJson'], ['version', 'source', 'files', 'uploaded_at', 'uploaded_by'], 'Evidence JSON contract includes required fields.');
            $this->assertJsonContains((string)$report['auditMetadata'], ['source', 'file_count', 'storage_root', 'preserve_ticket_status', 'cleanup_required'], 'Audit metadata contract includes required fields.');
            $this->assertGateStatus($report, 'backend_upload_controls', 'disabled', 'Backend upload controls remain disabled.');
            $this->assertGateStatus($report, 'storage_root_outside_web', 'ready', 'Storage root outside web gate is ready.');
            $this->assertGateStatus($report, 'evidence_json_contract', 'ready', 'Evidence JSON contract gate is ready.');
            $this->assertGateStatus($report, 'audit_event_contract', 'ready', 'Audit event contract gate is ready.');
            $this->assertGateStatus($report, 'cleanup_contract', 'ready', 'Cleanup contract gate is ready.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Complaint Evidence Upload Implementation Gate',
                '- Result: PASS',
                'storage_root_outside_web',
                'Evidence JSON Contract',
                'This report is a read-only implementation gate',
            ]);
            $this->assertFileContains($paths['csv'], [
                'name,mime,bytes,storage_key,sha256',
                'customer-service/complaint-evidence/{ticket_id}/{yyyymmdd}/',
                'image/png',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Customer-service complaint evidence upload implementation gate fixture generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('Customer-service complaint evidence upload implementation gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-complaint-evidence-upload-implementation-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceComplaintEvidenceUploadImplementationGateService();
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
        $this->ok('Tickets, events, orders, payments, chats, files, funds, and statistics were not mutated by upload implementation gate.');
    }

    private function assertJsonContains(string $json, array $keys, string $message): void
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->fail($message . ' JSON did not decode.');
            return;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $decoded)) {
                $this->fail("{$message} Missing key {$key}.");
                return;
            }
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
