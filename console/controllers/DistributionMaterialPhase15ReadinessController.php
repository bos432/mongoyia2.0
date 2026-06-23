<?php

namespace console\controllers;

use common\services\mall\DistributionMaterialPhase15Service;
use common\services\mall\DistributionProfileService;
use common\services\mall\DistributionSupportContentService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class DistributionMaterialPhase15ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_DISTRIBUTION_MATERIAL_PHASE15_READINESS_V1';

    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia Phase 15 distributor material readiness\n");
        $this->checkFiles();
        $this->checkSchema();
        if ($this->fixture) {
            $this->checkFixture();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles(): void
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/DistributionMaterialPhase15Service.php', [
            'MONGOYIA_DISTRIBUTION_MATERIAL_PHASE15_V1',
            'MONGOYIA_DISTRIBUTION_MATERIAL_SAFE_URL_V1',
            'visibleMaterials',
            'saveMaterial',
            'cleanUrl',
            'recordAction',
            'disableMaterial',
        ]);
        $this->requireFileContains('console/migrations/m260623_210000_mongoyia_distribution_material_phase15.php', [
            'mall_distribution_material_download_log',
            'language',
            'asset_url',
            'qr_code_url',
            'download_count',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionDistributorController.php', [
            'MONGOYIA_DISTRIBUTION_PHASE15_BACKEND_POST_VERB_GUARD_V1',
            'behaviors',
            'DistributionMaterialPhase15Service',
            'actionMaterialSave',
            'actionMaterialDisable',
            "'material-save'",
            "'material-disable'",
        ]);
        $this->requireFileContains('backend/modules/mall/views/distribution-distributor/index.php', [
            'data-mongoyia-phase15-material-management',
            'material-save',
            'material-disable',
            'download_count',
        ]);
        $this->requireFileContains('frontend/modules/mall/controllers/UserController.php', [
            'DistributionMaterialPhase15Service',
            'actionDistributionMaterialTrack',
            'recordAction',
        ]);
        $this->requireFileContains('web/resources/mall/default/views/user/distribution.php', [
            'data-mongoyia-phase15-promotion-materials',
            'distribution-material-track',
            'Download',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_material}}', [
            'id',
            'title',
            'content',
            'target_url',
            'material_type',
            'material_status',
            'language',
            'asset_url',
            'qr_code_url',
            'download_enabled',
            'download_count',
            'copy_count',
        ]);
        $this->requireColumns('{{%mall_distribution_material_download_log}}', [
            'id',
            'material_id',
            'distributor_user_id',
            'language',
            'action_type',
            'channel',
            'user_agent_hash',
        ]);
    }

    private function checkFixture(): void
    {
        $this->section('Fixture');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new DistributionMaterialPhase15Service();
            $dryRun = $service->saveMaterial([
                'language' => DistributionSupportContentService::LANG_EN,
                'title' => 'Phase 15 material dry-run',
                'content' => 'Dry-run material',
                'target_url' => '/mall/default/index',
                'asset_url' => '/uploads/material-fixture.png',
                'qr_code_url' => '/uploads/material-fixture-qr.png',
                'download_enabled' => 1,
            ], false, 1);
            $this->assertSameInt(0, (int)$dryRun['created'] + (int)$dryRun['updated'], 'Material dry-run does not persist.');
            $unsafeDryRun = $service->saveMaterial([
                'language' => DistributionSupportContentService::LANG_EN,
                'title' => 'Phase 15 unsafe material dry-run',
                'content' => 'Unsafe URL material',
                'target_url' => 'javascript:alert(1)',
                'asset_url' => '//evil.example/material.png',
                'qr_code_url' => 'data:image/png;base64,AAAA',
                'download_enabled' => 1,
            ], false, 1);
            $this->assertSameString('', (string)($unsafeDryRun['material']['target_url'] ?? ''), 'Unsafe target URL is stripped.');
            $this->assertSameString('', (string)($unsafeDryRun['material']['asset_url'] ?? ''), 'Protocol-relative asset URL is stripped.');
            $this->assertSameString('', (string)($unsafeDryRun['material']['qr_code_url'] ?? ''), 'Data URI QR code URL is stripped.');

            $create = $service->saveMaterial([
                'language' => DistributionSupportContentService::LANG_EN,
                'title' => 'Phase 15 material fixture',
                'content' => 'Share this English material.',
                'target_url' => '/mall/default/index?fxid=990000001',
                'asset_url' => '/uploads/material-fixture.png',
                'qr_code_url' => '/uploads/material-fixture-qr.png',
                'download_enabled' => 1,
                'material_type' => 'image',
                'sort' => 10,
            ], true, 1);
            $materialId = (int)$create['id'];
            $this->assertSameInt(1, (int)$create['created'], 'Material is created.');
            $this->assertContainsId($service->visibleMaterials('en-US', 10), $materialId, 'English material is visible.');

            $copyDryRun = $service->recordAction($materialId, 990000001, DistributionMaterialPhase15Service::ACTION_COPY, 'fixture', 'agent', false);
            $this->assertSameInt(1, (int)$copyDryRun['eligible'], 'Copy dry-run is eligible.');
            $this->assertCounter($materialId, 'copy_count', 0, 'Copy dry-run does not increment.');

            $copy = $service->recordAction($materialId, 990000001, DistributionMaterialPhase15Service::ACTION_COPY, 'fixture', 'agent', true);
            $download = $service->recordAction($materialId, 990000001, DistributionMaterialPhase15Service::ACTION_DOWNLOAD, 'fixture', 'agent', true);
            $this->assertSameInt(1, (int)$copy['created'], 'Copy action log is created.');
            $this->assertSameInt(1, (int)$download['created'], 'Download action log is created.');
            $this->assertCounter($materialId, 'copy_count', 1, 'Copy counter increments.');
            $this->assertCounter($materialId, 'download_count', 1, 'Download counter increments.');
            $this->assertLogCount($materialId, 2, 'Material action logs are stored.');

            $disable = $service->disableMaterial($materialId, true, 1);
            $this->assertSameInt(1, (int)$disable['updated'], 'Disable action updates material.');
            $this->assertMaterialStatus($materialId, DistributionProfileService::MATERIAL_STATUS_DISABLED, 'Disabled material status is stored.');

            $transaction->rollBack();
            $this->ok('Distributor material fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distributor material fixture failed: ' . $e->getMessage());
        }
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
        $content = (string)file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function assertContainsId(array $rows, int $id, string $message): void
    {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                $this->ok($message);
                return;
            }
        }
        $this->fail("{$message} Missing id {$id}.");
    }

    private function assertCounter(int $materialId, string $column, int $expected, string $message): void
    {
        $actual = (int)(new \yii\db\Query())
            ->select($column)
            ->from('{{%mall_distribution_material}}')
            ->where(['id' => $materialId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertLogCount(int $materialId, int $expected, string $message): void
    {
        $actual = (int)(new \yii\db\Query())
            ->from('{{%mall_distribution_material_download_log}}')
            ->where(['material_id' => $materialId, 'status' => 1])
            ->count('*', Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertMaterialStatus(int $materialId, string $expected, string $message): void
    {
        $actual = (string)(new \yii\db\Query())
            ->select('material_status')
            ->from('{{%mall_distribution_material}}')
            ->where(['id' => $materialId])
            ->scalar(Yii::$app->db);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertSameString(string $expected, string $actual, string $message): void
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
