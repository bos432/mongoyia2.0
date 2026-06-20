<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceTicketNoteService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceTicketNoteTestController extends Controller
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
        $this->stdout("Mongoyia customer-service ticket note backend test\n");
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
        $this->requireFileContains('common/services/mall/CustomerServiceTicketNoteService.php', [
            'class CustomerServiceTicketNoteService',
            'function run(',
            'EVENT_TYPE_NOTE',
            'beginTransaction',
            'rollBack',
            'customer-service-ticket-note',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionTicketNote',
            'CustomerServiceTicketNoteService',
            'isPost',
            'OPERATOR_TYPE_MERCHANT',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TICKET_NOTE_BACKEND_V1',
            'data-mongoyia-customer-service-ticket-note="form"',
            'method="post"',
            'ticket-note',
            'csrfParam',
            'textarea name="content"',
        ]);
        $this->requireFileContains('console/migrations/m260619_104000_mongoyia_customer_service_ticket_note_permission.php', [
            '/mall/kf/ticket-note',
            '客服工单备注',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceTicketNote',
            'customer-service ticket note backend Phase 6 closure',
            'customer-service-ticket-note-test/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceTicketNoteService.php',
            'CustomerServiceTicketNoteTestController.php',
            'm260619_104000_mongoyia_customer_service_ticket_note_permission.php',
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
            ->where(['path' => '/mall/kf/ticket-note', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/ticket-note. Run migration m260619_104000_mongoyia_customer_service_ticket_note_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/ticket-note');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have ticket note permission.');
            return;
        }
        $this->ok('Seller role has ticket note permission.');
    }

    private function checkFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service ticket note fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new CustomerServiceTicketNoteService();
            $businessCounts = $this->businessTableCounts();
            $oldUpdatedAt = time() - 3600;
            $ticketId = $this->createTicket($storeIds[0], $userId, $oldUpdatedAt, 'Note fixture ticket');
            $otherStoreTicketId = $this->createTicket($storeIds[1], $userId, $oldUpdatedAt, 'Note cross-store ticket');

            $dryRun = $service->run($ticketId, 'Dry-run internal note', false, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($dryRun);
            $this->assertSameInt(0, (int)$dryRun['noted'], 'Dry-run does not create note event.');
            $this->assertTrue((bool)$dryRun['dryRun'], 'Dry-run result is marked.');
            $this->assertEventCount($ticketId, 0, 'Dry-run leaves event table unchanged.');
            $this->assertTicketUpdatedAt($ticketId, $oldUpdatedAt, 'Dry-run leaves ticket updated_at unchanged.');

            $applied = $service->run($ticketId, 'Apply internal note', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($applied);
            $this->assertSameInt(1, (int)$applied['noted'], 'Apply creates one note event.');
            $this->assertPositiveInt((int)$applied['eventId'], 'Note event id is returned.');
            $this->assertNoteEvent($ticketId, (int)$applied['eventId'], 'Apply internal note');
            $this->assertTicketStatus($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Note does not change ticket status.');
            $this->assertTicketUpdatedBy($ticketId, 1, 'Note updates ticket updated_by.');
            $this->assertTicketUpdatedAfter($ticketId, $oldUpdatedAt, 'Note updates ticket updated_at.');

            $crossStore = $service->run($otherStoreTicketId, 'Cross-store internal note', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($crossStore);
            $this->assertSameInt(0, (int)$crossStore['noted'], 'Store-scoped note blocks other-store ticket.');
            $this->assertSkippedReason($crossStore, 'ticket not found or out of scope');
            $this->assertEventCount($otherStoreTicketId, 0, 'Cross-store ticket gets no note event.');

            $missingContent = $service->run($ticketId, '   ', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($missingContent);
            $this->assertSkippedReason($missingContent, 'content is required');

            $missingTicket = $service->run(999999999, 'Missing ticket note', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($missingTicket);
            $this->assertSkippedReason($missingTicket, 'ticket not found or out of scope');

            $this->assertBusinessCountsUnchanged($businessCounts);
            $transaction->rollBack();
            $this->ok('Customer-service ticket note fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Customer-service ticket note fixture failed: ' . $e->getMessage());
        }
    }

    private function createTicket(int $storeId, int $userId, int $updatedAt, string $title): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSTNT-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST,
            'ticket_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 102,
            'order_id' => 7700000 + mt_rand(1000, 9999),
            'order_sn' => 'CSTNT-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'customer_user_id' => $userId,
            'customer_uuid' => 'ticket_note_user_' . $userId,
            'merchant_user_id' => 37,
            'platform_user_id' => 1,
            'chat_uuid' => 'ticket_note_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-ticket-note-test/run.',
            'result' => '',
            'remark' => 'ticket note fixture',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $updatedAt,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function assertNoteEvent(int $ticketId, int $eventId, string $content): void
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
            $this->fail('Note event row not found.');
            return;
        }
        if ((string)$row['content'] !== $content) {
            $this->fail('Note event content mismatch.');
            return;
        }

        $metadata = json_decode((string)$row['metadata_json'], true);
        if (!is_array($metadata) || (string)($metadata['source'] ?? '') !== 'customer-service-ticket-note') {
            $this->fail('Note event metadata source mismatch.');
            return;
        }
        $this->ok('Note event row and metadata are stored.');
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
        $this->stdout("Ticket: {$result['ticketId']}; store scope: {$result['storeId']}; noted: {$result['noted']}; event: {$result['eventId']}\n");
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
