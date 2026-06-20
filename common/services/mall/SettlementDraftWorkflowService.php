<?php

namespace common\services\mall;

use Yii;

class SettlementDraftWorkflowService
{
    const ACTION_SUBMIT = 'submit';
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';
    const ACTION_CANCEL = 'cancel';

    public function run(array $draftIds, string $action, bool $apply = false, string $remark = ''): array
    {
        $draftIds = array_values(array_unique(array_filter(array_map('intval', $draftIds))));
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

        foreach ($this->draftRows($draftIds) as $draft) {
            $result['scanned']++;
            $draftId = (int)$draft['id'];
            $currentStatus = (string)$draft['draft_status'];
            $targetStatus = $this->targetStatus($action);
            $reason = $this->transitionBlockReason($currentStatus, $action);
            if ($reason !== '') {
                $result['skipped'][] = ['id' => $draftId, 'reason' => $reason];
                continue;
            }

            $result['eligible']++;
            if (!$apply) {
                $result['dryRunIds'][] = $draftId;
                continue;
            }

            Yii::$app->db->createCommand()->update('{{%mall_settlement_draft}}', [
                'draft_status' => $targetStatus,
                'remark' => $this->mergeRemark((string)$draft['remark'], $action, $remark),
                'updated_at' => time(),
                'updated_by' => 1,
            ], ['id' => $draftId])->execute();
            $result['updated']++;
            $result['updatedIds'][] = $draftId;
        }

        $missingIds = array_values(array_diff($draftIds, array_map(static function ($row) {
            return (int)$row['id'];
        }, $this->draftRows($draftIds))));
        foreach ($missingIds as $missingId) {
            $result['skipped'][] = ['id' => $missingId, 'reason' => 'draft not found'];
        }

        return $result;
    }

    public function isSupportedAction(string $action): bool
    {
        return in_array($action, [self::ACTION_SUBMIT, self::ACTION_APPROVE, self::ACTION_REJECT, self::ACTION_CANCEL], true);
    }

    public function targetStatus(string $action): string
    {
        $map = [
            self::ACTION_SUBMIT => SettlementDraftService::DRAFT_STATUS_SUBMITTED,
            self::ACTION_APPROVE => SettlementDraftService::DRAFT_STATUS_APPROVED,
            self::ACTION_REJECT => SettlementDraftService::DRAFT_STATUS_REJECTED,
            self::ACTION_CANCEL => SettlementDraftService::DRAFT_STATUS_CANCELLED,
        ];

        return $map[$action] ?? '';
    }

    public function transitionBlockReason(string $currentStatus, string $action): string
    {
        $allowed = [
            self::ACTION_SUBMIT => [SettlementDraftService::DRAFT_STATUS_DRAFT],
            self::ACTION_APPROVE => [SettlementDraftService::DRAFT_STATUS_SUBMITTED],
            self::ACTION_REJECT => [SettlementDraftService::DRAFT_STATUS_SUBMITTED],
            self::ACTION_CANCEL => [SettlementDraftService::DRAFT_STATUS_DRAFT, SettlementDraftService::DRAFT_STATUS_SUBMITTED, SettlementDraftService::DRAFT_STATUS_REJECTED],
        ];

        if (!isset($allowed[$action])) {
            return 'unsupported action';
        }
        if (!in_array($currentStatus, $allowed[$action], true)) {
            return 'invalid transition from ' . $currentStatus;
        }

        return '';
    }

    private function draftRows(array $draftIds): array
    {
        if (!$draftIds) {
            return [];
        }

        return (new \yii\db\Query())
            ->from('{{%mall_settlement_draft}}')
            ->where(['id' => $draftIds, 'status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    private function mergeRemark(string $oldRemark, string $action, string $remark): string
    {
        $marker = 'workflow ' . $action;
        if ($remark !== '') {
            $marker .= ': ' . $remark;
        }
        $combined = trim($oldRemark . ' | ' . $marker, ' |');

        return function_exists('mb_substr') ? mb_substr($combined, 0, 255, 'UTF-8') : substr($combined, 0, 255);
    }
}
