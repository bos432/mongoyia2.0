<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceTicketReadonlyTestController extends Controller
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
        $this->stdout("Mongoyia customer-service ticket readonly backend test\n");
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
        $this->requireFileContains('common/services/mall/CustomerServiceAdvancedService.php', [
            'ticketRows',
            'ticketRow',
            'eventRows',
            'statRows',
            'tableExists',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionTickets',
            'actionTicketView',
            'readableStoreId',
            'CustomerServiceAdvancedService',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TICKET_READONLY_V1',
            'data-mongoyia-customer-service-ticket-readonly="index"',
            '本页只读展示',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TICKET_WORKFLOW_BACKEND_V1',
            'data-mongoyia-customer-service-ticket-readonly="view"',
            '不提供退款、改订单、发消息、上传文件等操作',
        ]);
        $this->requireFileMissing('backend/modules/mall/views/kf/tickets.php', [
            'data-mongoyia-customer-service-order-assist',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/tickets.php', [
            'data-mongoyia-customer-service-stat-widget-readiness="reserved"',
            'data-mongoyia-customer-service-stat-apply="disabled"',
        ]);
        $this->requireFileMissing('backend/modules/mall/views/kf/ticket-view.php', [
            'data-mongoyia-customer-service-order-assist',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'data-mongoyia-customer-service-complaint-evidence-gate="reserved"',
            'data-mongoyia-customer-service-complaint-evidence-upload="disabled"',
            'data-mongoyia-customer-service-complaint-evidence-apply="disabled"',
        ]);
        $this->requireFileContains('console/migrations/m260619_101000_mongoyia_customer_service_ticket_readonly_permission.php', [
            '/mall/kf/tickets',
            '/mall/kf/ticket-view',
            '客服工单只读',
            'grantToCustomerServiceRoles',
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
            'customer_user_id',
            'merchant_user_id',
            'platform_user_id',
            'title',
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
            'status',
        ]);
        $this->requireColumns('{{%mall_customer_service_stat_daily}}', [
            'id',
            'stat_date',
            'store_id',
            'service_user_id',
            'ticket_count',
            'resolved_count',
            'unresolved_count',
            'status',
        ]);
    }

    private function checkPermissions(): void
    {
        $this->section('Permissions');
        foreach (['/mall/kf/tickets', '/mall/kf/ticket-view'] as $path) {
            $permissionId = (int)(new \yii\db\Query())
                ->select('id')
                ->from('{{%base_permission}}')
                ->where(['path' => $path, 'status' => 1])
                ->scalar(Yii::$app->db);
            if ($permissionId <= 0) {
                $this->fail('Missing active permission ' . $path . '. Run migration m260619_101000_mongoyia_customer_service_ticket_readonly_permission.');
                continue;
            }
            $this->ok('Permission exists: ' . $path);

            $sellerGrant = (new \yii\db\Query())
                ->from('{{%base_role_permission}}')
                ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
                ->exists(Yii::$app->db);
            if (!$sellerGrant) {
                $this->fail('Seller role 50 must have readonly permission ' . $path . '.');
                continue;
            }
            $this->ok('Seller role has readonly permission ' . $path . '.');
        }
    }

    private function checkFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service ticket readonly fixture.');
            return;
        }

        $service = new CustomerServiceAdvancedService();
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $statUserId = 900000 + mt_rand(1000, 9999);
            $ownTicket = $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Readonly own order assist');
            $otherTicket = $this->createTicket($storeIds[1], $userId, CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'Readonly other complaint');
            $this->createEvent($ownTicket, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS);
            $this->createEvent($ownTicket, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED);
            $this->createStat($storeIds[0], $statUserId);

            $platformRows = $service->ticketRows(0, 20);
            $this->assertContainsId($platformRows, $ownTicket, 'Platform readonly list includes own-store fixture ticket.');
            $this->assertContainsId($platformRows, $otherTicket, 'Platform readonly list includes other-store fixture ticket.');

            $sellerRows = $service->ticketRows($storeIds[0], 20);
            $this->assertContainsId($sellerRows, $ownTicket, 'Seller-scoped readonly list includes own-store fixture ticket.');
            $this->assertNotContainsId($sellerRows, $otherTicket, 'Seller-scoped readonly list excludes other-store fixture ticket.');

            $complaints = $service->ticketRows(0, 20, ['ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT]);
            $this->assertContainsId($complaints, $otherTicket, 'Ticket type filter returns complaint fixture.');
            $this->assertNotContainsId($complaints, $ownTicket, 'Ticket type filter excludes order-assist fixture.');

            $ownDetail = $service->ticketRow($ownTicket, $storeIds[0]);
            $blockedDetail = $service->ticketRow($otherTicket, $storeIds[0]);
            $this->assertSameInt($ownTicket, (int)($ownDetail['id'] ?? 0), 'Readonly detail loads own-store ticket.');
            $this->assertSameInt(0, (int)($blockedDetail['id'] ?? 0), 'Readonly detail blocks cross-store ticket.');

            $events = $service->eventRows($ownTicket);
            $this->assertSameInt(2, count($events), 'Readonly detail loads two fixture events.');

            $stats = $service->statRows($storeIds[0], 10);
            $this->assertContainsStat($stats, $storeIds[0], $statUserId);

            $transaction->rollBack();
            $this->ok('Customer-service ticket readonly fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Customer-service ticket readonly fixture failed: ' . $e->getMessage());
        }
    }

    private function createTicket(int $storeId, int $userId, string $type, string $status, string $title): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSTRO-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'ticket_type' => $type,
            'ticket_status' => $status,
            'priority' => $type === CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT ? CustomerServiceAdvancedService::PRIORITY_HIGH : CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 0,
            'order_id' => 0,
            'order_sn' => '',
            'customer_user_id' => $userId,
            'customer_uuid' => 'readonly_user_' . $userId,
            'merchant_user_id' => 0,
            'platform_user_id' => 1,
            'chat_uuid' => 'readonly_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-ticket-readonly-test/run.',
            'result' => '',
            'remark' => 'readonly backend fixture',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function createEvent(int $ticketId, string $fromStatus, string $toStatus): void
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
            'ticket_id' => $ticketId,
            'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_STATUS_CHANGE,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'operator_user_id' => 1,
            'operator_type' => CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
            'content' => 'Readonly backend fixture event.',
            'metadata_json' => '{}',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function createStat(int $storeId, int $userId): void
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_stat_daily}}', [
            'stat_date' => (int)date('Ymd', $now),
            'store_id' => $storeId,
            'service_user_id' => $userId,
            'session_count' => 1,
            'ticket_count' => 1,
            'order_assist_count' => 1,
            'complaint_count' => 0,
            'resolved_count' => 1,
            'unresolved_count' => 0,
            'first_response_seconds_total' => 60,
            'resolved_seconds_total' => 600,
            'remark' => 'readonly backend fixture',
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

    private function firstUserId(): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', 0])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
    }

    private function assertContainsId(array $rows, int $id, string $message): void
    {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                $this->ok($message);
                return;
            }
        }
        $this->fail($message . ' Missing id ' . $id . '.');
    }

    private function assertNotContainsId(array $rows, int $id, string $message): void
    {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                $this->fail($message . ' Unexpected id ' . $id . '.');
                return;
            }
        }
        $this->ok($message);
    }

    private function assertContainsStat(array $rows, int $storeId, int $userId): void
    {
        foreach ($rows as $row) {
            if ((int)$row['store_id'] === $storeId && (int)$row['service_user_id'] === $userId) {
                $this->ok('Readonly stat preview includes fixture stat row.');
                return;
            }
        }
        $this->fail('Readonly stat preview did not include fixture stat row.');
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

    private function requireFileMissing(string $path, array $needles): void
    {
        $fullPath = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                $this->fail("File {$path} exposes '{$needle}' before runtime write workflow is ready.");
                return;
            }
        }
        $this->ok("File keeps write-workflow markers hidden: {$path}");
    }

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
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
