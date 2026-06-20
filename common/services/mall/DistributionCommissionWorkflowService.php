<?php

namespace common\services\mall;

use Yii;

class DistributionCommissionWorkflowService
{
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';

    public function run(array $commissionIds, string $action, bool $apply = false, string $remark = ''): array
    {
        $commissionIds = array_values(array_unique(array_filter(array_map('intval', $commissionIds))));
        $action = strtolower(trim($action));
        $result = [
            'apply' => $apply,
            'action' => $action,
            'scanned' => 0,
            'eligible' => 0,
            'updated' => 0,
            'dryRunIds' => [],
            'updatedIds' => [],
            'skipped' => [],
        ];

        if (!$this->isSupportedAction($action)) {
            $result['skipped'][] = ['id' => 0, 'reason' => 'unsupported action'];
            return $result;
        }

        $rows = $this->commissionRows($commissionIds);
        foreach ($rows as $row) {
            $result['scanned']++;
            $id = (int)$row['id'];
            $reason = $this->transitionBlockReason((string)$row['commission_status'], $action);
            if ($reason !== '') {
                $result['skipped'][] = ['id' => $id, 'reason' => $reason];
                continue;
            }

            $result['eligible']++;
            if (!$apply) {
                $result['dryRunIds'][] = $id;
                continue;
            }

            Yii::$app->db->createCommand()->update('{{%mall_distribution_commission}}', [
                'commission_status' => $this->targetStatus($action),
                'remark' => $this->mergeRemark((string)$row['remark'], $action, $remark),
                'settled_at' => $action === self::ACTION_APPROVE ? time() : 0,
                'updated_at' => time(),
                'updated_by' => 1,
            ], ['id' => $id])->execute();
            $result['updated']++;
            $result['updatedIds'][] = $id;
        }

        $foundIds = array_map(static function ($row) {
            return (int)$row['id'];
        }, $rows);
        foreach (array_values(array_diff($commissionIds, $foundIds)) as $missingId) {
            $result['skipped'][] = ['id' => $missingId, 'reason' => 'commission not found'];
        }

        return $result;
    }

    public function isSupportedAction(string $action): bool
    {
        return in_array($action, [self::ACTION_APPROVE, self::ACTION_REJECT], true);
    }

    public function targetStatus(string $action): string
    {
        $map = [
            self::ACTION_APPROVE => DistributionCommissionService::COMMISSION_STATUS_APPROVED,
            self::ACTION_REJECT => DistributionCommissionService::COMMISSION_STATUS_REJECTED,
        ];

        return $map[$action] ?? '';
    }

    public function transitionBlockReason(string $currentStatus, string $action): string
    {
        if (!$this->isSupportedAction($action)) {
            return 'unsupported action';
        }
        if ($currentStatus !== DistributionCommissionService::COMMISSION_STATUS_PENDING) {
            return 'invalid transition from ' . $currentStatus;
        }

        return '';
    }

    private function commissionRows(array $commissionIds): array
    {
        if (!$commissionIds) {
            return [];
        }

        return (new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['id' => $commissionIds, 'status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    private function mergeRemark(string $oldRemark, string $action, string $remark): string
    {
        $marker = 'commission workflow ' . $action;
        if ($remark !== '') {
            $marker .= ': ' . $remark;
        }
        $combined = trim($oldRemark . ' | ' . $marker, ' |');

        return function_exists('mb_substr') ? mb_substr($combined, 0, 255, 'UTF-8') : substr($combined, 0, 255);
    }
}
