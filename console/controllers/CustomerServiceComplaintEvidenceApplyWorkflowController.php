<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceComplaintEvidenceApplyWorkflowService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceComplaintEvidenceApplyWorkflowController extends Controller
{
    public $ticketId = 0;
    public $evidenceJson = '';
    public $evidenceJsonFile = '';
    public $storeId = 0;
    public $outputDir = '';
    public $fixture = false;
    public $apply = false;
    public $confirmApply = '';
    public $operatorUserId = 1;
    public $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM;
    public $remark = '';
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'ticketId',
            'evidenceJson',
            'evidenceJsonFile',
            'storeId',
            'outputDir',
            'fixture',
            'apply',
            'confirmApply',
            'operatorUserId',
            'operatorType',
            'remark',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service complaint evidence apply workflow\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkBackendBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            if ($this->apply && $this->confirmApply !== 'COMPLAINT_EVIDENCE_APPLY') {
                $this->fail('Real complaint evidence apply requires --confirmApply=COMPLAINT_EVIDENCE_APPLY.');
            } else {
                $report = (new CustomerServiceComplaintEvidenceApplyWorkflowService())->run(
                    (int)$this->ticketId,
                    $this->loadEvidenceJson(),
                    (bool)$this->apply,
                    (int)$this->operatorUserId,
                    (string)$this->operatorType,
                    (int)$this->storeId,
                    (string)$this->remark
                );
                $this->recordSkippedIssues($report);
                $paths = $this->writeExport($report, false);
                $this->stdout("Markdown: {$paths['md']}\nCSV: {$paths['csv']}\n");
            }
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
        $this->requireFileContains('common/services/mall/CustomerServiceComplaintEvidenceApplyWorkflowService.php', [
            'class CustomerServiceComplaintEvidenceApplyWorkflowService',
            'function run(',
            'Mongoyia Customer Service Complaint Evidence Apply Workflow',
            'customer-service-complaint-evidence-apply',
            'Apply mode writes only complaint ticket evidence_json',
        ]);
        $this->requireFileContains('console/controllers/CustomerServiceComplaintEvidenceApplyWorkflowController.php', [
            'class CustomerServiceComplaintEvidenceApplyWorkflowController',
            'COMPLAINT_EVIDENCE_APPLY',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_APPLY_WORKFLOW_V1',
            'data-mongoyia-customer-service-complaint-evidence-gate="reserved"',
            'data-mongoyia-customer-service-complaint-evidence-apply="disabled"',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceComplaintEvidenceApplyWorkflow',
            'customer-service complaint evidence apply workflow Phase 6 closure',
            'customer-service-complaint-evidence-apply-workflow/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceComplaintEvidenceApplyWorkflowController.php',
            'CustomerServiceComplaintEvidenceApplyWorkflowService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceComplaintEvidenceApplyWorkflowPath',
            'mongoyia-customer-service-complaint-evidence-apply-workflow-*.md',
            'Customer-service complaint evidence apply workflow result',
        ]);
        $this->requireFileContains('docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_APPLY_WORKFLOW_V1',
            'customer-service complaint evidence apply workflow',
            'COMPLAINT_EVIDENCE_APPLY',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'customer-service-complaint-evidence-apply-workflow/run',
            'mongoyia-customer-service-complaint-evidence-apply-workflow-*.md',
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
            'product_id',
            'order_id',
            'chat_uuid',
            'title',
            'evidence_json',
            'updated_at',
            'updated_by',
            'status',
        ]);
        $this->requireColumns('{{%mall_customer_service_event}}', [
            'id',
            'ticket_id',
            'event_type',
            'from_status',
            'to_status',
            'operator_user_id',
            'operator_type',
            'content',
            'metadata_json',
            'remark',
            'status',
        ]);
    }

    private function checkBackendBoundary(): void
    {
        $this->section('Backend boundary');
        $this->requireFileNotContains('backend/modules/mall/controllers/KfController.php', [
            'actionComplaintEvidenceApply',
            'COMPLAINT_EVIDENCE_APPLY',
        ]);
        $this->requireFileNotContains('backend/modules/mall/views/kf/ticket-view.php', [
            'data-mongoyia-customer-service-complaint-evidence-apply="enabled"',
            'name="evidence_json"',
            'actionComplaintEvidenceApply',
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
            $this->fail('Need two active stores and one active user for customer-service complaint evidence apply workflow fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $service = new CustomerServiceComplaintEvidenceApplyWorkflowService();
            $businessCounts = $this->businessTableCounts();
            $fixtureDate = '2037-01-07';
            $now = strtotime($fixtureDate . ' 10:00:00');
            $missingId = $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, 'Complaint evidence apply missing fixture', '', $now);
            $validId = $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, 'Complaint evidence apply valid fixture', '{"source":"fixture","state":"old"}', $now + 60);
            $crossStoreId = $this->createTicket($storeIds[1], $userId, CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, 'Complaint evidence apply cross-store fixture', '', $now + 120);
            $nonComplaintId = $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, 'Complaint evidence apply non-complaint fixture', '', $now + 180);

            $newEvidence = '{"source":"fixture","type":"complaint-evidence-apply","files":[{"name":"proof.png","sha256":"abc123"}],"notes":["reviewed"]}';
            $eventCount = $this->eventCount();
            $missingBefore = $this->ticketRow($missingId);

            $dryRun = $service->run($missingId, $newEvidence, false, 71, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0], 'complaint evidence apply fixture dry-run');
            $this->assertSameInt(0, (int)$dryRun['written'], 'Dry-run writes no ticket evidence.');
            $this->assertTrue(!empty($dryRun['dryRun']), 'Dry-run report is marked as dry-run.');
            $this->assertSameInt($eventCount, $this->eventCount(), 'Dry-run appends no event rows.');
            $this->assertSameString((string)$missingBefore['evidence_json'], (string)$this->ticketRow($missingId)['evidence_json'], 'Dry-run leaves evidence_json unchanged.');
            $this->assertSameString((string)$missingBefore['ticket_status'], (string)$this->ticketRow($missingId)['ticket_status'], 'Dry-run leaves ticket status unchanged.');

            $applyReport = $service->run($missingId, $newEvidence, true, 71, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0], 'complaint evidence apply fixture apply');
            $this->assertSameInt(1, (int)$applyReport['written'], 'Apply writes one complaint evidence JSON.');
            $this->assertTrue((int)$applyReport['eventId'] > 0, 'Apply appends one event audit row.');
            $this->assertSameInt($eventCount + 1, $this->eventCount(), 'Apply changes only the event row count.');
            $appliedTicket = $this->ticketRow($missingId);
            $this->assertSameString($applyReport['newEvidenceJson'], (string)$appliedTicket['evidence_json'], 'Apply stores normalized evidence JSON.');
            $this->assertSameString((string)$missingBefore['ticket_status'], (string)$appliedTicket['ticket_status'], 'Apply leaves ticket status unchanged.');
            $this->assertEventMetadata((int)$applyReport['eventId'], 'customer-service-complaint-evidence-apply', 'Apply event metadata records audited source.');

            $repeat = $service->run($missingId, $newEvidence, true, 71, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0], 'complaint evidence apply fixture repeat');
            $this->assertSkippedReason($repeat, 'evidence JSON unchanged', 'Repeated same evidence is blocked.');
            $this->assertSameInt($eventCount + 1, $this->eventCount(), 'Repeated blocked apply appends no event rows.');

            $invalid = $service->run($validId, '{"source":"fixture"', false, 71, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0], 'complaint evidence apply fixture invalid');
            $this->assertSkippedReason($invalid, 'valid evidence JSON is required', 'Invalid evidence JSON is blocked.');
            $this->assertSameString('{"source":"fixture","state":"old"}', (string)$this->ticketRow($validId)['evidence_json'], 'Invalid evidence attempt leaves existing evidence JSON unchanged.');

            $nonComplaint = $service->run($nonComplaintId, $newEvidence, false, 71, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0], 'complaint evidence apply fixture non-complaint');
            $this->assertSkippedReason($nonComplaint, 'ticket is not complaint', 'Non-complaint ticket is blocked.');

            $crossStore = $service->run($crossStoreId, $newEvidence, false, 71, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0], 'complaint evidence apply fixture cross-store');
            $this->assertSkippedReason($crossStore, 'ticket not found or out of scope', 'Store-scoped cross-store ticket is blocked.');

            $paths = $this->writeExport($applyReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Complaint Evidence Apply Workflow',
                '- Mode: apply',
                '- Written: 1',
                'Apply mode writes only complaint ticket evidence_json',
                'Backend complaint evidence upload/write controls remain disabled',
            ]);
            $this->assertFileContains($paths['csv'], [
                'mode,ticket_id,store_id,operator_user_id,operator_type,written,event_id',
                'apply',
                'valid_evidence_json',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->ok('Customer-service complaint evidence apply workflow fixture data rolled back; evidence files are kept for delivery index.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service complaint evidence apply workflow fixture failed: ' . $e->getMessage());
        }
    }

    private function loadEvidenceJson(): string
    {
        $file = trim((string)$this->evidenceJsonFile);
        if ($file === '') {
            return (string)$this->evidenceJson;
        }

        $path = Yii::getAlias($file);
        if (!is_file($path)) {
            $this->fail("Evidence JSON file not found: {$file}.");
            return '';
        }

        return (string)file_get_contents($path);
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-complaint-evidence-apply-workflow-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceComplaintEvidenceApplyWorkflowService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createTicket(int $storeId, int $userId, string $ticketType, string $title, string $evidenceJson, int $createdAt): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSEAPPLY-' . date('YmdHis', $createdAt) . '-' . mt_rand(1000, 9999),
            'ticket_type' => $ticketType,
            'ticket_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
            'priority' => CustomerServiceAdvancedService::PRIORITY_HIGH,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 992000 + mt_rand(100, 999),
            'order_sn' => 'CS-EVIDENCE-APPLY-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'complaint_evidence_apply_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'complaint_evidence_apply_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-complaint-evidence-apply-workflow/run fixture.',
            'result' => '',
            'evidence_json' => $evidenceJson,
            'first_response_at' => 0,
            'resolved_at' => 0,
            'closed_at' => 0,
            'remark' => 'complaint evidence apply workflow fixture',
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

    private function ticketRow(int $ticketId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId])
            ->one(Yii::$app->db) ?: [];
    }

    private function eventCount(): int
    {
        return (int)(new \yii\db\Query())->from('{{%mall_customer_service_event}}')->count('*', Yii::$app->db);
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
        $this->ok('Orders, payments, chats, files, funds, and statistics were not mutated by complaint evidence apply workflow.');
    }

    private function assertEventMetadata(int $eventId, string $source, string $message): void
    {
        $metadataJson = (string)(new \yii\db\Query())
            ->select('metadata_json')
            ->from('{{%mall_customer_service_event}}')
            ->where(['id' => $eventId])
            ->scalar(Yii::$app->db);
        $metadata = json_decode($metadataJson, true);
        if (!is_array($metadata) || (string)($metadata['source'] ?? '') !== $source) {
            $this->fail($message);
            return;
        }
        $this->ok($message);
    }

    private function assertSkippedReason(array $report, string $expected, string $message): void
    {
        $actual = (string)($report['skipped'][0]['reason'] ?? '');
        if ($actual !== $expected) {
            $this->fail("{$message} Expected '{$expected}', got '{$actual}'.");
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

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            $this->fail($message);
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
        $this->ok("File keeps disabled backend boundary: {$path}");
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

    private function recordSkippedIssues(array $report): void
    {
        foreach (($report['skipped'] ?? []) as $row) {
            $this->warnings++;
            $this->stdout('WARN ' . (string)($row['reason'] ?? 'skipped') . "\n");
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
