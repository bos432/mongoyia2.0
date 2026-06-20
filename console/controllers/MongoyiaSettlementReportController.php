<?php

namespace console\controllers;

use common\services\mall\SettlementCloseService;
use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementPayoutEvidenceService;
use common\services\mall\SettlementReportService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementReportController extends Controller
{
    public $storeId = 0;
    public $dateFrom = '';
    public $dateTo = '';
    public $limit = 500;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['storeId', 'dateFrom', 'dateTo', 'limit', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia settlement report readiness\n");
        $this->checkSchema();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->printReport((new SettlementReportService())->run((int)$this->storeId, (string)$this->dateFrom, (string)$this->dateTo, (int)$this->limit));
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $storeId = $this->firstSellerStoreId();
            $closed = $this->createDraft($storeId, SettlementDraftService::DRAFT_STATUS_APPROVED, 30.00);
            $this->createEvidence($closed, 30.00, 'REPORT-CLOSED-TXN');
            (new SettlementCloseService())->run([$closed], true, 'report fixture close');

            $approvedNoEvidence = $this->createDraft($storeId, SettlementDraftService::DRAFT_STATUS_APPROVED, 20.00);
            $draft = $this->createDraft($storeId, SettlementDraftService::DRAFT_STATUS_DRAFT, 10.00);

            $report = (new SettlementReportService())->run($storeId, '', '', 20);
            $this->printReport($report);
            $this->assertSameInt(3, (int)$report['draftsScanned'], 'Report scans three fixture drafts.');
            $this->assertSameInt(1, (int)$report['closedDrafts'], 'Report counts one closed draft.');
            $this->assertSameInt(2, (int)$report['openDrafts'], 'Report counts two open drafts.');
            $this->assertMoney(60, (float)$report['totals']['net_amount'], 'Report summarizes net amount.');
            $this->assertMoney(30, (float)$report['totals']['closed_net_amount'], 'Report summarizes closed net amount.');
            $this->assertMoney(30, (float)$report['totals']['evidence_amount'], 'Report summarizes evidence amount.');
            $this->assertReasonCount($report, 'payout evidence is required', 1);
            $this->assertReasonCount($report, 'draft is not approved', 1);
            $this->assertSameInt(1, (int)($report['statusCounts'][SettlementDraftService::DRAFT_STATUS_CLOSED] ?? 0), 'Report counts closed status.');

            $transaction->rollBack();
            $this->ok('Settlement report fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement report fixture failed: ' . $e->getMessage());
        }
    }

    private function createDraft(int $storeId, string $status, float $netAmount): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft}}', [
            'store_id' => $storeId,
            'sn' => 'SETD-REPORT-' . strtoupper($status) . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'order_count' => 1,
            'order_amount' => $netAmount,
            'shipment_fee_deducted' => 0,
            'net_amount' => $netAmount,
            'draft_status' => $status,
            'remark' => 'settlement report fixture',
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
            'remark' => 'settlement report evidence fixture',
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

    private function printReport(array $report): void
    {
        $this->stdout("Drafts: {$report['draftsScanned']}; closed: {$report['closedDrafts']}; open: {$report['openDrafts']}\n");
        $this->stdout('Totals: orders=' . (int)$report['totals']['orders'] . ', net_amount=' . number_format((float)$report['totals']['net_amount'], 2) . ', closed_net_amount=' . number_format((float)$report['totals']['closed_net_amount'], 2) . ', evidence_amount=' . number_format((float)$report['totals']['evidence_amount'], 2) . "\n");
        foreach ($report['statusCounts'] as $status => $count) {
            $this->stdout("STATUS {$status}={$count}\n");
        }
        foreach ($report['openReasons'] as $reason => $count) {
            $this->stdout("OPEN_REASON {$reason}={$count}\n");
        }
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_settlement_draft}}', ['id', 'store_id', 'order_count', 'order_amount', 'shipment_fee_deducted', 'net_amount', 'draft_status', 'created_at', 'status']);
        $this->requireColumns('{{%mall_settlement_payout_evidence}}', ['id', 'draft_id', 'amount', 'status']);
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

    private function assertReasonCount(array $report, string $reason, int $expected): void
    {
        $actual = (int)($report['openReasons'][$reason] ?? 0);
        $this->assertSameInt($expected, $actual, "Report reason {$reason} count is {$expected}.");
    }

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertMoney(float $expected, float $actual, string $message): void
    {
        if (round($expected, 2) !== round($actual, 2)) {
            $this->fail("{$message} Expected " . number_format($expected, 2) . ', got ' . number_format($actual, 2) . '.');
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
