<?php

namespace console\controllers;

use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionCommissionWorkflowService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionBackendTestController extends Controller
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
        $this->stdout("Mongoyia distribution backend Phase 4 test\n");
        $this->checkFiles();
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
        $this->section('Backend files');
        $this->requireFileContains('common/services/mall/DistributionCommissionWorkflowService.php', ['class DistributionCommissionWorkflowService', 'ACTION_APPROVE', 'transitionBlockReason']);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionCommissionController.php', ['actionIndex', 'actionWorkflow', 'isMallPlatformOperator', 'mall_distribution_commission', 'statusLabels']);
        $this->requireFileContains('backend/modules/mall/views/distribution-commission/index.php', ['分销佣金审核', '分销规则', '佣金状态汇总', '佣金账本', 'workflow_action', '不会触发真实打款']);
        $this->requireFileContains('console/migrations/m260618_183000_mongoyia_distribution_commission.php', ['mall_distribution_rule', 'mall_distribution_commission', 'mall_distribution_withdraw']);
        $this->requireFileContains('console/migrations/m260618_184000_mongoyia_distribution_commission_permission.php', ['/mall/distribution-commission/index', '/mall/distribution-commission/*', '分销佣金审核']);
    }

    private function checkPermissions()
    {
        $this->section('Permissions');
        $this->assertPlatformOnlyPermission('/mall/distribution-commission/index', 'm260618_184000_mongoyia_distribution_commission_permission');
        $this->assertPlatformOnlyPermission('/mall/distribution-commission/*', 'm260618_184000_mongoyia_distribution_commission_permission');
    }

    private function checkWorkflowFixture()
    {
        $this->section('Workflow fixture');
        $storeId = $this->firstSellerStoreId();
        $buyerId = $this->firstUserId();
        $distributorId = $this->secondUserId($buyerId);
        if ($storeId <= 0 || $buyerId <= 0 || $distributorId <= 0) {
            $this->fail('Need active store, buyer, and distributor for distribution backend fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $approvedId = $this->createCommission($storeId, $buyerId, $distributorId, 100.00, 10.00, 10.00, DistributionCommissionService::COMMISSION_STATUS_PENDING);
            $rejectedId = $this->createCommission($storeId, $buyerId, $distributorId, 80.00, 5.00, 4.00, DistributionCommissionService::COMMISSION_STATUS_PENDING);
            $alreadyApprovedId = $this->createCommission($storeId, $buyerId, $distributorId, 50.00, 8.00, 4.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);

            $this->assertSameInt(2, $this->pendingCount($storeId), 'Backend fixture starts with two pending commissions.');
            $workflow = new DistributionCommissionWorkflowService();

            $dryRun = $workflow->run([$approvedId], DistributionCommissionWorkflowService::ACTION_APPROVE, false, 'backend-test-dry-run');
            $this->assertSameInt(1, (int)$dryRun['eligible'], 'Commission approve dry-run finds one eligible row.');
            $this->assertCommissionStatus($approvedId, DistributionCommissionService::COMMISSION_STATUS_PENDING, 'Commission approve dry-run does not mutate status.');

            $approve = $workflow->run([$approvedId], DistributionCommissionWorkflowService::ACTION_APPROVE, true, 'backend-test-approve');
            $this->assertSameInt(1, (int)$approve['updated'], 'Backend approve action updates one commission.');
            $this->assertCommissionStatus($approvedId, DistributionCommissionService::COMMISSION_STATUS_APPROVED, 'Backend approve status is stored.');
            $this->assertSettledAtSet($approvedId, 'Backend approve sets settled_at marker.');

            $reject = $workflow->run([$rejectedId], DistributionCommissionWorkflowService::ACTION_REJECT, true, 'backend-test-reject');
            $this->assertSameInt(1, (int)$reject['updated'], 'Backend reject action updates one commission.');
            $this->assertCommissionStatus($rejectedId, DistributionCommissionService::COMMISSION_STATUS_REJECTED, 'Backend reject status is stored.');

            $repeatApprove = $workflow->run([$approvedId], DistributionCommissionWorkflowService::ACTION_APPROVE, true, 'backend-test-repeat');
            $this->assertSameInt(0, (int)$repeatApprove['updated'], 'Backend repeat approve is blocked.');
            $this->assertSkippedReason($repeatApprove, 'invalid transition from approved');

            $alreadyApproved = $workflow->run([$alreadyApprovedId], DistributionCommissionWorkflowService::ACTION_REJECT, true, 'backend-test-approved-reject');
            $this->assertSameInt(0, (int)$alreadyApproved['updated'], 'Backend reject of approved commission is blocked.');
            $this->assertSkippedReason($alreadyApproved, 'invalid transition from approved');

            $this->assertSameInt(0, $this->pendingCount($storeId), 'Backend fixture has no pending commissions after approve/reject.');
            $this->assertSummaryBucket($storeId, DistributionCommissionService::COMMISSION_STATUS_APPROVED, 2, 'Backend summary counts approved commissions.');
            $this->assertSummaryBucket($storeId, DistributionCommissionService::COMMISSION_STATUS_REJECTED, 1, 'Backend summary counts rejected commissions.');

            $transaction->rollBack();
            $this->ok('Distribution backend fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution backend fixture failed: ' . $e->getMessage());
        }
    }

    private function createCommission(int $storeId, int $buyerId, int $distributorId, float $orderAmount, float $rate, float $commissionAmount, string $status): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_commission}}', [
            'store_id' => $storeId,
            'order_id' => mt_rand(1000000, 9999999),
            'order_sn' => 'DIST-BACKEND-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'distributor_user_id' => $distributorId,
            'buyer_user_id' => $buyerId,
            'order_amount' => $orderAmount,
            'commission_rate' => $rate,
            'commission_amount' => $commissionAmount,
            'commission_status' => $status,
            'source' => 'backend_fixture',
            'remark' => 'Created by mongoyia-distribution-backend-test/run',
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

    private function pendingCount(int $storeId): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['store_id' => $storeId, 'commission_status' => DistributionCommissionService::COMMISSION_STATUS_PENDING, 'status' => 1])
            ->count('*', Yii::$app->db);
    }

    private function assertSummaryBucket(int $storeId, string $status, int $expectedRows, string $message)
    {
        $actual = (int)(new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['store_id' => $storeId, 'commission_status' => $status, 'status' => 1])
            ->count('*', Yii::$app->db);
        if ($actual !== $expectedRows) {
            $this->fail("{$message} Expected {$expectedRows}, got {$actual}.");
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

    private function assertSettledAtSet(int $commissionId, string $message)
    {
        $settledAt = (int)(new \yii\db\Query())
            ->select('settled_at')
            ->from('{{%mall_distribution_commission}}')
            ->where(['id' => $commissionId])
            ->scalar(Yii::$app->db);
        if ($settledAt <= 0) {
            $this->fail($message . ' Expected settled_at > 0.');
            return;
        }
        $this->ok($message);
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
