<?php

namespace console\controllers;

use common\services\mall\CustomerServiceStatWidgetReadinessService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceStatWidgetReadinessController extends Controller
{
    public $storeId = 0;
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
        $this->stdout("Mongoyia customer-service stat widget readiness\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkBackendBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceStatWidgetReadinessService())->run(
                (int)$this->storeId,
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
        $this->requireFileContains('common/services/mall/CustomerServiceStatWidgetReadinessService.php', [
            'class CustomerServiceStatWidgetReadinessService',
            'function run(',
            'markdownLines',
            'csvLines',
            'Mongoyia Customer Service Stat Widget Readiness',
            'read-only readiness evidence',
            'write_workflow',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_STAT_WIDGET_READINESS_V1',
            'data-mongoyia-customer-service-stat-widget-readiness="reserved"',
            'data-mongoyia-customer-service-stat-widget-apply="disabled"',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceStatWidgetReadiness',
            'customer-service stat widget readiness Phase 6 closure',
            'customer-service-stat-widget-readiness/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceStatWidgetReadinessController.php',
            'CustomerServiceStatWidgetReadinessService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'customerServiceStatWidgetReadinessPath',
            'mongoyia-customer-service-stat-widget-readiness-*.md',
            'Customer-service stat widget readiness result',
        ]);
        $this->requireFileContains('docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_STAT_WIDGET_READINESS_V1',
            'customer-service stat widget readiness',
            'statistic write widgets remain disabled',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'customer-service-stat-widget-readiness/run',
            'mongoyia-customer-service-stat-widget-readiness-*.md',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
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
    }

    private function checkBackendBoundary(): void
    {
        $this->section('Backend boundary');
        $this->requireFileNotContains('backend/modules/mall/controllers/KfController.php', [
            'actionStatWidgetApply',
            'stat-widget-apply',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'disabled',
            '统计写入待启用',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        if (count($storeIds) < 2) {
            $this->fail('Need two active stores for customer-service stat widget readiness fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $service = new CustomerServiceStatWidgetReadinessService();
            $businessCounts = $this->businessTableCounts();
            $statDate = 20991230;
            $this->createStat($statDate, $storeIds[0], 990301, 6, 3, 2, 1, 2, 1, 120, 1200);
            $this->createStat($statDate, $storeIds[0], 990302, 2, 1, 0, 1, 1, 0, 30, 300);
            $this->createStat($statDate, $storeIds[1], 990401, 8, 5, 3, 2, 4, 1, 240, 2400);

            $storeReport = $service->run($storeIds[0], (string)$statDate, (string)$statDate, 20);
            $this->assertSameInt(2, (int)$storeReport['rowsScanned'], 'Store-scoped widget readiness includes two fixture rows.');
            $this->assertTotal($storeReport, 'session_count', 8, 'Store-scoped sessions match fixture.');
            $this->assertTotal($storeReport, 'ticket_count', 4, 'Store-scoped tickets match fixture.');
            $this->assertWidgetStatus($storeReport, 'daily_totals', 'ready', 'Daily totals widget is ready.');
            $this->assertWidgetStatus($storeReport, 'store_scope', 'ready', 'Store-scope widget is ready.');
            $this->assertWidgetStatus($storeReport, 'ticket_mix', 'ready', 'Ticket-mix widget is ready.');
            $this->assertWidgetStatus($storeReport, 'resolution_rate', 'ready', 'Resolution-rate widget is ready.');
            $this->assertWidgetStatus($storeReport, 'response_time', 'ready', 'Response-time widget is ready.');
            $this->assertWidgetStatus($storeReport, 'write_workflow', 'reserved', 'Write workflow remains reserved.');

            $allReport = $service->run(0, (string)$statDate, (string)$statDate, 20);
            $this->assertSameInt(3, (int)$allReport['rowsScanned'], 'All-store widget readiness includes all fixture rows.');
            $this->assertTotal($allReport, 'session_count', 16, 'All-store sessions include cross-store row.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Stat Widget Readiness',
                '- Result: PASS',
                '| daily_totals | ready | 2 |',
                '| write_workflow | reserved | 0 |',
                'This report is read-only readiness evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'widget_key,status,value,details',
                'daily_totals,ready,2,',
                'write_workflow,reserved,0,',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service stat widget readiness fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service stat widget readiness fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-stat-widget-readiness-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceStatWidgetReadinessService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
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
            'remark' => 'customer-service stat widget readiness fixture',
            'type' => 1,
            'sort' => 50,
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
        $this->ok('Business tables for tickets, orders, payments, chats, files, and funds were not mutated.');
    }

    private function assertTotal(array $report, string $key, int $expected, string $message): void
    {
        $actual = (int)($report['totals'][$key] ?? -1);
        $this->assertSameInt($expected, $actual, $message);
    }

    private function assertWidgetStatus(array $report, string $key, string $expected, string $message): void
    {
        foreach (($report['widgets'] ?? []) as $widget) {
            if ((string)$widget['key'] !== $key) {
                continue;
            }
            if ((string)$widget['status'] !== $expected) {
                $this->fail("{$message} Expected {$expected}, got {$widget['status']}.");
                return;
            }
            $this->ok($message);
            return;
        }

        $this->fail("{$message} Widget {$key} missing.");
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
