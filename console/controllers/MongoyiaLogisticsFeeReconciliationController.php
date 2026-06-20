<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaLogisticsFeeReconciliationController extends Controller
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
        $this->stdout("Mongoyia logistics fee reconciliation\n");
        $this->limit = max(1, (int)$this->limit);

        $this->checkSchema();
        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runReconciliation((int)$this->storeId, $this->limit);
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
            $this->fail('Need an active seller store and user for logistics fee reconciliation fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $store = Store::findOne($storeId);
            $store->fund = 100;
            $store->consume_amount = 0;
            $store->consume_count = 0;
            if (!$store->save()) {
                throw new \RuntimeException(json_encode($store->errors, JSON_UNESCAPED_UNICODE));
            }

            $okOrder = $this->createOrder($storeId, $userId, 'LOGREC-OK', 12.50);
            $okOrder->markShipped(9010, 'Reconcile Express', time(), 12.50);
            $missingLog = $this->createOrder($storeId, $userId, 'LOGREC-MISSING-LOG', 3.25);
            $missingLog->shipment_fee_deducted_at = time();
            if (!$missingLog->save()) {
                throw new \RuntimeException(json_encode($missingLog->errors, JSON_UNESCAPED_UNICODE));
            }

            $wrongAmount = $this->createOrder($storeId, $userId, 'LOGREC-WRONG-AMOUNT', 4.00);
            $wrongAmount->shipment_fee_deducted_at = time();
            if (!$wrongAmount->save()) {
                throw new \RuntimeException(json_encode($wrongAmount->errors, JSON_UNESCAPED_UNICODE));
            }
            $this->createFundLog($storeId, $wrongAmount, -2.00, 87.50, 85.50);

            $notDeducted = $this->createOrder($storeId, $userId, 'LOGREC-NOT-DEDUCTED', 5.50);

            $result = $this->collectReconciliation($storeId, 50);
            $this->printResult($result);
            $this->assertSameInt(4, (int)$result['ordersWithFee'], 'Fixture has four shipment-fee orders.');
            $this->assertSameInt(1, (int)$result['ok'], 'Fixture has one reconciled order.');
            $this->assertSameInt(3, (int)$result['issueCount'], 'Fixture has three reconciliation issues.');
            $this->assertIssue($result, $missingLog->id, 'missing deduction log');
            $this->assertIssue($result, $wrongAmount->id, 'deduction amount mismatch');
            $this->assertIssue($result, $notDeducted->id, 'fee not deducted');

            $transaction->rollBack();
            $this->ok('Logistics fee reconciliation fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Logistics fee reconciliation fixture failed: ' . $e->getMessage());
        }
    }

    private function runReconciliation(int $storeId, int $limit)
    {
        $this->section('Current data reconciliation');
        $result = $this->collectReconciliation($storeId, $limit);
        $this->printResult($result);
        if ($result['issueCount'] > 0) {
            $this->warn("Found {$result['issueCount']} logistics fee reconciliation issue(s).");
        }
    }

    private function collectReconciliation(int $storeId, int $limit): array
    {
        $query = Order::find()
            ->where(['>', 'shipment_fee', 0])
            ->andWhere(['>', 'status', Order::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        $orders = $query->all();
        $result = [
            'ordersWithFee' => count($orders),
            'ok' => 0,
            'issueCount' => 0,
            'shipmentFeeTotal' => 0.0,
            'deductedLogTotal' => 0.0,
            'issues' => [],
        ];

        foreach ($orders as $order) {
            $fee = round((float)$order->shipment_fee, 2);
            $result['shipmentFeeTotal'] += $fee;
            $logs = $this->deductionLogs($order);
            $logTotal = $this->deductionLogTotal($logs);
            $result['deductedLogTotal'] += $logTotal;

            if ((int)$order->shipment_fee_deducted_at <= 0) {
                $this->addIssue($result, $order, 'fee not deducted', $fee, $logTotal);
                continue;
            }
            if (!count($logs)) {
                $this->addIssue($result, $order, 'missing deduction log', $fee, $logTotal);
                continue;
            }
            if (abs($logTotal - $fee) >= 0.01) {
                $this->addIssue($result, $order, 'deduction amount mismatch', $fee, $logTotal);
                continue;
            }

            $result['ok']++;
        }

        $result['shipmentFeeTotal'] = round($result['shipmentFeeTotal'], 2);
        $result['deductedLogTotal'] = round($result['deductedLogTotal'], 2);

        return $result;
    }

    private function addIssue(array &$result, Order $order, string $reason, float $fee, float $logTotal)
    {
        $result['issueCount']++;
        if (count($result['issues']) >= 20) {
            return;
        }

        $result['issues'][] = [
            'id' => (int)$order->id,
            'sn' => (string)$order->sn,
            'store_id' => (int)$order->store_id,
            'reason' => $reason,
            'shipment_fee' => $fee,
            'deducted_log_total' => round($logTotal, 2),
            'shipment_fee_deducted_at' => (int)$order->shipment_fee_deducted_at,
        ];
    }

    private function deductionLogs(Order $order): array
    {
        return (new \yii\db\Query())
            ->from('{{%base_fund_log}}')
            ->where(['store_id' => (int)$order->store_id, 'type' => FundLog::TYPE_CONSUME])
            ->andWhere(['like', 'remark', 'shipment_fee_deduction order_sn=' . $order->sn])
            ->all(Yii::$app->db);
    }

    private function deductionLogTotal(array $logs): float
    {
        $total = 0.0;
        foreach ($logs as $log) {
            $total += abs((float)$log['change']);
        }

        return round($total, 2);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'sn', 'shipment_fee', 'shipment_fee_deducted_at', 'shipment_status']);
        $this->requireColumns('{{%base_fund_log}}', ['id', 'store_id', 'change', 'remark', 'type']);
    }

    private function createOrder(int $storeId, int $userId, string $prefix, float $shipmentFee): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Logistics fee reconciliation fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'FeeReconcile';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local logistics fee reconciliation fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_fee_reconciliation@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-logistics-fee-reconciliation/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9010;
        $order->shipment_name = 'Reconcile Express';
        $order->shipment_fee = $shipmentFee;
        $order->shipment_fee_deducted_at = 0;
        $order->shipment_status = Order::SHIPMENT_STATUS_UNSHIPPED;
        $order->logistics_review_status = Order::LOGISTICS_REVIEW_PENDING;
        $order->logistics_reviewed_at = 0;
        $order->logistics_reviewed_by = 0;
        $order->logistics_review_remark = '';
        $order->shipped_at = 0;
        $order->product_amount = 1;
        $order->amount = 1;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = Order::PAYMENT_STATUS_PAID;

        if (!$order->save()) {
            throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
    }

    private function createFundLog(int $storeId, Order $order, float $change, float $original, float $balance)
    {
        $log = new FundLog();
        $log->store_id = $storeId;
        $log->user_id = 1;
        $log->name = '物流费扣费：订单 #' . $order->id;
        $log->change = $change;
        $log->original = $original;
        $log->balance = $balance;
        $log->remark = 'shipment_fee_deduction order_sn=' . $order->sn;
        $log->type = FundLog::TYPE_CONSUME;
        if (!$log->save()) {
            throw new \RuntimeException(json_encode($log->errors, JSON_UNESCAPED_UNICODE));
        }
    }

    private function printResult(array $result)
    {
        $this->stdout("Orders with fee: {$result['ordersWithFee']}\n");
        $this->stdout("Reconciled: {$result['ok']}\n");
        $this->stdout("Issues: {$result['issueCount']}\n");
        $this->stdout('Shipment fee total: ' . number_format((float)$result['shipmentFeeTotal'], 2) . "\n");
        $this->stdout('Deducted log total: ' . number_format((float)$result['deductedLogTotal'], 2) . "\n");
        foreach ($result['issues'] as $issue) {
            $this->stdout("ISSUE order={$issue['id']} store={$issue['store_id']} reason={$issue['reason']} fee=" . number_format((float)$issue['shipment_fee'], 2) . ' log=' . number_format((float)$issue['deducted_log_total'], 2) . "\n");
        }
    }

    private function assertIssue(array $result, int $orderId, string $reason)
    {
        foreach ($result['issues'] as $issue) {
            if ((int)$issue['id'] === $orderId && $issue['reason'] === $reason) {
                $this->ok("Issue '{$reason}' is reported for order {$orderId}.");
                return;
            }
        }

        $this->fail("Issue '{$reason}' was not reported for order {$orderId}.");
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
