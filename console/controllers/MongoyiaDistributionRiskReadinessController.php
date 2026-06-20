<?php

namespace console\controllers;

use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionInviteService;
use common\services\mall\DistributionProfileService;
use common\services\mall\DistributionRiskReadinessService;
use common\services\mall\DistributionWithdrawService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionRiskReadinessController extends Controller
{
    public $fixture = true;
    public $strict = false;
    public $largeWithdrawAmount = 500.00;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['fixture', 'strict', 'largeWithdrawAmount']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia distribution risk readiness\n");
        $this->checkFiles();
        $this->checkSchema();
        if ($this->fixture) {
            $this->checkFixture();
        } else {
            $this->checkCurrentData();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles()
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/DistributionRiskReadinessService.php', [
            'class DistributionRiskReadinessService',
            'pending_withdraw_open_risk',
            'large_pending_withdraw',
            'withdraw_amount_mismatch',
            'invalid_invite_reward',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDistributionRiskReadinessController.php', [
            'DistributionRiskReadinessService',
            'largeWithdrawAmount',
            'rollback',
        ]);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_commission}}', ['id', 'distributor_user_id', 'commission_amount', 'commission_status']);
        $this->requireColumns('{{%mall_distribution_withdraw}}', ['id', 'distributor_user_id', 'amount', 'commission_ids', 'withdraw_status']);
        $this->requireColumns('{{%mall_distribution_risk}}', ['id', 'distributor_user_id', 'risk_status']);
        $this->requireColumns('{{%mall_distribution_invite}}', ['id', 'distributor_user_id', 'invited_user_id', 'first_order_id', 'invite_status']);
        $this->requireColumns('{{%mall_distribution_invite_reward}}', ['id', 'invite_id', 'order_id', 'distributor_user_id', 'invited_user_id', 'reward_amount']);
    }

    private function checkFixture()
    {
        $this->section('Rollback fixture');
        $distributorId = $this->firstUserId();
        $invitedUserId = $this->secondUserId($distributorId);
        $otherUserId = $this->thirdUserId($distributorId, $invitedUserId);
        $storeId = $this->firstSellerStoreId();
        if ($distributorId <= 0 || $invitedUserId <= 0 || $otherUserId <= 0 || $storeId <= 0) {
            $this->fail('Need active users and seller store for distribution risk readiness fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $validCommission = $this->createCommission($storeId, $distributorId, $invitedUserId, 30.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $mismatchCommission = $this->createCommission($storeId, $distributorId, $invitedUserId, 20.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $this->createWithdraw($distributorId, [$validCommission], 30.00);
            $this->createWithdraw($distributorId, [$mismatchCommission], 25.00);
            $this->createRisk($distributorId);

            $largeCommission = $this->createCommission($storeId, $otherUserId, $invitedUserId, 800.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $this->createWithdraw($otherUserId, [$largeCommission], 800.00);

            $inviteId = $this->createInvite($distributorId, $invitedUserId, 700001);
            $this->createInviteReward($inviteId, $storeId, 700001, $distributorId, $invitedUserId, 6.00);
            $this->createInviteReward($inviteId, $storeId, 700002, $distributorId, $invitedUserId, 6.00);

            $result = (new DistributionRiskReadinessService())->run((float)$this->largeWithdrawAmount);
            $this->printResult($result);

            $this->assertIssue($result, 'pending_withdraw_open_risk', 'Open distributor risk is reported for pending withdrawal.');
            $this->assertIssue($result, 'large_pending_withdraw', 'Large pending withdrawal is reported.');
            $this->assertIssue($result, 'withdraw_amount_mismatch', 'Withdraw amount mismatch is reported.');
            $this->assertIssue($result, 'invalid_invite_reward', 'Invalid invite reward is reported.');
            $this->assertSummaryCount($result, 'pending_withdraw_open_risk', 2, 'Fixture reports two open-risk pending withdrawals.');
            $this->assertSummaryCount($result, 'large_pending_withdraw', 1, 'Fixture reports one large pending withdrawal.');
            $this->assertSummaryCount($result, 'withdraw_amount_mismatch', 1, 'Fixture reports one amount mismatch.');
            $this->assertSummaryCount($result, 'invalid_invite_reward', 1, 'Fixture reports one invalid invite reward.');
            $this->assertSameInt(5, (int)$result['issueCount'], 'Fixture has exactly five risk issues.');

            $transaction->rollBack();
            $this->ok('Distribution risk readiness fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution risk readiness fixture failed: ' . $e->getMessage());
        }
    }

    private function checkCurrentData()
    {
        $this->section('Current data');
        $result = (new DistributionRiskReadinessService())->run((float)$this->largeWithdrawAmount);
        $this->printResult($result);
        if ((int)$result['issueCount'] > 0) {
            $this->warn('Distribution risk readiness found review issues. Resolve or sign off before payout.');
        }
    }

    private function createCommission(int $storeId, int $distributorId, int $buyerId, float $amount, string $status): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_commission}}', [
            'store_id' => $storeId,
            'order_id' => mt_rand(10000000, 99999999),
            'order_sn' => 'DIST-RISK-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'distributor_user_id' => $distributorId,
            'buyer_user_id' => $buyerId,
            'order_amount' => $amount * 10,
            'commission_rate' => 10.00,
            'commission_amount' => $amount,
            'commission_status' => $status,
            'source' => 'risk_fixture',
            'remark' => 'Created by mongoyia-distribution-risk-readiness/run',
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

    private function createWithdraw(int $distributorId, array $commissionIds, float $amount): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_withdraw}}', [
            'distributor_user_id' => $distributorId,
            'amount' => $amount,
            'commission_ids' => json_encode(array_values(array_map('intval', $commissionIds))),
            'withdraw_status' => DistributionWithdrawService::WITHDRAW_STATUS_PENDING,
            'apply_remark' => 'risk fixture',
            'audit_remark' => '',
            'audited_at' => 0,
            'audited_by' => 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => $distributorId,
            'updated_by' => $distributorId,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function createRisk(int $distributorId): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_risk}}', [
            'distributor_user_id' => $distributorId,
            'risk_type' => 'withdraw_review',
            'risk_level' => 'high',
            'content' => 'Fixture open withdrawal risk',
            'risk_status' => DistributionProfileService::RISK_STATUS_OPEN,
            'handled_at' => 0,
            'handled_by' => 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function createInvite(int $distributorId, int $invitedUserId, int $firstOrderId): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite}}', [
            'distributor_user_id' => $distributorId,
            'invited_user_id' => $invitedUserId,
            'source' => 'risk_fixture',
            'invite_status' => DistributionInviteService::INVITE_STATUS_ACTIVE,
            'first_order_id' => $firstOrderId,
            'first_order_at' => time(),
            'remark' => 'risk fixture',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => $distributorId,
            'updated_by' => $distributorId,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function createInviteReward(int $inviteId, int $storeId, int $orderId, int $distributorId, int $invitedUserId, float $amount): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite_reward}}', [
            'invite_id' => $inviteId,
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_sn' => 'DIST-RISK-INVITE-' . $orderId,
            'distributor_user_id' => $distributorId,
            'invited_user_id' => $invitedUserId,
            'reward_amount' => $amount,
            'reward_status' => DistributionInviteService::REWARD_STATUS_PENDING,
            'source' => 'risk_fixture',
            'remark' => 'Created by mongoyia-distribution-risk-readiness/run',
            'settled_at' => 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function printResult(array $result)
    {
        $this->stdout('Large withdraw threshold: ' . number_format((float)$result['largeWithdrawAmount'], 2) . "\n");
        $this->stdout("Issue count: {$result['issueCount']}\n");
        foreach ($result['summary'] as $type => $count) {
            $this->stdout("SUMMARY type={$type} count={$count}\n");
        }
        foreach ($result['issues'] as $issue) {
            $this->stdout("ISSUE type={$issue['type']} severity={$issue['severity']} distributor={$issue['distributor_user_id']} object={$issue['object_id']} amount=" . number_format((float)$issue['amount'], 2) . " message={$issue['message']}\n");
        }
    }

    private function assertIssue(array $result, string $type, string $message)
    {
        foreach ($result['issues'] as $issue) {
            if ((string)$issue['type'] === $type) {
                $this->ok($message);
                return;
            }
        }
        $this->fail($message . " Missing issue type {$type}.");
    }

    private function assertSummaryCount(array $result, string $type, int $expected, string $message)
    {
        $actual = (int)($result['summary'][$type] ?? 0);
        $this->assertSameInt($expected, $actual, $message);
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

    private function thirdUserId(int $excludeOne, int $excludeTwo): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', 0])
            ->andWhere(['not in', 'id', [$excludeOne, $excludeTwo]])
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
