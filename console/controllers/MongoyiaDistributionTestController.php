<?php

namespace console\controllers;

use common\models\mall\Order;
use common\services\mall\DistributionCommissionService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionTestController extends Controller
{
    public $storeId = 0;
    public $limit = 100;
    public $apply = false;
    public $fixture = true;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['storeId', 'limit', 'apply', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia distribution Phase 4 readiness\n");
        $this->limit = max(1, (int)$this->limit);

        $this->checkLegacyEntrances();
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

    private function checkLegacyEntrances()
    {
        $this->section('Legacy distribution entrances');
        $this->requireFileContains('@app/../backend/modules/mall/controllers/FxController.php', ['actionIndex', 'actionGoods', 'actionShowAjax', 'fx_id']);
        $this->requireFileContains('@app/../web/index.php', ['fxid', '$_SESSION']);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/CartController.php', ['fxid', 'fx_id']);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'user_id', 'sn', 'amount', 'payment_status', 'shipment_status', 'fx_id']);
        $this->requireColumns('{{%mall_distribution_rule}}', ['id', 'store_id', 'commission_rate', 'min_order_amount', 'rule_status', 'status']);
        $this->requireColumns('{{%mall_distribution_commission}}', ['id', 'store_id', 'order_id', 'distributor_user_id', 'order_amount', 'commission_rate', 'commission_amount', 'commission_status']);
        $this->requireColumns('{{%mall_distribution_withdraw}}', ['id', 'distributor_user_id', 'amount', 'withdraw_status']);
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $storeId = $this->firstSellerStoreId();
        $buyerId = $this->firstUserId();
        $distributorId = $this->secondUserId($buyerId);
        if ($storeId <= 0 || $buyerId <= 0 || $distributorId <= 0) {
            $this->fail('Need active seller store, buyer user, and distributor user for distribution fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $this->createRule($storeId, 12.50, 10.00);
            $readyOne = $this->createOrder($storeId, $buyerId, $distributorId, 'DIST-READY-ONE', 80.00, Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_RECEIVED);
            $readyTwo = $this->createOrder($storeId, $buyerId, $distributorId, 'DIST-READY-TWO', 40.00, Order::PAYMENT_STATUS_COD, Order::SHIPMENT_STATUS_RECEIVED);
            $notReceived = $this->createOrder($storeId, $buyerId, $distributorId, 'DIST-BLOCKED-SHIP', 30.00, Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_SHIPPING);
            $noRuleStore = $this->firstSellerStoreId($storeId);
            if ($noRuleStore > 0) {
                $noRule = $this->createOrder($noRuleStore, $buyerId, $distributorId, 'DIST-BLOCKED-RULE', 50.00, Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_RECEIVED);
            } else {
                $noRule = null;
            }

            $dryRun = $this->collect(0, 10, false, $distributorId);
            $this->section('Dry-run report');
            $this->printResult($dryRun);
            $this->assertSameInt($noRule ? 4 : 3, (int)$dryRun['scanned'], 'Dry-run scans fixture attributed orders.');
            $this->assertSameInt(2, (int)$dryRun['readyOrders'], 'Dry-run has two commission-ready orders.');
            $this->assertSameInt($noRule ? 2 : 1, (int)$dryRun['blockedOrders'], 'Dry-run reports blocked orders.');
            $this->assertMoney(120.00, (float)$dryRun['orderAmount'], 'Dry-run order amount is summed.');
            $this->assertMoney(15.00, (float)$dryRun['commissionAmount'], 'Dry-run commission amount is calculated.');
            $this->assertBlockedReason($dryRun, $notReceived->id, 'not received');
            if ($noRule) {
                $this->assertBlockedReason($dryRun, $noRule->id, 'missing active distribution rule');
            }

            $apply = $this->collect(0, 10, true, $distributorId);
            $this->section('Apply report');
            $this->printResult($apply);
            $this->assertSameInt(2, (int)$apply['commissionsCreated'], 'Apply creates two commission rows.');
            $this->assertPersistedCommission($readyOne->id, $distributorId, 80.00, 12.50, 10.00);
            $this->assertPersistedCommission($readyTwo->id, $distributorId, 40.00, 12.50, 5.00);

            $repeat = $this->collect(0, 10, true, $distributorId);
            $this->section('Repeat apply report');
            $this->printResult($repeat);
            $this->assertSameInt(0, (int)$repeat['commissionsCreated'], 'Repeat apply creates no duplicate commission rows.');
            $this->assertSameInt(2, (int)$repeat['duplicateOrders'], 'Repeat apply blocks existing commission rows by order id.');

            $transaction->rollBack();
            $this->ok('Distribution fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution fixture failed: ' . $e->getMessage());
        }
    }

    private function runCurrentData(int $storeId, int $limit, bool $apply)
    {
        $this->section($apply ? 'Apply current data' : 'Dry-run current data');
        $result = $this->collect($storeId, $limit, $apply);
        $this->printResult($result);
        if (!$apply && $result['readyOrders'] > 0) {
            $this->warn('Distribution commissions are ready to create; rerun with --apply=1 after reviewing the report.');
        }
        if ($result['blockedOrders'] > 0 || $result['duplicateOrders'] > 0) {
            $this->warn('Some attributed orders are blocked or already have commission rows.');
        }
    }

    private function collect(int $storeId, int $limit, bool $apply, int $distributorId = 0): array
    {
        return (new DistributionCommissionService())->run($storeId, $limit, $apply, $distributorId);
    }

    private function createRule(int $storeId, float $rate, float $minAmount)
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_rule}}', [
            'store_id' => $storeId,
            'name' => 'Fixture store distribution rule',
            'commission_rate' => $rate,
            'min_order_amount' => $minAmount,
            'rule_status' => DistributionCommissionService::RULE_STATUS_ACTIVE,
            'remark' => 'Created by mongoyia-distribution-test/run --fixture=1',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function createOrder(int $storeId, int $buyerId, int $distributorId, string $prefix, float $amount, int $paymentStatus, int $shipmentStatus): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $buyerId;
        $order->address_id = 0;
        $order->name = 'Distribution fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'Distribution';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local distribution fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_distribution@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-distribution-test/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = $paymentStatus;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9020;
        $order->shipment_name = 'Distribution Express';
        $order->shipment_fee = 0;
        $order->shipment_fee_deducted_at = 0;
        $order->shipment_status = $shipmentStatus;
        $order->logistics_review_status = Order::LOGISTICS_REVIEW_PASSED;
        $order->logistics_reviewed_at = time();
        $order->logistics_reviewed_by = 1;
        $order->logistics_review_remark = '';
        $order->shipped_at = time();
        $order->product_amount = $amount;
        $order->amount = $amount;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->fx_id = $distributorId;
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = $shipmentStatus;
        if (!$order->save()) {
            throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
    }

    private function assertPersistedCommission(int $orderId, int $distributorId, float $orderAmount, float $rate, float $commissionAmount)
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['order_id' => $orderId, 'status' => 1])
            ->one(Yii::$app->db);
        if (!$row) {
            $this->fail("Commission row for order {$orderId} was not found.");
            return;
        }

        $this->assertSameInt($distributorId, (int)$row['distributor_user_id'], "Commission row {$orderId} distributor is correct.");
        $this->assertMoney($orderAmount, (float)$row['order_amount'], "Commission row {$orderId} order amount is correct.");
        $this->assertMoney($rate, (float)$row['commission_rate'], "Commission row {$orderId} rate is correct.");
        $this->assertMoney($commissionAmount, (float)$row['commission_amount'], "Commission row {$orderId} amount is correct.");
    }

    private function printResult(array $result)
    {
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Scanned: {$result['scanned']}\n");
        $this->stdout("Ready orders: {$result['readyOrders']}\n");
        $this->stdout("Blocked orders: {$result['blockedOrders']}\n");
        $this->stdout("Duplicate orders: {$result['duplicateOrders']}\n");
        $this->stdout("Commissions created: {$result['commissionsCreated']}\n");
        $this->stdout('Order amount: ' . number_format((float)$result['orderAmount'], 2) . "\n");
        $this->stdout('Commission amount: ' . number_format((float)$result['commissionAmount'], 2) . "\n");
        foreach ($result['stores'] as $row) {
            $this->stdout("STORE store={$row['store_id']} orders={$row['orders']} amount=" . number_format((float)$row['order_amount'], 2) . ' commission=' . number_format((float)$row['commission_amount'], 2) . "\n");
        }
        foreach ($result['distributors'] as $row) {
            $this->stdout("DISTRIBUTOR user={$row['distributor_user_id']} orders={$row['orders']} amount=" . number_format((float)$row['order_amount'], 2) . ' commission=' . number_format((float)$row['commission_amount'], 2) . "\n");
        }
        foreach ($result['commissions'] as $row) {
            $id = $row['commission_id'] === null ? 'preview' : ('#' . $row['commission_id']);
            $this->stdout("COMMISSION {$id} order={$row['order_id']} distributor={$row['distributor_user_id']} amount=" . number_format((float)$row['order_amount'], 2) . ' rate=' . number_format((float)$row['commission_rate'], 2) . ' commission=' . number_format((float)$row['commission_amount'], 2) . "\n");
        }
        foreach ($result['blockedRows'] as $row) {
            $this->stdout("BLOCKED order={$row['order_id']} store={$row['store_id']} distributor={$row['distributor_user_id']} reason={$row['reason']} amount=" . number_format((float)$row['order_amount'], 2) . "\n");
        }
    }

    private function assertBlockedReason(array $result, int $orderId, string $reason)
    {
        foreach ($result['blockedRows'] as $row) {
            if ((int)$row['order_id'] === $orderId && $row['reason'] === $reason) {
                $this->ok("Blocked order {$orderId} reason is {$reason}.");
                return;
            }
        }

        $this->fail("Blocked order {$orderId} reason {$reason} was not found.");
    }

    private function firstSellerStoreId(int $excludeStoreId = 0): int
    {
        $query = (new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'id', 0])
            ->andWhere(['>', 'status', 0])
            ->andWhere(['not in', 'id', [5]])
            ->orderBy(['id' => SORT_ASC]);
        if ($excludeStoreId > 0) {
            $query->andWhere(['<>', 'id', $excludeStoreId]);
        }

        return (int)$query->scalar(Yii::$app->db);
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

    private function secondUserId(int $excludeUserId): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', 0])
            ->andWhere(['<>', 'id', $excludeUserId])
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
