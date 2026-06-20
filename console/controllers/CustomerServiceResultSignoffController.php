<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceResultSignoffService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceResultSignoffController extends Controller
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
        $this->stdout("Mongoyia customer-service result signoff\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new CustomerServiceResultSignoffService())->run(
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
        $this->requireFileContains('common/services/mall/CustomerServiceResultSignoffService.php', [
            'class CustomerServiceResultSignoffService',
            'function run(',
            'Mongoyia Customer Service Result Signoff',
            'This report is read-only evidence',
            'needs_result_writeback',
            'premature_result_review',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionResultSignoff',
            'CustomerServiceResultSignoffService',
            'sendContentAsFile',
            'result-signoff',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_RESULT_SIGNOFF_BACKEND_V1',
            'data-mongoyia-customer-service-export-result-signoff="csv"',
            'result-signoff',
        ]);
        $this->requireFileContains('console/migrations/m260619_114000_mongoyia_customer_service_result_signoff_permission.php', [
            '/mall/kf/result-signoff',
            '客服结果签字导出',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceResultSignoff',
            'customer-service result signoff Phase 6 closure',
            'customer-service-result-signoff/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceResultSignoffService.php',
            'CustomerServiceResultSignoffController.php',
            'm260619_114000_mongoyia_customer_service_result_signoff_permission.php',
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
            'resolved_at',
            'closed_at',
            'created_at',
            'updated_at',
            'status',
        ]);
    }

    private function checkPermissions(): void
    {
        $this->section('Permissions');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/kf/result-signoff', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/result-signoff. Run migration m260619_114000_mongoyia_customer_service_result_signoff_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/result-signoff');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have customer-service result signoff permission.');
            return;
        }
        $this->ok('Seller role has customer-service result signoff permission.');
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service result signoff fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $businessCounts = $this->businessTableCounts();
            $service = new CustomerServiceResultSignoffService();
            $now = time();
            $dateFrom = date('Y-m-d', $now - 3 * 86400);
            $dateTo = date('Y-m-d', $now);

            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'Result signoff ready resolved fixture', 'Resolved with result.', $now - 7200, $now - 3600, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, 'Result signoff ready closed fixture', 'Closed with result.', $now - 7000, $now - 3600, $now - 1800);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'Result signoff missing fixture', '', $now - 6800, $now - 3000, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, 'Result signoff premature fixture', 'Premature result text.', $now - 6600, 0, 0);
            $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Result signoff continue fixture', '', $now - 6400, 0, 0);
            $this->createTicket($storeIds[1], $userId, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, 'Result signoff other-store missing fixture', '', $now - 6200, $now - 3000, $now - 1200);

            $storeReport = $service->run($storeIds[0], '', $dateFrom, $dateTo, 20);
            $this->assertSameInt(5, (int)$storeReport['rowsScanned'], 'Store-scoped result signoff report includes five fixture rows.');
            $this->assertTotal($storeReport, 'ticket_count', 5, 'Store-scoped ticket count matches fixture.');
            $this->assertTotal($storeReport, 'ready_for_signoff_count', 2, 'Store-scoped ready count matches fixture.');
            $this->assertTotal($storeReport, 'needs_result_writeback_count', 1, 'Store-scoped missing-result count matches fixture.');
            $this->assertTotal($storeReport, 'premature_result_review_count', 1, 'Store-scoped premature-result count matches fixture.');
            $this->assertTotal($storeReport, 'continue_workflow_count', 1, 'Store-scoped continue-workflow count matches fixture.');
            $this->assertTotal($storeReport, 'signoff_blocked_count', 2, 'Store-scoped blocked count matches fixture.');

            $allReport = $service->run(0, '', $dateFrom, $dateTo, 20);
            $this->assertSameInt(6, (int)$allReport['rowsScanned'], 'All-store result signoff report includes cross-store missing row.');
            $this->assertTotal($allReport, 'needs_result_writeback_count', 2, 'All-store missing-result count includes cross-store row.');
            $this->assertTotal($allReport, 'signoff_blocked_count', 3, 'All-store blocked count includes cross-store row.');

            $paths = $this->writeExport($storeReport, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Customer Service Result Signoff',
                '- Result: PASS',
                '| Ready for signoff | 2 |',
                '| Needs result writeback | 1 |',
                'This report is read-only evidence',
            ]);
            $this->assertFileContains($paths['csv'], [
                'ticket_id,ticket_sn,ticket_type,store_id,ticket_status,priority,order_id,order_sn,result_length,suggested_action,created_at,updated_at,resolved_at,closed_at,title',
                'ready_for_signoff',
                'needs_result_writeback',
                'premature_result_review',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);

            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->ok('Customer-service result signoff fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->removeFiles($paths);
            $this->fail('Customer-service result signoff fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-result-signoff-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new CustomerServiceResultSignoffService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function createTicket(int $storeId, int $userId, string $ticketStatus, string $title, string $result, int $createdAt, int $resolvedAt, int $closedAt): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSRS-' . date('YmdHis', $createdAt) . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST,
            'ticket_status' => $ticketStatus,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 993000 + mt_rand(100, 999),
            'order_sn' => 'CS-RESULT-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'result_signoff_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'result_signoff_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-result-signoff/run fixture.',
            'result' => $result,
            'evidence_json' => '',
            'first_response_at' => $createdAt + 300,
            'resolved_at' => $resolvedAt,
            'closed_at' => $closedAt,
            'remark' => 'result signoff fixture',
            'status' => 1,
            'created_at' => $createdAt,
            'updated_at' => max($createdAt, $resolvedAt, $closedAt),
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
