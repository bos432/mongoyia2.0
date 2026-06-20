<?php

namespace console\controllers;

use common\services\mall\CustomerServiceStatApplyLogReviewService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceStatApplyLogReviewController extends Controller
{
    public $storeId = 0;
    public $dateFrom = '';
    public $dateTo = '';
    public $batchSn = '';
    public $operation = '';
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
            'dateFrom',
            'dateTo',
            'batchSn',
            'operation',
            'limit',
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service stat apply log review\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();
        $this->checkBackendBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceStatApplyLogReviewService())->run(
                (int)$this->storeId,
                (string)$this->dateFrom,
                (string)$this->dateTo,
                (string)$this->batchSn,
                (string)$this->operation,
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
        $this->requireFileContains('common/services/mall/CustomerServiceStatApplyLogReviewService.php', [
            'class CustomerServiceStatApplyLogReviewService',
            'function run(',
            'Mongoyia Customer Service Stat Apply Log Review',
            'read-only audit evidence',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionStatApplyLog',
            'CustomerServiceStatApplyLogReviewService',
            'stat-apply-log',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_REVIEW_V1',
            'data-mongoyia-customer-service-stat-apply-log-review="link"',
            'stat-apply-log',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/stat-apply-log.php', [
            'MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_REVIEW_V1',
            'data-mongoyia-customer-service-stat-apply-log-review="readonly"',
            '统计写入审计',
        ]);
        $this->requireFileContains('console/migrations/m260619_122000_mongoyia_customer_service_stat_apply_log_review_permission.php', [
            '/mall/kf/stat-apply-log',
            '客服统计写入审计',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceStatApplyLogReview',
            'customer-service stat apply log review Phase 6 closure',
            'customer-service-stat-apply-log-review/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceStatApplyLogReviewController.php',
            'CustomerServiceStatApplyLogReviewService.php',
            'm260619_122000_mongoyia_customer_service_stat_apply_log_review_permission.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceStatApplyLogReviewPath',
            'mongoyia-customer-service-stat-apply-log-review-*.md',
            'Customer-service stat apply log review result',
        ]);
        $this->requireFileContains('docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_REVIEW_V1',
            'customer-service stat apply log review',
            '/backend/mall/kf/stat-apply-log',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'customer-service-stat-apply-log-review/run',
            'mongoyia-customer-service-stat-apply-log-review-*.md',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
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

    private function checkPermissions(): void
    {
        $this->section('Permissions');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/kf/stat-apply-log', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/stat-apply-log. Run migration m260619_122000_mongoyia_customer_service_stat_apply_log_review_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/stat-apply-log');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have customer-service stat apply log review permission.');
            return;
        }
        $this->ok('Seller role has customer-service stat apply log review permission.');
    }

    private function checkBackendBoundary(): void
    {
        $this->section('Backend boundary');
        $this->requireFileNotContains('backend/modules/mall/views/kf/stat-apply-log.php', [
            'method="post"',
            'data-mongoyia-customer-service-stat-apply="enabled"',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'data-mongoyia-customer-service-stat-apply="disabled"',
            '统计重算写入待启用',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        if (count($storeIds) < 2) {
            $this->fail('Need two active stores for customer-service stat apply log review fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $service = new CustomerServiceStatApplyLogReviewService();
            $businessCounts = $this->businessTableCounts();
            $fixtureDate = 20370106;
            $appliedAt = strtotime('2037-01-06 10:00:00');
            $batchA = 'CSSALR-A-' . mt_rand(1000, 9999);
            $batchB = 'CSSALR-B-' . mt_rand(1000, 9999);
            $batchC = 'CSSALR-C-' . mt_rand(1000, 9999);

            $this->createLog($batchA, $fixtureDate, $storeIds[0], 990801, 'insert', 880101, 2, 41, $appliedAt, 'insert fixture stat row');
            $this->createLog($batchA, $fixtureDate, $storeIds[0], 990802, 'update', 880102, 3, 42, $appliedAt + 60, 'update fixture stat row');
            $this->createLog($batchB, $fixtureDate, $storeIds[0], 990803, 'skip', 880103, 1, 43, $appliedAt + 120, 'skip unchanged fixture stat row');
            $this->createLog($batchC, $fixtureDate, $storeIds[1], 990901, 'insert', 880201, 4, 44, $appliedAt + 180, 'cross-store fixture stat row');

            $logCount = $this->logCount();
            $storeReport = $service->run($storeIds[0], (string)$fixtureDate, (string)$fixtureDate, '', '', 20);
            $this->assertSameInt(3, (int)$storeReport['rowsScanned'], 'Store-scoped review includes three fixture audit rows.');
            $this->assertTotal($storeReport, 'audit_log_count', 3, 'Store-scoped audit row total matches fixture.');
            $this->assertTotal($storeReport, 'insert_count', 1, 'Store-scoped insert log total matches fixture.');
            $this->assertTotal($storeReport, 'update_count', 1, 'Store-scoped update log total matches fixture.');
            $this->assertTotal($storeReport, 'skip_count', 1, 'Store-scoped skip log total matches fixture.');
            $this->assertTotal($storeReport, 'source_ticket_count', 6, 'Store-scoped source-ticket total matches fixture.');
            $this->assertSameInt($logCount, $this->logCount(), 'Read-only review does not append audit logs.');

            $batchReport = $service->run(0, (string)$fixtureDate, (string)$fixtureDate, $batchA, '', 20);
            $this->assertSameInt(2, (int)$batchReport['rowsScanned'], 'Batch filter returns two fixture rows.');
            $this->assertTotal($batchReport, 'batch_count', 1, 'Batch filter reports one batch.');

            $operationReport = $service->run($storeIds[0], (string)$fixtureDate, (string)$fixtureDate, '', 'update', 20);
            $this->assertSameInt(1, (int)$operationReport['rowsScanned'], 'Operation filter returns one update row.');
            $this->assertTotal($operationReport, 'update_count', 1, 'Operation filter update total matches fixture.');

            $allReport = $service->run(0, (string)$fixtureDate, (string)$fixtureDate, '', '', 20);
            $this->assertSameInt(4, (int)$allReport['rowsScanned'], 'All-store review includes cross-store audit row.');
            $this->assertTotal($allReport, 'store_count', 2, 'All-store review reports two stores.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Stat Apply Log Review',
                '- Result: PASS',
                '| Audit rows | 3 |',
                '| Insert logs | 1 |',
                'This report is read-only audit evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'id,batch_sn,stat_date,store_id,service_user_id,operation,stat_id,source_ticket_count,operator_user_id,applied_at,diff_summary',
                $batchA,
                'update fixture stat row',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service stat apply log review fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service stat apply log review fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-stat-apply-log-review-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceStatApplyLogReviewService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createLog(string $batchSn, int $statDate, int $storeId, int $serviceUserId, string $operation, int $statId, int $sourceTicketCount, int $operatorUserId, int $appliedAt, string $diffSummary): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_stat_apply_log}}', [
            'batch_sn' => $batchSn,
            'stat_date' => $statDate,
            'store_id' => $storeId,
            'service_user_id' => $serviceUserId,
            'operation' => $operation,
            'stat_id' => $statId,
            'source_ticket_count' => $sourceTicketCount,
            'before_json' => '{"source":"fixture","state":"before"}',
            'after_json' => '{"source":"fixture","state":"after"}',
            'diff_summary' => $diffSummary,
            'operator_user_id' => $operatorUserId,
            'applied_at' => $appliedAt,
            'remark' => 'customer-service stat apply log review fixture',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $appliedAt,
            'updated_at' => $appliedAt,
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

    private function logCount(): int
    {
        return (int)(new \yii\db\Query())->from('{{%mall_customer_service_stat_apply_log}}')->count('*', Yii::$app->db);
    }

    private function businessTableCounts(): array
    {
        $counts = [];
        foreach ([
            '{{%mall_customer_service_stat_daily}}',
            '{{%mall_customer_service_ticket}}',
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
        $this->ok('Statistics, tickets, orders, payments, chats, files, and funds were not mutated by log review.');
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
        $this->ok("File keeps read-only backend boundary: {$path}");
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
