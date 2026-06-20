<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceResolutionExportService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceResolutionExportController extends Controller
{
    public $storeId = 0;
    public $ticketType = '';
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
            'ticketType',
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
        $this->stdout("Mongoyia customer-service resolution export\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceResolutionExportService())->run(
                (int)$this->storeId,
                (string)$this->ticketType,
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
        $this->requireFileContains('common/services/mall/CustomerServiceResolutionExportService.php', [
            'class CustomerServiceResolutionExportService',
            'function run(',
            'Mongoyia Customer Service Resolution Export',
            'This report is read-only evidence',
            'TICKET_STATUS_RESOLVED',
            'TICKET_STATUS_CLOSED',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionResolutionExport',
            'CustomerServiceResolutionExportService',
            'sendContentAsFile',
            'resolution-export',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_RESOLUTION_EXPORT_BACKEND_V1',
            'data-mongoyia-customer-service-export-resolution="csv"',
            'resolution-export',
        ]);
        $this->requireFileContains('console/migrations/m260619_112000_mongoyia_customer_service_resolution_export_permission.php', [
            '/mall/kf/resolution-export',
            '客服解决结果导出',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceResolutionExport',
            'customer-service resolution export Phase 6 closure',
            'customer-service-resolution-export/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceResolutionExportService.php',
            'CustomerServiceResolutionExportController.php',
            'm260619_112000_mongoyia_customer_service_resolution_export_permission.php',
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
            'title',
            'result',
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
            ->where(['path' => '/mall/kf/resolution-export', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/resolution-export. Run migration m260619_112000_mongoyia_customer_service_resolution_export_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/resolution-export');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have customer-service resolution export permission.');
            return;
        }
        $this->ok('Seller role has customer-service resolution export permission.');
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service resolution export fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new CustomerServiceResolutionExportService();
            $fixtureDate = '2037-01-03';
            $now = strtotime($fixtureDate . ' 10:00:00');
            $resolvedOrderAssistId = $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'Resolution order-assist fixture', 'Order assistance resolved by fixture.', $now, $now + 300, 0);
            $closedComplaintId = $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, 'Resolution complaint fixture', 'Complaint closed by fixture.', $now + 60, $now + 660, $now + 900);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, 'Resolution in-progress excluded fixture', 'Not exported.', $now + 90, 0, 0);
            $otherStoreId = $this->createTicket($storeIds[1], $userId, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'Resolution other-store fixture', 'Cross-store resolved by fixture.', $now + 120, $now + 240, 0);

            $this->createEvent($resolvedOrderAssistId, '', CustomerServiceAdvancedService::TICKET_STATUS_PENDING);
            $this->createEvent($resolvedOrderAssistId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED);
            $this->createEvent($closedComplaintId, '', CustomerServiceAdvancedService::TICKET_STATUS_PENDING);
            $this->createEvent($closedComplaintId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED);
            $this->createEvent($closedComplaintId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED);
            $this->createEvent($otherStoreId, '', CustomerServiceAdvancedService::TICKET_STATUS_PENDING);
            $this->createEvent($otherStoreId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED);

            $storeReport = $service->run($storeIds[0], '', $fixtureDate, $fixtureDate, 20);
            $this->assertSameInt(2, (int)$storeReport['rowsScanned'], 'Store-scoped export includes two resolved/closed fixture rows.');
            $this->assertTotal($storeReport, 'resolution_count', 2, 'Store-scoped resolution total matches fixture.');
            $this->assertTotal($storeReport, 'order_assist_count', 1, 'Store-scoped order-assist count matches fixture.');
            $this->assertTotal($storeReport, 'complaint_count', 1, 'Store-scoped complaint count matches fixture.');
            $this->assertTotal($storeReport, 'resolved_count', 1, 'Store-scoped resolved count matches fixture.');
            $this->assertTotal($storeReport, 'closed_count', 1, 'Store-scoped closed count matches fixture.');
            $this->assertTotal($storeReport, 'with_result_count', 2, 'Store-scoped result count matches fixture.');
            $this->assertTotal($storeReport, 'event_count', 5, 'Store-scoped event count matches fixture.');
            $this->assertTotal($storeReport, 'status_change_event_count', 3, 'Store-scoped status-change event count matches fixture.');
            $this->assertTotal($storeReport, 'resolution_seconds_total', 900, 'Store-scoped resolution seconds match fixture.');

            $complaintReport = $service->run($storeIds[0], CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, $fixtureDate, $fixtureDate, 20);
            $this->assertSameInt(1, (int)$complaintReport['rowsScanned'], 'Ticket-type filter returns one closed complaint.');

            $allReport = $service->run(0, '', $fixtureDate, $fixtureDate, 20);
            $this->assertSameInt(3, (int)$allReport['rowsScanned'], 'All-store export includes cross-store resolved row.');
            $this->assertTotal($allReport, 'resolved_count', 2, 'All-store resolved count includes cross-store row.');
            $this->assertTotal($allReport, 'resolution_seconds_total', 1020, 'All-store resolution seconds include cross-store row.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Resolution Export',
                '- Result: PASS',
                '| Resolution tickets | 2 |',
                'This report is read-only evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'ticket_id,ticket_sn,ticket_type,store_id,ticket_status,priority,order_id,order_sn,customer_user_id,merchant_user_id,platform_user_id,event_count,status_change_event_count,first_response_at,resolved_at,closed_at,resolution_seconds,has_result,title,result',
                'Resolution order-assist fixture',
                'Resolution complaint fixture',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service resolution export fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service resolution export fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-resolution-export-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceResolutionExportService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createTicket(int $storeId, int $userId, string $ticketType, string $status, string $title, string $result, int $firstResponseAt, int $resolvedAt, int $closedAt): int
    {
        $now = $firstResponseAt > 0 ? $firstResponseAt : time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSRES-' . date('YmdHis', $now) . '-' . mt_rand(1000, 9999),
            'ticket_type' => $ticketType,
            'ticket_status' => $status,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 991000 + mt_rand(100, 999),
            'order_sn' => 'CS-RESOLUTION-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'resolution_export_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'resolution_export_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-resolution-export/run fixture.',
            'result' => $result,
            'evidence_json' => '',
            'first_response_at' => $firstResponseAt,
            'resolved_at' => $resolvedAt,
            'closed_at' => $closedAt,
            'remark' => 'resolution export fixture',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $closedAt > 0 ? $closedAt : ($resolvedAt > 0 ? $resolvedAt : $now),
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
            'content' => 'Resolution export fixture event.',
            'metadata_json' => '{"source":"customer-service-resolution-export"}',
            'remark' => 'resolution export fixture',
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
