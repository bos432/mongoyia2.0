<?php

namespace common\services\mall;

use Yii;

class DistributionRiskReadinessService
{
    public function run(float $largeWithdrawAmount = 500.00): array
    {
        $issues = [];
        $this->appendPendingWithdrawWithOpenRisk($issues);
        $this->appendLargePendingWithdraw($issues, $largeWithdrawAmount);
        $this->appendWithdrawAmountMismatch($issues);
        $this->appendInvalidInviteRewards($issues);

        return [
            'largeWithdrawAmount' => round($largeWithdrawAmount, 2),
            'issueCount' => count($issues),
            'issues' => $issues,
            'summary' => $this->summary($issues),
        ];
    }

    private function appendPendingWithdrawWithOpenRisk(array &$issues): void
    {
        $rows = (new \yii\db\Query())
            ->select([
                'withdraw_id' => 'w.id',
                'distributor_user_id' => 'w.distributor_user_id',
                'amount' => 'w.amount',
                'risk_count' => 'COUNT(r.id)',
            ])
            ->from(['w' => '{{%mall_distribution_withdraw}}'])
            ->innerJoin(['r' => '{{%mall_distribution_risk}}'], 'r.distributor_user_id = w.distributor_user_id AND r.risk_status = :riskStatus AND r.status = 1', [
                ':riskStatus' => DistributionProfileService::RISK_STATUS_OPEN,
            ])
            ->where(['w.withdraw_status' => DistributionWithdrawService::WITHDRAW_STATUS_PENDING, 'w.status' => 1])
            ->groupBy(['w.id', 'w.distributor_user_id', 'w.amount'])
            ->orderBy(['w.id' => SORT_ASC])
            ->all(Yii::$app->db);

        foreach ($rows as $row) {
            $issues[] = [
                'type' => 'pending_withdraw_open_risk',
                'severity' => 'high',
                'distributor_user_id' => (int)$row['distributor_user_id'],
                'object_id' => (int)$row['withdraw_id'],
                'amount' => round((float)$row['amount'], 2),
                'message' => 'Pending withdrawal has open distributor risk records.',
                'context' => ['risk_count' => (int)$row['risk_count']],
            ];
        }
    }

    private function appendLargePendingWithdraw(array &$issues, float $largeWithdrawAmount): void
    {
        $rows = (new \yii\db\Query())
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['withdraw_status' => DistributionWithdrawService::WITHDRAW_STATUS_PENDING, 'status' => 1])
            ->andWhere(['>=', 'amount', $largeWithdrawAmount])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);

        foreach ($rows as $row) {
            $issues[] = [
                'type' => 'large_pending_withdraw',
                'severity' => 'medium',
                'distributor_user_id' => (int)$row['distributor_user_id'],
                'object_id' => (int)$row['id'],
                'amount' => round((float)$row['amount'], 2),
                'message' => 'Pending withdrawal amount is above review threshold.',
                'context' => ['threshold' => round($largeWithdrawAmount, 2)],
            ];
        }
    }

    private function appendWithdrawAmountMismatch(array &$issues): void
    {
        $rows = (new \yii\db\Query())
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['withdraw_status' => DistributionWithdrawService::WITHDRAW_STATUS_PENDING, 'status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);

        foreach ($rows as $row) {
            $commissionIds = $this->decodeIds($row['commission_ids'] ?? '');
            if (!$commissionIds) {
                $issues[] = [
                    'type' => 'withdraw_missing_commission_ids',
                    'severity' => 'high',
                    'distributor_user_id' => (int)$row['distributor_user_id'],
                    'object_id' => (int)$row['id'],
                    'amount' => round((float)$row['amount'], 2),
                    'message' => 'Pending withdrawal has no commission_ids trace.',
                    'context' => [],
                ];
                continue;
            }

            $sum = (float)(new \yii\db\Query())
                ->from('{{%mall_distribution_commission}}')
                ->where([
                    'id' => $commissionIds,
                    'distributor_user_id' => (int)$row['distributor_user_id'],
                    'commission_status' => DistributionCommissionService::COMMISSION_STATUS_APPROVED,
                    'status' => 1,
                ])
                ->sum('commission_amount', Yii::$app->db);
            $sum = round($sum, 2);
            $amount = round((float)$row['amount'], 2);
            if ($sum !== $amount) {
                $issues[] = [
                    'type' => 'withdraw_amount_mismatch',
                    'severity' => 'high',
                    'distributor_user_id' => (int)$row['distributor_user_id'],
                    'object_id' => (int)$row['id'],
                    'amount' => $amount,
                    'message' => 'Pending withdrawal amount does not match approved commission total.',
                    'context' => [
                        'commission_ids' => $commissionIds,
                        'commission_amount' => $sum,
                    ],
                ];
            }
        }
    }

    private function appendInvalidInviteRewards(array &$issues): void
    {
        $rows = (new \yii\db\Query())
            ->select([
                'reward_id' => 'rw.id',
                'invite_id' => 'rw.invite_id',
                'order_id' => 'rw.order_id',
                'distributor_user_id' => 'rw.distributor_user_id',
                'invited_user_id' => 'rw.invited_user_id',
                'reward_amount' => 'rw.reward_amount',
                'first_order_id' => 'i.first_order_id',
                'invite_distributor_user_id' => 'i.distributor_user_id',
                'invite_invited_user_id' => 'i.invited_user_id',
                'invite_status' => 'i.invite_status',
            ])
            ->from(['rw' => '{{%mall_distribution_invite_reward}}'])
            ->leftJoin(['i' => '{{%mall_distribution_invite}}'], 'i.id = rw.invite_id AND i.status = 1')
            ->where(['rw.status' => 1])
            ->orderBy(['rw.id' => SORT_ASC])
            ->all(Yii::$app->db);

        foreach ($rows as $row) {
            $reason = $this->inviteRewardIssueReason($row);
            if ($reason === '') {
                continue;
            }

            $issues[] = [
                'type' => 'invalid_invite_reward',
                'severity' => 'high',
                'distributor_user_id' => (int)$row['distributor_user_id'],
                'object_id' => (int)$row['reward_id'],
                'amount' => round((float)$row['reward_amount'], 2),
                'message' => $reason,
                'context' => [
                    'invite_id' => (int)$row['invite_id'],
                    'order_id' => (int)$row['order_id'],
                    'first_order_id' => (int)$row['first_order_id'],
                ],
            ];
        }
    }

    private function inviteRewardIssueReason(array $row): string
    {
        if ((int)$row['first_order_id'] <= 0) {
            return 'Invite reward has no first-order trace on invite relation.';
        }
        if ((int)$row['first_order_id'] !== (int)$row['order_id']) {
            return 'Invite reward order is not the invite relation first order.';
        }
        if ((int)$row['distributor_user_id'] !== (int)$row['invite_distributor_user_id']) {
            return 'Invite reward distributor does not match invite relation.';
        }
        if ((int)$row['invited_user_id'] !== (int)$row['invite_invited_user_id']) {
            return 'Invite reward invited user does not match invite relation.';
        }
        if ((string)$row['invite_status'] !== DistributionInviteService::INVITE_STATUS_ACTIVE) {
            return 'Invite reward relation is not active.';
        }

        return '';
    }

    private function summary(array $issues): array
    {
        $summary = [];
        foreach ($issues as $issue) {
            $type = (string)$issue['type'];
            if (!isset($summary[$type])) {
                $summary[$type] = 0;
            }
            $summary[$type]++;
        }
        ksort($summary);

        return $summary;
    }

    private function decodeIds(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $decoded))));
    }
}
