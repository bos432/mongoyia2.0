<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\services\mall\LogisticsFeeAdjustmentService;
use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaLogisticsFeeAdjustmentController extends Controller
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
        $this->stdout("Mongoyia logistics fee adjustment\n");
        $this->limit = max(1, (int)$this->limit);

        $this->checkSchema();
        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runAdjustment((int)$this->storeId, $this->limit, (bool)$this->apply);
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
            $this->fail('Need an active seller store and user for logistics fee adjustment fixture.');
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

            $missingLog = $this->createOrder($storeId, $userId, 'LOGADJ-MISSING-LOG', 10.00, true);
            $underDeducted = $this->createOrder($storeId, $userId, 'LOGADJ-UNDER', 12.00, true);
            $this->createFundLog($storeId, $underDeducted, -5.00, 100, 95, 'shipment_fee_deduction order_sn=' . $underDeducted->sn);
            $overDeducted = $this->createOrder($storeId, $userId, 'LOGADJ-OVER', 8.00, true);
            $this->createFundLog($storeId, $overDeducted, -10.00, 95, 85, 'shipment_fee_deduction order_sn=' . $overDeducted->sn);
            $notDeducted = $this->createOrder($storeId, $userId, 'LOGADJ-NOT-DEDUCTED', 4.00, false);

            $dryRun = $this->collectAdjustments($storeId, 50, false);
            $this->printResult($dryRun);
            $this->assertSameInt(4, (int)$dryRun['ordersWithFee'], 'Fixture dry-run scans four orders.');
            $this->assertSameInt(2, (int)$dryRun['adjustable'], 'Fixture dry-run finds two adjustable issues.');
            $this->assertSameInt(0, (int)$dryRun['applied'], 'Fixture dry-run does not apply adjustments.');
            $this->assertMoney(100, $this->storeFund($storeId), 'Dry-run leaves merchant deposit unchanged.');

            $apply = $this->collectAdjustments($storeId, 50, true);
            $this->printResult($apply);
            $this->assertSameInt(2, (int)$apply['applied'], 'Fixture apply writes two adjustments.');
            $this->assertSameInt(1, $this->logCountByRemark($storeId, 'shipment_fee_missing_log_repair order_sn=' . $missingLog->sn), 'Missing-log repair writes one audit row.');
            $this->assertSameInt(1, $this->logCountByRemark($storeId, 'shipment_fee_adjustment order_sn=' . $underDeducted->sn), 'Under-deduction adjustment writes one audit row.');
            $this->assertMoney(93, $this->storeFund($storeId), 'Under-deduction adjustment deducts only the difference.');
            $this->assertSameInt(0, $this->logCountByRemark($storeId, 'shipment_fee_adjustment order_sn=' . $overDeducted->sn), 'Over-deduction is reported but not auto-refunded.');
            $this->assertSameInt(0, $this->logCountByRemark($storeId, 'shipment_fee_adjustment order_sn=' . $notDeducted->sn), 'Undeducted order is reported but not auto-deducted.');

            $transaction->rollBack();
            $this->ok('Logistics fee adjustment fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Logistics fee adjustment fixture failed: ' . $e->getMessage());
        }
    }

    private function runAdjustment(int $storeId, int $limit, bool $apply)
    {
        $this->section($apply ? 'Current data apply' : 'Current data dry-run');
        $result = $this->collectAdjustments($storeId, $limit, $apply);
        $this->printResult($result);
        if ($result['blocked'] > 0 || $result['reported'] > 0) {
            $this->warn("Found {$result['blocked']} blocked and {$result['reported']} report-only logistics fee issue(s).");
        }
    }

    private function collectAdjustments(int $storeId, int $limit, bool $apply): array
    {
        return (new LogisticsFeeAdjustmentService())->run($storeId, $limit, $apply);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'sn', 'shipment_fee', 'shipment_fee_deducted_at', 'status']);
        $this->requireColumns('{{%store}}', ['id', 'fund', 'consume_amount', 'consume_count']);
        $this->requireColumns('{{%base_fund_log}}', ['id', 'store_id', 'name', 'change', 'original', 'balance', 'remark', 'type']);
    }

    private function createOrder(int $storeId, int $userId, string $prefix, float $shipmentFee, bool $deducted): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Logistics fee adjustment fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'FeeAdjust';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local logistics fee adjustment fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_fee_adjustment@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-logistics-fee-adjustment/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9011;
        $order->shipment_name = 'Adjust Express';
        $order->shipment_fee = $shipmentFee;
        $order->shipment_fee_deducted_at = $deducted ? time() : 0;
        $order->shipment_status = Order::SHIPMENT_STATUS_SHIPPING;
        $order->logistics_review_status = Order::LOGISTICS_REVIEW_PENDING;
        $order->logistics_reviewed_at = 0;
        $order->logistics_reviewed_by = 0;
        $order->logistics_review_remark = '';
        $order->shipped_at = time();
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

    private function createFundLog(int $storeId, Order $order, float $change, float $original, float $balance, string $remark)
    {
        $log = new FundLog();
        $log->store_id = $storeId;
        $log->user_id = 1;
        $log->name = '物流费调账：订单 #' . $order->id;
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
        $this->stdout("Orders with fee: {$result['ordersWithFee']}\n");
        $this->stdout("Adjustable: {$result['adjustable']}\n");
        $this->stdout("Applied: {$result['applied']}\n");
        $this->stdout("Blocked: {$result['blocked']}\n");
        $this->stdout("Report-only: {$result['reported']}\n");
        $this->stdout('Planned amount: ' . number_format((float)$result['plannedAmount'], 2) . "\n");
        $this->stdout('Applied amount: ' . number_format((float)$result['appliedAmount'], 2) . "\n");
        foreach ($result['rows'] as $row) {
            $this->stdout("ROW order={$row['id']} store={$row['store_id']} status={$row['status']} reason={$row['reason']} fee=" . number_format((float)$row['fee'], 2) . ' log=' . number_format((float)$row['logTotal'], 2) . ' amount=' . number_format((float)$row['amount'], 2) . "\n");
        }
    }

    private function logCountByRemark(int $storeId, string $remark): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%base_fund_log}}')
            ->where(['store_id' => $storeId, 'remark' => $remark, 'type' => FundLog::TYPE_CONSUME])
            ->count('*', Yii::$app->db);
    }

    private function storeFund(int $storeId): float
    {
        return (float)(new \yii\db\Query())
            ->select('fund')
            ->from('{{%store}}')
            ->where(['id' => $storeId])
            ->scalar(Yii::$app->db);
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
