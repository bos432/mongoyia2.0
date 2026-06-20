<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementDraftWorkflowService;
use common\services\mall\SettlementPayoutEvidenceService;
use common\services\mall\SettlementCloseService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementDraftBackendTestController extends Controller
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
        $this->stdout("Mongoyia settlement draft backend test\n");
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
        $this->requireFileContains('common/services/mall/SettlementDraftService.php', ['class SettlementDraftService', 'activeDraftOrderExists', 'mall_settlement_draft_order']);
        $this->requireFileContains('common/services/mall/SettlementDraftWorkflowService.php', ['class SettlementDraftWorkflowService', 'ACTION_APPROVE', 'transitionBlockReason']);
        $this->requireFileContains('common/services/mall/SettlementPayoutEvidenceService.php', ['class SettlementPayoutEvidenceService', 'activeEvidenceExists', 'evidenceRows']);
        $this->requireFileContains('common/services/mall/SettlementCloseService.php', ['class SettlementCloseService', 'payout evidence is required', 'DRAFT_STATUS_CLOSED']);
        $this->requireFileContains('backend/modules/mall/controllers/SettlementDraftController.php', ['actionIndex', 'actionWorkflow', 'actionPayoutEvidence', 'actionClose', 'SettlementDraftWorkflowService', 'SettlementPayoutEvidenceService', 'SettlementCloseService', 'isMallPlatformOperator', 'draftOrderRows']);
        $this->requireFileContains('backend/modules/mall/views/settlement-draft/index.php', ['结算草案', '草案订单明细', '记录线下打款凭证', 'workflow_action', 'ACTION_APPROVE', '打款凭证', 'payout-evidence', '关闭结算']);
        $this->requireFileContains('console/migrations/m260618_174000_mongoyia_settlement_draft.php', ['mall_settlement_draft', 'mall_settlement_draft_order']);
        $this->requireFileContains('console/migrations/m260618_181000_mongoyia_settlement_payout_evidence.php', ['mall_settlement_payout_evidence', 'transaction_no']);
        $this->requireFileContains('console/migrations/m260618_175000_mongoyia_settlement_draft_permission.php', ['/mall/settlement-draft/index', '结算草案']);
        $this->requireFileContains('console/migrations/m260618_180000_mongoyia_settlement_draft_workflow_permission.php', ['/mall/settlement-draft/*', '结算草案状态操作']);
    }

    private function checkPermissions()
    {
        $this->section('Permissions');
        $path = '/mall/settlement-draft/index';
        $this->assertPlatformOnlyPermission($path, 'm260618_175000_mongoyia_settlement_draft_permission');
        $this->assertPlatformOnlyPermission('/mall/settlement-draft/*', 'm260618_180000_mongoyia_settlement_draft_workflow_permission');
    }

    private function checkServiceFixture()
    {
        $this->section('Settlement draft backend fixture');
        $storeId = $this->firstSellerStoreId();
        $userId = $this->firstUserId();
        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Need an active seller store and user for settlement draft backend fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $readyOne = $this->createOrder($storeId, $userId, 'DRAFTUI-READY-ONE', 22.00, 2.00, Order::LOGISTICS_REVIEW_PASSED);
            $this->createFundLog($storeId, $readyOne, -2.00, 'shipment_fee_deduction order_sn=' . $readyOne->sn);

            $readyTwo = $this->createOrder($storeId, $userId, 'DRAFTUI-READY-TWO', 28.00, 0.00, Order::LOGISTICS_REVIEW_PASSED);

            $result = (new SettlementDraftService())->run($storeId, 2, true);
            $this->assertSameInt(1, (int)$result['draftsCreated'], 'Backend draft fixture creates one draft.');
            $this->assertSameInt(2, (int)$result['ordersInserted'], 'Backend draft fixture inserts two draft order rows.');
            $this->assertMoney(50, (float)$result['orderAmount'], 'Backend draft fixture sums order amount.');
            $this->assertMoney(2, (float)$result['shipmentFeeDeducted'], 'Backend draft fixture sums shipment-fee deductions.');
            $this->assertMoney(50, (float)$result['netAmount'], 'Backend draft fixture net amount remains order amount.');

            $draftId = (int)$result['drafts'][0]['draft_id'];
            $draft = $this->draftRow($draftId);
            if (!$draft) {
                $this->fail('Persisted draft row was not found for backend review.');
            } else {
                $this->ok('Persisted draft row can be loaded for backend review.');
                $this->assertSameInt($storeId, (int)$draft['store_id'], 'Persisted draft store is correct.');
            }

            $orderRows = $this->draftOrderRows($draftId);
            $this->assertSameInt(2, count($orderRows), 'Backend review can load draft order details.');
            $repeat = (new SettlementDraftService())->run($storeId, 2, true);
            $this->assertSameInt(0, (int)$repeat['draftsCreated'], 'Backend draft fixture repeat apply creates no draft.');
            $this->assertSameInt(2, (int)$repeat['duplicateOrders'], 'Backend draft fixture repeat apply reports duplicates.');

            $workflow = new SettlementDraftWorkflowService();
            $submit = $workflow->run([$draftId], SettlementDraftWorkflowService::ACTION_SUBMIT, true, 'backend-test-submit');
            $this->assertSameInt(1, (int)$submit['updated'], 'Backend workflow submit action updates draft.');
            $this->assertDraftStatus($draftId, SettlementDraftService::DRAFT_STATUS_SUBMITTED, 'Backend workflow submit status is stored.');
            $approve = $workflow->run([$draftId], SettlementDraftWorkflowService::ACTION_APPROVE, true, 'backend-test-approve');
            $this->assertSameInt(1, (int)$approve['updated'], 'Backend workflow approve action updates draft.');
            $this->assertDraftStatus($draftId, SettlementDraftService::DRAFT_STATUS_APPROVED, 'Backend workflow approve status is stored.');

            $evidenceService = new SettlementPayoutEvidenceService();
            $evidence = $evidenceService->run($draftId, 50.00, 'BACKEND-EVIDENCE-' . mt_rand(1000, 9999), true, [
                'currency' => 'MNT',
                'channel' => 'offline',
                'evidenceFile' => 'backend-evidence-ticket',
                'remark' => 'backend settlement payout evidence test',
            ]);
            $this->assertSameInt(1, (int)$evidence['created'], 'Backend payout evidence action persists evidence.');
            $this->assertSameInt(1, $this->evidenceCount($draftId), 'Backend payout evidence can be loaded for display.');

            $repeatEvidence = $evidenceService->run($draftId, 50.00, 'BACKEND-EVIDENCE-REPEAT', true);
            $this->assertSameInt(0, (int)$repeatEvidence['created'], 'Backend payout evidence duplicate is blocked.');
            $this->assertSkippedReason($repeatEvidence, 'payout evidence already exists');

            $close = (new SettlementCloseService())->run([$draftId], true, 'backend-test-close');
            $this->assertSameInt(1, (int)$close['closed'], 'Backend settlement close action closes draft.');
            $this->assertDraftStatus($draftId, SettlementDraftService::DRAFT_STATUS_CLOSED, 'Backend settlement close status is stored.');

            $repeatClose = (new SettlementCloseService())->run([$draftId], true, 'backend-test-repeat-close');
            $this->assertSameInt(0, (int)$repeatClose['closed'], 'Backend settlement close repeat is blocked.');
            $this->assertSkippedReason($repeatClose, 'draft already closed');

            $transaction->rollBack();
            $this->ok('Settlement draft backend fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement draft backend fixture failed: ' . $e->getMessage());
        }
    }

    private function draftRow(int $draftId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_settlement_draft}}')
            ->where(['id' => $draftId, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function draftOrderRows(int $draftId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_settlement_draft_order}}')
            ->where(['draft_id' => $draftId, 'status' => 1])
            ->all(Yii::$app->db);
    }

    private function evidenceCount(int $draftId): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%mall_settlement_payout_evidence}}')
            ->where(['draft_id' => $draftId, 'status' => 1])
            ->count('*', Yii::$app->db);
    }

    private function assertSkippedReason(array $result, string $expected)
    {
        $actual = (string)($result['skipped'][0]['reason'] ?? '');
        if ($actual !== $expected) {
            $this->fail("Expected skip reason '{$expected}', got '{$actual}'.");
            return;
        }
        $this->ok("Skip reason is '{$expected}'.");
    }

    private function assertPlatformOnlyPermission(string $path, string $migration)
    {
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => $path, 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission ' . $path . '. Run migration ' . $migration . '.');
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

    private function assertDraftStatus(int $draftId, string $expected, string $message)
    {
        $actual = (string)(new \yii\db\Query())
            ->select('draft_status')
            ->from('{{%mall_settlement_draft}}')
            ->where(['id' => $draftId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function createOrder(int $storeId, int $userId, string $prefix, float $amount, float $shipmentFee, int $reviewStatus): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Settlement draft backend fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'DraftUI';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local settlement draft backend fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_draft_backend@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-settlement-draft-backend-test/run';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9017;
        $order->shipment_name = 'Draft UI Express';
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
