<?php

namespace common\services\mall;

use Yii;

class DistributionInviteRewardWorkflowService
{
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';

    public function run(array $rewardIds, string $action, bool $apply = false, int $auditorId = 1, string $remark = ''): array
    {
        $rewardIds = array_values(array_unique(array_filter(array_map('intval', $rewardIds))));
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

        $rows = $this->rewardRows($rewardIds);
        foreach ($rows as $row) {
            $result['scanned']++;
            $id = (int)$row['id'];
            $reason = $this->transitionBlockReason((string)$row['reward_status'], $action);
            if ($reason !== '') {
                $result['skipped'][] = ['id' => $id, 'reason' => $reason];
                continue;
            }

            $result['eligible']++;
            if (!$apply) {
                $result['dryRunIds'][] = $id;
                continue;
            }

            Yii::$app->db->createCommand()->update('{{%mall_distribution_invite_reward}}', [
                'reward_status' => $this->targetStatus($action),
                'remark' => $this->mergeRemark((string)$row['remark'], $action, $remark),
                'settled_at' => $action === self::ACTION_APPROVE ? time() : 0,
                'updated_at' => time(),
                'updated_by' => $auditorId,
            ], ['id' => $id])->execute();
            $result['updated']++;
            $result['updatedIds'][] = $id;
        }

        $foundIds = array_map(static function ($row) {
            return (int)$row['id'];
        }, $rows);
        foreach (array_values(array_diff($rewardIds, $foundIds)) as $missingId) {
            $result['skipped'][] = ['id' => $missingId, 'reason' => 'invite reward not found'];
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
            self::ACTION_APPROVE => DistributionInviteService::REWARD_STATUS_APPROVED,
            self::ACTION_REJECT => DistributionInviteService::REWARD_STATUS_REJECTED,
        ];

        return $map[$action] ?? '';
    }

    public function transitionBlockReason(string $currentStatus, string $action): string
    {
        if (!$this->isSupportedAction($action)) {
            return 'unsupported action';
        }
        if ($currentStatus !== DistributionInviteService::REWARD_STATUS_PENDING) {
            return 'invalid transition from ' . $currentStatus;
        }

        return '';
    }

    private function rewardRows(array $rewardIds): array
    {
        if (!$rewardIds) {
            return [];
        }

        return (new \yii\db\Query())
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['id' => $rewardIds, 'status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    private function mergeRemark(string $oldRemark, string $action, string $remark): string
    {
        $marker = 'invite reward workflow ' . $action;
        if ($remark !== '') {
            $marker .= ': ' . $remark;
        }
        $combined = trim($oldRemark . ' | ' . $marker, ' |');

        return function_exists('mb_substr') ? mb_substr($combined, 0, 255, 'UTF-8') : substr($combined, 0, 255);
    }
}
