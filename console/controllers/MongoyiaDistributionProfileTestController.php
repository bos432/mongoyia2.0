<?php

namespace console\controllers;

use common\services\mall\DistributionProfileService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionProfileTestController extends Controller
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
        $this->stdout("Mongoyia distribution profile/material/risk Phase 4 test\n");
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
        $this->requireFileContains('common/services/mall/DistributionProfileService.php', ['class DistributionProfileService', 'PROFILE_STATUS_PENDING', 'MATERIAL_STATUS_ACTIVE', 'RISK_STATUS_OPEN']);
        $this->requireFileContains('frontend/modules/mall/controllers/UserController.php', [
            'MONGOYIA_DISTRIBUTION_FRONTEND_POST_VERB_GUARD_V1',
            'VerbFilter',
            "'distribution-profile' => ['POST']",
            "'distribution-withdraw' => ['POST']",
            'actionDistributionProfile',
            'DistributionProfileService',
            'distributionProfileStatusLabels',
        ]);
        $this->requireFileContains('web/resources/mall/default/views/user/distribution.php', [
            'Distributor Profile',
            'Promotion Materials',
            'Risk Records',
            'Submit Profile',
            'data-mongoyia-distribution-frontend-post-guard="profile"',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionDistributorController.php', ['actionIndex', 'actionProfileWorkflow', 'actionRiskWorkflow', 'isMallPlatformOperator']);
        $this->requireFileContains('backend/modules/mall/views/distribution-distributor/index.php', ['分销员运营', '分销员资料审核', '推广素材', '风险记录']);
        $this->requireFileContains('console/migrations/m260618_190000_mongoyia_distribution_profile_material_risk.php', ['mall_distribution_profile', 'mall_distribution_material', 'mall_distribution_risk', '/mall/distribution-distributor/index']);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_profile}}', ['id', 'distributor_user_id', 'display_name', 'contact_mobile', 'channel', 'profile_status']);
        $this->requireColumns('{{%mall_distribution_material}}', ['id', 'title', 'content', 'target_url', 'material_type', 'material_status']);
        $this->requireColumns('{{%mall_distribution_risk}}', ['id', 'distributor_user_id', 'risk_type', 'risk_level', 'content', 'risk_status']);
    }

    private function checkPermissions()
    {
        $this->section('Permissions');
        $this->assertPlatformOnlyPermission('/mall/distribution-distributor/index', 'm260618_190000_mongoyia_distribution_profile_material_risk');
        $this->assertPlatformOnlyPermission('/mall/distribution-distributor/*', 'm260618_190000_mongoyia_distribution_profile_material_risk');
    }

    private function checkWorkflowFixture()
    {
        $this->section('Workflow fixture');
        $userId = $this->firstUserId();
        if ($userId <= 0) {
            $this->fail('Need active user for distribution profile fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new DistributionProfileService();

            $dryRun = $service->saveProfile($userId, [
                'display_name' => 'Fixture Distributor',
                'contact_mobile' => '13800000000',
                'contact_email' => 'dist@example.test',
                'channel' => 'H5/PWA',
                'bio' => 'Rollback profile fixture',
            ], false);
            $this->assertSameInt(0, (int)$dryRun['created'] + (int)$dryRun['updated'], 'Profile dry-run does not persist.');

            $create = $service->saveProfile($userId, [
                'display_name' => 'Fixture Distributor',
                'contact_mobile' => '13800000000',
                'contact_email' => 'dist@example.test',
                'channel' => 'H5/PWA',
                'bio' => 'Rollback profile fixture',
            ], true);
            $this->assertSameInt(1, (int)$create['created'], 'Profile apply creates row.');
            $profile = $service->profile($userId);
            $profileId = (int)$profile['id'];
            $this->assertProfileStatus($profileId, DistributionProfileService::PROFILE_STATUS_PENDING, 'Profile starts pending.');

            $update = $service->saveProfile($userId, [
                'display_name' => 'Fixture Distributor Updated',
                'contact_mobile' => '13900000000',
                'contact_email' => 'dist-updated@example.test',
                'channel' => 'Social',
                'bio' => 'Updated rollback profile fixture',
            ], true);
            $this->assertSameInt(1, (int)$update['updated'], 'Profile resubmit updates row.');
            $this->assertProfileStatus($profileId, DistributionProfileService::PROFILE_STATUS_PENDING, 'Profile resubmit returns to pending.');

            $auditDryRun = $service->auditProfile($profileId, DistributionProfileService::ACTION_APPROVE, false, 1, 'fixture dry-run');
            $this->assertSameInt(1, (int)$auditDryRun['eligible'], 'Profile approve dry-run is eligible.');
            $this->assertProfileStatus($profileId, DistributionProfileService::PROFILE_STATUS_PENDING, 'Profile dry-run does not mutate.');

            $approve = $service->auditProfile($profileId, DistributionProfileService::ACTION_APPROVE, true, 1, 'fixture approve');
            $this->assertSameInt(1, (int)$approve['updated'], 'Profile approve updates row.');
            $this->assertProfileStatus($profileId, DistributionProfileService::PROFILE_STATUS_APPROVED, 'Profile is approved.');

            $repeat = $service->auditProfile($profileId, DistributionProfileService::ACTION_APPROVE, true, 1, 'fixture repeat');
            $this->assertSameInt(0, (int)$repeat['updated'], 'Repeat profile approve is blocked.');

            $materialId = $this->createMaterial('Fixture promotion material', 'Use this message with your fxid link.', '/mall/default/index');
            $materials = $service->materials(10);
            $this->assertContainsId($materials, $materialId, 'Active material is visible to distributor center.');

            $riskId = $this->createRisk($userId, 'manual', 'high', 'Fixture suspicious activity');
            $risks = $service->risks($userId, 10);
            $this->assertContainsId($risks, $riskId, 'Open risk is visible to distributor center.');

            $riskDryRun = $service->closeRisk($riskId, false, 1);
            $this->assertSameInt(1, (int)$riskDryRun['eligible'], 'Risk close dry-run is eligible.');
            $this->assertRiskStatus($riskId, DistributionProfileService::RISK_STATUS_OPEN, 'Risk dry-run does not mutate.');

            $close = $service->closeRisk($riskId, true, 1);
            $this->assertSameInt(1, (int)$close['updated'], 'Risk close updates row.');
            $this->assertRiskStatus($riskId, DistributionProfileService::RISK_STATUS_CLOSED, 'Risk is closed.');

            $repeatClose = $service->closeRisk($riskId, true, 1);
            $this->assertSameInt(0, (int)$repeatClose['updated'], 'Repeat risk close is blocked.');

            $transaction->rollBack();
            $this->ok('Distribution profile/material/risk fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution profile/material/risk fixture failed: ' . $e->getMessage());
        }
    }

    private function createMaterial(string $title, string $content, string $targetUrl): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_material}}', [
            'title' => $title,
            'content' => $content,
            'target_url' => $targetUrl,
            'material_type' => 'text',
            'material_status' => DistributionProfileService::MATERIAL_STATUS_ACTIVE,
            'remark' => 'Created by mongoyia-distribution-profile-test/run',
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

    private function createRisk(int $userId, string $type, string $level, string $content): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_risk}}', [
            'distributor_user_id' => $userId,
            'risk_type' => $type,
            'risk_level' => $level,
            'content' => $content,
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

    private function assertProfileStatus(int $profileId, string $expected, string $message)
    {
        $actual = (string)(new \yii\db\Query())->select('profile_status')->from('{{%mall_distribution_profile}}')->where(['id' => $profileId])->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertRiskStatus(int $riskId, string $expected, string $message)
    {
        $actual = (string)(new \yii\db\Query())->select('risk_status')->from('{{%mall_distribution_risk}}')->where(['id' => $riskId])->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertContainsId(array $rows, int $id, string $message)
    {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                $this->ok($message);
                return;
            }
        }
        $this->fail("{$message} Missing id {$id}.");
    }

    private function assertPlatformOnlyPermission(string $path, string $migration)
    {
        $permissionId = (int)(new \yii\db\Query())->select('id')->from('{{%base_permission}}')->where(['path' => $path, 'status' => 1])->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission ' . $path . '. Run migration ' . $migration . '.');
            return;
        }
        $this->ok('Permission exists: ' . $path);

        $sellerGrant = (new \yii\db\Query())->from('{{%base_role_permission}}')->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])->exists(Yii::$app->db);
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

    private function firstUserId(): int
    {
        return (int)(new \yii\db\Query())->select('id')->from('{{%user}}')->where(['>', 'status', 0])->orderBy(['id' => SORT_ASC])->scalar(Yii::$app->db);
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
