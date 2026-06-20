<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceStatApplyWorkflowService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceStatApplyWorkflowController extends Controller
{
    public $storeId = 0;
    public $dateFrom = '';
    public $dateTo = '';
    public $limit = 500;
    public $outputDir = '';
    public $fixture = false;
    public $apply = false;
    public $confirmApply = '';
    public $operatorUserId = 1;
    public $remark = '';
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'storeId',
            'dateFrom',
            'dateTo',
            'limit',
            'outputDir',
            'fixture',
            'apply',
            'confirmApply',
            'operatorUserId',
            'remark',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service stat apply workflow\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkBackendBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            if ($this->apply && $this->confirmApply !== 'STAT_APPLY') {
                $this->fail('Real statistic apply requires --confirmApply=STAT_APPLY.');
            } else {
                $report = (new CustomerServiceStatApplyWorkflowService())->run(
                    (int)$this->storeId,
                    (string)$this->dateFrom,
                    (string)$this->dateTo,
                    (int)$this->limit,
                    (bool)$this->apply,
                    (int)$this->operatorUserId,
                    (string)$this->remark
                );
                $this->recordReportIssues($report);
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
        $this->requireFileContains('common/services/mall/CustomerServiceStatApplyWorkflowService.php', [
            'class CustomerServiceStatApplyWorkflowService',
            'function run(',
            'Mongoyia Customer Service Stat Apply Workflow',
            'Applied inserts',
            'insertAuditLog',
        ]);
        $this->requireFileContains('console/migrations/m260619_121000_mongoyia_customer_service_stat_apply_log.php', [
            'mall_customer_service_stat_apply_log',
            'batch_sn',
            'before_json',
            'after_json',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_WORKFLOW_V1',
            'data-mongoyia-customer-service-stat-apply-workflow="reserved"',
            'data-mongoyia-customer-service-stat-apply="disabled"',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceStatApplyWorkflow',
            'customer-service stat apply workflow Phase 6 closure',
            'customer-service-stat-apply-workflow/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceStatApplyWorkflowController.php',
            'CustomerServiceStatApplyWorkflowService.php',
            'm260619_121000_mongoyia_customer_service_stat_apply_log.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceStatApplyWorkflowPath',
            'mongoyia-customer-service-stat-apply-workflow-*.md',
            'Customer-service stat apply workflow result',
        ]);
        $this->requireFileContains('docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_WORKFLOW_V1',
            'customer-service stat apply workflow',
            'mall_customer_service_stat_apply_log',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'customer-service-stat-apply-workflow/run',
            'mongoyia-customer-service-stat-apply-workflow-*.md',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_customer_service_ticket}}', [
            'id',
            'ticket_type',
            'ticket_status',
            'store_id',
            'merchant_user_id',
            'platform_user_id',
            'chat_uuid',
            'first_response_at',
            'resolved_at',
            'created_at',
            'status',
        ]);
        $this->requireColumns('{{%mall_customer_service_stat_daily}}', [
            'id',
            'stat_date',
            'store_id',
            'service_user_id',
            'session_count',
            'ticket_count',
            'order_assist_count',
            'complaint_count',
            'resolved_count',
            'unresolved_count',
            'first_response_seconds_total',
            'resolved_seconds_total',
            'status',
        ]);
        $this->requireColumns('{{%mall_customer_service_stat_apply_log}}', [
            'id',
            'batch_sn',
            'stat_date',
            'store_id',
            'service_user_id',
            'operation',
            'stat_id',
            'source_ticket_count',
            'before_json',
            'after_json',
            'diff_summary',
            'operator_user_id',
            'applied_at',
            'status',
        ]);
    }

    private function checkBackendBoundary(): void
    {
        $this->section('Backend boundary');
        $this->requireFileNotContains('backend/modules/mall/controllers/KfController.php', [
            'actionStatApplyWrite',
            'confirmApply',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'disabled',
            '统计重算写入待启用',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service stat apply workflow fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $service = new CustomerServiceStatApplyWorkflowService();
            $businessCounts = $this->businessTableCounts();
            $fixtureDate = '2037-01-05';
            $statDate = 20370105;
            $now = strtotime($fixtureDate . ' 10:00:00');

            $this->createTicket($storeIds[0], $userId, 990701, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Stat workflow update order fixture', $now, 0, 0, 'stat_workflow_update_a');
            $this->createTicket($storeIds[0], $userId, 990701, CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'Stat workflow update complaint fixture', $now + 60, 300, 3600, 'stat_workflow_update_b');
            $this->createTicket($storeIds[0], $userId, 990702, CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Stat workflow insert fixture', $now + 120, 0, 0, 'stat_workflow_insert');
            $this->createTicket($storeIds[0], $userId, 990703, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, 'Stat workflow skip fixture', $now + 180, 60, 600, 'stat_workflow_skip');

            $this->createStat($statDate, $storeIds[0], 990701, 0, 1, 1, 0, 0, 1, 0, 0);
            $this->createStat($statDate, $storeIds[0], 990703, 1, 1, 1, 0, 1, 0, 60, 600);
            $statSnapshot = $this->statSnapshot($statDate);
            $logCount = $this->logCount();

            $dryRun = $service->run($storeIds[0], $fixtureDate, $fixtureDate, 20, false, 1, 'stat apply workflow fixture dry-run');
            $this->assertTotal($dryRun, 'insert_count', 1, 'Dry-run plans one insert.');
            $this->assertTotal($dryRun, 'update_count', 1, 'Dry-run plans one update.');
            $this->assertTotal($dryRun, 'skip_count', 1, 'Dry-run plans one skip.');
            $this->assertApplyTotal($dryRun, 'audit_log_count', 0, 'Dry-run writes no audit rows.');
            $this->assertStatSnapshotUnchanged($statDate, $statSnapshot, 'Dry-run does not mutate stat rows.');
            $this->assertSameInt($logCount, $this->logCount(), 'Dry-run does not mutate stat apply logs.');

            $applyReport = $service->run($storeIds[0], $fixtureDate, $fixtureDate, 20, true, 1, 'stat apply workflow fixture apply');
            $this->assertApplyTotal($applyReport, 'applied_insert_count', 1, 'Apply writes one insert.');
            $this->assertApplyTotal($applyReport, 'applied_update_count', 1, 'Apply writes one update.');
            $this->assertApplyTotal($applyReport, 'logged_skip_count', 1, 'Apply logs one skip.');
            $this->assertApplyTotal($applyReport, 'audit_log_count', 3, 'Apply writes three audit rows.');
            $this->assertStatFields($this->statRow($statDate, $storeIds[0], 990701), [
                'session_count' => 2,
                'ticket_count' => 2,
                'order_assist_count' => 1,
                'complaint_count' => 1,
                'resolved_count' => 1,
                'unresolved_count' => 1,
                'first_response_seconds_total' => 300,
                'resolved_seconds_total' => 3600,
            ], 'Updated stat row matches source-ticket aggregation.');
            $this->assertStatFields($this->statRow($statDate, $storeIds[0], 990702), [
                'session_count' => 1,
                'ticket_count' => 1,
                'order_assist_count' => 0,
                'complaint_count' => 1,
                'resolved_count' => 0,
                'unresolved_count' => 1,
                'first_response_seconds_total' => 0,
                'resolved_seconds_total' => 0,
            ], 'Inserted stat row matches source-ticket aggregation.');
            $this->assertStatFields($this->statRow($statDate, $storeIds[0], 990703), [
                'session_count' => 1,
                'ticket_count' => 1,
                'order_assist_count' => 1,
                'complaint_count' => 0,
                'resolved_count' => 1,
                'unresolved_count' => 0,
                'first_response_seconds_total' => 60,
                'resolved_seconds_total' => 600,
            ], 'Skipped stat row remains unchanged.');
            $this->assertSameInt(3, $this->batchLogCount((string)$applyReport['batchSn']), 'Apply batch has three audit log rows.');

            $paths = $this->writeExport($applyReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Stat Apply Workflow',
                '- Mode: apply',
                '| Applied inserts | 1 |',
                '| Applied updates | 1 |',
                '| Logged skips | 1 |',
                'Apply mode writes only customer-service daily statistics',
            ]);
            $this->assertFileContains($paths['csv'], [
                'batch_sn,mode,stat_date,store_id,service_user_id,operation,applied_status',
                '20370105',
                'applied_update',
                'applied_insert',
                'logged_skip',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service stat apply workflow fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service stat apply workflow fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-stat-apply-workflow-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceStatApplyWorkflowService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createTicket(int $storeId, int $userId, int $serviceUserId, string $ticketType, string $ticketStatus, string $title, int $createdAt, int $firstResponseOffset, int $resolvedOffset, string $chatUuid): int
    {
        $firstResponseAt = $firstResponseOffset > 0 ? $createdAt + $firstResponseOffset : 0;
        $resolvedAt = $resolvedOffset > 0 ? $createdAt + $resolvedOffset : 0;
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSSAW-' . date('YmdHis', $createdAt) . '-' . mt_rand(1000, 9999),
            'ticket_type' => $ticketType,
            'ticket_status' => $ticketStatus,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 993000 + mt_rand(100, 999),
            'order_sn' => 'CS-STAT-WORKFLOW-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'stat_apply_workflow_user_' . $userId,
            'merchant_user_id' => $serviceUserId,
            'platform_user_id' => 1,
            'chat_uuid' => $chatUuid,
            'title' => $title,
            'content' => 'Created by customer-service-stat-apply-workflow/run fixture.',
            'result' => $resolvedAt > 0 ? 'Resolved by fixture.' : '',
            'evidence_json' => '',
            'first_response_at' => $firstResponseAt,
            'resolved_at' => $resolvedAt,
            'closed_at' => $ticketStatus === CustomerServiceAdvancedService::TICKET_STATUS_CLOSED ? $resolvedAt + 60 : 0,
            'remark' => 'stat apply workflow fixture',
            'status' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function createStat(int $statDate, int $storeId, int $serviceUserId, int $sessions, int $tickets, int $orderAssists, int $complaints, int $resolved, int $unresolved, int $firstResponseSeconds, int $resolvedSeconds): void
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_stat_daily}}', [
            'stat_date' => $statDate,
            'store_id' => $storeId,
            'service_user_id' => $serviceUserId,
            'session_count' => $sessions,
            'ticket_count' => $tickets,
            'order_assist_count' => $orderAssists,
            'complaint_count' => $complaints,
            'resolved_count' => $resolved,
            'unresolved_count' => $unresolved,
            'first_response_seconds_total' => $firstResponseSeconds,
            'resolved_seconds_total' => $resolvedSeconds,
            'remark' => 'customer-service stat apply workflow fixture',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function statSnapshot(int $statDate): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_customer_service_stat_daily}}')
            ->where(['stat_date' => $statDate])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    private function statRow(int $statDate, int $storeId, int $serviceUserId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_customer_service_stat_daily}}')
            ->where([
                'stat_date' => $statDate,
                'store_id' => $storeId,
                'service_user_id' => $serviceUserId,
                'status' => 1,
            ])
            ->one(Yii::$app->db) ?: [];
    }

    private function logCount(): int
    {
        return (int)(new \yii\db\Query())->from('{{%mall_customer_service_stat_apply_log}}')->count('*', Yii::$app->db);
    }

    private function batchLogCount(string $batchSn): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%mall_customer_service_stat_apply_log}}')
            ->where(['batch_sn' => $batchSn])
            ->count('*', Yii::$app->db);
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

    private function assertStatSnapshotUnchanged(int $statDate, array $expected, string $message): void
    {
        $actual = $this->statSnapshot($statDate);
        if ($actual !== $expected) {
            $this->fail($message);
            return;
        }
        $this->ok($message);
    }

    private function assertStatFields(array $row, array $expected, string $message): void
    {
        if (empty($row)) {
            $this->fail($message . ' Stat row missing.');
            return;
        }
        foreach ($expected as $field => $value) {
            if ((int)($row[$field] ?? -1) !== (int)$value) {
                $this->fail("{$message} Field {$field} expected {$value}, got " . (int)($row[$field] ?? -1) . '.');
                return;
            }
        }
        $this->ok($message);
    }

    private function assertTotal(array $report, string $key, int $expected, string $message): void
    {
        $actual = (int)($report['totals'][$key] ?? -1);
        $this->assertSameInt($expected, $actual, $message);
    }

    private function assertApplyTotal(array $report, string $key, int $expected, string $message): void
    {
        $actual = (int)($report['applyTotals'][$key] ?? -1);
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
