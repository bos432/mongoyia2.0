<?php

namespace console\controllers;

use common\services\mall\CustomerServiceStatExportService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceStatExportController extends Controller
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
        $this->stdout("Mongoyia customer-service stat export\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceStatExportService())->run(
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
        $this->requireFileContains('common/services/mall/CustomerServiceStatExportService.php', [
            'class CustomerServiceStatExportService',
            'function run(',
            'markdownLines',
            'csvLines',
            'Mongoyia Customer Service Stat Export',
            'This report is read-only evidence',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionStatExport',
            'CustomerServiceStatExportService',
            'sendContentAsFile',
            'stat-export',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_STAT_EXPORT_BACKEND_V1',
            'data-mongoyia-customer-service-export-stat="csv"',
            'stat-export',
        ]);
        $this->requireFileContains('console/migrations/m260619_110000_mongoyia_customer_service_stat_export_permission.php', [
            '/mall/kf/stat-export',
            '客服统计导出',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceStatExport',
            'customer-service stat export Phase 6 closure',
            'customer-service-stat-export/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceStatExportService.php',
            'CustomerServiceStatExportController.php',
            'm260619_110000_mongoyia_customer_service_stat_export_permission.php',
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

    private function checkPermissions(): void
    {
        $this->section('Permissions');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/kf/stat-export', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/stat-export. Run migration m260619_110000_mongoyia_customer_service_stat_export_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/stat-export');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have customer-service stat export permission.');
            return;
        }
        $this->ok('Seller role has customer-service stat export permission.');
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        if (count($storeIds) < 2) {
            $this->fail('Need two active stores for customer-service stat export fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $service = new CustomerServiceStatExportService();
            $businessCounts = $this->businessTableCounts();
            $statDate = 20991231;
            $this->createStat($statDate, $storeIds[0], 990101, 4, 2, 1, 1, 1, 1, 40, 400);
            $this->createStat($statDate, $storeIds[0], 990102, 3, 1, 1, 0, 1, 0, 20, 200);
            $this->createStat($statDate, $storeIds[1], 990201, 5, 4, 2, 2, 3, 1, 90, 900);

            $storeReport = $service->run($storeIds[0], (string)$statDate, (string)$statDate, 20);
            $this->assertSameInt(2, (int)$storeReport['rowsScanned'], 'Store-scoped export includes two fixture rows.');
            $this->assertTotal($storeReport, 'session_count', 7, 'Store-scoped session total matches fixture.');
            $this->assertTotal($storeReport, 'ticket_count', 3, 'Store-scoped ticket total matches fixture.');
            $this->assertTotal($storeReport, 'order_assist_count', 2, 'Store-scoped order-assist total matches fixture.');
            $this->assertTotal($storeReport, 'complaint_count', 1, 'Store-scoped complaint total matches fixture.');
            $this->assertTotal($storeReport, 'resolved_count', 2, 'Store-scoped resolved total matches fixture.');
            $this->assertTotal($storeReport, 'unresolved_count', 1, 'Store-scoped unresolved total matches fixture.');
            $this->assertTotal($storeReport, 'first_response_seconds_total', 60, 'Store-scoped first-response seconds match fixture.');
            $this->assertTotal($storeReport, 'resolved_seconds_total', 600, 'Store-scoped resolved seconds match fixture.');

            $allReport = $service->run(0, (string)$statDate, (string)$statDate, 20);
            $this->assertSameInt(3, (int)$allReport['rowsScanned'], 'All-store export includes all fixture rows.');
            $this->assertTotal($allReport, 'session_count', 12, 'All-store session total includes cross-store row.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Stat Export',
                '- Result: PASS',
                '| Sessions | 7 |',
                'This report is read-only evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'stat_date,store_id,service_user_id,session_count,ticket_count,order_assist_count,complaint_count,resolved_count,unresolved_count,first_response_seconds_total,resolved_seconds_total',
                '20991231,' . $storeIds[0] . ',990101,4,2,1,1,1,1,40,400',
                '20991231,' . $storeIds[0] . ',990102,3,1,1,0,1,0,20,200',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service stat export fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service stat export fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-stat-export-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceStatExportService();
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
            'remark' => 'customer-service stat export fixture',
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
