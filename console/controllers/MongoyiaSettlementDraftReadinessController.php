<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\services\mall\SettlementDraftService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementDraftReadinessController extends Controller
{
    public $storeId = 0;
    public $limit = 100;
    public $apply = false;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['storeId', 'limit', 'apply', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia settlement draft readiness\n");
        $this->limit = max(1, (int)$this->limit);

        $this->checkSchema();
        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runCurrentData((int)$this->storeId, $this->limit, (bool)$this->apply);
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for settlement draft fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $readyOne = $this->createOrder($storeId, $userId, 'DRAFT-READY-ONE', 25.00, 4.00, Order::LOGISTICS_REVIEW_PASSED);
            $this->createFundLog($storeId, $readyOne, -4.00, 'shipment_fee_deduction order_sn=' . $readyOne->sn);

            $readyTwo = $this->createOrder($storeId, $userId, 'DRAFT-READY-TWO', 35.00, 6.00, Order::LOGISTICS_REVIEW_PASSED);
            $this->createFundLog($storeId, $readyTwo, -6.00, 'shipment_fee_deduction order_sn=' . $readyTwo->sn);

            $blocked = $this->createOrder($storeId, $userId, 'DRAFT-BLOCKED', 15.00, 2.00, Order::LOGISTICS_REVIEW_PENDING);
            $this->createFundLog($storeId, $blocked, -2.00, 'shipment_fee_deduction order_sn=' . $blocked->sn);

            $existing = $this->createOrder($storeId, $userId, 'DRAFT-EXISTING', 18.00, 3.00, Order::LOGISTICS_REVIEW_PASSED);
            $this->createFundLog($storeId, $existing, -3.00, 'shipment_fee_deduction order_sn=' . $existing->sn);
            $this->createExistingDraft($storeId, $existing);

            $dryRun = $this->collectDrafts($storeId, 4, false);
            $this->section('Dry-run report');
            $this->printResult($dryRun);
            $this->assertSameInt(4, (int)$dryRun['scanned'], 'Dry-run scans four received orders.');
            $this->assertSameInt(2, (int)$dryRun['readyOrders'], 'Dry-run has two draft-ready orders.');
            $this->assertSameInt(1, (int)$dryRun['blockedOrders'], 'Dry-run has one readiness-blocked order.');
            $this->assertSameInt(1, (int)$dryRun['duplicateOrders'], 'Dry-run reports one already-drafted order.');
            $this->assertSameInt(1, count($dryRun['drafts']), 'Dry-run returns one draft preview.');
            $this->assertMoney(60, (float)$dryRun['orderAmount'], 'Dry-run order amount is summed.');
            $this->assertMoney(10, (float)$dryRun['shipmentFeeDeducted'], 'Dry-run shipment-fee deductions are summarized.');
            $this->assertMoney(60, (float)$dryRun['netAmount'], 'Dry-run net amount equals order amount for draft stage.');
            $this->assertBlockedReason($dryRun, $blocked->id, 'logistics review pending');
            $this->assertBlockedReason($dryRun, $existing->id, 'already in active settlement draft');

            $apply = $this->collectDrafts($storeId, 4, true);
            $this->section('Apply report');
            $this->printResult($apply);
            $this->assertSameInt(1, (int)$apply['draftsCreated'], 'Apply creates one settlement draft.');
            $this->assertSameInt(2, (int)$apply['ordersInserted'], 'Apply inserts two draft order rows.');
            $this->assertPersistedDraft($storeId, 2, 60, 10);

            $repeat = $this->collectDrafts($storeId, 4, true);
            $this->section('Repeat apply report');
            $this->printResult($repeat);
            $this->assertSameInt(0, (int)$repeat['draftsCreated'], 'Repeat apply creates no extra draft.');
            $this->assertSameInt(0, (int)$repeat['ordersInserted'], 'Repeat apply inserts no extra draft rows.');
            $this->assertSameInt(3, (int)$repeat['duplicateOrders'], 'Repeat apply treats all ready orders as already drafted.');

            $transaction->rollBack();
            $this->ok('Settlement draft fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement draft fixture failed: ' . $e->getMessage());
        }
    }

    private function runCurrentData(int $storeId, int $limit, bool $apply)
    {
        $this->section($apply ? 'Apply current data' : 'Dry-run current data');
        $result = $this->collectDrafts($storeId, $limit, $apply);
        $this->printResult($result);
        if (!$apply && $result['readyOrders'] > 0) {
            $this->warn('Settlement draft rows are ready to create; rerun with --apply=1 after reviewing the report.');
        }
        if ($result['blockedOrders'] > 0 || $result['duplicateOrders'] > 0) {
            $this->warn('Some received orders are blocked or already in active settlement drafts.');
        }
    }

    private function collectDrafts(int $storeId, int $limit, bool $apply): array
    {
        return (new SettlementDraftService())->run($storeId, $limit, $apply);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'sn', 'amount', 'payment_status', 'shipment_status', 'shipment_fee', 'shipment_fee_deducted_at', 'logistics_review_status']);
        $this->requireColumns('{{%base_fund_log}}', ['id', 'store_id', 'change', 'remark', 'type']);
        $this->requireColumns('{{%mall_settlement_draft}}', ['id', 'store_id', 'sn', 'order_count', 'order_amount', 'shipment_fee_deducted', 'net_amount', 'draft_status', 'status']);
        $this->requireColumns('{{%mall_settlement_draft_order}}', ['id', 'draft_id', 'order_id', 'order_sn', 'store_id', 'order_amount', 'shipment_fee_deducted', 'status']);
    }

    private function createOrder(int $storeId, int $userId, string $prefix, float $amount, float $shipmentFee, int $reviewStatus): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Settlement draft fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'Draft';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local settlement draft fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_settlement_draft@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-settlement-draft-readiness/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9016;
        $order->shipment_name = 'Settlement Draft Express';
        $order->shipment_fee = $shipmentFee;
        $order->shipment_fee_deducted_at = time();
        $order->shipment_status = Order::SHIPMENT_STATUS_RECEIVED;
        $order->logistics_review_status = $reviewStatus;
        $order->logistics_reviewed_at = $reviewStatus === Order::LOGISTICS_REVIEW_PASSED ? time() : 0;
        $order->logistics_reviewed_by = $reviewStatus === Order::LOGISTICS_REVIEW_PASSED ? 1 : 0;
        $order->logistics_review_remark = '';
        $order->shipped_at = time();
        $order->product_amount = $amount;
        $order->amount = $amount;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = Order::SHIPMENT_STATUS_RECEIVED;
        if (!$order->save()) {
            throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
    }

    private function createFundLog(int $storeId, Order $order, float $change, string $remark)
    {
        $log = new FundLog();
        $log->store_id = $storeId;
        $log->user_id = 1;
        $log->name = '物流费扣费：订单 #' . $order->id;
        $log->change = $change;
        $log->original = 100;
        $log->balance = 100 + $change;
        $log->remark = $remark;
        $log->type = FundLog::TYPE_CONSUME;
        if (!$log->save()) {
            throw new \RuntimeException(json_encode($log->errors, JSON_UNESCAPED_UNICODE));
        }
    }

    private function createExistingDraft(int $storeId, Order $order)
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft}}', [
            'store_id' => $storeId,
            'sn' => 'SETD-EXISTING-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'order_count' => 1,
            'order_amount' => $order->amount,
            'shipment_fee_deducted' => 3.00,
            'net_amount' => $order->amount,
            'draft_status' => SettlementDraftService::DRAFT_STATUS_DRAFT,
            'remark' => 'Existing fixture draft',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
        $draftId = (int)Yii::$app->db->getLastInsertID();

        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft_order}}', [
            'draft_id' => $draftId,
            'order_id' => (int)$order->id,
            'order_sn' => (string)$order->sn,
            'store_id' => $storeId,
            'order_amount' => $order->amount,
            'shipment_fee_deducted' => 3.00,
            'payment_status' => (int)$order->payment_status,
            'shipment_status' => (int)$order->shipment_status,
            'logistics_review_status' => (int)$order->logistics_review_status,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function assertPersistedDraft(int $storeId, int $orderCount, float $orderAmount, float $shipmentFee)
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_settlement_draft}}')
            ->where(['store_id' => $storeId, 'draft_status' => SettlementDraftService::DRAFT_STATUS_DRAFT, 'remark' => 'Created by mongoyia-settlement-draft-readiness/run'])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db);
        if (!$row) {
            $this->fail('Persisted settlement draft row was not found.');
            return;
        }

        $this->assertSameInt($orderCount, (int)$row['order_count'], 'Persisted draft order count is correct.');
        $this->assertMoney($orderAmount, (float)$row['order_amount'], 'Persisted draft order amount is correct.');
        $this->assertMoney($shipmentFee, (float)$row['shipment_fee_deducted'], 'Persisted draft shipment-fee total is correct.');
        $this->assertMoney($orderAmount, (float)$row['net_amount'], 'Persisted draft net amount is correct.');

        $childCount = (int)(new \yii\db\Query())
            ->from('{{%mall_settlement_draft_order}}')
            ->where(['draft_id' => (int)$row['id'], 'status' => 1])
            ->count('*', Yii::$app->db);
        $this->assertSameInt($orderCount, $childCount, 'Persisted draft has expected child rows.');
    }

    private function printResult(array $result)
    {
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Scanned: {$result['scanned']}\n");
        $this->stdout("Ready orders: {$result['readyOrders']}\n");
        $this->stdout("Blocked orders: {$result['blockedOrders']}\n");
        $this->stdout("Duplicate orders: {$result['duplicateOrders']}\n");
        $this->stdout("Drafts created: {$result['draftsCreated']}\n");
        $this->stdout("Orders inserted: {$result['ordersInserted']}\n");
        $this->stdout('Order amount: ' . number_format((float)$result['orderAmount'], 2) . "\n");
        $this->stdout('Shipment fee deducted: ' . number_format((float)$result['shipmentFeeDeducted'], 2) . "\n");
        $this->stdout('Net amount: ' . number_format((float)$result['netAmount'], 2) . "\n");
        foreach ($result['drafts'] as $row) {
            $draft = $row['draft_id'] === null ? 'preview' : ('#' . $row['draft_id']);
            $this->stdout("DRAFT {$draft} store={$row['store_id']} orders={$row['order_count']} amount=" . number_format((float)$row['order_amount'], 2) . ' fee=' . number_format((float)$row['shipment_fee_deducted'], 2) . ' net=' . number_format((float)$row['net_amount'], 2) . ' order_ids=' . implode(',', $row['order_ids']) . "\n");
        }
        foreach ($result['blockedRows'] as $row) {
            $this->stdout("BLOCKED order={$row['id']} store={$row['store_id']} reason={$row['reason']} amount=" . number_format((float)$row['amount'], 2) . "\n");
        }
    }

    private function assertBlockedReason(array $result, int $orderId, string $reason)
    {
        foreach ($result['blockedRows'] as $row) {
            if ((int)$row['id'] === $orderId && $row['reason'] === $reason) {
                $this->ok("Blocked order {$orderId} reason is {$reason}.");
                return;
            }
        }

        $this->fail("Blocked order {$orderId} reason {$reason} was not found.");
    }

    private function firstSellerStoreId(): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'id', 0])
            ->andWhere(['>', 'status', 0])
            ->andWhere(['not in', 'id', [5]])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
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

    private function requireColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema) {
            $this->fail("Missing table {$table}.");
            return;
        }
        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                $this->fail("Table {$table} missing column {$column}.");
                return;
            }
        }
        $this->ok("Table {$table} has required columns.");
    }

    private function assertSameInt(int $expected, int $actual, string $message)
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertMoney(float $expected, float $actual, string $message)
    {
        if (round($expected, 2) !== round($actual, 2)) {
            $this->fail("{$message} Expected " . number_format($expected, 2) . ', got ' . number_format($actual, 2) . '.');
            return;
        }
        $this->ok($message);
    }

    private function section(string $name)
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function warn(string $message)
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
