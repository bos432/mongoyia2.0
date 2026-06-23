<?php

namespace console\controllers;

use common\models\mall\Order;
use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaLogisticsStatusBatchController extends Controller
{
    public $ids = '';
    public $targetStatus = 0;
    public $apply = false;
    public $storeId = 0;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['ids', 'targetStatus', 'apply', 'storeId', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia logistics status batch\n");
        $this->checkSchema();
        $this->checkBackendEntrances();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runBatch();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function runBatch()
    {
        $ids = $this->parseIds($this->ids);
        $targetStatus = (int)$this->targetStatus;
        if (!$ids) {
            $this->fail('ids is required. Example: --ids=101,102');
            return;
        }
        if (!$this->isSupportedTargetStatus($targetStatus)) {
            $this->fail('targetStatus must be one of preparing/shipping/received status values.');
            return;
        }

        $result = Order::batchSetLogisticsStatus($ids, $targetStatus, (bool)$this->apply, $this->storeId > 0 ? (int)$this->storeId : null);
        $this->printResult($result);
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for logistics status batch fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $otherStoreId = $this->secondSellerStoreId($storeId);
            if ($otherStoreId <= 0) {
                $otherStoreId = $this->createTempStore($userId);
            }

            $parent = $this->createOrder($storeId, $userId, 'LOGBATCH-PARENT', 0, Order::SHIPMENT_STATUS_UNSHIPPED);
            $childOne = $this->createOrder($storeId, $userId, 'LOGBATCH-C1', $parent->id, Order::SHIPMENT_STATUS_UNSHIPPED);
            $childTwo = $this->createOrder($storeId, $userId, 'LOGBATCH-C2', $parent->id, Order::SHIPMENT_STATUS_UNSHIPPED);
            $alreadyShipped = $this->createOrder($storeId, $userId, 'LOGBATCH-SHIPPED', 1, Order::SHIPMENT_STATUS_SHIPPING);
            $refunded = $this->createOrder($storeId, $userId, 'LOGBATCH-REFUND', 1, Order::SHIPMENT_STATUS_UNSHIPPED, Order::PAYMENT_STATUS_REFUND);
            $outOfScope = $this->createOrder($otherStoreId, $userId, 'LOGBATCH-OTHER', 1, Order::SHIPMENT_STATUS_UNSHIPPED);

            $ids = [$childOne->id, $childTwo->id, $alreadyShipped->id, $refunded->id, $outOfScope->id];
            $dryRun = Order::batchSetLogisticsStatus($ids, Order::SHIPMENT_STATUS_PREPARING, false, $storeId);
            $this->printResult($dryRun);
            $this->assertSameInt(2, (int)$dryRun['eligible'], 'Dry-run sees only two in-scope unshipped child orders.');
            $childOne->refresh();
            $this->assertSameInt(Order::SHIPMENT_STATUS_UNSHIPPED, (int)$childOne->shipment_status, 'Dry-run does not change order status.');

            $prepare = Order::batchSetLogisticsStatus($ids, Order::SHIPMENT_STATUS_PREPARING, true, $storeId);
            $this->printResult($prepare);
            $this->assertSameInt(2, (int)$prepare['updated'], 'Apply updates two orders to preparing.');
            $childOne->refresh();
            $childTwo->refresh();
            $this->assertSameInt(Order::SHIPMENT_STATUS_PREPARING, (int)$childOne->shipment_status, 'Child one is preparing.');
            $this->assertSameInt(Order::SHIPMENT_STATUS_PREPARING, (int)$childTwo->shipment_status, 'Child two is preparing.');

            $shipping = Order::batchSetLogisticsStatus([$childOne->id, $childTwo->id], Order::SHIPMENT_STATUS_SHIPPING, true, $storeId);
            $this->printResult($shipping);
            $this->assertSameInt(2, (int)$shipping['updated'], 'Apply updates preparing orders to shipping.');
            $childOne->refresh();
            $childTwo->refresh();
            $parent->refresh();
            $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, (int)$childOne->shipment_status, 'Child one is shipping.');
            $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, (int)$childTwo->shipment_status, 'Child two is shipping.');
            $this->assertSameInt(Order::SHIPMENT_STATUS_SHIPPING, (int)$parent->shipment_status, 'Parent becomes shipping after all children ship.');

            $receive = Order::batchSetLogisticsStatus([$childOne->id, $childTwo->id], Order::SHIPMENT_STATUS_RECEIVED, true, $storeId);
            $this->printResult($receive);
            $this->assertSameInt(2, (int)$receive['updated'], 'Apply updates shipping orders to received.');
            $parent->refresh();
            $this->assertSameInt(Order::SHIPMENT_STATUS_RECEIVED, (int)$parent->shipment_status, 'Parent becomes received after all children receive.');

            $secondReceive = Order::batchSetLogisticsStatus([$childOne->id, $childTwo->id], Order::SHIPMENT_STATUS_RECEIVED, true, $storeId);
            $this->assertSameInt(0, (int)$secondReceive['updated'], 'Second receive apply is idempotent.');

            $outOfScope->refresh();
            $refunded->refresh();
            $this->assertSameInt(Order::SHIPMENT_STATUS_UNSHIPPED, (int)$outOfScope->shipment_status, 'Other-store order is not changed by scoped batch.');
            $this->assertSameInt(Order::SHIPMENT_STATUS_UNSHIPPED, (int)$refunded->shipment_status, 'Refunded order is skipped.');

            $transaction->rollBack();
            $this->ok('Logistics status batch fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Logistics status batch fixture failed: ' . $e->getMessage());
        }
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'parent_id', 'payment_status', 'shipment_status', 'shipped_at', 'status']);
        $this->requireColumns('{{%mall_order_log}}', ['id', 'order_id', 'status', 'user_id']);
    }

    private function checkBackendEntrances()
    {
        $this->section('Backend entrances');
        $this->requireFileContains('@app/../common/models/mall/Order.php', ['setLogisticsStatus', 'batchSetLogisticsStatus']);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OrderController.php', [
            'MONGOYIA_ORDER_LOGISTICS_WORKFLOW_POST_GUARD_V1',
            "'logistics-status-batch'] = ['post']",
            "post('ids', '')",
            "post('target_status', 0)",
            'actionLogisticsStatusBatch',
            'batchSetLogisticsStatus',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/order/index.php', [
            'data-mongoyia-order-logistics-post-guard',
            'csrfToken',
            'logistics-status-batch',
            'Prepare',
            'Receive',
        ]);
    }

    private function createOrder(int $storeId, int $userId, string $prefix, int $parentId, int $shipmentStatus, int $paymentStatus = Order::PAYMENT_STATUS_PAID): Order
    {
        $now = time();
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = $parentId;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Logistics status batch fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'LogBatch';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local logistics status batch fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_logistics_batch@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-logistics-status-batch/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = $paymentStatus;
        $order->paid_at = $paymentStatus === Order::PAYMENT_STATUS_PAID ? $now - 3600 : 0;
        $order->stock_deducted_at = $paymentStatus === Order::PAYMENT_STATUS_PAID ? $now - 3600 : 0;
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9008;
        $order->shipment_name = 'Logistics Batch Express';
        $order->shipment_fee = 0;
        $order->shipment_fee_deducted_at = 0;
        $order->shipment_status = $shipmentStatus;
        $order->shipped_at = $shipmentStatus >= Order::SHIPMENT_STATUS_SHIPPING ? $now - 1800 : 0;
        $order->product_amount = 1;
        $order->amount = 1;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = $paymentStatus === Order::PAYMENT_STATUS_REFUND ? Order::PAYMENT_STATUS_REFUND : $shipmentStatus;

        if (!$order->save()) {
            throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
    }

    private function createTempStore(int $userId): int
    {
        $store = new Store();
        $store->name = 'Logistics Batch Fixture Store';
        $store->user_id = $userId;
        $store->fund = 0;
        $store->status = Store::STATUS_ACTIVE;
        if (!$store->save()) {
            throw new \RuntimeException(json_encode($store->errors, JSON_UNESCAPED_UNICODE));
        }

        return (int)$store->id;
    }

    private function parseIds(string $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', $ids, -1, PREG_SPLIT_NO_EMPTY)))));
    }

    private function printResult(array $result)
    {
        $label = Order::getShipmentStatusLabels((int)$result['targetStatus']);
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Target: {$label} ({$result['targetStatus']})\n");
        $this->stdout("Scanned: {$result['scanned']}; eligible: {$result['eligible']}; updated: {$result['updated']}\n");
        foreach ($result['dryRunIds'] as $id) {
            $this->stdout("DRY order={$id}\n");
        }
        foreach ($result['updatedIds'] as $id) {
            $this->stdout("APPLY order={$id}\n");
        }
        foreach ($result['skipped'] as $skip) {
            $this->stdout("SKIP order={$skip['id']} reason={$skip['reason']}\n");
        }
    }

    private function isSupportedTargetStatus(int $targetStatus): bool
    {
        return in_array($targetStatus, [Order::SHIPMENT_STATUS_PREPARING, Order::SHIPMENT_STATUS_SHIPPING, Order::SHIPMENT_STATUS_RECEIVED], true);
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

    private function secondSellerStoreId(int $excludeStoreId): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'id', 0])
            ->andWhere(['>', 'status', 0])
            ->andWhere(['not in', 'id', [5, $excludeStoreId]])
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
