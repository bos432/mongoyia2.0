<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceTicketAssignService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceTicketAssignTestController extends Controller
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
        $this->stdout("Mongoyia customer-service ticket assign backend test\n");
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
        $this->requireFileContains('common/services/mall/CustomerServiceTicketAssignService.php', [
            'class CustomerServiceTicketAssignService',
            'ASSIGNMENT_TYPE_MERCHANT',
            'ASSIGNMENT_TYPE_PLATFORM',
            'function run(',
            'beginTransaction',
            'rollBack',
            'customer-service-ticket-assign',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionTicketAssign',
            'CustomerServiceTicketAssignService',
            'isPost',
            'assignment_type',
            'assignee_user_id',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TICKET_ASSIGN_BACKEND_V1',
            'data-mongoyia-customer-service-ticket-assign="form"',
            'method="post"',
            'ticket-assign',
            'assignee_user_id',
            'csrfParam',
        ]);
        $this->requireFileContains('console/migrations/m260619_105000_mongoyia_customer_service_ticket_assign_permission.php', [
            '/mall/kf/ticket-assign',
            '客服工单分配',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceTicketAssign',
            'customer-service ticket assign backend Phase 6 closure',
            'customer-service-ticket-assign-test/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceTicketAssignService.php',
            'CustomerServiceTicketAssignTestController.php',
            'm260619_105000_mongoyia_customer_service_ticket_assign_permission.php',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_customer_service_ticket}}', [
            'id',
            'ticket_status',
            'store_id',
            'merchant_user_id',
            'platform_user_id',
            'order_id',
            'product_id',
            'chat_uuid',
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
            ->where(['path' => '/mall/kf/ticket-assign', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/ticket-assign. Run migration m260619_105000_mongoyia_customer_service_ticket_assign_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/ticket-assign');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have ticket assign permission.');
            return;
        }
        $this->ok('Seller role has ticket assign permission.');
    }

    private function checkFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service ticket assign fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new CustomerServiceTicketAssignService();
            $businessCounts = $this->businessTableCounts();
            $oldUpdatedAt = time() - 3600;
            $ticketId = $this->createTicket($storeIds[0], $userId, $oldUpdatedAt, 'Assign fixture ticket');
            $otherStoreTicketId = $this->createTicket($storeIds[1], $userId, $oldUpdatedAt, 'Assign cross-store ticket');
            $merchantAssignee = 880001;
            $platformAssignee = 880002;

            $dryRun = $service->run($ticketId, CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_MERCHANT, $merchantAssignee, false, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'dry-run assign', $storeIds[0]);
            $this->printResult($dryRun);
            $this->assertSameInt(0, (int)$dryRun['assigned'], 'Dry-run does not assign ticket.');
            $this->assertTrue((bool)$dryRun['dryRun'], 'Dry-run result is marked.');
            $this->assertTicketAssignee($ticketId, 'merchant_user_id', 37, 'Dry-run leaves merchant assignee unchanged.');
            $this->assertEventCount($ticketId, 0, 'Dry-run leaves event table unchanged.');
            $this->assertTicketUpdatedAt($ticketId, $oldUpdatedAt, 'Dry-run leaves ticket updated_at unchanged.');

            $merchantAssign = $service->run($ticketId, CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_MERCHANT, $merchantAssignee, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'Assign merchant handler', $storeIds[0]);
            $this->printResult($merchantAssign);
            $this->assertSameInt(1, (int)$merchantAssign['assigned'], 'Apply assigns merchant handler.');
            $this->assertPositiveInt((int)$merchantAssign['eventId'], 'Assign event id is returned.');
            $this->assertTicketAssignee($ticketId, 'merchant_user_id', $merchantAssignee, 'Merchant assignee is stored.');
            $this->assertTicketAssignee($ticketId, 'platform_user_id', 1, 'Platform assignee is unchanged by merchant assignment.');
            $this->assertTicketStatus($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Assign does not change ticket status.');
            $this->assertAssignEvent($ticketId, (int)$merchantAssign['eventId'], CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_MERCHANT, 37, $merchantAssignee);
            $this->assertTicketUpdatedBy($ticketId, 1, 'Assign updates ticket updated_by.');
            $this->assertTicketUpdatedAfter($ticketId, $oldUpdatedAt, 'Assign updates ticket updated_at.');

            $sameAssignee = $service->run($ticketId, CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_MERCHANT, $merchantAssignee, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'same assign', $storeIds[0]);
            $this->printResult($sameAssignee);
            $this->assertSameInt(0, (int)$sameAssignee['assigned'], 'Repeat same assignee is blocked.');
            $this->assertSkippedReason($sameAssignee, 'assignee unchanged');

            $platformAssign = $service->run($ticketId, CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_PLATFORM, $platformAssignee, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'Assign platform handler', $storeIds[0]);
            $this->printResult($platformAssign);
            $this->assertSameInt(1, (int)$platformAssign['assigned'], 'Platform operator can assign platform handler.');
            $this->assertTicketAssignee($ticketId, 'platform_user_id', $platformAssignee, 'Platform assignee is stored.');
            $this->assertAssignEvent($ticketId, (int)$platformAssign['eventId'], CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_PLATFORM, 1, $platformAssignee);

            $merchantPlatform = $service->run($ticketId, CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_PLATFORM, 880003, true, 37, CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT, 'merchant blocked platform assign', $storeIds[0]);
            $this->printResult($merchantPlatform);
            $this->assertSameInt(0, (int)$merchantPlatform['assigned'], 'Merchant operator cannot assign platform handler.');
            $this->assertSkippedReason($merchantPlatform, 'merchant can only assign merchant handler');

            $crossStore = $service->run($otherStoreTicketId, CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_MERCHANT, 880004, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'cross-store assign', $storeIds[0]);
            $this->printResult($crossStore);
            $this->assertSameInt(0, (int)$crossStore['assigned'], 'Store-scoped assign blocks other-store ticket.');
            $this->assertSkippedReason($crossStore, 'ticket not found or out of scope');
            $this->assertTicketAssignee($otherStoreTicketId, 'merchant_user_id', 37, 'Cross-store ticket assignee is unchanged.');

            $unsupported = $service->run($ticketId, 'not_a_handler_type', 880005, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'unsupported', $storeIds[0]);
            $this->printResult($unsupported);
            $this->assertSkippedReason($unsupported, 'unsupported assignment type');

            $missingAssignee = $service->run($ticketId, CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_MERCHANT, 0, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'missing assignee', $storeIds[0]);
            $this->printResult($missingAssignee);
            $this->assertSkippedReason($missingAssignee, 'assignee user id is required');

            $this->assertBusinessCountsUnchanged($businessCounts);
            $transaction->rollBack();
            $this->ok('Customer-service ticket assign fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Customer-service ticket assign fixture failed: ' . $e->getMessage());
        }
    }

    private function createTicket(int $storeId, int $userId, int $updatedAt, string $title): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSTAS-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST,
            'ticket_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 7600000 + mt_rand(1000, 9999),
            'order_sn' => 'CSTAS-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'ticket_assign_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'ticket_assign_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-ticket-assign-test/run.',
            'result' => '',
            'remark' => 'ticket assign fixture',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $updatedAt,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function assertAssignEvent(int $ticketId, int $eventId, string $assignmentType, int $fromUserId, int $toUserId): void
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
            $this->fail('Assign event row not found.');
            return;
        }

        $metadata = json_decode((string)$row['metadata_json'], true);
        if (
            !is_array($metadata)
            || (string)($metadata['source'] ?? '') !== 'customer-service-ticket-assign'
            || (string)($metadata['assignment_type'] ?? '') !== $assignmentType
            || (int)($metadata['from_user_id'] ?? -1) !== $fromUserId
            || (int)($metadata['to_user_id'] ?? -1) !== $toUserId
        ) {
            $this->fail('Assign event metadata mismatch.');
            return;
        }
        $this->ok('Assign event row and metadata are stored.');
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

    private function assertTicketAssignee(int $ticketId, string $column, int $expected, string $message): void
    {
        $actual = (int)(new \yii\db\Query())
            ->select($column)
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
        $this->stdout("Ticket: {$result['ticketId']}; type: {$result['assignmentType']}; assignee: {$result['assigneeUserId']}; assigned: {$result['assigned']}; event: {$result['eventId']}\n");
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
