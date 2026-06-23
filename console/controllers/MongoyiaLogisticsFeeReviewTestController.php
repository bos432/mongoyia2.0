<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\services\mall\LogisticsFeeAdjustmentService;
use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaLogisticsFeeReviewTestController extends Controller
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
        $this->stdout("Mongoyia logistics fee review backend test\n");
        $this->checkFiles();
        $this->checkPermissions();
        $this->checkAdjustmentFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles()
    {
        $this->section('Backend files');
        $this->requireFileContains('common/services/mall/LogisticsFeeAdjustmentService.php', ['class LogisticsFeeAdjustmentService', 'shipment_fee_adjustment']);
        $this->requireFileContains('backend/modules/mall/controllers/LogisticsFeeReviewController.php', [
            'MONGOYIA_LOGISTICS_FEE_REVIEW_APPLY_POST_GUARD_V1',
            'actionIndex',
            'actionApply',
            'isMallPlatformOperator',
            "'apply'] = ['post']",
            "post('store_id', 0)",
            "post('limit', 100)",
        ]);
        $this->requireFileContains('backend/modules/mall/views/logistics-fee-review/index.php', [
            '物流费财务复核',
            '执行调账',
            '查看预存金流水',
            'data-mongoyia-logistics-fee-review-post-guard',
            'csrfToken',
        ]);
        $this->requireFileContains('console/migrations/m260618_171000_mongoyia_logistics_fee_review_permission.php', ['/mall/logistics-fee-review/index', '/mall/logistics-fee-review/*']);
    }

    private function checkPermissions()
    {
        $this->section('Permissions');
        foreach (['/mall/logistics-fee-review/index', '/mall/logistics-fee-review/*'] as $path) {
            $permissionId = (int)(new \yii\db\Query())
                ->select('id')
                ->from('{{%base_permission}}')
                ->where(['path' => $path, 'status' => 1])
                ->scalar(Yii::$app->db);
            if ($permissionId <= 0) {
                $this->fail("Missing active permission {$path}. Run migration m260618_171000_mongoyia_logistics_fee_review_permission.");
                continue;
            }
            $this->ok("Permission exists: {$path}");

            $sellerGrant = (new \yii\db\Query())
                ->from('{{%base_role_permission}}')
                ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
                ->exists(Yii::$app->db);
            if ($sellerGrant) {
                $this->fail("Seller role 50 must not have {$path} permission.");
                continue;
            }
            $this->ok("Seller role is not granted {$path}.");
        }
    }

    private function checkAdjustmentFixture()
    {
        $this->section('Adjustment service fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for logistics fee review fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $store = Store::findOne($storeId);
            $store->fund = 60;
            $store->consume_amount = 0;
            $store->consume_count = 0;
            if (!$store->save()) {
                throw new \RuntimeException(json_encode($store->errors, JSON_UNESCAPED_UNICODE));
            }

            $missingLog = $this->createOrder($storeId, $userId, 'LOGREV-MISSING-LOG', 9.00, true);
            $underDeducted = $this->createOrder($storeId, $userId, 'LOGREV-UNDER', 11.00, true);
            $this->createFundLog($storeId, $underDeducted, -6.00, 60, 54, 'shipment_fee_deduction order_sn=' . $underDeducted->sn);

            $service = new LogisticsFeeAdjustmentService();
            $dryRun = $service->run($storeId, 50, false);
            $this->assertSameInt(2, (int)$dryRun['adjustable'], 'Backend dry-run sees two adjustable logistics fee issues.');
            $this->assertSameInt(0, (int)$dryRun['applied'], 'Backend dry-run does not apply adjustments.');
            $this->assertMoney(60, $this->storeFund($storeId), 'Backend dry-run leaves merchant balance unchanged.');

            $apply = $service->run($storeId, 50, true);
            $this->assertSameInt(2, (int)$apply['applied'], 'Backend apply writes two logistics fee adjustments.');
            $this->assertSameInt(1, $this->logCountByRemark($storeId, 'shipment_fee_missing_log_repair order_sn=' . $missingLog->sn), 'Backend missing-log repair writes one audit row.');
            $this->assertSameInt(1, $this->logCountByRemark($storeId, 'shipment_fee_adjustment order_sn=' . $underDeducted->sn), 'Backend under-deduction adjustment writes one audit row.');
            $this->assertMoney(55, $this->storeFund($storeId), 'Backend under-deduction apply deducts only the difference.');

            $transaction->rollBack();
            $this->ok('Logistics fee review fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Logistics fee review fixture failed: ' . $e->getMessage());
        }
    }

    private function createOrder(int $storeId, int $userId, string $prefix, float $shipmentFee, bool $deducted): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Logistics fee review fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'FeeReview';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local logistics fee review fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_fee_review@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-logistics-fee-review-test/run';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9012;
        $order->shipment_name = 'Review Express';
        $order->shipment_fee = $shipmentFee;
        $order->shipment_fee_deducted_at = $deducted ? time() : 0;
        $order->shipment_status = Order::SHIPMENT_STATUS_SHIPPING;
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
