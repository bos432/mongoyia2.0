<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceTicketCreateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceTicketCreateTestController extends Controller
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
        $this->stdout("Mongoyia customer-service ticket create backend test\n");
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
        $this->requireFileContains('common/services/mall/CustomerServiceTicketCreateService.php', [
            'class CustomerServiceTicketCreateService',
            'function run(',
            'duplicateTicket',
            'insertCreateEvent',
            'EVENT_TYPE_CREATE',
            'beginTransaction',
            'rollBack',
            'ticketSn',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionTicketCreate',
            'CustomerServiceTicketCreateService',
            'isPost',
            'OPERATOR_TYPE_MERCHANT',
            'backend customer-service ticket create',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TICKET_CREATE_BACKEND_V1',
            'data-mongoyia-customer-service-ticket-create="form"',
            'method="post"',
            'ticket-create',
            'csrfParam',
            'ticket_type',
            'title',
        ]);
        $this->requireFileContains('console/migrations/m260619_103000_mongoyia_customer_service_ticket_create_permission.php', [
            '/mall/kf/ticket-create',
            '客服工单创建',
            'grantToCustomerServiceRoles',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceTicketCreate',
            'customer-service ticket create backend Phase 6 closure',
            'customer-service-ticket-create-test/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceTicketCreateService.php',
            'CustomerServiceTicketCreateTestController.php',
            'm260619_103000_mongoyia_customer_service_ticket_create_permission.php',
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
            'product_id',
            'order_id',
            'order_sn',
            'customer_user_id',
            'customer_uuid',
            'merchant_user_id',
            'platform_user_id',
            'chat_uuid',
            'title',
            'content',
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
            ->where(['path' => '/mall/kf/ticket-create', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/ticket-create. Run migration m260619_103000_mongoyia_customer_service_ticket_create_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/ticket-create');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have ticket create permission.');
            return;
        }
        $this->ok('Seller role has ticket create permission.');
    }

    private function checkFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service ticket create fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new CustomerServiceTicketCreateService();
            $context = [
                'store_id' => $storeIds[0],
                'product_id' => 102,
                'order_id' => 8800000 + mt_rand(1000, 9999),
                'order_sn' => 'CSTC-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
                'customer_user_id' => $userId,
                'customer_uuid' => 'ticket_create_user_' . $userId,
                'merchant_user_id' => 37,
                'platform_user_id' => 1,
                'chat_uuid' => 'ticket_create_chat_' . $storeIds[0],
                'title' => 'Ticket create fixture',
                'content' => 'Created by customer-service-ticket-create-test/run.',
                'remark' => 'ticket create fixture',
            ];
            $businessCounts = $this->businessTableCounts();

            $dryRun = $service->run($context, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, false, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($dryRun);
            $this->assertSameInt(0, (int)$dryRun['created'], 'Dry-run does not create ticket.');
            $this->assertTrue((bool)$dryRun['dryRun'], 'Dry-run result is marked.');
            $this->assertNoTicketByOrder((int)$context['order_id'], 'Dry-run leaves ticket table unchanged for fixture order.');

            $created = $service->run($context, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($created);
            $ticketId = (int)$created['ticketId'];
            $this->assertSameInt(1, (int)$created['created'], 'Apply creates one ticket.');
            $this->assertPositiveInt($ticketId, 'Created ticket id is returned.');
            $this->assertTicket($ticketId, $context, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, CustomerServiceAdvancedService::TICKET_STATUS_PENDING);
            $this->assertCreateEvent($ticketId, (int)$context['order_id'], (int)$context['product_id'], (string)$context['chat_uuid']);

            $duplicate = $service->run($context, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($duplicate);
            $this->assertSameInt(0, (int)$duplicate['created'], 'Duplicate active order ticket is blocked.');
            $this->assertSkippedReason($duplicate, 'active ticket already exists');

            Yii::$app->db->createCommand()
                ->update('{{%mall_customer_service_ticket}}', [
                    'ticket_status' => CustomerServiceAdvancedService::TICKET_STATUS_CLOSED,
                    'closed_at' => time(),
                ], ['id' => $ticketId])
                ->execute();
            $afterClosed = $service->run(array_merge($context, ['title' => 'Ticket create fixture after close']), CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($afterClosed);
            $this->assertSameInt(1, (int)$afterClosed['created'], 'Closed duplicate allows a new follow-up ticket.');

            $crossStore = $service->run(array_merge($context, ['store_id' => $storeIds[1], 'order_id' => 9900000 + mt_rand(1000, 9999)]), CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($crossStore);
            $this->assertSameInt(0, (int)$crossStore['created'], 'Store-scoped create blocks other-store ticket.');
            $this->assertSkippedReason($crossStore, 'store scope mismatch');

            $unsupported = $service->run($context, 'not_a_ticket_type', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($unsupported);
            $this->assertSkippedReason($unsupported, 'unsupported ticket type');

            $missingTitle = $service->run(array_merge($context, ['title' => '']), CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, $storeIds[0]);
            $this->printResult($missingTitle);
            $this->assertSkippedReason($missingTitle, 'title is required');

            $this->assertBusinessCountsUnchanged($businessCounts);
            $transaction->rollBack();
            $this->ok('Customer-service ticket create fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Customer-service ticket create fixture failed: ' . $e->getMessage());
        }
    }

    private function assertTicket(int $ticketId, array $context, string $type, string $status): void
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId])
            ->one(Yii::$app->db);
        if (!$row) {
            $this->fail('Created ticket row not found.');
            return;
        }

        $checks = [
            'ticket_type' => $type,
            'ticket_status' => $status,
            'store_id' => (int)$context['store_id'],
            'product_id' => (int)$context['product_id'],
            'order_id' => (int)$context['order_id'],
            'order_sn' => (string)$context['order_sn'],
            'customer_user_id' => (int)$context['customer_user_id'],
            'customer_uuid' => (string)$context['customer_uuid'],
            'merchant_user_id' => (int)$context['merchant_user_id'],
            'platform_user_id' => (int)$context['platform_user_id'],
            'chat_uuid' => (string)$context['chat_uuid'],
            'title' => (string)$context['title'],
        ];
        foreach ($checks as $column => $expected) {
            $actual = is_int($expected) ? (int)$row[$column] : (string)$row[$column];
            if ($actual !== $expected) {
                $this->fail("Created ticket column {$column} expected {$expected}, got {$actual}.");
                return;
            }
        }
        $this->ok('Created ticket stores expected context and pending status.');
    }

    private function assertCreateEvent(int $ticketId, int $orderId, int $productId, string $chatUuid): void
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_customer_service_event}}')
            ->where([
                'ticket_id' => $ticketId,
                'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_CREATE,
                'from_status' => '',
                'to_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
                'status' => 1,
            ])
            ->one(Yii::$app->db);
        if (!$row) {
            $this->fail('Create event row not found.');
            return;
        }

        $metadata = json_decode((string)$row['metadata_json'], true);
        if (!is_array($metadata) || (int)($metadata['order_id'] ?? 0) !== $orderId || (int)($metadata['product_id'] ?? 0) !== $productId || (string)($metadata['chat_uuid'] ?? '') !== $chatUuid) {
            $this->fail('Create event metadata does not preserve order/product/chat context.');
            return;
        }
        $this->ok('Create event row and metadata are stored.');
    }

    private function assertNoTicketByOrder(int $orderId, string $message): void
    {
        $exists = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['order_id' => $orderId])
            ->exists(Yii::$app->db);
        if ($exists) {
            $this->fail($message);
            return;
        }
        $this->ok($message);
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
        $this->stdout("Ticket type: {$result['ticketType']}; store: {$result['storeId']}; created: {$result['created']}; ticket: {$result['ticketId']}\n");
        foreach ($result['skipped'] as $skip) {
            $this->stdout('SKIP reason=' . $skip['reason'] . "\n");
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
