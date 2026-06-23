<?php

namespace console\controllers;

use common\services\mall\DistributionSupportContentService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class DistributionSupportContentPhase15ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_DISTRIBUTION_SUPPORT_CONTENT_PHASE15_READINESS_V1';

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
        $this->stdout("Mongoyia Phase 15 distributor support content readiness\n");
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
        $this->requireFileContains('common/services/mall/DistributionSupportContentService.php', [
            'MONGOYIA_DISTRIBUTION_SUPPORT_CONTENT_PHASE15_V1',
            'visibleForDistributor',
            'saveContent',
            'disableContent',
        ]);
        $this->requireFileContains('console/migrations/m260623_200000_mongoyia_distribution_support_content.php', [
            'mall_distribution_support_content',
            'content_type',
            'language',
            'support_url',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionDistributorController.php', [
            'DistributionSupportContentService',
            'actionSupportContentSave',
            'actionSupportContentDisable',
        ]);
        $this->requireFileContains('backend/modules/mall/views/distribution-distributor/index.php', [
            'data-mongoyia-phase15-support-content',
            '分销培训/FAQ/规则',
            'support-content-save',
            'support-content-disable',
        ]);
        $this->requireFileContains('frontend/modules/mall/controllers/UserController.php', [
            'DistributionSupportContentService',
            'supportContents',
            'supportLanguage',
        ]);
        $this->requireFileContains('web/resources/mall/default/views/user/distribution.php', [
            'data-mongoyia-phase15-distributor-training',
            'Training & FAQ',
            'Open resource',
        ]);
        $this->requireFileContains('console/controllers/DistributionSupportPhase15AcceptanceController.php', [
            'distribution-support-phase15-acceptance/run',
            'Training and FAQ content',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_support_content}}', [
            'id',
            'content_type',
            'language',
            'category',
            'title',
            'body',
            'support_url',
            'content_status',
            'sort',
        ]);
    }

    private function checkFixture(): void
    {
        $this->section('Fixture');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new DistributionSupportContentService();
            $dryRun = $service->saveContent([
                'content_type' => DistributionSupportContentService::TYPE_TRAINING,
                'language' => DistributionSupportContentService::LANG_EN,
                'category' => 'Getting started',
                'title' => 'Phase 15 training dry-run',
                'body' => 'Dry-run content must not persist.',
                'support_url' => '',
                'sort' => 10,
            ], false, 1);
            $this->assertSameInt(0, (int)$dryRun['created'] + (int)$dryRun['updated'], 'Dry-run save does not persist.');

            $training = $service->saveContent([
                'content_type' => DistributionSupportContentService::TYPE_TRAINING,
                'language' => DistributionSupportContentService::LANG_EN,
                'category' => 'Getting started',
                'title' => 'Phase 15 training fixture',
                'body' => 'Explain promotion link usage.',
                'support_url' => '',
                'sort' => 10,
            ], true, 1);
            $faq = $service->saveContent([
                'content_type' => DistributionSupportContentService::TYPE_FAQ,
                'language' => DistributionSupportContentService::LANG_MN,
                'category' => 'FAQ',
                'title' => 'Phase 15 FAQ fixture',
                'body' => 'Answer common distributor questions.',
                'support_url' => '/mall/user/distribution',
                'sort' => 20,
            ], true, 1);

            $trainingId = (int)$training['id'];
            $faqId = (int)$faq['id'];
            $this->assertSameInt(1, (int)$training['created'], 'English training content is created.');
            $this->assertSameInt(1, (int)$faq['created'], 'Mongolian FAQ content is created.');
            $this->assertContainsId($service->visibleForDistributor('en-US', 10), $trainingId, 'English distributor content is visible.');
            $this->assertContainsId($service->visibleForDistributor('mn', 10), $faqId, 'Mongolian distributor content is visible.');

            $disableDryRun = $service->disableContent($trainingId, false, 1);
            $this->assertSameInt(1, (int)$disableDryRun['eligible'], 'Disable dry-run is eligible.');
            $this->assertContentStatus($trainingId, DistributionSupportContentService::STATUS_ACTIVE, 'Disable dry-run does not mutate.');

            $disable = $service->disableContent($trainingId, true, 1);
            $this->assertSameInt(1, (int)$disable['updated'], 'Disable action updates content.');
            $this->assertContentStatus($trainingId, DistributionSupportContentService::STATUS_DISABLED, 'Disabled content status is stored.');

            $transaction->rollBack();
            $this->ok('Distributor support content fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distributor support content fixture failed: ' . $e->getMessage());
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

    private function assertContentStatus(int $id, string $expected, string $message): void
    {
        $actual = (string)(new \yii\db\Query())
            ->select('content_status')
            ->from('{{%mall_distribution_support_content}}')
            ->where(['id' => $id])
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
