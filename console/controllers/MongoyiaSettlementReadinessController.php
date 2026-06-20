<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\models\Store;
use common\services\mall\SettlementReadinessService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementReadinessController extends Controller
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
        $this->stdout("Mongoyia settlement readiness\n");
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
            $this->fail('Need an active seller store and user for settlement readiness fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $ready = $this->createOrder($storeId, $userId, 'SETTLE-READY', Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_RECEIVED, Order::LOGISTICS_REVIEW_PASSED, 8.00, true);
            $this->createFundLog($storeId, $ready, -8.00, 100, 92, 'shipment_fee_deduction order_sn=' . $ready->sn);

            $pendingReview = $this->createOrder($storeId, $userId, 'SETTLE-PENDING-REVIEW', Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_RECEIVED, Order::LOGISTICS_REVIEW_PENDING, 3.00, true);
            $this->createFundLog($storeId, $pendingReview, -3.00, 92, 89, 'shipment_fee_deduction order_sn=' . $pendingReview->sn);

            $feeIssue = $this->createOrder($storeId, $userId, 'SETTLE-FEE-ISSUE', Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_RECEIVED, Order::LOGISTICS_REVIEW_PASSED, 5.00, true);

            $refunded = $this->createOrder($storeId, $userId, 'SETTLE-REFUND', Order::PAYMENT_STATUS_REFUND, Order::SHIPMENT_STATUS_RECEIVED, Order::LOGISTICS_REVIEW_PASSED, 2.00, true);
            $this->createFundLog($storeId, $refunded, -2.00, 89, 87, 'shipment_fee_deduction order_sn=' . $refunded->sn);

            $result = $this->collectReadiness($storeId, 4);
            $this->printResult($result);
            $this->assertSameInt(4, (int)$result['scanned'], 'Fixture scans four received orders.');
            $this->assertSameInt(1, (int)$result['ready'], 'Fixture has one settlement-ready order.');
            $this->assertSameInt(1, (int)$result['pendingReview'], 'Fixture has one pending-review order.');
            $this->assertSameInt(1, (int)$result['feeIssues'], 'Fixture has one fee issue order.');
            $this->assertSameInt(1, (int)$result['refunded'], 'Fixture has one refunded order.');
            $this->assertReason($result, $ready->id, 'ready');
            $this->assertReason($result, $pendingReview->id, 'logistics review pending');
            $this->assertReason($result, $feeIssue->id, 'logistics fee not reconciled');
            $this->assertReason($result, $refunded->id, 'refunded order');

            $transaction->rollBack();
            $this->ok('Settlement readiness fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement readiness fixture failed: ' . $e->getMessage());
        }
    }

    private function runCurrentData(int $storeId, int $limit)
    {
        $this->section('Current data');
        $result = $this->collectReadiness($storeId, $limit);
        $this->printResult($result);
        if ($result['pendingReview'] > 0 || $result['feeIssues'] > 0 || $result['refunded'] > 0) {
            $this->warn('Some received orders are not settlement-ready.');
        }
    }

    private function collectReadiness(int $storeId, int $limit): array
    {
        return (new SettlementReadinessService())->run($storeId, $limit);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'sn', 'payment_status', 'shipment_status', 'shipment_fee', 'shipment_fee_deducted_at', 'logistics_review_status']);
        $this->requireColumns('{{%base_fund_log}}', ['id', 'store_id', 'change', 'remark', 'type']);
    }

    private function createOrder(int $storeId, int $userId, string $prefix, int $paymentStatus, int $shipmentStatus, int $reviewStatus, float $shipmentFee, bool $deducted): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Settlement readiness fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'Settlement';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local settlement readiness fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_settlement@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-settlement-readiness/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = $paymentStatus;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = $paymentStatus === Order::PAYMENT_STATUS_REFUND ? time() : 0;
        $order->shipment_id = 9013;
        $order->shipment_name = 'Settlement Express';
        $order->shipment_fee = $shipmentFee;
        $order->shipment_fee_deducted_at = $deducted ? time() : 0;
        $order->shipment_status = $shipmentStatus;
        $order->logistics_review_status = $reviewStatus;
        $order->logistics_reviewed_at = $reviewStatus === Order::LOGISTICS_REVIEW_PASSED ? time() : 0;
        $order->logistics_reviewed_by = $reviewStatus === Order::LOGISTICS_REVIEW_PASSED ? 1 : 0;
        $order->logistics_review_remark = '';
        $order->shipped_at = time();
        $order->product_amount = 20;
        $order->amount = 20;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = $shipmentStatus;
        if (!$order->save()) {
            throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
    }

    private function createFundLog(int $storeId, Order $order, float $change, float $original, float $balance, string $remark)
    {
        $log = new FundLog();
        $log->store_id = $storeId;
        $log->user_id = 1;
        $log->name = '物流费扣费：订单 #' . $order->id;
        $log->change = $change;
        $log->original = $original;
        $log->balance = $balance;
        $log->remark = $remark;
        $log->type = FundLog::TYPE_CONSUME;
        if (!$log->save()) {
            throw new \RuntimeException(json_encode($log->errors, JSON_UNESCAPED_UNICODE));
        }
    }

    private function printResult(array $result)
    {
        $this->stdout("Scanned: {$result['scanned']}\n");
        $this->stdout("Ready: {$result['ready']}\n");
        $this->stdout("Pending review: {$result['pendingReview']}\n");
        $this->stdout("Fee issues: {$result['feeIssues']}\n");
        $this->stdout("Refunded: {$result['refunded']}\n");
        foreach ($result['rows'] as $row) {
            $this->stdout("ROW order={$row['id']} store={$row['store_id']} reason={$row['reason']} fee=" . number_format((float)$row['shipment_fee'], 2) . ' log=' . number_format((float)$row['logTotal'], 2) . "\n");
        }
    }

    private function assertReason(array $result, int $orderId, string $reason)
    {
        foreach ($result['rows'] as $row) {
            if ((int)$row['id'] === $orderId && $row['reason'] === $reason) {
                $this->ok("Order {$orderId} reason is {$reason}.");
                return;
            }
        }

        $this->fail("Order {$orderId} reason {$reason} was not found.");
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
