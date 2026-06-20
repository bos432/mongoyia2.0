<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaLogisticsFeeDeductionTestController extends Controller
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
        $this->stdout("Mongoyia logistics fee deduction test\n");

        $this->checkSchema();
        $this->checkBackendEntrances();
        $this->checkDeductionFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'shipment_fee', 'shipment_fee_deducted_at', 'shipment_status']);
        $this->requireColumns('{{%store}}', ['id', 'fund', 'consume_amount', 'consume_count']);
        $this->requireColumns('{{%base_fund_log}}', ['id', 'store_id', 'name', 'change', 'original', 'balance', 'remark', 'type']);
    }

    private function checkBackendEntrances()
    {
        $this->section('Backend entrances');
        $this->requireFileContains('@app/../backend/modules/mall/views/order/fh-ajax.php', ['shipment_fee', '预存金']);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OrderController.php', ['markShipped($model->shipment_id, $model->shipment_name, null, $model->shipment_fee)']);
        $this->requireFileContains('@app/../common/models/mall/Order.php', ['deductShipmentFeeIfNeeded', 'shipment_fee_deduction']);
    }

    private function checkDeductionFixture()
    {
        $this->section('Deduction fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for logistics fee deduction fixture.');
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

            $order = $this->createPaidOrder($storeId, $userId, 'LOGFEE-SUCCESS');
            $order->shipment_fee = 12.50;
            if (!$order->save()) {
                throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
            }

            $order->markShipped(8801, 'LOGFEE Express', time(), 12.50);
            $order->refresh();
            $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, (int)$order->shipment_status, 'Order is marked shipped.');
            $this->assertGreaterThan(0, (int)$order->shipment_fee_deducted_at, 'Shipment fee deduction timestamp is set.');
            $this->assertMoney(87.50, $this->storeFund($storeId), 'Shipment fee deducted from merchant deposit.');
            $this->assertSameInt(1, $this->fundLogCount($storeId, $order->id, -12.50, 100, 87.50), 'Shipment fee writes one fund log.');

            $order->markShipped(8801, 'LOGFEE Express', time(), 12.50);
            $this->assertMoney(87.50, $this->storeFund($storeId), 'Duplicate shipment does not deduct again.');
            $this->assertSameInt(1, $this->fundLogCount($storeId, $order->id, -12.50, 100, 87.50), 'Duplicate shipment does not write another fund log.');

            $lowBalanceOrder = $this->createPaidOrder($storeId, $userId, 'LOGFEE-LOWBAL');
            $lowBalanceOrder->shipment_fee = 150;
            if (!$lowBalanceOrder->save()) {
                throw new \RuntimeException(json_encode($lowBalanceOrder->errors, JSON_UNESCAPED_UNICODE));
            }

            try {
                $lowBalanceOrder->markShipped(8802, 'LOGFEE Express', time(), 150);
                $this->fail('Low-balance shipment fee deduction should be rejected.');
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'Merchant deposit balance is insufficient') === false) {
                    throw $e;
                }
                $this->ok('Low-balance shipment fee deduction is rejected.');
            }
            $lowBalanceOrder->refresh();
            $this->assertSameInt(0, (int)$lowBalanceOrder->shipment_fee_deducted_at, 'Rejected deduction leaves timestamp unset.');
            $this->assertMoney(87.50, $this->storeFund($storeId), 'Rejected deduction leaves balance unchanged.');

            $parent = $this->createPaidOrder($storeId, $userId, 'LOGFEE-PARENT', 0);
            $childOne = $this->createPaidOrder($storeId, $userId, 'LOGFEE-PARENT-C1', $parent->id);
            $childTwo = $this->createPaidOrder($storeId, $userId, 'LOGFEE-PARENT-C2', $parent->id);
            $parent->markShipped(8803, 'LOGFEE Parent Express', time(), 6.66);
            $childOne->refresh();
            $childTwo->refresh();
            $this->assertMoney(0, (float)$childOne->shipment_fee, 'Parent shipment fee input is not copied to child one.');
            $this->assertMoney(0, (float)$childTwo->shipment_fee, 'Parent shipment fee input is not copied to child two.');
            $this->assertMoney(87.50, $this->storeFund($storeId), 'Parent shipment fee input does not duplicate-deduct child stores.');

            $transaction->rollBack();
            $this->ok('Logistics fee deduction fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Logistics fee deduction fixture failed: ' . $e->getMessage());
        }
    }

    private function createPaidOrder(int $storeId, int $userId, string $prefix, int $parentId = 1): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = $parentId;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Logistics fee deduction fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'Fixture';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local logistics fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_logistics_fee@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-logistics-fee-deduction-test/run';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 0;
        $order->shipment_name = '';
        $order->shipment_fee = 0;
        $order->shipment_fee_deducted_at = 0;
        $order->shipment_status = Order::SHIPMENT_STATUS_UNSHIPPED;
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

    private function fundLogCount(int $storeId, int $orderId, float $change, float $original, float $balance): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%base_fund_log}}')
            ->where(['store_id' => $storeId, 'type' => FundLog::TYPE_CONSUME])
            ->andWhere(['like', 'name', '订单 #' . $orderId])
            ->andWhere(['change' => number_format($change, 2, '.', '')])
            ->andWhere(['original' => number_format($original, 2, '.', '')])
            ->andWhere(['balance' => number_format($balance, 2, '.', '')])
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

    private function requireFileContains(string $alias, array $needles)
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function assertSameInt(int $expected, int $actual, string $message)
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertGreaterThan(int $threshold, int $actual, string $message)
    {
        if ($actual <= $threshold) {
            $this->fail("{$message} Expected > {$threshold}, got {$actual}.");
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
