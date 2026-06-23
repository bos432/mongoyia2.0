<?php

namespace console\controllers;

use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionWithdrawService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionWithdrawTestController extends Controller
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
        $this->stdout("Mongoyia distribution withdraw Phase 4 test\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkPermissions();
        $this->checkWorkflowFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles()
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/DistributionWithdrawService.php', ['class DistributionWithdrawService', 'WITHDRAW_STATUS_PENDING', 'ACTION_APPROVE', 'commission_ids']);
        $this->requireFileContains('frontend/modules/mall/controllers/UserController.php', [
            'MONGOYIA_DISTRIBUTION_FRONTEND_POST_VERB_GUARD_V1',
            'VerbFilter',
            "'distribution-profile' => ['POST']",
            "'distribution-withdraw' => ['POST']",
            'actionDistributionWithdraw',
            'DistributionWithdrawService',
            'distributionWithdrawRows',
        ]);
        $this->requireFileContains('web/resources/mall/default/views/user/distribution.php', [
            'Withdrawal Request',
            'Request Withdrawal',
            'Withdrawal Records',
            'does not trigger real payout',
            'data-mongoyia-distribution-frontend-post-guard="withdraw"',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionWithdrawController.php', [
            'MONGOYIA_DISTRIBUTION_COMMISSION_WITHDRAW_POST_VERB_GUARD_V1',
            'behaviors',
            "'workflow'] = ['post']",
            "post('id', 0)",
            'actionIndex',
            'actionWorkflow',
            'isMallPlatformOperator',
            'mall_distribution_withdraw',
        ]);
        $this->requireFileContains('backend/modules/mall/views/distribution-withdraw/index.php', ['分销提现审核', '提现状态汇总', '提现申请', '不会触发真实打款']);
        $this->requireFileContains('console/migrations/m260618_185000_mongoyia_distribution_withdraw_workflow.php', ['commission_ids', '/mall/distribution-withdraw/index', '/mall/distribution-withdraw/*']);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_commission}}', ['id', 'distributor_user_id', 'commission_amount', 'commission_status']);
        $this->requireColumns('{{%mall_distribution_withdraw}}', ['id', 'distributor_user_id', 'amount', 'commission_ids', 'withdraw_status', 'audited_at', 'audited_by']);
    }

    private function checkPermissions()
    {
        $this->section('Permissions');
        $this->assertPlatformOnlyPermission('/mall/distribution-withdraw/index', 'm260618_185000_mongoyia_distribution_withdraw_workflow');
        $this->assertPlatformOnlyPermission('/mall/distribution-withdraw/*', 'm260618_185000_mongoyia_distribution_withdraw_workflow');
    }

    private function checkWorkflowFixture()
    {
        $this->section('Workflow fixture');
        $storeId = $this->firstSellerStoreId();
        $buyerId = $this->firstUserId();
        $distributorId = $this->secondUserId($buyerId);
        if ($storeId <= 0 || $buyerId <= 0 || $distributorId <= 0) {
            $this->fail('Need active store, buyer, and distributor for distribution withdraw fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $approvedOne = $this->createCommission($storeId, $buyerId, $distributorId, 100.00, 10.00, 10.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $approvedTwo = $this->createCommission($storeId, $buyerId, $distributorId, 80.00, 5.00, 4.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $pending = $this->createCommission($storeId, $buyerId, $distributorId, 70.00, 5.00, 3.50, DistributionCommissionService::COMMISSION_STATUS_PENDING);

            $service = new DistributionWithdrawService();
            $summary = $service->summary($distributorId);
            $this->assertSameInt(2, (int)$summary['availableRows'], 'Withdraw summary sees two approved rows.');
            $this->assertMoney(14.00, (float)$summary['availableAmount'], 'Withdraw summary amount is correct.');

            $dryRun = $service->apply($distributorId, [$approvedOne, $approvedTwo, $pending], false, 'fixture dry-run');
            $this->assertSameInt(2, count($dryRun['eligibleIds']), 'Withdraw dry-run includes approved commissions.');
            $this->assertSameInt(1, count($dryRun['blockedIds']), 'Withdraw dry-run blocks pending commission.');
            $this->assertMoney(14.00, (float)$dryRun['amount'], 'Withdraw dry-run amount is correct.');
            $this->assertSameInt(0, $this->withdrawCount($distributorId), 'Withdraw dry-run does not create request.');

            $apply = $service->apply($distributorId, [$approvedOne, $approvedTwo], true, 'fixture apply');
            $withdrawId = (int)$apply['withdrawId'];
            $this->assertSameInt(1, (int)$apply['created'], 'Withdraw apply creates request.');
            $this->assertSameInt(1, $this->withdrawCount($distributorId), 'Withdraw request is persisted.');
            $this->assertWithdrawStatus($withdrawId, DistributionWithdrawService::WITHDRAW_STATUS_PENDING, 'Withdraw starts pending.');

            $blockedRepeat = $service->apply($distributorId, [$approvedOne], true, 'fixture repeat');
            $this->assertSameInt(0, (int)$blockedRepeat['created'], 'Repeat withdraw is blocked while request is pending.');

            $auditDryRun = $service->audit($withdrawId, DistributionWithdrawService::ACTION_APPROVE, false, 1, 'fixture audit dry-run');
            $this->assertSameInt(1, (int)$auditDryRun['eligible'], 'Withdraw approve dry-run is eligible.');
            $this->assertWithdrawStatus($withdrawId, DistributionWithdrawService::WITHDRAW_STATUS_PENDING, 'Withdraw approve dry-run does not mutate status.');

            $approve = $service->audit($withdrawId, DistributionWithdrawService::ACTION_APPROVE, true, 1, 'fixture approve');
            $this->assertSameInt(1, (int)$approve['updated'], 'Withdraw approve updates request.');
            $this->assertSameInt(2, (int)$approve['commissionUpdated'], 'Withdraw approve marks commissions withdrawn.');
            $this->assertWithdrawStatus($withdrawId, DistributionWithdrawService::WITHDRAW_STATUS_APPROVED, 'Withdraw status is approved.');
            $this->assertCommissionStatus($approvedOne, DistributionCommissionService::COMMISSION_STATUS_WITHDRAWN, 'First commission is withdrawn.');
            $this->assertCommissionStatus($approvedTwo, DistributionCommissionService::COMMISSION_STATUS_WITHDRAWN, 'Second commission is withdrawn.');
            $this->assertCommissionStatus($pending, DistributionCommissionService::COMMISSION_STATUS_PENDING, 'Pending commission remains pending.');

            $repeatAudit = $service->audit($withdrawId, DistributionWithdrawService::ACTION_APPROVE, true, 1, 'fixture repeat audit');
            $this->assertSameInt(0, (int)$repeatAudit['updated'], 'Repeat withdraw approve is blocked.');

            $rejectCommission = $this->createCommission($storeId, $buyerId, $distributorId, 50.00, 10.00, 5.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $rejectApply = $service->apply($distributorId, [$rejectCommission], true, 'fixture reject apply');
            $rejectId = (int)$rejectApply['withdrawId'];
            $reject = $service->audit($rejectId, DistributionWithdrawService::ACTION_REJECT, true, 1, 'fixture reject');
            $this->assertSameInt(1, (int)$reject['updated'], 'Withdraw reject updates request.');
            $this->assertSameInt(0, (int)$reject['commissionUpdated'], 'Withdraw reject does not mark commission withdrawn.');
            $this->assertWithdrawStatus($rejectId, DistributionWithdrawService::WITHDRAW_STATUS_REJECTED, 'Withdraw status is rejected.');
            $this->assertCommissionStatus($rejectCommission, DistributionCommissionService::COMMISSION_STATUS_APPROVED, 'Rejected withdraw leaves commission approved.');

            $transaction->rollBack();
            $this->ok('Distribution withdraw fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution withdraw fixture failed: ' . $e->getMessage());
        }
    }

    private function createCommission(int $storeId, int $buyerId, int $distributorId, float $orderAmount, float $rate, float $commissionAmount, string $status): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_commission}}', [
            'store_id' => $storeId,
            'order_id' => mt_rand(10000000, 99999999),
            'order_sn' => 'DIST-WITHDRAW-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'distributor_user_id' => $distributorId,
            'buyer_user_id' => $buyerId,
            'order_amount' => $orderAmount,
            'commission_rate' => $rate,
            'commission_amount' => $commissionAmount,
            'commission_status' => $status,
            'source' => 'withdraw_fixture',
            'remark' => 'Created by mongoyia-distribution-withdraw-test/run',
            'settled_at' => $status === DistributionCommissionService::COMMISSION_STATUS_APPROVED ? $now : 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function withdrawCount(int $distributorId): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->count('*', Yii::$app->db);
    }

    private function assertWithdrawStatus(int $withdrawId, string $expected, string $message)
    {
        $actual = (string)(new \yii\db\Query())
            ->select('withdraw_status')
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['id' => $withdrawId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertCommissionStatus(int $commissionId, string $expected, string $message)
    {
        $actual = (string)(new \yii\db\Query())
            ->select('commission_status')
            ->from('{{%mall_distribution_commission}}')
            ->where(['id' => $commissionId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
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
