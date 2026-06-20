<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\services\mall\SettlementPayoutPlanService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementPayoutPlanBackendTestController extends Controller
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
        $this->stdout("Mongoyia settlement payout plan backend test\n");
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
        $this->requireFileContains('common/services/mall/SettlementPayoutPlanService.php', ['class SettlementPayoutPlanService', 'netPayoutAmount', 'blockedRows']);
        $this->requireFileContains('backend/modules/mall/controllers/SettlementPayoutPlanController.php', ['actionIndex', 'SettlementPayoutPlanService', 'isMallPlatformOperator']);
        $this->requireFileContains('backend/modules/mall/views/settlement-payout-plan/index.php', ['结算打款计划', '计划打款', '只读计划入口']);
        $this->requireFileContains('console/migrations/m260618_173000_mongoyia_settlement_payout_plan_permission.php', ['/mall/settlement-payout-plan/index', '结算打款计划']);
    }

    private function checkPermissions()
    {
        $this->section('Permissions');
        $path = '/mall/settlement-payout-plan/index';
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => $path, 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission ' . $path . '. Run migration m260618_173000_mongoyia_settlement_payout_plan_permission.');
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
        $this->section('Payout plan service fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for settlement payout plan backend fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $readyOne = $this->createOrder($storeId, $userId, 'PAYOUTUI-READY-ONE', 31.00, 7.00, Order::LOGISTICS_REVIEW_PASSED);
            $this->createFundLog($storeId, $readyOne, -7.00, 'shipment_fee_deduction order_sn=' . $readyOne->sn);

            $readyTwo = $this->createOrder($storeId, $userId, 'PAYOUTUI-READY-TWO', 19.00, 0.00, Order::LOGISTICS_REVIEW_PASSED);

            $blocked = $this->createOrder($storeId, $userId, 'PAYOUTUI-BLOCKED', 17.00, 2.00, Order::LOGISTICS_REVIEW_PENDING);
            $this->createFundLog($storeId, $blocked, -2.00, 'shipment_fee_deduction order_sn=' . $blocked->sn);

            $result = (new SettlementPayoutPlanService())->run($storeId, 3);
            $this->assertSameInt(3, (int)$result['scanned'], 'Backend payout service scans three received orders.');
            $this->assertSameInt(2, (int)$result['readyOrders'], 'Backend payout service has two ready orders.');
            $this->assertSameInt(1, (int)$result['blockedOrders'], 'Backend payout service has one blocked order.');
            $this->assertMoney(50, (float)$result['readyAmount'], 'Backend payout service sums ready amount.');
            $this->assertMoney(7, (float)$result['shipmentFeeDeducted'], 'Backend payout service sums shipment-fee deductions.');
            $this->assertSameInt(1, count($result['stores']), 'Backend payout service creates one store plan row.');
            $this->assertBlockedReason($result, $blocked->id, 'logistics review pending');

            $transaction->rollBack();
            $this->ok('Settlement payout plan backend fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement payout plan backend fixture failed: ' . $e->getMessage());
        }
    }

    private function createOrder(int $storeId, int $userId, string $prefix, float $amount, float $shipmentFee, int $reviewStatus): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Settlement payout plan backend fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'PayoutUI';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local settlement payout plan backend fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_payout_plan_backend@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-settlement-payout-plan-backend-test/run';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9016;
        $order->shipment_name = 'Payout UI Express';
        $order->shipment_fee = $shipmentFee;
        $order->shipment_fee_deducted_at = $shipmentFee > 0 ? time() : 0;
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

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
