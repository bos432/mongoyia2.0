<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceComplaintExportService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceComplaintExportController extends Controller
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
        $this->stdout("Mongoyia customer-service complaint export\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceComplaintExportService())->run(
                (int)$this->storeId,
                (string)$this->ticketStatus,
                (string)$this->dateFrom,
                (string)$this->dateTo,
                (int)$this->limit
            );
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
        $this->requireFileContains('common/services/mall/CustomerServiceComplaintExportService.php', [
            'class CustomerServiceComplaintExportService',
            'function run(',
            'Mongoyia Customer Service Complaint Export',
            'This report is read-only evidence',
            'TICKET_TYPE_COMPLAINT',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionComplaintExport',
            'CustomerServiceComplaintExportService',
            'sendContentAsFile',
            'complaint-export',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EXPORT_BACKEND_V1',
            'data-mongoyia-customer-service-export-complaint="csv"',
            'complaint-export',
        ]);
        $this->requireFileContains('console/migrations/m260619_111000_mongoyia_customer_service_complaint_export_permission.php', [
            '/mall/kf/complaint-export',
            '客服投诉证据导出',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceComplaintExport',
            'customer-service complaint export Phase 6 closure',
            'customer-service-complaint-export/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceComplaintExportService.php',
            'CustomerServiceComplaintExportController.php',
            'm260619_111000_mongoyia_customer_service_complaint_export_permission.php',
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
            'priority',
            'store_id',
            'order_id',
            'order_sn',
            'customer_user_id',
            'merchant_user_id',
            'platform_user_id',
            'chat_uuid',
            'title',
            'evidence_json',
            'first_response_at',
            'resolved_at',
            'closed_at',
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
            'status',
        ]);
    }

    private function checkPermissions(): void
    {
        $this->section('Permissions');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/kf/complaint-export', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/complaint-export. Run migration m260619_111000_mongoyia_customer_service_complaint_export_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/complaint-export');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have customer-service complaint export permission.');
            return;
        }
        $this->ok('Seller role has customer-service complaint export permission.');
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service complaint export fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new CustomerServiceComplaintExportService();
            $fixtureDate = '2037-01-02';
            $now = strtotime($fixtureDate . ' 10:00:00');
            $pendingId = $this->createComplaint($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Complaint pending fixture', true, $now, 0, 0);
            $resolvedId = $this->createComplaint($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'Complaint resolved fixture', true, $now + 60, $now + 660, 0);
            $closedId = $this->createComplaint($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, 'Complaint closed fixture', false, $now + 120, $now + 720, $now + 900);
            $otherStoreId = $this->createComplaint($storeIds[1], $userId, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, 'Complaint other store fixture', true, $now + 180, 0, 0);
            $this->createEvent($pendingId, '', CustomerServiceAdvancedService::TICKET_STATUS_PENDING);
            $this->createEvent($resolvedId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS);
            $this->createEvent($resolvedId, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED);
            $this->createEvent($closedId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED);
            $this->createEvent($otherStoreId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS);

            $storeReport = $service->run($storeIds[0], '', $fixtureDate, $fixtureDate, 20);
            $this->assertSameInt(3, (int)$storeReport['rowsScanned'], 'Store-scoped export includes three complaint fixture rows.');
            $this->assertTotal($storeReport, 'complaint_count', 3, 'Store-scoped complaint total matches fixture.');
            $this->assertTotal($storeReport, 'pending_count', 1, 'Store-scoped pending count matches fixture.');
            $this->assertTotal($storeReport, 'resolved_count', 1, 'Store-scoped resolved count matches fixture.');
            $this->assertTotal($storeReport, 'closed_count', 1, 'Store-scoped closed count matches fixture.');
            $this->assertTotal($storeReport, 'with_evidence_count', 2, 'Store-scoped evidence count matches fixture.');
            $this->assertTotal($storeReport, 'event_count', 4, 'Store-scoped event count matches fixture.');
            $this->assertTotal($storeReport, 'resolution_seconds_total', 1200, 'Store-scoped resolution seconds match fixture.');

            $resolvedReport = $service->run($storeIds[0], CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, $fixtureDate, $fixtureDate, 20);
            $this->assertSameInt(1, (int)$resolvedReport['rowsScanned'], 'Status filter returns one resolved complaint.');

            $allReport = $service->run(0, '', $fixtureDate, $fixtureDate, 20);
            $this->assertSameInt(4, (int)$allReport['rowsScanned'], 'All-store export includes cross-store complaint row.');
            $this->assertTotal($allReport, 'in_progress_count', 1, 'All-store in-progress count includes cross-store row.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Complaint Export',
                '- Result: PASS',
                '| Complaints | 3 |',
                'This report is read-only evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'ticket_id,ticket_sn,store_id,ticket_status,priority,order_id,order_sn,customer_user_id,merchant_user_id,platform_user_id,chat_uuid,has_evidence,event_count,first_response_at,resolved_at,closed_at,resolution_seconds,title',
                'Complaint pending fixture',
                'Complaint resolved fixture',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service complaint export fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service complaint export fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-complaint-export-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceComplaintExportService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createComplaint(int $storeId, int $userId, string $status, string $title, bool $withEvidence, int $firstResponseAt, int $resolvedAt, int $closedAt): int
    {
        $now = $firstResponseAt > 0 ? $firstResponseAt : time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSEXP-' . date('YmdHis', $now) . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT,
            'ticket_status' => $status,
            'priority' => CustomerServiceAdvancedService::PRIORITY_HIGH,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 990000 + mt_rand(100, 999),
            'order_sn' => 'CS-COMPLAINT-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'complaint_export_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'complaint_export_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-complaint-export/run fixture.',
            'result' => $status === CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED ? 'Resolved by fixture.' : '',
            'evidence_json' => $withEvidence ? '{"source":"fixture","type":"complaint-evidence"}' : '',
            'first_response_at' => $firstResponseAt,
            'resolved_at' => $resolvedAt,
            'closed_at' => $closedAt,
            'remark' => 'complaint export fixture',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function createEvent(int $ticketId, string $fromStatus, string $toStatus): void
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
            'ticket_id' => $ticketId,
            'event_type' => $fromStatus === '' ? CustomerServiceAdvancedService::EVENT_TYPE_CREATE : CustomerServiceAdvancedService::EVENT_TYPE_STATUS_CHANGE,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'operator_user_id' => 1,
            'operator_type' => CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
            'content' => 'Complaint export fixture event.',
            'metadata_json' => '{"source":"customer-service-complaint-export"}',
            'remark' => 'complaint export fixture',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
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
