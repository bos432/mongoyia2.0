<?php

namespace console\controllers;

use common\services\mall\SettlementCloseService;
use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementPayoutEvidenceService;
use common\services\mall\SettlementReportService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementReportBackendTestController extends Controller
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
        $this->stdout("Mongoyia settlement report backend test\n");
        $this->checkFiles();
        $this->checkPermissions();
        $this->checkServiceFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles()
    {
        $this->section('Backend files');
        $this->requireFileContains('common/services/mall/SettlementReportService.php', ['class SettlementReportService', 'openReasons', 'evidence_amount']);
        $this->requireFileContains('backend/modules/mall/controllers/SettlementReportController.php', ['actionIndex', 'SettlementReportService', 'isMallPlatformOperator']);
        $this->requireFileContains('backend/modules/mall/views/settlement-report/index.php', ['结算报表', '只读报表入口', '未关闭原因']);
        $this->requireFileContains('console/migrations/m260618_182000_mongoyia_settlement_report_permission.php', ['/mall/settlement-report/index', '结算报表']);
    }

    private function checkPermissions()
    {
        $this->section('Permissions');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/settlement-report/index', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active permission /mall/settlement-report/index. Run migration m260618_182000_mongoyia_settlement_report_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/settlement-report/index');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if ($sellerGrant) {
            $this->fail('Seller role 50 must not have settlement report permission.');
            return;
        }
        $this->ok('Seller role is not granted settlement report permission.');
    }

    private function checkServiceFixture()
    {
        $this->section('Settlement report backend fixture');
        $storeId = $this->firstSellerStoreId();
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $closed = $this->createDraft($storeId, SettlementDraftService::DRAFT_STATUS_APPROVED, 35.00);
            $this->createEvidence($closed, 35.00, 'REPORT-BACKEND-CLOSED');
            (new SettlementCloseService())->run([$closed], true, 'report backend fixture close');
            $this->createDraft($storeId, SettlementDraftService::DRAFT_STATUS_APPROVED, 15.00);

            $report = (new SettlementReportService())->run($storeId, '', '', 20);
            $this->assertSameInt(2, (int)$report['draftsScanned'], 'Backend report fixture scans two drafts.');
            $this->assertSameInt(1, (int)$report['closedDrafts'], 'Backend report fixture counts closed draft.');
            $this->assertMoney(50, (float)$report['totals']['net_amount'], 'Backend report fixture sums net amount.');
            $this->assertMoney(35, (float)$report['totals']['closed_net_amount'], 'Backend report fixture sums closed net amount.');
            $this->assertMoney(35, (float)$report['totals']['evidence_amount'], 'Backend report fixture sums evidence amount.');
            $this->assertSameInt(1, (int)($report['openReasons']['payout evidence is required'] ?? 0), 'Backend report fixture counts missing-evidence reason.');

            $transaction->rollBack();
            $this->ok('Settlement report backend fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement report backend fixture failed: ' . $e->getMessage());
        }
    }

    private function createDraft(int $storeId, string $status, float $netAmount): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft}}', [
            'store_id' => $storeId,
            'sn' => 'SETD-REPORT-BACKEND-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'order_count' => 1,
            'order_amount' => $netAmount,
            'shipment_fee_deducted' => 0,
            'net_amount' => $netAmount,
            'draft_status' => $status,
            'remark' => 'settlement report backend fixture',
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
            'remark' => 'settlement report backend evidence fixture',
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

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
