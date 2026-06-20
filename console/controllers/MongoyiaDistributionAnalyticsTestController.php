<?php

namespace console\controllers;

use common\services\mall\DistributionAnalyticsService;
use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionInviteService;
use common\services\mall\DistributionProfileService;
use common\services\mall\DistributionWithdrawService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionAnalyticsTestController extends Controller
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
        $this->stdout("Mongoyia distribution analytics Phase 4 test\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles()
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/DistributionAnalyticsService.php', ['class DistributionAnalyticsService', 'distributorRows', 'invite_count', 'open_risk_count']);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionDistributorController.php', ['DistributionAnalyticsService', 'analyticsRows']);
        $this->requireFileContains('backend/modules/mall/views/distribution-distributor/index.php', ['分销员数据分析', '邀请/首单', '开放风险']);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_commission}}', ['id', 'distributor_user_id', 'commission_amount', 'commission_status']);
        $this->requireColumns('{{%mall_distribution_withdraw}}', ['id', 'distributor_user_id', 'amount', 'withdraw_status']);
        $this->requireColumns('{{%mall_distribution_invite}}', ['id', 'distributor_user_id', 'invited_user_id', 'first_order_id']);
        $this->requireColumns('{{%mall_distribution_invite_reward}}', ['id', 'distributor_user_id', 'reward_amount', 'reward_status']);
        $this->requireColumns('{{%mall_distribution_risk}}', ['id', 'distributor_user_id', 'risk_status']);
    }

    private function checkFixture()
    {
        $this->section('Rollback fixture');
        $storeId = $this->firstSellerStoreId();
        $distributorId = $this->firstUserId();
        $buyerOne = $this->secondUserId($distributorId);
        $buyerTwo = $this->thirdUserId($distributorId, $buyerOne);
        if ($storeId <= 0 || $distributorId <= 0 || $buyerOne <= 0 || $buyerTwo <= 0) {
            $this->fail('Need active store and users for distribution analytics fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $this->createCommission($storeId, $distributorId, $buyerOne, 100.00, 10.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $this->createCommission($storeId, $distributorId, $buyerTwo, 80.00, 4.00, DistributionCommissionService::COMMISSION_STATUS_PENDING);
            $this->createWithdraw($distributorId, 10.00, DistributionWithdrawService::WITHDRAW_STATUS_PENDING);
            $this->createWithdraw($distributorId, 3.00, DistributionWithdrawService::WITHDRAW_STATUS_APPROVED);
            $this->createInvite($distributorId, $buyerOne, 910001);
            $this->createInvite($distributorId, $buyerTwo, 0);
            $this->createInviteReward($storeId, $distributorId, $buyerOne, 910001, 5.00);
            $this->createRisk($distributorId, DistributionProfileService::RISK_STATUS_OPEN);
            $this->createRisk($distributorId, DistributionProfileService::RISK_STATUS_CLOSED);

            $row = (new DistributionAnalyticsService())->row($distributorId);
            $this->printRow($row);
            $this->assertSameInt(2, (int)$row['invite_count'], 'Analytics invite count is correct.');
            $this->assertSameInt(1, (int)$row['first_order_count'], 'Analytics first-order count is correct.');
            $this->assertSameInt(2, (int)$row['commission_rows'], 'Analytics commission row count is correct.');
            $this->assertMoney(14.00, (float)$row['commission_amount'], 'Analytics commission amount is correct.');
            $this->assertMoney(10.00, (float)$row['approved_commission_amount'], 'Analytics approved commission amount is correct.');
            $this->assertSameInt(2, (int)$row['withdraw_rows'], 'Analytics withdraw row count is correct.');
            $this->assertMoney(13.00, (float)$row['withdraw_amount'], 'Analytics withdraw amount is correct.');
            $this->assertMoney(10.00, (float)$row['pending_withdraw_amount'], 'Analytics pending withdraw amount is correct.');
            $this->assertSameInt(1, (int)$row['invite_reward_rows'], 'Analytics invite reward row count is correct.');
            $this->assertMoney(5.00, (float)$row['invite_reward_amount'], 'Analytics invite reward amount is correct.');
            $this->assertSameInt(1, (int)$row['open_risk_count'], 'Analytics open risk count is correct.');

            $rows = (new DistributionAnalyticsService())->distributorRows(10);
            $this->assertContainsDistributor($rows, $distributorId, 'Analytics distributor list includes fixture distributor.');

            $transaction->rollBack();
            $this->ok('Distribution analytics fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution analytics fixture failed: ' . $e->getMessage());
        }
    }

    private function createCommission(int $storeId, int $distributorId, int $buyerId, float $orderAmount, float $amount, string $status): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_commission}}', [
            'store_id' => $storeId,
            'order_id' => mt_rand(10000000, 99999999),
            'order_sn' => 'DIST-ANALYTICS-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'distributor_user_id' => $distributorId,
            'buyer_user_id' => $buyerId,
            'order_amount' => $orderAmount,
            'commission_rate' => 10.00,
            'commission_amount' => $amount,
            'commission_status' => $status,
            'source' => 'analytics_fixture',
            'remark' => 'Created by mongoyia-distribution-analytics-test/run',
            'settled_at' => $status === DistributionCommissionService::COMMISSION_STATUS_APPROVED ? time() : 0,
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

    private function createWithdraw(int $distributorId, float $amount, string $status): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_withdraw}}', [
            'distributor_user_id' => $distributorId,
            'amount' => $amount,
            'commission_ids' => '[]',
            'withdraw_status' => $status,
            'apply_remark' => 'analytics fixture',
            'audit_remark' => '',
            'audited_at' => $status === DistributionWithdrawService::WITHDRAW_STATUS_APPROVED ? time() : 0,
            'audited_by' => $status === DistributionWithdrawService::WITHDRAW_STATUS_APPROVED ? 1 : 0,
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

    private function createInvite(int $distributorId, int $invitedUserId, int $firstOrderId): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite}}', [
            'distributor_user_id' => $distributorId,
            'invited_user_id' => $invitedUserId,
            'source' => 'analytics_fixture',
            'invite_status' => DistributionInviteService::INVITE_STATUS_ACTIVE,
            'first_order_id' => $firstOrderId,
            'first_order_at' => $firstOrderId > 0 ? time() : 0,
            'remark' => 'analytics fixture',
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

    private function createInviteReward(int $storeId, int $distributorId, int $invitedUserId, int $orderId, float $amount): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite_reward}}', [
            'invite_id' => mt_rand(100000, 999999),
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_sn' => 'DIST-ANALYTICS-INVITE-' . $orderId,
            'distributor_user_id' => $distributorId,
            'invited_user_id' => $invitedUserId,
            'reward_amount' => $amount,
            'reward_status' => DistributionInviteService::REWARD_STATUS_PENDING,
            'source' => 'analytics_fixture',
            'remark' => 'Created by mongoyia-distribution-analytics-test/run',
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

    private function createRisk(int $distributorId, string $status): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_risk}}', [
            'distributor_user_id' => $distributorId,
            'risk_type' => 'analytics_fixture',
            'risk_level' => 'medium',
            'content' => 'Fixture analytics risk',
            'risk_status' => $status,
            'handled_at' => $status === DistributionProfileService::RISK_STATUS_CLOSED ? time() : 0,
            'handled_by' => $status === DistributionProfileService::RISK_STATUS_CLOSED ? 1 : 0,
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

    private function printRow(array $row)
    {
        $this->stdout("ANALYTICS distributor={$row['distributor_user_id']} invites={$row['invite_count']} firstOrders={$row['first_order_count']} commissions={$row['commission_rows']} commissionAmount=" . number_format((float)$row['commission_amount'], 2) . " withdrawAmount=" . number_format((float)$row['withdraw_amount'], 2) . " inviteRewardAmount=" . number_format((float)$row['invite_reward_amount'], 2) . " openRisks={$row['open_risk_count']}\n");
    }

    private function assertContainsDistributor(array $rows, int $distributorId, string $message)
    {
        foreach ($rows as $row) {
            if ((int)$row['distributor_user_id'] === $distributorId) {
                $this->ok($message);
                return;
            }
        }
        $this->fail($message . " Missing distributor {$distributorId}.");
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
        return (int)(new \yii\db\Query())->select('id')->from('{{%user}}')->where(['>', 'status', 0])->orderBy(['id' => SORT_ASC])->scalar(Yii::$app->db);
    }

    private function secondUserId(int $excludeUserId): int
    {
        return (int)(new \yii\db\Query())->select('id')->from('{{%user}}')->where(['>', 'status', 0])->andWhere(['<>', 'id', $excludeUserId])->orderBy(['id' => SORT_ASC])->scalar(Yii::$app->db);
    }

    private function thirdUserId(int $excludeOne, int $excludeTwo): int
    {
        return (int)(new \yii\db\Query())->select('id')->from('{{%user}}')->where(['>', 'status', 0])->andWhere(['not in', 'id', [$excludeOne, $excludeTwo]])->orderBy(['id' => SORT_ASC])->scalar(Yii::$app->db);
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
