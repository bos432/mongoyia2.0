<?php

namespace console\controllers;

use common\services\mall\DistributionInviteRewardWorkflowService;
use common\services\mall\DistributionInviteService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionRewardWorkflowTestController extends Controller
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
        $this->stdout("Mongoyia distribution invite reward workflow Phase 4 test\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles(): void
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/DistributionInviteRewardWorkflowService.php', ['class DistributionInviteRewardWorkflowService', 'ACTION_APPROVE', 'transitionBlockReason', 'invite reward workflow']);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionDistributorController.php', ['DistributionInviteRewardWorkflowService', 'actionInviteRewardWorkflow']);
        $this->requireFileContains('backend/modules/mall/views/distribution-distributor/index.php', ['invite-reward-workflow', 'workflow_action', '邀请奖励']);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_invite_reward}}', ['id', 'store_id', 'order_id', 'distributor_user_id', 'reward_amount', 'reward_status', 'settled_at', 'remark']);
    }

    private function checkFixture(): void
    {
        $this->section('Rollback fixture');
        $storeId = $this->firstSellerStoreId();
        $distributorId = $this->firstUserId();
        $invitedUserId = $this->secondUserId($distributorId);
        if ($storeId <= 0 || $distributorId <= 0 || $invitedUserId <= 0) {
            $this->fail('Need active store and users for invite reward workflow fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $approveId = $this->createReward($storeId, $distributorId, $invitedUserId, 8.00, DistributionInviteService::REWARD_STATUS_PENDING);
            $rejectId = $this->createReward($storeId, $distributorId, $invitedUserId, 4.00, DistributionInviteService::REWARD_STATUS_PENDING);
            $alreadyApprovedId = $this->createReward($storeId, $distributorId, $invitedUserId, 2.00, DistributionInviteService::REWARD_STATUS_APPROVED);
            $workflow = new DistributionInviteRewardWorkflowService();

            $dryRun = $workflow->run([$approveId], DistributionInviteRewardWorkflowService::ACTION_APPROVE, false, 1, 'reward workflow dry-run');
            $this->assertSameInt(1, (int)$dryRun['eligible'], 'Reward approve dry-run finds one eligible row.');
            $this->assertRewardStatus($approveId, DistributionInviteService::REWARD_STATUS_PENDING, 'Reward approve dry-run does not mutate status.');

            $approve = $workflow->run([$approveId], DistributionInviteRewardWorkflowService::ACTION_APPROVE, true, 1, 'reward workflow approve');
            $this->assertSameInt(1, (int)$approve['updated'], 'Reward approve updates one row.');
            $this->assertRewardStatus($approveId, DistributionInviteService::REWARD_STATUS_APPROVED, 'Reward approve status is stored.');
            $this->assertSettledAtSet($approveId, 'Reward approve sets settled_at marker.');
            $this->assertRemarkContains($approveId, 'invite reward workflow approve', 'Reward approve remark is appended.');

            $reject = $workflow->run([$rejectId], DistributionInviteRewardWorkflowService::ACTION_REJECT, true, 1, 'reward workflow reject');
            $this->assertSameInt(1, (int)$reject['updated'], 'Reward reject updates one row.');
            $this->assertRewardStatus($rejectId, DistributionInviteService::REWARD_STATUS_REJECTED, 'Reward reject status is stored.');
            $this->assertSettledAtZero($rejectId, 'Reward reject leaves settled_at empty.');

            $repeatApprove = $workflow->run([$approveId], DistributionInviteRewardWorkflowService::ACTION_APPROVE, true, 1, 'reward workflow repeat');
            $this->assertSameInt(0, (int)$repeatApprove['updated'], 'Repeat reward approve is blocked.');
            $this->assertSkippedReason($repeatApprove, 'invalid transition from approved');

            $alreadyApproved = $workflow->run([$alreadyApprovedId], DistributionInviteRewardWorkflowService::ACTION_REJECT, true, 1, 'reward workflow approved reject');
            $this->assertSameInt(0, (int)$alreadyApproved['updated'], 'Reject of approved reward is blocked.');
            $this->assertSkippedReason($alreadyApproved, 'invalid transition from approved');

            $missing = $workflow->run([999999999], DistributionInviteRewardWorkflowService::ACTION_APPROVE, true, 1, 'reward workflow missing');
            $this->assertSkippedReason($missing, 'invite reward not found');

            $transaction->rollBack();
            $this->ok('Distribution invite reward workflow fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution invite reward workflow fixture failed: ' . $e->getMessage());
        }
    }

    private function createReward(int $storeId, int $distributorId, int $invitedUserId, float $amount, string $status): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite_reward}}', [
            'invite_id' => mt_rand(100000, 999999),
            'store_id' => $storeId,
            'order_id' => mt_rand(300000000, 399999999),
            'order_sn' => 'DIST-REWARD-WF-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'distributor_user_id' => $distributorId,
            'invited_user_id' => $invitedUserId,
            'reward_amount' => $amount,
            'reward_status' => $status,
            'source' => 'reward_workflow_fixture',
            'remark' => 'Created by mongoyia-distribution-reward-workflow-test/run',
            'settled_at' => $status === DistributionInviteService::REWARD_STATUS_APPROVED ? $now : 0,
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

    private function assertRewardStatus(int $rewardId, string $expected, string $message): void
    {
        $actual = (string)(new \yii\db\Query())
            ->select('reward_status')
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['id' => $rewardId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertSettledAtSet(int $rewardId, string $message): void
    {
        $settledAt = (int)(new \yii\db\Query())
            ->select('settled_at')
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['id' => $rewardId])
            ->scalar(Yii::$app->db);
        if ($settledAt <= 0) {
            $this->fail($message . ' Expected settled_at > 0.');
            return;
        }
        $this->ok($message);
    }

    private function assertSettledAtZero(int $rewardId, string $message): void
    {
        $settledAt = (int)(new \yii\db\Query())
            ->select('settled_at')
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['id' => $rewardId])
            ->scalar(Yii::$app->db);
        if ($settledAt !== 0) {
            $this->fail($message . " Expected settled_at 0, got {$settledAt}.");
            return;
        }
        $this->ok($message);
    }

    private function assertRemarkContains(int $rewardId, string $needle, string $message): void
    {
        $remark = (string)(new \yii\db\Query())
            ->select('remark')
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['id' => $rewardId])
            ->scalar(Yii::$app->db);
        if (strpos($remark, $needle) === false) {
            $this->fail($message . " Missing '{$needle}'.");
            return;
        }
        $this->ok($message);
    }

    private function assertSkippedReason(array $result, string $expected): void
    {
        $actual = (string)($result['skipped'][0]['reason'] ?? '');
        if ($actual !== $expected) {
            $this->fail("Expected skip reason '{$expected}', got '{$actual}'.");
            return;
        }
        $this->ok("Skip reason is '{$expected}'.");
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

    private function requireColumns(string $table, array $columns): void
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

    private function requireFileContains(string $path, array $needles): void
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

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
