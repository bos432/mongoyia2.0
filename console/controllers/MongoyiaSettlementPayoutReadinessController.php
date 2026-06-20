<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\services\mall\SettlementPayoutPlanService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementPayoutReadinessController extends Controller
{
    public $storeId = 0;
    public $limit = 100;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['storeId', 'limit', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia settlement payout readiness\n");
        $this->limit = max(1, (int)$this->limit);

        $this->checkSchema();
        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runCurrentData((int)$this->storeId, $this->limit);
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
            $this->fail('Need an active seller store and user for settlement payout fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $readyOne = $this->createOrder($storeId, $userId, 'PAYOUT-READY-ONE', 21.00, 4.00, Order::LOGISTICS_REVIEW_PASSED);
            $this->createFundLog($storeId, $readyOne, -4.00, 'shipment_fee_deduction order_sn=' . $readyOne->sn);

            $readyTwo = $this->createOrder($storeId, $userId, 'PAYOUT-READY-TWO', 29.00, 6.00, Order::LOGISTICS_REVIEW_PASSED);
            $this->createFundLog($storeId, $readyTwo, -6.00, 'shipment_fee_deduction order_sn=' . $readyTwo->sn);

            $blocked = $this->createOrder($storeId, $userId, 'PAYOUT-BLOCKED', 13.00, 3.00, Order::LOGISTICS_REVIEW_PENDING);
            $this->createFundLog($storeId, $blocked, -3.00, 'shipment_fee_deduction order_sn=' . $blocked->sn);

            $result = $this->collectPayoutPlan($storeId, 3);
            $this->printResult($result);
            $this->assertSameInt(3, (int)$result['scanned'], 'Fixture scans three received orders.');
            $this->assertSameInt(2, (int)$result['readyOrders'], 'Fixture has two payout-ready orders.');
            $this->assertSameInt(1, (int)$result['blockedOrders'], 'Fixture has one blocked order.');
            $this->assertMoney(50, (float)$result['readyAmount'], 'Fixture ready amount is summed.');
            $this->assertMoney(10, (float)$result['shipmentFeeDeducted'], 'Fixture shipment-fee deductions are summarized.');
            $this->assertSameInt(1, count($result['stores']), 'Fixture creates one store payout row.');
            $this->assertSameInt(2, (int)$result['stores'][0]['orders'], 'Fixture store row has two ready orders.');
            $this->assertMoney(50, (float)$result['stores'][0]['netPayoutAmount'], 'Fixture net payout is report-only order amount.');
            $this->assertBlockedReason($result, $blocked->id, 'logistics review pending');

            $transaction->rollBack();
            $this->ok('Settlement payout fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement payout fixture failed: ' . $e->getMessage());
        }
    }

    private function runCurrentData(int $storeId, int $limit)
    {
        $this->section('Current data');
        $result = $this->collectPayoutPlan($storeId, $limit);
        $this->printResult($result);
        if ($result['blockedOrders'] > 0) {
            $this->warn('Some received orders are blocked from payout planning.');
        }
    }

    private function collectPayoutPlan(int $storeId, int $limit): array
    {
        return (new SettlementPayoutPlanService())->run($storeId, $limit);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'sn', 'amount', 'payment_status', 'shipment_status', 'shipment_fee', 'shipment_fee_deducted_at', 'logistics_review_status']);
        $this->requireColumns('{{%base_fund_log}}', ['id', 'store_id', 'change', 'remark', 'type']);
    }

    private function createOrder(int $storeId, int $userId, string $prefix, float $amount, float $shipmentFee, int $reviewStatus): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Settlement payout fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'Payout';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local settlement payout fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_settlement_payout@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-settlement-payout-readiness/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9015;
        $order->shipment_name = 'Payout Express';
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

    private function printResult(array $result)
    {
        $this->stdout("Scanned: {$result['scanned']}\n");
        $this->stdout("Ready orders: {$result['readyOrders']}\n");
        $this->stdout("Blocked orders: {$result['blockedOrders']}\n");
        $this->stdout('Ready amount: ' . number_format((float)$result['readyAmount'], 2) . "\n");
        $this->stdout('Shipment fee deducted: ' . number_format((float)$result['shipmentFeeDeducted'], 2) . "\n");
        $this->stdout('Net payout amount: ' . number_format((float)$result['netPayoutAmount'], 2) . "\n");
        foreach ($result['stores'] as $row) {
            $this->stdout("STORE store={$row['store_id']} orders={$row['orders']} amount=" . number_format((float)$row['orderAmount'], 2) . ' fee=' . number_format((float)$row['shipmentFeeDeducted'], 2) . ' net=' . number_format((float)$row['netPayoutAmount'], 2) . "\n");
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
