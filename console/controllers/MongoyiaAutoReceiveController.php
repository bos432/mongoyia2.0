<?php

namespace console\controllers;

use common\models\mall\Order;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaAutoReceiveController extends Controller
{
    public $apply = false;
    public $days = 0;
    public $limit = 100;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['apply', 'days', 'limit', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia auto receive\n");
        $this->days = (int)($this->days ?: (Yii::$app->params['mallAutoReceiveDays'] ?? 7));
        $this->limit = max(1, (int)$this->limit);
        if ($this->days <= 0) {
            $this->fail('Auto receive days must be greater than 0.');
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->checkSchema();
        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runAutoReceive((bool)$this->apply, $this->days, $this->limit);
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'parent_id', 'user_id', 'payment_status', 'shipment_status', 'shipped_at', 'status']);
        $this->requireColumns('{{%mall_order_log}}', ['id', 'order_id', 'user_id', 'status']);
    }

    private function runAutoReceive(bool $apply, int $days, int $limit): int
    {
        $cutoff = time() - $days * 86400;
        $orders = $this->eligibleOrders($cutoff, $limit);
        $this->section($apply ? 'Apply' : 'Dry-run');
        $this->stdout('Days: ' . $days . "\n");
        $this->stdout('Cutoff shipped_at: ' . date('Y-m-d H:i:s', $cutoff) . "\n");
        $this->stdout('Eligible orders: ' . count($orders) . "\n");

        if (!$apply) {
            foreach (array_slice($orders, 0, 20) as $order) {
                $this->stdout("DRY order={$order->id} parent={$order->parent_id} store={$order->store_id} shipped_at=" . date('Y-m-d H:i:s', (int)$order->shipped_at) . "\n");
            }
            if (count($orders) > 20) {
                $this->stdout('DRY ... ' . (count($orders) - 20) . " more\n");
            }
            return count($orders);
        }

        $applied = 0;
        foreach ($orders as $order) {
            try {
                $order->markReceived();
                $applied++;
                $this->ok("Auto received order {$order->id}.");
            } catch (\Throwable $e) {
                $this->fail("Auto receive order {$order->id} failed: " . $e->getMessage());
            }
        }
        $this->stdout("Applied: {$applied}\n");

        return $applied;
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for auto receive fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $oldShippedAt = time() - ($this->days + 1) * 86400;
            $recentShippedAt = time() - max(1, $this->days - 1) * 86400;
            $parent = $this->createShippedOrder($storeId, $userId, 'AUTOREC-PARENT', 0, $oldShippedAt);
            $childOne = $this->createShippedOrder($storeId, $userId, 'AUTOREC-CHILD-1', $parent->id, $oldShippedAt);
            $childTwo = $this->createShippedOrder($storeId, $userId, 'AUTOREC-CHILD-2', $parent->id, $oldShippedAt);
            $recent = $this->createShippedOrder($storeId, $userId, 'AUTOREC-RECENT', 1, $recentShippedAt);
            $refunded = $this->createShippedOrder($storeId, $userId, 'AUTOREC-REFUND', 1, $oldShippedAt, Order::PAYMENT_STATUS_REFUND);

            $dryRunCount = $this->runAutoReceive(false, $this->days, 20);
            if ($dryRunCount < 2) {
                $this->fail("Fixture dry-run expected at least 2 eligible orders, got {$dryRunCount}.");
            } else {
                $this->ok('Fixture dry-run sees eligible shipped child orders.');
            }

            $this->runAutoReceive(true, $this->days, 20);
            $parent->refresh();
            $childOne->refresh();
            $childTwo->refresh();
            $recent->refresh();
            $refunded->refresh();
            $this->assertSameInt(Order::SHIPMENT_STATUS_RECEIVED, (int)$childOne->shipment_status, 'Old shipped child one is received.');
            $this->assertSameInt(Order::SHIPMENT_STATUS_RECEIVED, (int)$childTwo->shipment_status, 'Old shipped child two is received.');
            $this->assertSameInt(Order::SHIPMENT_STATUS_RECEIVED, (int)$parent->shipment_status, 'Parent is received after all children are received.');
            $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, (int)$recent->shipment_status, 'Recent shipped order is not auto received.');
            $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, (int)$refunded->shipment_status, 'Refunded shipped order is not auto received.');

            $secondApply = $this->runAutoReceive(true, $this->days, 20);
            if ($secondApply !== 0) {
                $this->fail("Second apply should be idempotent, got {$secondApply} applied.");
            } else {
                $this->ok('Second apply is idempotent.');
            }

            $transaction->rollBack();
            $this->ok('Auto receive fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Auto receive fixture failed: ' . $e->getMessage());
        }
    }

    private function eligibleOrders(int $cutoff, int $limit): array
    {
        $baseWhere = [
            'payment_status' => [Order::PAYMENT_STATUS_COD, Order::PAYMENT_STATUS_PAID],
            'shipment_status' => Order::SHIPMENT_STATUS_SHIPPING,
        ];

        $childIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where($baseWhere)
            ->andWhere(['>', 'parent_id', 0])
            ->andWhere(['>', 'status', Order::STATUS_DELETED])
            ->andWhere(['>', 'shipped_at', 0])
            ->andWhere(['<=', 'shipped_at', $cutoff])
            ->orderBy(['shipped_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->column(Yii::$app->db);

        $remaining = max(0, $limit - count($childIds));
        $standaloneIds = [];
        if ($remaining > 0) {
            $standaloneIds = (new \yii\db\Query())
                ->select('o.id')
                ->from(['o' => '{{%mall_order}}'])
                ->leftJoin(['c' => '{{%mall_order}}'], 'c.parent_id = o.id')
                ->where([
                    'o.payment_status' => [Order::PAYMENT_STATUS_COD, Order::PAYMENT_STATUS_PAID],
                    'o.shipment_status' => Order::SHIPMENT_STATUS_SHIPPING,
                    'o.parent_id' => 0,
                ])
                ->andWhere(['>', 'o.status', Order::STATUS_DELETED])
                ->andWhere(['>', 'o.shipped_at', 0])
                ->andWhere(['<=', 'o.shipped_at', $cutoff])
                ->andWhere(['c.id' => null])
                ->orderBy(['o.shipped_at' => SORT_ASC, 'o.id' => SORT_ASC])
                ->limit($remaining)
                ->column(Yii::$app->db);
        }

        $ids = array_values(array_unique(array_map('intval', array_merge($childIds, $standaloneIds))));
        if (!$ids) {
            return [];
        }

        return Order::find()
            ->where(['id' => $ids])
            ->orderBy(['shipped_at' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    private function createShippedOrder(int $storeId, int $userId, string $prefix, int $parentId, int $shippedAt, int $paymentStatus = Order::PAYMENT_STATUS_PAID): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = $parentId;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Auto receive fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'AutoReceive';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local auto receive fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_auto_receive@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-auto-receive/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = $paymentStatus;
        $order->paid_at = $paymentStatus === Order::PAYMENT_STATUS_PAID ? $shippedAt - 3600 : 0;
        $order->stock_deducted_at = $paymentStatus === Order::PAYMENT_STATUS_PAID ? $shippedAt - 3600 : 0;
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9007;
        $order->shipment_name = 'Auto Receive Express';
        $order->shipment_fee = 0;
        $order->shipment_fee_deducted_at = 0;
        $order->shipment_status = Order::SHIPMENT_STATUS_SHIPPING;
        $order->shipped_at = $shippedAt;
        $order->product_amount = 1;
        $order->amount = 1;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = $paymentStatus === Order::PAYMENT_STATUS_REFUND ? Order::PAYMENT_STATUS_REFUND : Order::SHIPMENT_STATUS_SHIPPING;

        if (!$order->save()) {
            throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
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
