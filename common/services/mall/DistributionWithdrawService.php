<?php

namespace common\services\mall;

use Yii;

class DistributionWithdrawService
{
    const WITHDRAW_STATUS_PENDING = 'pending';
    const WITHDRAW_STATUS_APPROVED = 'approved';
    const WITHDRAW_STATUS_REJECTED = 'rejected';

    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';

    public function summary(int $distributorId): array
    {
        $approved = $this->approvedCommissionRows($distributorId);
        $pendingWithdraw = $this->pendingWithdrawRows($distributorId);
        $pendingIds = [];
        foreach ($pendingWithdraw as $row) {
            $pendingIds = array_merge($pendingIds, $this->decodeCommissionIds($row['commission_ids'] ?? ''));
        }
        $pendingIds = array_values(array_unique(array_map('intval', $pendingIds)));

        $availableRows = [];
        $availableAmount = 0.0;
        foreach ($approved as $row) {
            if (in_array((int)$row['id'], $pendingIds, true)) {
                continue;
            }
            $availableRows[] = $row;
            $availableAmount += (float)$row['commission_amount'];
        }

        return [
            'availableRows' => count($availableRows),
            'availableAmount' => round($availableAmount, 2),
            'availableCommissionIds' => array_map('intval', array_column($availableRows, 'id')),
            'pendingWithdrawRows' => count($pendingWithdraw),
            'pendingWithdrawAmount' => round(array_sum(array_map(static function ($row) {
                return (float)$row['amount'];
            }, $pendingWithdraw)), 2),
        ];
    }

    public function apply(int $distributorId, array $commissionIds, bool $apply = false, string $remark = ''): array
    {
        $commissionIds = array_values(array_unique(array_filter(array_map('intval', $commissionIds))));
        $summary = $this->summary($distributorId);
        $availableIds = array_map('intval', $summary['availableCommissionIds']);
        if (!$commissionIds) {
            $commissionIds = $availableIds;
        }

        $eligibleIds = array_values(array_intersect($commissionIds, $availableIds));
        $blockedIds = array_values(array_diff($commissionIds, $eligibleIds));
        $rows = $eligibleIds ? $this->commissionRowsByIds($distributorId, $eligibleIds) : [];
        $amount = round(array_sum(array_map(static function ($row) {
            return (float)$row['commission_amount'];
        }, $rows)), 2);

        $result = [
            'apply' => $apply,
            'distributorUserId' => $distributorId,
            'requestedIds' => $commissionIds,
            'eligibleIds' => $eligibleIds,
            'blockedIds' => $blockedIds,
            'amount' => $amount,
            'withdrawId' => null,
            'created' => 0,
            'skippedReason' => '',
        ];

        if (!$eligibleIds) {
            $result['skippedReason'] = 'no eligible approved commissions';
            return $result;
        }
        if ($amount <= 0) {
            $result['skippedReason'] = 'withdraw amount must be positive';
            return $result;
        }

        if (!$apply) {
            return $result;
        }

        Yii::$app->db->createCommand()->insert('{{%mall_distribution_withdraw}}', [
            'distributor_user_id' => $distributorId,
            'amount' => $amount,
            'commission_ids' => json_encode($eligibleIds),
            'withdraw_status' => self::WITHDRAW_STATUS_PENDING,
            'apply_remark' => $this->limitText($remark),
            'audit_remark' => '',
            'audited_at' => 0,
            'audited_by' => 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => $distributorId,
            'updated_by' => $distributorId,
        ])->execute();

        $result['withdrawId'] = (int)Yii::$app->db->getLastInsertID();
        $result['created'] = 1;
        return $result;
    }

