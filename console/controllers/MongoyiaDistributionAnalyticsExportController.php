<?php

namespace console\controllers;

use common\services\mall\DistributionAnalyticsService;
use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionInviteService;
use common\services\mall\DistributionProfileService;
use common\services\mall\DistributionWithdrawService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionAnalyticsExportController extends Controller
{
    public $limit = 500;
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['limit', 'outputDir', 'fixture', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia distribution analytics export\n");

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $paths = $this->writeExport((new DistributionAnalyticsService())->distributorRows((int)$this->limit), false);
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
            $distributorId = 990000001;
            $buyerOne = 990000101;
            $buyerTwo = 990000102;
            $storeId = $this->firstSellerStoreId();

            $this->createCommission($storeId, $distributorId, $buyerOne, 100.00, 12.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $this->createCommission($storeId, $distributorId, $buyerTwo, 50.00, 5.00, DistributionCommissionService::COMMISSION_STATUS_PENDING);
            $this->createWithdraw($distributorId, 12.00, DistributionWithdrawService::WITHDRAW_STATUS_PENDING);
            $this->createInvite($distributorId, $buyerOne, 990100001);
            $this->createInvite($distributorId, $buyerTwo, 0);
            $this->createInviteReward($storeId, $distributorId, $buyerOne, 990100001, 6.00);
            $this->createRisk($distributorId, DistributionProfileService::RISK_STATUS_OPEN);

            $rows = (new DistributionAnalyticsService())->distributorRows((int)$this->limit);
            $paths = $this->writeExport($rows, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Distribution Analytics Export',
                'Signoff Checklist',
                'Signoff Decision Matrix',
                '| Real payout allowed by this report | No |',
                '| risk_reviewer | Open risk rows must be reviewed before offline payout. | Open risks: 1 | APPROVE / REWORK |',
                'This report is read-only evidence',
                '| Distributor | Invites | First Orders | Commissions | Commission Amount | Approved Commission | Withdrawals | Withdraw Amount | Pending Withdraw | Invite Rewards | Invite Reward Amount | Open Risks |',
                '| 990000001 | 2 | 1 | 2 | 17.00 | 12.00 | 1 | 12.00 | 12.00 | 1 | 6.00 | 1 |',
            ]);
            $this->assertFileContains($paths['csv'], [
                'distributor_user_id,invite_count,first_order_count,commission_rows,commission_amount,approved_commission_amount,withdraw_rows,withdraw_amount,pending_withdraw_amount,invite_reward_rows,invite_reward_amount,open_risk_count',
                '990000001,2,1,2,17.00,12.00,1,12.00,12.00,1,6.00,1',
            ]);
            $this->ok('Distribution analytics export fixture files generated.');

            $transaction->rollBack();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->ok('Distribution analytics export fixture data and files rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->fail('Distribution analytics export fixture failed: ' . $e->getMessage());
        }
    }

    private function writeExport(array $rows, bool $fixture): array
    {
        $dir = (string)$this->outputDir !== ''
            ? Yii::getAlias((string)$this->outputDir)
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'handover';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stamp = date('Ymd-His') . ($fixture ? '-fixture-' . mt_rand(1000, 9999) : '');
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-distribution-analytics-export-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';

        file_put_contents($md, implode("\n", $this->markdownLines($rows)) . "\n");
        file_put_contents($csv, implode("\n", $this->csvLines($rows)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function markdownLines(array $rows): array
    {
        $totals = $this->totals($rows);
        $lines = [
            '# Mongoyia Distribution Analytics Export',
            '',
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Distributors scanned: ' . count($rows),
            '- Export limit: ' . (int)$this->limit,
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Invites | ' . (int)$totals['invite_count'] . ' |',
            '| First orders | ' . (int)$totals['first_order_count'] . ' |',
            '| Commission rows | ' . (int)$totals['commission_rows'] . ' |',
            '| Commission amount | ' . number_format((float)$totals['commission_amount'], 2) . ' |',
            '| Approved commission amount | ' . number_format((float)$totals['approved_commission_amount'], 2) . ' |',
            '| Withdraw rows | ' . (int)$totals['withdraw_rows'] . ' |',
            '| Withdraw amount | ' . number_format((float)$totals['withdraw_amount'], 2) . ' |',
            '| Pending withdraw amount | ' . number_format((float)$totals['pending_withdraw_amount'], 2) . ' |',
            '| Invite reward rows | ' . (int)$totals['invite_reward_rows'] . ' |',
            '| Invite reward amount | ' . number_format((float)$totals['invite_reward_amount'], 2) . ' |',
            '| Open risks | ' . (int)$totals['open_risk_count'] . ' |',
            '',
            '## Signoff Readiness',
            '',
            '| Item | Value |',
            '|---|---|',
            '| Manual signoff required | Yes |',
            '| Real payout allowed by this report | No |',
            '| Pending withdrawal amount requiring finance review | ' . number_format((float)$totals['pending_withdraw_amount'], 2) . ' |',
            '| Open risk rows requiring risk review | ' . (int)$totals['open_risk_count'] . ' |',
            '',
            '## Distributors',
            '',
            '| Distributor | Invites | First Orders | Commissions | Commission Amount | Approved Commission | Withdrawals | Withdraw Amount | Pending Withdraw | Invite Rewards | Invite Reward Amount | Open Risks |',
            '|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|',
        ];

        foreach ($rows as $row) {
            $lines[] = '| ' . (int)$row['distributor_user_id']
                . ' | ' . (int)$row['invite_count']
                . ' | ' . (int)$row['first_order_count']
                . ' | ' . (int)$row['commission_rows']
                . ' | ' . number_format((float)$row['commission_amount'], 2)
                . ' | ' . number_format((float)$row['approved_commission_amount'], 2)
                . ' | ' . (int)$row['withdraw_rows']
                . ' | ' . number_format((float)$row['withdraw_amount'], 2)
                . ' | ' . number_format((float)$row['pending_withdraw_amount'], 2)
                . ' | ' . (int)$row['invite_reward_rows']
                . ' | ' . number_format((float)$row['invite_reward_amount'], 2)
                . ' | ' . (int)$row['open_risk_count'] . ' |';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Distribution owner reviewed distributor totals: PENDING',
            '- Finance reviewer checked pending withdrawals and open risks: PENDING',
            '- Offline payout evidence matched approved withdrawals: PENDING',
            '- Risk records reviewed before payout: PENDING',
            '- Report archived with the reviewed CSV sidecar: PENDING',
            '',
            '## Signoff Decision Matrix',
            '',
            '| Reviewer role | Review focus | Current evidence | Required decision |',
            '|---|---|---|---|',
            '| distribution_owner | Distributor totals, invite counts, and first-order attribution. | Distributors: ' . count($rows) . ', invites: ' . (int)$totals['invite_count'] . ', first orders: ' . (int)$totals['first_order_count'] . ' | APPROVE / REWORK |',
            '| finance_reviewer | Commission, approved commission, withdrawals, and pending withdrawal amount. | Approved commission: ' . number_format((float)$totals['approved_commission_amount'], 2) . ', pending withdrawal: ' . number_format((float)$totals['pending_withdraw_amount'], 2) . ' | APPROVE / REWORK |',
            '| risk_reviewer | Open risk rows must be reviewed before offline payout. | Open risks: ' . (int)$totals['open_risk_count'] . ' | APPROVE / REWORK |',
            '| operations_archivist | Markdown and CSV sidecar retained in the handover package or ticket. | Report pair generated by `mongoyia-distribution-analytics-export/run` | ARCHIVED / REWORK |',
            '',
            'This report is read-only evidence. It does not approve commissions, create withdrawal requests, write fund logs, change payment state, or trigger real payouts.',
        ]);
    }

    private function csvLines(array $rows): array
    {
        $lines = ['distributor_user_id,invite_count,first_order_count,commission_rows,commission_amount,approved_commission_amount,withdraw_rows,withdraw_amount,pending_withdraw_amount,invite_reward_rows,invite_reward_amount,open_risk_count'];
        foreach ($rows as $row) {
            $lines[] = implode(',', [
                (int)$row['distributor_user_id'],
                (int)$row['invite_count'],
                (int)$row['first_order_count'],
                (int)$row['commission_rows'],
                number_format((float)$row['commission_amount'], 2, '.', ''),
                number_format((float)$row['approved_commission_amount'], 2, '.', ''),
                (int)$row['withdraw_rows'],
                number_format((float)$row['withdraw_amount'], 2, '.', ''),
                number_format((float)$row['pending_withdraw_amount'], 2, '.', ''),
                (int)$row['invite_reward_rows'],
                number_format((float)$row['invite_reward_amount'], 2, '.', ''),
                (int)$row['open_risk_count'],
            ]);
        }

        return $lines;
    }

    private function totals(array $rows): array
    {
        $totals = [
            'invite_count' => 0,
            'first_order_count' => 0,
            'commission_rows' => 0,
            'commission_amount' => 0.0,
            'approved_commission_amount' => 0.0,
            'withdraw_rows' => 0,
            'withdraw_amount' => 0.0,
            'pending_withdraw_amount' => 0.0,
            'invite_reward_rows' => 0,
            'invite_reward_amount' => 0.0,
            'open_risk_count' => 0,
        ];
        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] += is_float($value) ? (float)$row[$key] : (int)$row[$key];
            }
        }

        foreach (['commission_amount', 'approved_commission_amount', 'withdraw_amount', 'pending_withdraw_amount', 'invite_reward_amount'] as $key) {
            $totals[$key] = round((float)$totals[$key], 2);
        }

        return $totals;
    }

    private function createCommission(int $storeId, int $distributorId, int $buyerId, float $orderAmount, float $amount, string $status): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_commission}}', [
            'store_id' => $storeId,
            'order_id' => mt_rand(200000000, 299999999),
            'order_sn' => 'DIST-EXPORT-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'distributor_user_id' => $distributorId,
            'buyer_user_id' => $buyerId,
            'order_amount' => $orderAmount,
            'commission_rate' => 10.00,
            'commission_amount' => $amount,
            'commission_status' => $status,
            'source' => 'analytics_export_fixture',
            'remark' => 'Created by mongoyia-distribution-analytics-export/run',
            'settled_at' => $status === DistributionCommissionService::COMMISSION_STATUS_APPROVED ? time() : 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function createWithdraw(int $distributorId, float $amount, string $status): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_withdraw}}', [
            'distributor_user_id' => $distributorId,
            'amount' => $amount,
            'commission_ids' => '[]',
            'withdraw_status' => $status,
            'apply_remark' => 'analytics export fixture',
            'audit_remark' => '',
            'audited_at' => $status === DistributionWithdrawService::WITHDRAW_STATUS_APPROVED ? time() : 0,
            'audited_by' => $status === DistributionWithdrawService::WITHDRAW_STATUS_APPROVED ? 1 : 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => $distributorId,
            'updated_by' => $distributorId,
        ])->execute();
    }

    private function createInvite(int $distributorId, int $invitedUserId, int $firstOrderId): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite}}', [
            'distributor_user_id' => $distributorId,
            'invited_user_id' => $invitedUserId,
            'source' => 'analytics_export_fixture',
            'invite_status' => DistributionInviteService::INVITE_STATUS_ACTIVE,
            'first_order_id' => $firstOrderId,
            'first_order_at' => $firstOrderId > 0 ? time() : 0,
            'remark' => 'analytics export fixture',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => $distributorId,
            'updated_by' => $distributorId,
        ])->execute();
    }

    private function createInviteReward(int $storeId, int $distributorId, int $invitedUserId, int $orderId, float $amount): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_invite_reward}}', [
            'invite_id' => mt_rand(200000, 999999),
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_sn' => 'DIST-EXPORT-INVITE-' . $orderId,
            'distributor_user_id' => $distributorId,
            'invited_user_id' => $invitedUserId,
            'reward_amount' => $amount,
            'reward_status' => DistributionInviteService::REWARD_STATUS_PENDING,
            'source' => 'analytics_export_fixture',
            'remark' => 'Created by mongoyia-distribution-analytics-export/run',
            'settled_at' => 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function createRisk(int $distributorId, string $status): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_risk}}', [
            'distributor_user_id' => $distributorId,
            'risk_type' => 'analytics_export_fixture',
            'risk_level' => 'medium',
            'content' => 'Fixture analytics export risk',
            'risk_status' => $status,
            'handled_at' => $status === DistributionProfileService::RISK_STATUS_CLOSED ? time() : 0,
            'handled_by' => $status === DistributionProfileService::RISK_STATUS_CLOSED ? 1 : 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
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
