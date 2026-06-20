<?php

namespace console\controllers;

use common\services\mall\SettlementCloseService;
use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementPayoutEvidenceService;
use common\services\mall\SettlementReportService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementExportController extends Controller
{
    public $storeId = 0;
    public $dateFrom = '';
    public $dateTo = '';
    public $limit = 500;
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['storeId', 'dateFrom', 'dateTo', 'limit', 'outputDir', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia settlement export\n");

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $paths = $this->writeExport((new SettlementReportService())->run((int)$this->storeId, (string)$this->dateFrom, (string)$this->dateTo, (int)$this->limit), false);
            $this->stdout("Markdown: {$paths['md']}\nCSV: {$paths['csv']}\n");
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function runFixture(): void
    {
        $this->section('Fixture');
        $transaction = Yii::$app->db->beginTransaction();
        $paths = [];
        try {
            $storeId = $this->firstSellerStoreId();
            $closed = $this->createDraft($storeId, SettlementDraftService::DRAFT_STATUS_APPROVED, 30.00);
            $this->createEvidence($closed, 30.00, 'EXPORT-CLOSED-TXN');
            (new SettlementCloseService())->run([$closed], true, 'export fixture close');
            $this->createDraft($storeId, SettlementDraftService::DRAFT_STATUS_APPROVED, 20.00);

            $paths = $this->writeExport((new SettlementReportService())->run($storeId, '', '', 20), true);
            $this->assertFileContains($paths['md'], ['# Mongoyia Settlement Export', 'Signoff Checklist', 'payout evidence is required', '| Store | Drafts | Orders | Net Amount | Closed Net Amount | Evidence Amount |']);
            $this->assertFileContains($paths['csv'], ['store_id,drafts,orders,net_amount,closed_net_amount,evidence_amount,status_counts,open_reasons', 'payout evidence is required']);
            $this->ok('Settlement export fixture files generated.');

            $transaction->rollBack();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->ok('Settlement export fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->fail('Settlement export fixture failed: ' . $e->getMessage());
        }
    }

    private function writeExport(array $report, bool $fixture): array
    {
        $dir = (string)$this->outputDir !== ''
            ? Yii::getAlias((string)$this->outputDir)
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'handover';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stamp = date('Ymd-His') . ($fixture ? '-fixture-' . mt_rand(1000, 9999) : '');
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-settlement-export-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';

        file_put_contents($md, implode("\n", $this->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $this->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Settlement Export',
            '',
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Store ID: ' . ((int)$this->storeId > 0 ? (int)$this->storeId : 'all'),
            '- Date from: ' . ((string)$this->dateFrom !== '' ? (string)$this->dateFrom : 'not limited'),
            '- Date to: ' . ((string)$this->dateTo !== '' ? (string)$this->dateTo : 'not limited'),
            '- Drafts scanned: ' . (int)$report['draftsScanned'],
            '- Closed drafts: ' . (int)$report['closedDrafts'],
            '- Open drafts: ' . (int)$report['openDrafts'],
            '',
            '## Totals',
            '',
            '| Item | Amount |',
            '|---|---:|',
            '| Orders | ' . (int)$report['totals']['orders'] . ' |',
            '| Net amount | ' . number_format((float)$report['totals']['net_amount'], 2) . ' |',
            '| Closed net amount | ' . number_format((float)$report['totals']['closed_net_amount'], 2) . ' |',
            '| Evidence amount | ' . number_format((float)$report['totals']['evidence_amount'], 2) . ' |',
            '',
            '## Stores',
            '',
            '| Store | Drafts | Orders | Net Amount | Closed Net Amount | Evidence Amount | Status Counts | Open Reasons |',
            '|---:|---:|---:|---:|---:|---:|---|---|',
        ];

        foreach ($report['stores'] as $row) {
            $lines[] = '| ' . (int)$row['store_id'] . ' | ' . (int)$row['drafts'] . ' | ' . (int)$row['orders'] . ' | ' . number_format((float)$row['net_amount'], 2) . ' | ' . number_format((float)$row['closed_net_amount'], 2) . ' | ' . number_format((float)$row['evidence_amount'], 2) . ' | `' . $this->json($row['statusCounts']) . '` | `' . $this->json($row['openReasons']) . '` |';
        }

        $lines = array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Finance reviewer: PENDING',
            '- Merchant settlement owner: PENDING',
            '- Evidence archive reviewed: PENDING',
            '- Real payout reconciliation: PENDING',
            '',
            'This report is read-only evidence. It does not write fund logs, change payment state, or trigger real payouts.',
        ]);

        return $lines;
    }

    private function csvLines(array $report): array
    {
        $lines = ['store_id,drafts,orders,net_amount,closed_net_amount,evidence_amount,status_counts,open_reasons'];
        foreach ($report['stores'] as $row) {
            $lines[] = implode(',', [
                (int)$row['store_id'],
                (int)$row['drafts'],
                (int)$row['orders'],
                number_format((float)$row['net_amount'], 2, '.', ''),
                number_format((float)$row['closed_net_amount'], 2, '.', ''),
                number_format((float)$row['evidence_amount'], 2, '.', ''),
                $this->csvCell($this->json($row['statusCounts'])),
                $this->csvCell($this->json($row['openReasons'])),
            ]);
        }

        return $lines;
    }

    private function createDraft(int $storeId, string $status, float $netAmount): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft}}', [
            'store_id' => $storeId,
            'sn' => 'SETD-EXPORT-' . strtoupper($status) . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'order_count' => 1,
            'order_amount' => $netAmount,
            'shipment_fee_deducted' => 0,
            'net_amount' => $netAmount,
            'draft_status' => $status,
            'remark' => 'settlement export fixture',
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
            'remark' => 'settlement export evidence fixture',
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

    private function assertFileContains(string $path, array $needles): void
    {
        if (!is_file($path)) {
            $this->fail("Missing export file {$path}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("Export file {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("Export file contains required markers: {$path}");
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function csvCell(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
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
