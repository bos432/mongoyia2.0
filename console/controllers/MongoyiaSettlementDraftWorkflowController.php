<?php

namespace console\controllers;

use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementDraftWorkflowService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSettlementDraftWorkflowController extends Controller
{
    public $ids = '';
    public $action = '';
    public $remark = 'settlement_draft_workflow';
    public $apply = false;
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['ids', 'action', 'remark', 'apply', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia settlement draft workflow readiness\n");
        $this->checkSchema();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runBatch();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function runBatch()
    {
        $ids = $this->parseIds($this->ids);
        if (!$ids) {
            $this->fail('ids is required. Example: --ids=101,102 --action=submit');
            return;
        }
        if ($this->action === '') {
            $this->fail('action is required. Supported actions: submit, approve, reject, cancel.');
            return;
        }

        $result = (new SettlementDraftWorkflowService())->run($ids, (string)$this->action, (bool)$this->apply, (string)$this->remark);
        $this->printResult($result);
        if (!$this->apply && $result['eligible'] > 0) {
            $this->warn('Settlement draft workflow updates are ready; rerun with --apply=1 after reviewing the report.');
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
            $draft = $this->createDraft(SettlementDraftService::DRAFT_STATUS_DRAFT, 'workflow draft fixture');
            $submitted = $this->createDraft(SettlementDraftService::DRAFT_STATUS_SUBMITTED, 'workflow submitted fixture');
            $approved = $this->createDraft(SettlementDraftService::DRAFT_STATUS_APPROVED, 'workflow approved fixture');
            $rejected = $this->createDraft(SettlementDraftService::DRAFT_STATUS_REJECTED, 'workflow rejected fixture');

            $service = new SettlementDraftWorkflowService();

            $drySubmit = $service->run([$draft], SettlementDraftWorkflowService::ACTION_SUBMIT, false, 'dry-submit');
            $this->printResult($drySubmit);
            $this->assertSameInt(1, (int)$drySubmit['eligible'], 'Dry-run submit sees one eligible draft.');
            $this->assertDraftStatus($draft, SettlementDraftService::DRAFT_STATUS_DRAFT, 'Dry-run submit does not change status.');

            $submit = $service->run([$draft], SettlementDraftWorkflowService::ACTION_SUBMIT, true, 'fixture-submit');
            $this->printResult($submit);
            $this->assertSameInt(1, (int)$submit['updated'], 'Submit updates one draft.');
            $this->assertDraftStatus($draft, SettlementDraftService::DRAFT_STATUS_SUBMITTED, 'Submitted draft status is stored.');

            $approve = $service->run([$draft], SettlementDraftWorkflowService::ACTION_APPROVE, true, 'fixture-approve');
            $this->printResult($approve);
            $this->assertSameInt(1, (int)$approve['updated'], 'Approve updates one submitted draft.');
            $this->assertDraftStatus($draft, SettlementDraftService::DRAFT_STATUS_APPROVED, 'Approved draft status is stored.');

            $repeatApprove = $service->run([$draft], SettlementDraftWorkflowService::ACTION_APPROVE, true, 'repeat-approve');
            $this->printResult($repeatApprove);
            $this->assertSameInt(0, (int)$repeatApprove['updated'], 'Repeat approve is blocked.');
            $this->assertSkippedReason($repeatApprove, $draft, 'invalid transition from approved');

            $reject = $service->run([$submitted], SettlementDraftWorkflowService::ACTION_REJECT, true, 'fixture-reject');
            $this->printResult($reject);
            $this->assertSameInt(1, (int)$reject['updated'], 'Reject updates one submitted draft.');
            $this->assertDraftStatus($submitted, SettlementDraftService::DRAFT_STATUS_REJECTED, 'Rejected draft status is stored.');

            $cancelRejected = $service->run([$submitted], SettlementDraftWorkflowService::ACTION_CANCEL, true, 'fixture-cancel-rejected');
            $this->printResult($cancelRejected);
            $this->assertSameInt(1, (int)$cancelRejected['updated'], 'Cancel updates one rejected draft.');
            $this->assertDraftStatus($submitted, SettlementDraftService::DRAFT_STATUS_CANCELLED, 'Cancelled rejected draft status is stored.');

            $cancelDraft = $service->run([$rejected], SettlementDraftWorkflowService::ACTION_CANCEL, true, 'fixture-cancel');
            $this->printResult($cancelDraft);
            $this->assertSameInt(1, (int)$cancelDraft['updated'], 'Cancel updates one rejected fixture draft.');
            $this->assertDraftStatus($rejected, SettlementDraftService::DRAFT_STATUS_CANCELLED, 'Cancelled draft status is stored.');

            $invalidApprove = $service->run([$approved], SettlementDraftWorkflowService::ACTION_APPROVE, true, 'invalid-approve');
            $this->printResult($invalidApprove);
            $this->assertSameInt(0, (int)$invalidApprove['updated'], 'Approve from approved is blocked.');
            $this->assertSkippedReason($invalidApprove, $approved, 'invalid transition from approved');

            $transaction->rollBack();
            $this->ok('Settlement draft workflow fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Settlement draft workflow fixture failed: ' . $e->getMessage());
        }
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_settlement_draft}}', ['id', 'store_id', 'sn', 'draft_status', 'remark', 'updated_at', 'updated_by', 'status']);
    }

    private function createDraft(string $status, string $remark): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft}}', [
            'store_id' => $this->firstSellerStoreId(),
            'sn' => 'SETD-WF-' . strtoupper($status) . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'order_count' => 1,
            'order_amount' => 10.00,
            'shipment_fee_deducted' => 0.00,
            'net_amount' => 10.00,
            'draft_status' => $status,
            'remark' => $remark,
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

    private function parseIds(string $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', $ids, -1, PREG_SPLIT_NO_EMPTY)))));
    }

    private function printResult(array $result)
    {
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Action: {$result['action']}\n");
        $this->stdout("Scanned: {$result['scanned']}; eligible: {$result['eligible']}; updated: {$result['updated']}\n");
        foreach ($result['dryRunIds'] as $id) {
            $this->stdout("DRY draft={$id}\n");
        }
        foreach ($result['updatedIds'] as $id) {
            $this->stdout("APPLY draft={$id}\n");
        }
        foreach ($result['skipped'] as $skip) {
            $this->stdout("SKIP draft={$skip['id']} reason={$skip['reason']}\n");
        }
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
