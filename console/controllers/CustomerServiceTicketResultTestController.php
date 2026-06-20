<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceTicketResultService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceTicketResultTestController extends Controller
{
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service ticket result backend test\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();
        $this->checkFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles(): void
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/CustomerServiceTicketResultService.php', [
            'class CustomerServiceTicketResultService',
            'function run(',
            'result unchanged',
            'beginTransaction',
            'rollBack',
            'customer-service-ticket-result',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionTicketResult',
            'CustomerServiceTicketResultService',
            'isPost',
            'OPERATOR_TYPE_MERCHANT',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TICKET_RESULT_BACKEND_V1',
            'data-mongoyia-customer-service-ticket-result="form"',
            'method="post"',
            'ticket-result',
            'csrfParam',
            'textarea name="result"',
        ]);
        $this->requireFileContains('console/migrations/m260619_115000_mongoyia_customer_service_ticket_result_permission.php', [
            '/mall/kf/ticket-result',
            '客服工单结果写回',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceTicketResult',
            'customer-service ticket result backend Phase 6 closure',
            'customer-service-ticket-result-test/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceTicketResultService.php',
            'CustomerServiceTicketResultTestController.php',
            'm260619_115000_mongoyia_customer_service_ticket_result_permission.php',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_customer_service_ticket}}', [
            'id',
            'ticket_status',
            'store_id',
            'order_id',
            'product_id',
            'chat_uuid',
            'result',
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
            'status',
        ]);
    }

    private function checkPermissions(): void
    {
        $this->section('Permissions');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/kf/ticket-result', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/ticket-result. Run migration m260619_115000_mongoyia_customer_service_ticket_result_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/ticket-result');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have ticket result permission.');
            return;
        }
        $this->ok('Seller role has ticket result permission.');
    }

    private function checkFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service ticket result fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new CustomerServiceTicketResultService();
            $businessCounts = $this->businessTableCounts();
            $oldUpdatedAt = time() - 3600;
            $oldResult = 'Initial result before writeback';
            $newResult = 'Final fixture result writeback';
            $ticketId = $this->createTicket($storeIds[0], $userId, $oldUpdatedAt, $oldResult, 'Result fixture ticket');
            $otherStoreTicketId = $this->createTicket($storeIds[1], $userId, $oldUpdatedAt, 'Other store result', 'Result cross-store ticket');

            $dryRun = $service->run($ticketId, $newResult, false, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($dryRun);
            $this->assertSameInt(0, (int)$dryRun['written'], 'Dry-run does not write result.');
            $this->assertTrue((bool)$dryRun['dryRun'], 'Dry-run result is marked.');
            $this->assertTicketResult($ticketId, $oldResult, 'Dry-run leaves result unchanged.');
            $this->assertTicketUpdatedAt($ticketId, $oldUpdatedAt, 'Dry-run leaves ticket updated_at unchanged.');
            $this->assertEventCount($ticketId, 0, 'Dry-run leaves event table unchanged.');

            $applied = $service->run($ticketId, $newResult, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($applied);
            $this->assertSameInt(1, (int)$applied['written'], 'Apply writes one result.');
            $this->assertPositiveInt((int)$applied['eventId'], 'Result event id is returned.');
            $this->assertTicketResult($ticketId, $newResult, 'Result text is stored.');
            $this->assertTicketStatus($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Result writeback does not change ticket status.');
            $this->assertTicketUpdatedBy($ticketId, 1, 'Result writeback updates ticket updated_by.');
            $this->assertTicketUpdatedAfter($ticketId, $oldUpdatedAt, 'Result writeback updates ticket updated_at.');
            $this->assertResultEvent($ticketId, (int)$applied['eventId'], $newResult, $oldResult);

            $unchanged = $service->run($ticketId, $newResult, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($unchanged);
            $this->assertSameInt(0, (int)$unchanged['written'], 'Repeat same result is blocked.');
            $this->assertSkippedReason($unchanged, 'result unchanged');
            $this->assertEventCount($ticketId, 1, 'Repeat same result creates no extra event.');

            $crossStore = $service->run($otherStoreTicketId, 'Cross-store result writeback', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($crossStore);
            $this->assertSameInt(0, (int)$crossStore['written'], 'Store-scoped result writeback blocks other-store ticket.');
            $this->assertSkippedReason($crossStore, 'ticket not found or out of scope');
            $this->assertTicketResult($otherStoreTicketId, 'Other store result', 'Cross-store ticket result is unchanged.');
            $this->assertEventCount($otherStoreTicketId, 0, 'Cross-store ticket gets no result event.');

            $missingResult = $service->run($ticketId, '   ', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($missingResult);
            $this->assertSkippedReason($missingResult, 'result is required');

            $missingTicket = $service->run(999999999, 'Missing ticket result', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($missingTicket);
            $this->assertSkippedReason($missingTicket, 'ticket not found or out of scope');

            $this->assertBusinessCountsUnchanged($businessCounts);
            $transaction->rollBack();
            $this->ok('Customer-service ticket result fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Customer-service ticket result fixture failed: ' . $e->getMessage());
        }
    }

    private function createTicket(int $storeId, int $userId, int $updatedAt, string $result, string $title): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSTRS-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST,
            'ticket_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 7900000 + mt_rand(1000, 9999),
            'order_sn' => 'CSTRS-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'ticket_result_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'ticket_result_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-ticket-result-test/run.',
            'result' => $result,
            'remark' => 'ticket result fixture',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $updatedAt,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function assertResultEvent(int $ticketId, int $eventId, string $content, string $oldResult): void
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_customer_service_event}}')
            ->where([
                'id' => $eventId,
                'ticket_id' => $ticketId,
                'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_NOTE,
                'from_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
                'to_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
                'status' => 1,
            ])
            ->one(Yii::$app->db);
        if (!$row) {
            $this->fail('Result event row not found.');
            return;
        }
        if ((string)$row['content'] !== $content) {
            $this->fail('Result event content mismatch.');
            return;
        }

        $metadata = json_decode((string)$row['metadata_json'], true);
        if (
            !is_array($metadata)
            || (string)($metadata['source'] ?? '') !== 'customer-service-ticket-result'
            || (string)($metadata['old_result'] ?? '') !== $oldResult
        ) {
            $this->fail('Result event metadata mismatch.');
            return;
        }
        $this->ok('Result event row and metadata are stored.');
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

    private function assertTicketResult(int $ticketId, string $expected, string $message): void
    {
        $actual = (string)(new \yii\db\Query())
            ->select('result')
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertTicketStatus(int $ticketId, string $expected, string $message): void
    {
        $actual = (string)(new \yii\db\Query())
            ->select('ticket_status')
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertTicketUpdatedAt(int $ticketId, int $expected, string $message): void
    {
        $actual = (int)(new \yii\db\Query())
            ->select('updated_at')
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertTicketUpdatedAfter(int $ticketId, int $before, string $message): void
    {
        $actual = (int)(new \yii\db\Query())
            ->select('updated_at')
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId])
            ->scalar(Yii::$app->db);
        if ($actual <= $before) {
            $this->fail("{$message} Expected greater than {$before}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertTicketUpdatedBy(int $ticketId, int $expected, string $message): void
    {
        $actual = (int)(new \yii\db\Query())
            ->select('updated_by')
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertEventCount(int $ticketId, int $expected, string $message): void
    {
        $actual = (int)(new \yii\db\Query())
            ->from('{{%mall_customer_service_event}}')
            ->where(['ticket_id' => $ticketId, 'status' => 1])
            ->count('*', Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
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

    private function printResult(array $result): void
    {
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Ticket: {$result['ticketId']}; store scope: {$result['storeId']}; written: {$result['written']}; event: {$result['eventId']}\n");
        foreach ($result['skipped'] as $skip) {
            $this->stdout('SKIP ticket=' . $skip['id'] . ' reason=' . $skip['reason'] . "\n");
        }
    }

    private function assertSkippedReason(array $result, string $reason): void
    {
        foreach ($result['skipped'] as $row) {
            if ((string)$row['reason'] === $reason) {
                $this->ok("Skip reason is {$reason}.");
                return;
            }
        }
        $this->fail("Skip reason {$reason} was not found.");
    }

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertPositiveInt(int $value, string $message): void
    {
        if ($value <= 0) {
            $this->fail("{$message} Got {$value}.");
            return;
        }
        $this->ok($message);
    }

    private function assertTrue(bool $value, string $message): void
    {
        if (!$value) {
            $this->fail($message);
            return;
        }
        $this->ok($message);
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
