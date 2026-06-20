<?php

namespace console\controllers;

use common\services\mall\SettlementCloseService;
use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementPayoutEvidenceService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementCloseController extends Controller
{
    public $ids = '';
    public $apply = false;
    public $fixture = false;
    public $strict = false;
    public $remark = 'settlement_close';

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['ids', 'apply', 'fixture', 'strict', 'remark']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia settlement close readiness\n");
        $this->checkSchema();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runCurrentData();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function runCurrentData()
    {
        $ids = $this->parseIds($this->ids);
        if (!$ids) {
            $this->fail('ids is required.');
            return;
        }

        $result = (new SettlementCloseService())->run($ids, (bool)$this->apply, (string)$this->remark);
        $this->printResult($result);
        if (!$this->apply && $result['eligible'] > 0) {
            $this->warn('Settlement drafts are ready to close; rerun with --apply=1 after reviewing the report.');
        }
        if ($result['skipped']) {
            $this->warn('Some settlement drafts were skipped.');
        }
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $approvedWithEvidence = $this->createDraft(SettlementDraftService::DRAFT_STATUS_APPROVED, 30.00);
            $this->createEvidence($approvedWithEvidence, 30.00, 'CLOSE-TXN-OK');
            $approvedNoEvidence = $this->createDraft(SettlementDraftService::DRAFT_STATUS_APPROVED, 20.00);
            $draft = $this->createDraft(SettlementDraftService::DRAFT_STATUS_DRAFT, 10.00);

            $service = new SettlementCloseService();
            $dryRun = $service->run([$approvedWithEvidence], false, 'dry-run-close');
            $this->printResult($dryRun);
            $this->assertSameInt(1, (int)$dryRun['eligible'], 'Dry-run sees one closeable draft.');
            $this->assertDraftStatus($approvedWithEvidence, SettlementDraftService::DRAFT_STATUS_APPROVED, 'Dry-run does not close draft.');
            $this->assertMoney(30, (float)$dryRun['totals']['net_amount'], 'Dry-run summarizes closeable net amount.');

            $apply = $service->run([$approvedWithEvidence], true, 'fixture-close');
            $this->printResult($apply);
            $this->assertSameInt(1, (int)$apply['closed'], 'Apply closes one draft.');
            $this->assertDraftStatus($approvedWithEvidence, SettlementDraftService::DRAFT_STATUS_CLOSED, 'Closed draft status is stored.');

            $repeat = $service->run([$approvedWithEvidence], true, 'repeat-close');
            $this->printResult($repeat);
            $this->assertSameInt(0, (int)$repeat['closed'], 'Repeat apply closes no draft.');
            $this->assertSkippedReason($repeat, $approvedWithEvidence, 'draft already closed');

            $missingEvidence = $service->run([$approvedNoEvidence], true, 'missing-evidence');
            $this->printResult($missingEvidence);
            $this->assertSkippedReason($missingEvidence, $approvedNoEvidence, 'payout evidence is required');

            $notApproved = $service->run([$draft], true, 'not-approved');
            $this->printResult($notApproved);
            $this->assertSkippedReason($notApproved, $draft, 'draft is not approved');

            $transaction->rollBack();
            $this->ok('Settlement close fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement close fixture failed: ' . $e->getMessage());
        }
    }

    private function createDraft(string $draftStatus, float $netAmount): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft}}', [
            'store_id' => $this->firstSellerStoreId(),
            'sn' => 'SETD-CLOSE-' . strtoupper($draftStatus) . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'order_count' => 1,
            'order_amount' => $netAmount,
            'shipment_fee_deducted' => 0,
            'net_amount' => $netAmount,
            'draft_status' => $draftStatus,
            'remark' => 'settlement close fixture',
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

    private function createEvidence(int $draftId, float $amount, string $transactionNo): void
    {
        $draft = (new SettlementPayoutEvidenceService())->draftRow($draftId);
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_payout_evidence}}', [
            'store_id' => (int)$draft['store_id'],
            'draft_id' => $draftId,
            'draft_sn' => (string)$draft['sn'],
            'amount' => $amount,
            'currency' => 'MNT',
            'channel' => 'offline',
            'transaction_no' => $transactionNo,
            'evidence_file' => '',
            'evidence_status' => SettlementPayoutEvidenceService::EVIDENCE_STATUS_RECORDED,
            'remark' => 'settlement close evidence fixture',
            'recorded_at' => $now,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function printResult(array $result)
    {
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Scanned: {$result['scanned']}; eligible: {$result['eligible']}; closed: {$result['closed']}\n");
        $this->stdout('Totals: orders=' . (int)$result['totals']['order_count'] . ', order_amount=' . number_format((float)$result['totals']['order_amount'], 2) . ', net_amount=' . number_format((float)$result['totals']['net_amount'], 2) . "\n");
        foreach ($result['skipped'] as $skip) {
            $this->stdout("SKIP draft={$skip['id']} reason={$skip['reason']}\n");
        }
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_settlement_draft}}', ['id', 'store_id', 'sn', 'order_count', 'order_amount', 'shipment_fee_deducted', 'net_amount', 'draft_status', 'remark', 'status']);
        $this->requireColumns('{{%mall_settlement_payout_evidence}}', ['id', 'draft_id', 'amount', 'transaction_no', 'status']);
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

    private function parseIds(string $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', trim($ids))))));
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

    private function assertSkippedReason(array $result, int $draftId, string $reason)
    {
        foreach ($result['skipped'] as $row) {
            if ((int)$row['id'] === $draftId && (string)$row['reason'] === $reason) {
                $this->ok("Draft {$draftId} skip reason is {$reason}.");
                return;
            }
        }

        $this->fail("Draft {$draftId} skip reason {$reason} was not found.");
    }

    private function firstSellerStoreId(): int
    {
        $storeId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'id', 0])
            ->andWhere(['>', 'status', 0])
            ->andWhere(['not in', 'id', [5]])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);

        return $storeId > 0 ? $storeId : 1;
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
