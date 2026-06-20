<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\services\mall\SettlementReadinessService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementReadinessBackendTestController extends Controller
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
        $this->stdout("Mongoyia settlement readiness backend test\n");
        $this->checkFiles();
        $this->checkPermissions();
        $this->checkServiceFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles()
    {
        $this->section('Backend files');
        $this->requireFileContains('common/services/mall/SettlementReadinessService.php', ['class SettlementReadinessService', 'settlementBlockReason', 'deductionLogTotal']);
        $this->requireFileContains('backend/modules/mall/controllers/SettlementReadinessController.php', ['actionIndex', 'SettlementReadinessService', 'isMallPlatformOperator']);
        $this->requireFileContains('backend/modules/mall/views/settlement-readiness/index.php', ['结算就绪复核', '可结算金额', '只读复核入口']);
        $this->requireFileContains('console/migrations/m260618_172000_mongoyia_settlement_readiness_permission.php', ['/mall/settlement-readiness/index', '结算就绪复核']);
    }

    private function checkPermissions()
    {
        $this->section('Permissions');
        $path = '/mall/settlement-readiness/index';
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => $path, 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission ' . $path . '. Run migration m260618_172000_mongoyia_settlement_readiness_permission.');
            return;
        }
        $this->ok('Permission exists: ' . $path);

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if ($sellerGrant) {
            $this->fail('Seller role 50 must not have ' . $path . ' permission.');
            return;
        }
        $this->ok('Seller role is not granted ' . $path . '.');
    }

    private function checkServiceFixture()
    {
        $this->section('Readiness service fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for settlement readiness backend fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $ready = $this->createOrder($storeId, $userId, 'SETTLEUI-READY', Order::PAYMENT_STATUS_PAID, Order::LOGISTICS_REVIEW_PASSED, 8.00, true);
            $this->createFundLog($storeId, $ready, -8.00, 'shipment_fee_deduction order_sn=' . $ready->sn);

            $pendingReview = $this->createOrder($storeId, $userId, 'SETTLEUI-PENDING', Order::PAYMENT_STATUS_PAID, Order::LOGISTICS_REVIEW_PENDING, 3.00, true);
            $this->createFundLog($storeId, $pendingReview, -3.00, 'shipment_fee_deduction order_sn=' . $pendingReview->sn);

            $feeIssue = $this->createOrder($storeId, $userId, 'SETTLEUI-FEE', Order::PAYMENT_STATUS_PAID, Order::LOGISTICS_REVIEW_PASSED, 5.00, true);

            $result = (new SettlementReadinessService())->run($storeId, 3);
            $this->assertSameInt(3, (int)$result['scanned'], 'Backend service scans three received orders.');
            $this->assertSameInt(1, (int)$result['ready'], 'Backend service has one settlement-ready order.');
            $this->assertSameInt(1, (int)$result['pendingReview'], 'Backend service has one pending-review order.');
            $this->assertSameInt(1, (int)$result['feeIssues'], 'Backend service has one fee issue order.');
            $this->assertReason($result, $ready->id, 'ready');
            $this->assertReason($result, $pendingReview->id, 'logistics review pending');
            $this->assertReason($result, $feeIssue->id, 'logistics fee not reconciled');

            $transaction->rollBack();
            $this->ok('Settlement readiness backend fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement readiness backend fixture failed: ' . $e->getMessage());
        }
    }

    private function createOrder(int $storeId, int $userId, string $prefix, int $paymentStatus, int $reviewStatus, float $shipmentFee, bool $deducted): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Settlement readiness backend fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'SettlementUI';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local settlement readiness backend fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_settlement_backend@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-settlement-readiness-backend-test/run';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = $paymentStatus;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9014;
        $order->shipment_name = 'Settlement UI Express';
        $order->shipment_fee = $shipmentFee;
        $order->shipment_fee_deducted_at = $deducted ? time() : 0;
        $order->shipment_status = Order::SHIPMENT_STATUS_RECEIVED;
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

    private function requireFileContains(string $path, array $needles)
    {
        $fullPath = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
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

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
