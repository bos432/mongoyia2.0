<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceSlaHandlingService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceSlaHandlingController extends Controller
{
    public $storeId = 0;
    public $ticketType = '';
    public $dateFrom = '';
    public $dateTo = '';
    public $firstResponseSeconds = 1800;
    public $resolutionSeconds = 86400;
    public $watchWindowSeconds = 3600;
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
            'firstResponseSeconds',
            'resolutionSeconds',
            'watchWindowSeconds',
            'limit',
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service SLA handling dry-run\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceSlaHandlingService())->run(
                (int)$this->storeId,
                (string)$this->ticketType,
                (string)$this->dateFrom,
                (string)$this->dateTo,
                (int)$this->firstResponseSeconds,
                (int)$this->resolutionSeconds,
                (int)$this->watchWindowSeconds,
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
        $this->requireFileContains('common/services/mall/CustomerServiceSlaHandlingService.php', [
            'class CustomerServiceSlaHandlingService',
            'function run(',
            'Mongoyia Customer Service SLA Handling Dry Run',
            'automatic SLA handling',
            'suggested_action',
            'resolution_overdue',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionSlaHandling',
            'CustomerServiceSlaHandlingService',
            'sendContentAsFile',
            'sla-handling',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_SLA_HANDLING_BACKEND_V1',
            'data-mongoyia-customer-service-export-sla-handling="csv"',
            'sla-handling',
        ]);
        $this->requireFileContains('console/migrations/m260619_120000_mongoyia_customer_service_sla_handling_permission.php', [
            '/mall/kf/sla-handling',
            '客服SLA处理建议导出',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceSlaHandling',
            'customer-service SLA handling dry-run Phase 6 closure',
            'customer-service-sla-handling/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceSlaHandlingService.php',
            'CustomerServiceSlaHandlingController.php',
            'm260619_120000_mongoyia_customer_service_sla_handling_permission.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceSlaHandlingPath',
            'mongoyia-customer-service-sla-handling-*.md',
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
            'title',
            'result',
            'first_response_at',
            'resolved_at',
            'closed_at',
            'created_at',
            'status',
        ]);
    }

    private function checkPermissions(): void
    {
        $this->section('Permissions');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/kf/sla-handling', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/sla-handling. Run migration m260619_120000_mongoyia_customer_service_sla_handling_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/sla-handling');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have customer-service SLA handling permission.');
            return;
        }
        $this->ok('Seller role has customer-service SLA handling permission.');
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service SLA handling fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new CustomerServiceSlaHandlingService();
            $now = time();
            $dateFrom = date('Y-m-d', $now - 3 * 86400);
            $dateTo = date('Y-m-d', $now);
            $firstResponseSla = 1800;
            $resolutionSla = 86400;
            $watchWindow = 900;

            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'SLA handling first response overdue fixture', '', $now - 7200, 0, 0, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, 'SLA handling resolution overdue fixture', '', $now - 172800, $now - 172200, 0, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'SLA handling result writeback fixture', '', $now - 5400, $now - 4800, $now - 1800, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'SLA handling first response watch fixture', '', $now - 1200, 0, 0, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, 'SLA handling no action fixture', 'Closed with result.', $now - 3600, $now - 3000, $now - 1800, $now - 600);
            $this->createTicket($storeIds[1], $userId, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, 'SLA handling other-store resolution overdue fixture', '', $now - 172800, $now - 172000, 0, 0);

            $storeReport = $service->run($storeIds[0], '', $dateFrom, $dateTo, $firstResponseSla, $resolutionSla, $watchWindow, 20);
            $this->assertSameInt(5, (int)$storeReport['rowsScanned'], 'Store-scoped SLA handling report includes five fixture rows.');
            $this->assertTotal($storeReport, 'ticket_count', 5, 'Store-scoped ticket count matches fixture.');
            $this->assertTotal($storeReport, 'first_response_overdue_count', 1, 'Store-scoped first-response overdue count matches fixture.');
            $this->assertTotal($storeReport, 'resolution_overdue_count', 1, 'Store-scoped resolution overdue count matches fixture.');
            $this->assertTotal($storeReport, 'result_writeback_required_count', 1, 'Store-scoped result writeback count matches fixture.');
            $this->assertTotal($storeReport, 'first_response_watch_count', 1, 'Store-scoped first-response watch count matches fixture.');
            $this->assertTotal($storeReport, 'no_action_count', 1, 'Store-scoped no-action count matches fixture.');
            $this->assertTotal($storeReport, 'action_required_count', 4, 'Store-scoped action-required count matches fixture.');

            $allReport = $service->run(0, '', $dateFrom, $dateTo, $firstResponseSla, $resolutionSla, $watchWindow, 20);
            $this->assertSameInt(6, (int)$allReport['rowsScanned'], 'All-store SLA handling report includes cross-store row.');
            $this->assertTotal($allReport, 'resolution_overdue_count', 2, 'All-store resolution overdue count includes cross-store row.');
            $this->assertTotal($allReport, 'action_required_count', 5, 'All-store action-required count includes cross-store row.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service SLA Handling Dry Run',
                '- Result: PASS',
                '| First response overdue | 1 |',
                '| Resolution overdue | 1 |',
                '| Result writeback required | 1 |',
                'This report is dry-run/readiness evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'ticket_id,ticket_sn,ticket_type,store_id,ticket_status,priority,order_id,order_sn,created_at,first_response_at,resolved_at,closed_at,first_response_seconds,resolution_seconds,first_response_overdue,resolution_overdue,result_writeback_required,first_response_watch,resolution_watch,suggested_action,title',
                'first_response_overdue',
                'resolution_overdue',
                'result_writeback_required',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service SLA handling fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service SLA handling fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-sla-handling-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceSlaHandlingService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createTicket(int $storeId, int $userId, string $ticketStatus, string $title, string $result, int $createdAt, int $firstResponseAt, int $resolvedAt, int $closedAt): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSSLH-' . date('YmdHis', $createdAt) . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST,
            'ticket_status' => $ticketStatus,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 994000 + mt_rand(100, 999),
            'order_sn' => 'CS-SLA-HANDLE-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'sla_handling_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'sla_handling_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-sla-handling/run fixture.',
            'result' => $result,
            'evidence_json' => '',
            'first_response_at' => $firstResponseAt,
            'resolved_at' => $resolvedAt,
            'closed_at' => $closedAt,
            'remark' => 'sla handling fixture',
            'status' => 1,
            'created_at' => $createdAt,
            'updated_at' => max($createdAt, $firstResponseAt, $resolvedAt, $closedAt),
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
