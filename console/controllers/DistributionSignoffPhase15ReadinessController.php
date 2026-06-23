<?php

namespace console\controllers;

use common\services\mall\DistributionSignoffPhase15Service;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class DistributionSignoffPhase15ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_DISTRIBUTION_SIGNOFF_PHASE15_READINESS_V1';

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
        $this->stdout("Mongoyia Phase 15 distributor signoff readiness\n");
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
        $this->requireFileContains('common/services/mall/DistributionSignoffPhase15Service.php', [
            'MONGOYIA_DISTRIBUTION_SIGNOFF_PHASE15_V1',
            'saveEvidence',
            'reviewEvidence',
            'evidenceRows',
        ]);
        $this->requireFileContains('console/migrations/m260623_220000_mongoyia_distribution_signoff_evidence.php', [
            'mall_distribution_signoff_evidence',
            'evidence_type',
            'signoff_status',
            'review_remark',
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionDistributorController.php', [
            'DistributionSignoffPhase15Service',
            'actionSignoffEvidenceSave',
            'actionSignoffEvidenceReview',
        ]);
        $this->requireFileContains('backend/modules/mall/views/distribution-distributor/index.php', [
            'data-mongoyia-phase15-signoff-evidence',
            'signoff-evidence-save',
            'signoff-evidence-review',
            '分销签核证据',
        ]);
    }

    private function checkSchema(): void
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_signoff_evidence}}', [
            'id',
            'evidence_type',
            'reference_type',
            'reference_id',
            'distributor_user_id',
            'amount',
            'evidence_title',
            'evidence_url',
            'evidence_note',
            'signoff_status',
            'reviewer_role',
            'reviewed_at',
            'reviewed_by',
            'review_remark',
        ]);
    }

    private function checkFixture(): void
    {
        $this->section('Fixture');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new DistributionSignoffPhase15Service();
            $dryRun = $service->saveEvidence([
                'evidence_type' => DistributionSignoffPhase15Service::TYPE_WITHDRAW_PAYOUT,
                'reference_type' => 'withdraw',
                'reference_id' => 900001,
                'distributor_user_id' => 990000001,
                'amount' => 12.50,
                'evidence_title' => 'Phase 15 signoff dry-run',
                'evidence_note' => 'Dry-run signoff evidence.',
            ], false, 1);
            $this->assertSameInt(0, (int)$dryRun['created'], 'Signoff dry-run does not persist.');

            $create = $service->saveEvidence([
                'evidence_type' => DistributionSignoffPhase15Service::TYPE_WITHDRAW_PAYOUT,
                'reference_type' => 'withdraw',
                'reference_id' => 900001,
                'distributor_user_id' => 990000001,
                'amount' => 12.50,
                'evidence_title' => 'Phase 15 payout evidence',
                'evidence_url' => 'handover://offline-payout-900001',
                'evidence_note' => 'Offline payout was checked by finance.',
                'reviewer_role' => 'finance_reviewer',
            ], true, 1);
            $id = (int)$create['id'];
            $this->assertSameInt(1, (int)$create['created'], 'Signoff evidence is created.');
            $this->assertStatus($id, DistributionSignoffPhase15Service::STATUS_PENDING, 'Signoff starts pending.');

            $approveDryRun = $service->reviewEvidence($id, DistributionSignoffPhase15Service::ACTION_APPROVE, false, 1, 'dry-run');
            $this->assertSameInt(1, (int)$approveDryRun['eligible'], 'Approve dry-run is eligible.');
            $this->assertStatus($id, DistributionSignoffPhase15Service::STATUS_PENDING, 'Approve dry-run does not mutate.');

            $approve = $service->reviewEvidence($id, DistributionSignoffPhase15Service::ACTION_APPROVE, true, 1, 'fixture approve');
            $this->assertSameInt(1, (int)$approve['updated'], 'Approve action updates evidence.');
            $this->assertStatus($id, DistributionSignoffPhase15Service::STATUS_APPROVED, 'Approved status is stored.');

            $repeat = $service->reviewEvidence($id, DistributionSignoffPhase15Service::ACTION_REJECT, true, 1, 'repeat reject');
            $this->assertSameInt(0, (int)$repeat['updated'], 'Repeat review is blocked.');

            $transaction->rollBack();
            $this->ok('Distributor signoff fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distributor signoff fixture failed: ' . $e->getMessage());
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

    private function assertStatus(int $id, string $expected, string $message): void
    {
        $actual = (string)(new \yii\db\Query())
            ->select('signoff_status')
            ->from('{{%mall_distribution_signoff_evidence}}')
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
