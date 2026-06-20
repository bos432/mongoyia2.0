<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceTicketWorkflowService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceTicketWorkflowTestController extends Controller
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
        $this->stdout("Mongoyia customer-service ticket workflow test\n");
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
        $this->requireFileContains('common/services/mall/CustomerServiceTicketWorkflowService.php', [
            'class CustomerServiceTicketWorkflowService',
            'function run(',
            'transitionBlockReason',
            'first_response_at',
            'resolved_at',
            'closed_at',
            'insertEvent',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipCustomerServiceTicketWorkflow',
            'customer-service ticket workflow Phase 6 closure',
            'customer-service-ticket-workflow-test/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'CustomerServiceTicketWorkflowService.php',
            'CustomerServiceTicketWorkflowTestController.php',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/KfController.php', [
            'actionTicketWorkflow',
            'CustomerServiceTicketWorkflowService',
            'isPost',
            'OPERATOR_TYPE_MERCHANT',
            'target_status',
        ]);
        $this->requireFileContains('backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TICKET_WORKFLOW_BACKEND_V1',
            'data-mongoyia-customer-service-ticket-workflow="actions"',
            'method="post"',
            'ticket-workflow',
            'target_status',
            'csrfParam',
        ]);
        $this->requireFileContains('console/migrations/m260619_102000_mongoyia_customer_service_ticket_workflow_permission.php', [
            '/mall/kf/ticket-workflow',
            '客服工单状态操作',
            'grantToCustomerServiceRoles',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_customer_service_ticket}}', [
            'id',
            'ticket_status',
            'store_id',
            'first_response_at',
            'resolved_at',
            'closed_at',
            'result',
            'remark',
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
            ->where(['path' => '/mall/kf/ticket-workflow', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/kf/ticket-workflow. Run migration m260619_102000_mongoyia_customer_service_ticket_workflow_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/kf/ticket-workflow');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 must have ticket workflow permission.');
            return;
        }
        $this->ok('Seller role has ticket workflow permission.');
    }

    private function checkFixture(): void
    {
        $this->section('Rollback-clean fixture');
        $storeIds = $this->firstTwoStoreIds();
        $userId = $this->firstUserId();
        if (count($storeIds) < 2 || $userId <= 0) {
            $this->fail('Need two active stores and one active user for customer-service ticket workflow fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new CustomerServiceTicketWorkflowService();
            $ticketId = $this->createTicket($storeIds[0], $userId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Workflow pending ticket');
            $otherStoreTicketId = $this->createTicket($storeIds[1], $userId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Workflow cross-store ticket');

            $dryRun = $service->run([$ticketId], CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, false, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'dry in progress', $storeIds[0]);
            $this->printResult($dryRun);
            $this->assertSameInt(1, (int)$dryRun['eligible'], 'Dry-run sees one eligible pending ticket.');
            $this->assertSameInt(0, (int)$dryRun['updated'], 'Dry-run does not update tickets.');
            $this->assertTicketStatus($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Dry-run keeps pending status.');
            $this->assertEventCount($ticketId, 0, 'Dry-run does not create workflow events.');

            $inProgress = $service->run([$ticketId], CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'fixture first response', $storeIds[0]);
            $this->printResult($inProgress);
            $this->assertSameInt(1, (int)$inProgress['updated'], 'Apply moves pending ticket to in_progress.');
            $this->assertTicketStatus($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, 'In-progress status is stored.');
            $this->assertPositiveColumn($ticketId, 'first_response_at', 'First response timestamp is stored.');
            $this->assertTransitionEvent($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS);

            $resolved = $service->run([$ticketId], CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'fixture resolved', $storeIds[0]);
            $this->printResult($resolved);
            $this->assertSameInt(1, (int)$resolved['updated'], 'Apply moves in_progress ticket to resolved.');
            $this->assertTicketStatus($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, 'Resolved status is stored.');
            $this->assertPositiveColumn($ticketId, 'resolved_at', 'Resolved timestamp is stored.');
            $this->assertTransitionEvent($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED);

            $repeatResolved = $service->run([$ticketId], CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'repeat resolved', $storeIds[0]);
            $this->printResult($repeatResolved);
            $this->assertSameInt(0, (int)$repeatResolved['updated'], 'Repeat resolved transition is blocked.');
            $this->assertSkippedReason($repeatResolved, $ticketId, 'invalid transition from resolved to resolved');

            $closed = $service->run([$ticketId], CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'fixture closed', $storeIds[0]);
            $this->printResult($closed);
            $this->assertSameInt(1, (int)$closed['updated'], 'Apply moves resolved ticket to closed.');
            $this->assertTicketStatus($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED, 'Closed status is stored.');
            $this->assertPositiveColumn($ticketId, 'closed_at', 'Closed timestamp is stored.');
            $this->assertTransitionEvent($ticketId, CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED, CustomerServiceAdvancedService::TICKET_STATUS_CLOSED);

            $crossStore = $service->run([$otherStoreTicketId], CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS, true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'cross-store blocked', $storeIds[0]);
            $this->printResult($crossStore);
            $this->assertSameInt(0, (int)$crossStore['updated'], 'Store-scoped workflow blocks other-store ticket.');
            $this->assertSkippedReason($crossStore, $otherStoreTicketId, 'ticket not found or out of scope');
            $this->assertTicketStatus($otherStoreTicketId, CustomerServiceAdvancedService::TICKET_STATUS_PENDING, 'Cross-store ticket remains pending.');

            $unsupported = $service->run([$ticketId], 'not_a_status', true, 1, CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM, 'unsupported', $storeIds[0]);
            $this->printResult($unsupported);
            $this->assertSkippedReason($unsupported, 0, 'unsupported target status');

            $transaction->rollBack();
            $this->ok('Customer-service ticket workflow fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Customer-service ticket workflow fixture failed: ' . $e->getMessage());
        }
    }

    private function createTicket(int $storeId, int $userId, string $status, string $title): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
            'ticket_sn' => 'CSTWF-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST,
            'ticket_status' => $status,
            'priority' => CustomerServiceAdvancedService::PRIORITY_NORMAL,
            'store_id' => $storeId,
            'product_id' => 0,
            'order_id' => 0,
            'order_sn' => '',
            'customer_user_id' => $userId,
            'customer_uuid' => 'workflow_user_' . $userId,
            'merchant_user_id' => 0,
            'platform_user_id' => 1,
            'chat_uuid' => 'workflow_chat_' . $storeId,
            'title' => $title,
            'content' => 'Created by customer-service-ticket-workflow-test/run.',
            'result' => '',
            'remark' => 'workflow fixture',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
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

    private function assertPositiveColumn(int $ticketId, string $column, string $message): void
    {
        $value = (int)(new \yii\db\Query())
            ->select($column)
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId])
            ->scalar(Yii::$app->db);
        if ($value <= 0) {
            $this->fail("{$message} Column {$column} is {$value}.");
            return;
        }
        $this->ok($message);
    }

    private function assertTransitionEvent(int $ticketId, string $fromStatus, string $toStatus): void
    {
        $exists = (new \yii\db\Query())
            ->from('{{%mall_customer_service_event}}')
            ->where([
                'ticket_id' => $ticketId,
                'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_STATUS_CHANGE,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'status' => 1,
            ])
            ->exists(Yii::$app->db);
        if (!$exists) {
            $this->fail("Missing transition event {$fromStatus} -> {$toStatus} for ticket {$ticketId}.");
            return;
        }
        $this->ok("Transition event {$fromStatus} -> {$toStatus} is stored.");
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

    private function assertSkippedReason(array $result, int $id, string $reason): void
    {
        foreach ($result['skipped'] as $row) {
            if ((int)$row['id'] === $id && (string)$row['reason'] === $reason) {
                $this->ok("Skip reason for {$id} is {$reason}.");
                return;
            }
        }

        $this->fail("Skip reason {$reason} for {$id} was not found.");
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
        $this->stdout("Target: {$result['targetStatus']}; store scope: {$result['storeId']}\n");
        $this->stdout("Scanned: {$result['scanned']}; eligible: {$result['eligible']}; updated: {$result['updated']}\n");
        foreach ($result['dryRunIds'] as $id) {
            $this->stdout("DRY ticket={$id}\n");
        }
        foreach ($result['updatedIds'] as $id) {
            $this->stdout("APPLY ticket={$id}\n");
        }
        foreach ($result['skipped'] as $skip) {
            $this->stdout("SKIP ticket={$skip['id']} reason={$skip['reason']}\n");
        }
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