    public function audit(int $withdrawId, string $action, bool $apply = false, int $auditorId = 1, string $remark = ''): array
    {
        $action = strtolower(trim($action));
        $row = $this->withdrawRow($withdrawId);
        $result = [
            'apply' => $apply,
            'withdrawId' => $withdrawId,
            'action' => $action,
            'eligible' => 0,
            'updated' => 0,
            'commissionUpdated' => 0,
            'skippedReason' => '',
        ];

        if (!$row) {
            $result['skippedReason'] = 'withdraw not found';
            return $result;
        }
        if (!in_array($action, [self::ACTION_APPROVE, self::ACTION_REJECT], true)) {
            $result['skippedReason'] = 'unsupported action';
            return $result;
        }
        if ((string)$row['withdraw_status'] !== self::WITHDRAW_STATUS_PENDING) {
            $result['skippedReason'] = 'invalid transition from ' . $row['withdraw_status'];
            return $result;
        }

        $commissionIds = $this->decodeCommissionIds($row['commission_ids'] ?? '');
        if ($action === self::ACTION_APPROVE && !$this->allCommissionsApproved((int)$row['distributor_user_id'], $commissionIds)) {
            $result['skippedReason'] = 'commission status changed';
            return $result;
        }

        $result['eligible'] = 1;
        if (!$apply) {
            return $result;
        }

        $targetStatus = $action === self::ACTION_APPROVE ? self::WITHDRAW_STATUS_APPROVED : self::WITHDRAW_STATUS_REJECTED;
        Yii::$app->db->createCommand()->update('{{%mall_distribution_withdraw}}', [
            'withdraw_status' => $targetStatus,
            'audit_remark' => $this->limitText($remark),
            'audited_at' => time(),
            'audited_by' => $auditorId,
            'updated_at' => time(),
            'updated_by' => $auditorId,
        ], ['id' => $withdrawId])->execute();

        if ($action === self::ACTION_APPROVE && $commissionIds) {
            $result['commissionUpdated'] = Yii::$app->db->createCommand()->update('{{%mall_distribution_commission}}', [
                'commission_status' => DistributionCommissionService::COMMISSION_STATUS_WITHDRAWN,
                'remark' => 'withdraw approved #' . $withdrawId,
                'updated_at' => time(),
                'updated_by' => $auditorId,
            ], [
                'id' => $commissionIds,
                'distributor_user_id' => (int)$row['distributor_user_id'],
                'commission_status' => DistributionCommissionService::COMMISSION_STATUS_APPROVED,
                'status' => 1,
            ])->execute();
        }

        $result['updated'] = 1;
        return $result;
    }

    private function approvedCommissionRows(int $distributorId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where([
                'distributor_user_id' => $distributorId,
                'commission_status' => DistributionCommissionService::COMMISSION_STATUS_APPROVED,
                'status' => 1,
            ])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    private function pendingWithdrawRows(int $distributorId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_withdraw}}')
            ->where([
                'distributor_user_id' => $distributorId,
                'withdraw_status' => self::WITHDRAW_STATUS_PENDING,
                'status' => 1,
            ])
            ->all(Yii::$app->db);
    }

    private function commissionRowsByIds(int $distributorId, array $commissionIds): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where([
                'id' => $commissionIds,
                'distributor_user_id' => $distributorId,
                'commission_status' => DistributionCommissionService::COMMISSION_STATUS_APPROVED,
                'status' => 1,
            ])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    private function withdrawRow(int $withdrawId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['id' => $withdrawId, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function allCommissionsApproved(int $distributorId, array $commissionIds): bool
    {
        if (!$commissionIds) {
            return false;
        }

        $count = (int)(new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where([
                'id' => $commissionIds,
                'distributor_user_id' => $distributorId,
                'commission_status' => DistributionCommissionService::COMMISSION_STATUS_APPROVED,
                'status' => 1,
            ])
            ->count('*', Yii::$app->db);

        return $count === count($commissionIds);
    }

    private function decodeCommissionIds(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $decoded))));
    }

    private function limitText(string $text): string
    {
        $text = trim($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, 255, 'UTF-8') : substr($text, 0, 255);
    }
}
