<?php

namespace common\services\mall;

use Yii;

class DistributionAnalyticsService
{
    public function distributorRows(int $limit = 100): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge(
            $this->column('{{%mall_distribution_commission}}', 'distributor_user_id'),
            $this->column('{{%mall_distribution_withdraw}}', 'distributor_user_id'),
            $this->column('{{%mall_distribution_invite}}', 'distributor_user_id'),
            $this->column('{{%mall_distribution_invite_reward}}', 'distributor_user_id'),
            $this->column('{{%mall_distribution_risk}}', 'distributor_user_id')
        )))));

        sort($ids);
        $ids = array_slice($ids, 0, max(1, $limit));
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = $this->row($id);
        }

        usort($rows, static function (array $a, array $b) {
            if ((float)$a['commission_amount'] === (float)$b['commission_amount']) {
                return (int)$a['distributor_user_id'] <=> (int)$b['distributor_user_id'];
            }
            return (float)$a['commission_amount'] < (float)$b['commission_amount'] ? 1 : -1;
        });

        return $rows;
    }

    public function row(int $distributorId): array
    {
        $commission = $this->commissionSummary($distributorId);
        $withdraw = $this->withdrawSummary($distributorId);
        $invite = $this->inviteSummary($distributorId);
        $reward = $this->rewardSummary($distributorId);
        $risk = $this->riskSummary($distributorId);

        return [
            'distributor_user_id' => $distributorId,
            'invite_count' => (int)$invite['invite_count'],
            'first_order_count' => (int)$invite['first_order_count'],
            'commission_rows' => (int)$commission['commission_rows'],
            'commission_amount' => round((float)$commission['commission_amount'], 2),
            'approved_commission_amount' => round((float)$commission['approved_commission_amount'], 2),
            'withdraw_rows' => (int)$withdraw['withdraw_rows'],
            'withdraw_amount' => round((float)$withdraw['withdraw_amount'], 2),
            'pending_withdraw_amount' => round((float)$withdraw['pending_withdraw_amount'], 2),
            'invite_reward_rows' => (int)$reward['invite_reward_rows'],
            'invite_reward_amount' => round((float)$reward['invite_reward_amount'], 2),
            'open_risk_count' => (int)$risk['open_risk_count'],
        ];
    }

    private function column(string $table, string $column): array
    {
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if ($schema === null || !isset($schema->columns[$column])) {
            return [];
        }

        return (new \yii\db\Query())
            ->select($column)
            ->from($table)
            ->where(['status' => 1])
            ->column(Yii::$app->db);
    }

    private function commissionSummary(int $distributorId): array
    {
        $rows = (new \yii\db\Query())
            ->select([
                'commission_status',
                'rows' => 'COUNT(*)',
                'amount' => 'SUM(commission_amount)',
            ])
            ->from('{{%mall_distribution_commission}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->groupBy(['commission_status'])
            ->all(Yii::$app->db);

        $result = ['commission_rows' => 0, 'commission_amount' => 0.0, 'approved_commission_amount' => 0.0];
        foreach ($rows as $row) {
            $result['commission_rows'] += (int)$row['rows'];
            $result['commission_amount'] += (float)$row['amount'];
            if ((string)$row['commission_status'] === DistributionCommissionService::COMMISSION_STATUS_APPROVED) {
                $result['approved_commission_amount'] += (float)$row['amount'];
            }
        }

        return $result;
    }

    private function withdrawSummary(int $distributorId): array
    {
        $rows = (new \yii\db\Query())
            ->select([
                'withdraw_status',
                'rows' => 'COUNT(*)',
                'amount' => 'SUM(amount)',
            ])
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->groupBy(['withdraw_status'])
            ->all(Yii::$app->db);

        $result = ['withdraw_rows' => 0, 'withdraw_amount' => 0.0, 'pending_withdraw_amount' => 0.0];
        foreach ($rows as $row) {
            $result['withdraw_rows'] += (int)$row['rows'];
            $result['withdraw_amount'] += (float)$row['amount'];
            if ((string)$row['withdraw_status'] === DistributionWithdrawService::WITHDRAW_STATUS_PENDING) {
                $result['pending_withdraw_amount'] += (float)$row['amount'];
            }
        }

        return $result;
    }

    private function inviteSummary(int $distributorId): array
    {
        $row = (new \yii\db\Query())
            ->select([
                'invite_count' => 'COUNT(*)',
                'first_order_count' => 'SUM(CASE WHEN first_order_id > 0 THEN 1 ELSE 0 END)',
            ])
            ->from('{{%mall_distribution_invite}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->one(Yii::$app->db);

        return [
            'invite_count' => (int)($row['invite_count'] ?? 0),
            'first_order_count' => (int)($row['first_order_count'] ?? 0),
        ];
    }

    private function rewardSummary(int $distributorId): array
    {
        $row = (new \yii\db\Query())
            ->select([
                'invite_reward_rows' => 'COUNT(*)',
                'invite_reward_amount' => 'SUM(reward_amount)',
            ])
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->one(Yii::$app->db);

        return [
            'invite_reward_rows' => (int)($row['invite_reward_rows'] ?? 0),
            'invite_reward_amount' => (float)($row['invite_reward_amount'] ?? 0),
        ];
    }

    private function riskSummary(int $distributorId): array
    {
        $count = (int)(new \yii\db\Query())
            ->from('{{%mall_distribution_risk}}')
            ->where([
                'distributor_user_id' => $distributorId,
                'risk_status' => DistributionProfileService::RISK_STATUS_OPEN,
                'status' => 1,
            ])
            ->count('*', Yii::$app->db);

        return ['open_risk_count' => $count];
    }
}
