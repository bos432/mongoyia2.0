<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceComplaintEvidenceGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceComplaintEvidenceGateController extends Controller
{
    public $storeId = 0;
    public $ticketStatus = '';
    public $dateFrom = '';
    public $dateTo = '';
    public $limit = 500;
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'storeId',
            'ticketStatus',
            'dateFrom',
            'dateTo',
            'limit',
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service complaint evidence gate\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkBackendBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceComplaintEvidenceGateService())->run(
                (int)$this->storeId,
                (string)$this->ticketStatus,
                (string)$this->dateFrom,
                (string)$this->dateTo,
                (int)$this->limit
            );
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
        $this->requireFileContains('common/services/mall/CustomerServiceComplaintEvidenceGateService.php', [
            'class CustomerServiceComplaintEvidenceGateService',
            'function run(',
            'Mongoyia Customer Service Complaint Evidence Gate',
            'read-only gate evidence',
            'write_handler',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_GATE_V1',
            'data-mongoyia-customer-service-complaint-evidence-gate="reserved"',
            'data-mongoyia-customer-service-complaint-evidence-apply="disabled"',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceComplaintEvidenceGate',
            'customer-service complaint evidence gate Phase 6 closure',
            'customer-service-complaint-evidence-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceComplaintEvidenceGateController.php',
            'CustomerServiceComplaintEvidenceGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceComplaintEvidenceGatePath',
            'mongoyia-customer-service-complaint-evidence-gate-*.md',
            'Customer-service complaint evidence gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_GATE_V1',
            'complaint evidence gate',
            'complaint evidence write handling remains disabled',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'customer-service-complaint-evidence-gate/run',
            'mongoyia-customer-service-complaint-evidence-gate-*.md',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_customer_service_ticket}}', [
            'id',
            'ticket_sn',
            'ticket_type',
            'ticket_status',
            'store_id',
            'title',
            'evidence_json',
            'status',
        ]);
    }

    private function checkBackendBoundary(): void
    {
        $this->section('Backend boundary');
        $this->requireFileNotContains('backend/modules/mall/controllers/KfController.php', [
            'actionComplaintEvidence',
            'complaint-evidence-apply',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'disabled',
            '投诉证据写入待启用',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service complaint evidence gate fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $service = new CustomerServiceComplaintEvidenceGateService();
            $businessCounts = $this->businessTableCounts();
            $fixtureDate = '2037-01-03';
            $now = strtotime($fixtureDate . ' 10:00:00');
            $validId = $this->createComplaint($storeIds[0], $userId, 'Complaint valid evidence gate fixture', '{"source":"fixture","type":"complaint-evidence","files":[{"name":"proof.png","mime":"image/png"}]}', $now);
            $missingId = $this->createComplaint($storeIds[0], $userId, 'Complaint missing evidence gate fixture', '', $now + 60);
            $invalidId = $this->createComplaint($storeIds[0], $userId, 'Complaint invalid evidence gate fixture', '{"source":"fixture"', $now + 120);
            $otherStoreId = $this->createComplaint($storeIds[1], $userId, 'Complaint other store evidence gate fixture', '{"source":"fixture","type":"complaint-evidence"}', $now + 180);
            $expectedEvidence = [
                $validId => '{"source":"fixture","type":"complaint-evidence","files":[{"name":"proof.png","mime":"image/png"}]}',
                $missingId => '',
                $invalidId => '{"source":"fixture"',
                $otherStoreId => '{"source":"fixture","type":"complaint-evidence"}',
            ];

            $storeReport = $service->run($storeIds[0], '', $fixtureDate, $fixtureDate, 20);
            $this->assertSameInt(3, (int)$storeReport['rowsScanned'], 'Store-scoped gate includes three complaint fixture rows.');
            $this->assertTotal($storeReport, 'complaint_count', 3, 'Store-scoped complaint total matches fixture.');
            $this->assertTotal($storeReport, 'valid_evidence_json_count', 1, 'Store-scoped valid evidence count matches fixture.');
            $this->assertTotal($storeReport, 'missing_evidence_count', 1, 'Store-scoped missing evidence count matches fixture.');
            $this->assertTotal($storeReport, 'invalid_evidence_json_count', 1, 'Store-scoped invalid evidence count matches fixture.');
            $this->assertTotal($storeReport, 'upload_required_count', 1, 'Store-scoped upload-required count matches fixture.');
            $this->assertTotal($storeReport, 'repair_required_count', 1, 'Store-scoped repair-required count matches fixture.');
            $this->assertTotal($storeReport, 'manual_review_count', 1, 'Store-scoped manual-review count matches fixture.');
            $this->assertGateStatus($storeReport, 'upload_transport', 'reserved', 'Upload transport remains reserved.');
            $this->assertGateStatus($storeReport, 'write_handler', 'reserved', 'Write handler remains reserved.');

            $allReport = $service->run(0, '', $fixtureDate, $fixtureDate, 20);
            $this->assertSameInt(4, (int)$allReport['rowsScanned'], 'All-store gate includes cross-store complaint row.');
            $this->assertTotal($allReport, 'valid_evidence_json_count', 2, 'All-store valid evidence count includes cross-store row.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Complaint Evidence Gate',
                '- Result: PASS',
                '| Missing evidence | 1 |',
                '| Invalid evidence JSON | 1 |',
                '| upload_transport | reserved |',
                'This report is read-only gate evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'ticket_id,ticket_sn,store_id,ticket_status,evidence_status,suggested_action,title',
                'valid_evidence_json',
                'missing_evidence',
                'invalid_evidence_json',
            ]);
            $this->assertFixtureEvidenceUnchanged($expectedEvidence);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service complaint evidence gate fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service complaint evidence gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-complaint-evidence-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceComplaintEvidenceGateService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createComplaint(int $storeId, int $userId, string $title, string $evidenceJson, int $createdAt): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSEGATE-' . date('YmdHis', $createdAt) . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT,
            'ticket_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
            'priority' => CustomerServiceAdvancedService::PRIORITY_HIGH,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 991000 + mt_rand(100, 999),
            'order_sn' => 'CS-EVIDENCE-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'complaint_evidence_gate_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'complaint_evidence_gate_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-complaint-evidence-gate/run fixture.',
            'result' => '',
            'evidence_json' => $evidenceJson,
            'first_response_at' => 0,
            'resolved_at' => 0,
            'closed_at' => 0,
            'remark' => 'complaint evidence gate fixture',
            'status' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function firstTwoStoreIds(): array
    {
        return array_map('intval', (new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'id', 0])
            ->andWhere(['>', 'status', 0])
            ->andWhere(['not in', 'id', [5]])
            ->orderBy(['id' => SORT_ASC])
            ->limit(2)
            ->column(Yii::$app->db));
    }

    private function firstUserId(): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', 0])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
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
        ] as $table) {
            if (Yii::$app->db->schema->getTableSchema($table, true) === null) {
                continue;
            }
            $counts[$table] = (int)(new \yii\db\Query())->from($table)->count('*', Yii::$app->db);
        }

        return $counts;
    }

    private function assertFixtureEvidenceUnchanged(array $expectedEvidence): void
    {
        foreach ($expectedEvidence as $ticketId => $expected) {
            $actual = (string)(new \yii\db\Query())
                ->select('evidence_json')
                ->from('{{%mall_customer_service_ticket}}')
                ->where(['id' => (int)$ticketId])
                ->scalar(Yii::$app->db);
            if ($actual !== $expected) {
                $this->fail("Fixture ticket {$ticketId} evidence_json changed.");
                return;
            }
        }
        $this->ok('Complaint evidence JSON was not mutated by the gate service.');
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
        $this->ok('Business tables for orders, payments, chats, files, and funds were not mutated.');
    }

    private function assertTotal(array $report, string $key, int $expected, string $message): void
    {
        $actual = (int)($report['totals'][$key] ?? -1);
        $this->assertSameInt($expected, $actual, $message);
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

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function requireColumns(string $table, array $columns): void
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        if ($schema === null) {
            $this->fail("Missing table {$table}.");
            return;
        }
        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                $this->fail("Missing column {$table}.{$column}.");
                return;
            }
        }
        $this->ok("Schema contains required columns for {$table}.");
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
        $this->ok("File keeps disabled write boundary: {$path}");
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

    private function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
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
