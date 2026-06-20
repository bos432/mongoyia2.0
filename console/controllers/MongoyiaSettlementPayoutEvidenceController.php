<?php

namespace console\controllers;

use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementPayoutEvidenceService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementPayoutEvidenceController extends Controller
{
    public $draftId = 0;
    public $amount = 0;
    public $transactionNo = '';
    public $currency = 'MNT';
    public $channel = 'offline';
    public $evidenceFile = '';
    public $remark = 'settlement_payout_evidence';
    public $apply = false;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['draftId', 'amount', 'transactionNo', 'currency', 'channel', 'evidenceFile', 'remark', 'apply', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia settlement payout evidence readiness\n");
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
        if ((int)$this->draftId <= 0) {
            $this->fail('draftId is required.');
            return;
        }

        $result = $this->service()->run((int)$this->draftId, (float)$this->amount, (string)$this->transactionNo, (bool)$this->apply, $this->optionsArray());
        $this->printResult($result);
        if (!$this->apply && $result['eligible'] > 0) {
            $this->warn('Payout evidence is ready to record; rerun with --apply=1 after reviewing the report.');
        }
        if ($result['skipped']) {
            $this->warn('Payout evidence was skipped.');
        }
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $draft = $this->createDraft(SettlementDraftService::DRAFT_STATUS_DRAFT, 20.00);
            $approved = $this->createDraft(SettlementDraftService::DRAFT_STATUS_APPROVED, 30.00);
            $duplicate = $this->createDraft(SettlementDraftService::DRAFT_STATUS_APPROVED, 40.00);
            $this->createEvidence($duplicate, 40.00, 'DUPLICATE-TXN');

            $service = $this->service();
            $dryRun = $service->run($approved, 30.00, 'TXN-DRY-RUN', false, $this->optionsArray());
            $this->printResult($dryRun);
            $this->assertSameInt(1, (int)$dryRun['eligible'], 'Dry-run sees one eligible approved draft.');
            $this->assertSameInt(0, $this->evidenceCount($approved), 'Dry-run does not create evidence.');

            $apply = $service->run($approved, 30.00, 'TXN-APPLY', true, $this->optionsArray());
            $this->printResult($apply);
            $this->assertSameInt(1, (int)$apply['created'], 'Apply creates one payout evidence.');
            $this->assertSameInt(1, $this->evidenceCount($approved), 'Evidence row is persisted.');

            $repeat = $service->run($approved, 30.00, 'TXN-REPEAT', true, $this->optionsArray());
            $this->printResult($repeat);
            $this->assertSameInt(0, (int)$repeat['created'], 'Repeat apply creates no evidence.');
            $this->assertSkippedReason($repeat, $approved, 'payout evidence already exists');

            $notApproved = $service->run($draft, 20.00, 'TXN-DRAFT', true, $this->optionsArray());
            $this->printResult($notApproved);
            $this->assertSkippedReason($notApproved, $draft, 'draft is not approved');

            $wrongAmount = $service->run($duplicate, 39.99, 'TXN-WRONG-AMOUNT', true, $this->optionsArray());
            $this->printResult($wrongAmount);
            $this->assertSkippedReason($wrongAmount, $duplicate, 'amount mismatch');

            $transaction->rollBack();
            $this->ok('Settlement payout evidence fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement payout evidence fixture failed: ' . $e->getMessage());
        }
    }

    private function service(): SettlementPayoutEvidenceService
    {
        return new SettlementPayoutEvidenceService();
    }

    private function optionsArray(): array
    {
        return [
            'currency' => (string)$this->currency,
            'channel' => (string)$this->channel,
            'evidenceFile' => (string)$this->evidenceFile,
            'remark' => (string)$this->remark,
        ];
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_settlement_draft}}', ['id', 'store_id', 'sn', 'net_amount', 'draft_status', 'status']);
        $this->requireColumns('{{%mall_settlement_payout_evidence}}', ['id', 'store_id', 'draft_id', 'draft_sn', 'amount', 'currency', 'channel', 'transaction_no', 'evidence_status', 'status']);
    }

    private function createDraft(string $draftStatus, float $netAmount): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft}}', [
            'store_id' => $this->firstSellerStoreId(),
            'sn' => 'SETD-EVID-' . strtoupper($draftStatus) . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'order_count' => 1,
            'order_amount' => $netAmount,
            'shipment_fee_deducted' => 0,
            'net_amount' => $netAmount,
            'draft_status' => $draftStatus,
            'remark' => 'payout evidence fixture',
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

    private function createEvidence(int $draftId, float $amount, string $transactionNo): int
    {
        $draft = $this->service()->draftRow($draftId);
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
            'remark' => 'existing payout evidence fixture',
            'recorded_at' => $now,
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

    private function evidenceCount(int $draftId): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%mall_settlement_payout_evidence}}')
            ->where(['draft_id' => $draftId, 'status' => 1])
            ->count('*', Yii::$app->db);
    }

    private function printResult(array $result)
    {
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Draft: {$result['draftId']}; eligible: {$result['eligible']}; created: {$result['created']}\n");
        $this->stdout('Amount: ' . number_format((float)$result['amount'], 2) . "; transaction_no={$result['transactionNo']}\n");
        if ($result['evidenceId'] !== null) {
            $this->stdout("EVIDENCE id={$result['evidenceId']}\n");
        }
        foreach ($result['skipped'] as $skip) {
            $this->stdout("SKIP draft={$skip['id']} reason={$skip['reason']}\n");
        }
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
