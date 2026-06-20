<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceSlaReadinessService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceSlaReadinessController extends Controller
{
    public $storeId = 0;
    public $ticketType = '';
    public $dateFrom = '';
    public $dateTo = '';
    public $firstResponseSeconds = 1800;
    public $resolutionSeconds = 86400;
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
            'limit',
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service SLA readiness\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceSlaReadinessService())->run(
                (int)$this->storeId,
                (string)$this->ticketType,
                (string)$this->dateFrom,
                (string)$this->dateTo,
                (int)$this->firstResponseSeconds,
                (int)$this->resolutionSeconds,
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
        $this->requireFileContains('common/services/mall/CustomerServiceSlaReadinessService.php', [
            'class CustomerServiceSlaReadinessService',
            'function run(',
            'Mongoyia Customer Service SLA Readiness',
            'This report is read-only evidence',
            'first_response_breached',
            'resolution_breached',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionSlaReadiness',
            'CustomerServiceSlaReadinessService',
            'sendContentAsFile',
            'sla-readiness',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_SLA_READINESS_BACKEND_V1',
            'data-mongoyia-customer-service-export-sla="csv"',
            'sla-readiness',
        ]);
        $this->requireFileContains('console/migrations/m260619_113000_mongoyia_customer_service_sla_readiness_permission.php', [
            '/mall/kf/sla-readiness',
            '客服SLA就绪导出',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceSlaReadiness',
            'customer-service SLA readiness Phase 6 closure',
            'customer-service-sla-readiness/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceSlaReadinessService.php',
            'CustomerServiceSlaReadinessController.php',
            'm260619_113000_mongoyia_customer_service_sla_readiness_permission.php',
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
            ->where(['path' => '/mall/kf/sla-readiness', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/sla-readiness. Run migration m260619_113000_mongoyia_customer_service_sla_readiness_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/sla-readiness');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have customer-service SLA readiness permission.');
            return;
        }
        $this->ok('Seller role has customer-service SLA readiness permission.');
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service SLA readiness fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new CustomerServiceSlaReadinessService();
            $now = time();
            $dateFrom = date('Y-m-d', $now - 4 * 86400);
            $dateTo = date('Y-m-d', $now);
            $firstResponseSla = 1800;
            $resolutionSla = 86400;

            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'SLA good resolved fixture', 'Resolved within SLA.', $now - 3600, $now - 3000, $now - 1800, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, 'SLA first-response breach fixture', '', $now - 10800, $now - 3600, 0, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'SLA resolution breach fixture', 'Resolved slowly by fixture.', $now - 172800, $now - 171600, $now - 64800, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, 'SLA missing result fixture', '', $now - 3600, $now - 3000, $now - 1800, $now - 600);
            $this->createTicket($storeIds[1], $userId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'SLA other-store breach fixture', '', $now - 172800, 0, 0, 0);

            $storeReport = $service->run($storeIds[0], '', $dateFrom, $dateTo, $firstResponseSla, $resolutionSla, 20);
            $this->assertSameInt(4, (int)$storeReport['rowsScanned'], 'Store-scoped SLA report includes four fixture rows.');
            $this->assertTotal($storeReport, 'ticket_count', 4, 'Store-scoped ticket count matches fixture.');
            $this->assertTotal($storeReport, 'in_progress_count', 1, 'Store-scoped in-progress count matches fixture.');
            $this->assertTotal($storeReport, 'resolved_count', 2, 'Store-scoped resolved count matches fixture.');
            $this->assertTotal($storeReport, 'closed_count', 1, 'Store-scoped closed count matches fixture.');
            $this->assertTotal($storeReport, 'open_count', 1, 'Store-scoped open count matches fixture.');
            $this->assertTotal($storeReport, 'first_response_breached_count', 1, 'Store-scoped first-response breach count matches fixture.');
            $this->assertTotal($storeReport, 'resolution_breached_count', 1, 'Store-scoped resolution breach count matches fixture.');
            $this->assertTotal($storeReport, 'missing_result_count', 1, 'Store-scoped missing-result count matches fixture.');

            $allReport = $service->run(0, '', $dateFrom, $dateTo, $firstResponseSla, $resolutionSla, 20);
            $this->assertSameInt(5, (int)$allReport['rowsScanned'], 'All-store SLA report includes cross-store pending row.');
            $this->assertTotal($allReport, 'first_response_breached_count', 2, 'All-store first-response breaches include cross-store row.');
            $this->assertTotal($allReport, 'resolution_breached_count', 2, 'All-store resolution breaches include cross-store row.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service SLA Readiness',
                '- Result: PASS',
                '| Tickets | 4 |',
                '| First-response SLA breaches | 1 |',
                'This report is read-only evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'ticket_id,ticket_sn,ticket_type,store_id,ticket_status,priority,order_id,order_sn,created_at,first_response_at,resolved_at,closed_at,first_response_seconds,resolution_seconds,first_response_breached,resolution_breached,missing_result,title',
                'SLA first-response breach fixture',
                'SLA missing result fixture',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service SLA readiness fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service SLA readiness fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-sla-readiness-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceSlaReadinessService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createTicket(int $storeId, int $userId, string $ticketStatus, string $title, string $result, int $createdAt, int $firstResponseAt, int $resolvedAt, int $closedAt): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSSLA-' . date('YmdHis', $createdAt) . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST,
            'ticket_status' => $ticketStatus,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 992000 + mt_rand(100, 999),
            'order_sn' => 'CS-SLA-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'sla_readiness_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'sla_readiness_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-sla-readiness/run fixture.',
            'result' => $result,
            'evidence_json' => '',
            'first_response_at' => $firstResponseAt,
            'resolved_at' => $resolvedAt,
            'closed_at' => $closedAt,
            'remark' => 'sla readiness fixture',
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
