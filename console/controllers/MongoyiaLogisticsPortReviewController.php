<?php

namespace console\controllers;

use common\models\mall\Order;
use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaLogisticsPortReviewController extends Controller
{
    public $ids = '';
    public $reviewStatus = 0;
    public $remark = 'platform_port_review';
    public $apply = false;
    public $storeId = 0;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['ids', 'reviewStatus', 'remark', 'apply', 'storeId', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia logistics port review\n");
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
        $reviewStatus = (int)$this->reviewStatus;
        if (!$ids) {
            $this->fail('ids is required. Example: --ids=101,102');
            return;
        }
        if (!$this->isSupportedReviewStatus($reviewStatus)) {
            $this->fail('reviewStatus must be 1 (passed) or 2 (rejected).');
            return;
        }

        $result = Order::batchReviewLogistics($ids, $reviewStatus, (string)$this->remark, (bool)$this->apply, $this->storeId > 0 ? (int)$this->storeId : null);
        $this->printResult($result);
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for logistics port review fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $otherStoreId = $this->secondSellerStoreId($storeId);
            if ($otherStoreId <= 0) {
                $otherStoreId = $this->createTempStore($userId);
            }

            $shipped = $this->createOrder($storeId, $userId, 'PORTREV-SHIPPED', 1, Order::SHIPMENT_STATUS_SHIPPING);
            $received = $this->createOrder($storeId, $userId, 'PORTREV-RECEIVED', 1, Order::SHIPMENT_STATUS_RECEIVED);
            $unshipped = $this->createOrder($storeId, $userId, 'PORTREV-UNSHIPPED', 1, Order::SHIPMENT_STATUS_UNSHIPPED);
            $refunded = $this->createOrder($storeId, $userId, 'PORTREV-REFUND', 1, Order::SHIPMENT_STATUS_SHIPPING, Order::PAYMENT_STATUS_REFUND);
            $otherStore = $this->createOrder($otherStoreId, $userId, 'PORTREV-OTHER', 1, Order::SHIPMENT_STATUS_SHIPPING);

            $ids = [$shipped->id, $received->id, $unshipped->id, $refunded->id, $otherStore->id];
            $dryRun = Order::batchReviewLogistics($ids, Order::LOGISTICS_REVIEW_PASSED, 'fixture-pass', false, $storeId);
            $this->printResult($dryRun);
            $this->assertSameInt(2, (int)$dryRun['eligible'], 'Dry-run sees shipped/received in-scope orders only.');
            $shipped->refresh();
            $this->assertSameInt(Order::LOGISTICS_REVIEW_PENDING, (int)$shipped->logistics_review_status, 'Dry-run does not change review status.');

            $pass = Order::batchReviewLogistics($ids, Order::LOGISTICS_REVIEW_PASSED, 'fixture-pass', true, $storeId);
            $this->printResult($pass);
            $this->assertSameInt(2, (int)$pass['updated'], 'Apply passes two in-scope shipped/received orders.');
            $shipped->refresh();
            $received->refresh();
            $this->assertSameInt(Order::LOGISTICS_REVIEW_PASSED, (int)$shipped->logistics_review_status, 'Shipped order review passed.');
            $this->assertSameInt(Order::LOGISTICS_REVIEW_PASSED, (int)$received->logistics_review_status, 'Received order review passed.');
            $this->assertGreaterThan(0, (int)$shipped->logistics_reviewed_at, 'Review timestamp is set.');

            $secondPass = Order::batchReviewLogistics([$shipped->id, $received->id], Order::LOGISTICS_REVIEW_PASSED, 'fixture-pass', true, $storeId);
            $this->assertSameInt(0, (int)$secondPass['updated'], 'Repeated same review is idempotent.');

            $reject = Order::batchReviewLogistics([$shipped->id], Order::LOGISTICS_REVIEW_REJECTED, 'fixture-reject', true, $storeId);
            $this->assertSameInt(1, (int)$reject['updated'], 'Review can be changed to rejected.');
            $shipped->refresh();
            $this->assertSameInt(Order::LOGISTICS_REVIEW_REJECTED, (int)$shipped->logistics_review_status, 'Rejected review status is stored.');

            $unshipped->refresh();
            $refunded->refresh();
            $otherStore->refresh();
            $this->assertSameInt(Order::LOGISTICS_REVIEW_PENDING, (int)$unshipped->logistics_review_status, 'Unshipped order is skipped.');
            $this->assertSameInt(Order::LOGISTICS_REVIEW_PENDING, (int)$refunded->logistics_review_status, 'Refunded order is skipped.');
            $this->assertSameInt(Order::LOGISTICS_REVIEW_PENDING, (int)$otherStore->logistics_review_status, 'Other-store order is not changed by scoped review.');

            $transaction->rollBack();
            $this->ok('Logistics port review fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Logistics port review fixture failed: ' . $e->getMessage());
        }
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'parent_id', 'payment_status', 'shipment_status', 'logistics_review_status', 'logistics_reviewed_at', 'logistics_reviewed_by', 'logistics_review_remark']);
    }

    private function checkBackendEntrances()
    {
        $this->section('Backend entrances');
        $this->requireFileContains('@app/../common/models/mall/Order.php', ['reviewLogistics', 'batchReviewLogistics', 'LOGISTICS_REVIEW_PASSED']);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OrderController.php', ['actionLogisticsReviewBatch', 'batchReviewLogistics']);
        $this->requireFileContains('@app/../backend/modules/mall/views/order/index.php', ['logistics-review-batch', 'logistics_review_status', 'Review Passed']);
    }

    private function createOrder(int $storeId, int $userId, string $prefix, int $parentId, int $shipmentStatus, int $paymentStatus = Order::PAYMENT_STATUS_PAID): Order
    {
        $now = time();
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = $parentId;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Logistics port review fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'PortReview';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local logistics port review fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_port_review@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-logistics-port-review/run --fixture=1';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = $paymentStatus;
        $order->paid_at = $paymentStatus === Order::PAYMENT_STATUS_PAID ? $now - 3600 : 0;
        $order->stock_deducted_at = $paymentStatus === Order::PAYMENT_STATUS_PAID ? $now - 3600 : 0;
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9009;
        $order->shipment_name = 'Port Review Express';
        $order->shipment_fee = 0;
        $order->shipment_fee_deducted_at = 0;
        $order->shipment_status = $shipmentStatus;
        $order->logistics_review_status = Order::LOGISTICS_REVIEW_PENDING;
        $order->logistics_reviewed_at = 0;
        $order->logistics_reviewed_by = 0;
        $order->logistics_review_remark = '';
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
        $store->name = 'Port Review Fixture Store';
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
        $label = Order::getLogisticsReviewStatusLabels((int)$result['reviewStatus']);
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Review target: {$label} ({$result['reviewStatus']})\n");
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

    private function isSupportedReviewStatus(int $reviewStatus): bool
    {
        return in_array($reviewStatus, [Order::LOGISTICS_REVIEW_PASSED, Order::LOGISTICS_REVIEW_REJECTED], true);
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

    private function assertGreaterThan(int $threshold, int $actual, string $message)
    {
        if ($actual <= $threshold) {
            $this->fail("{$message} Expected > {$threshold}, got {$actual}.");
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
