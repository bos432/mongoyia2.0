<?php

namespace common\services\mall;

use Yii;

class SettlementCloseService
{
    public function run(array $draftIds, bool $apply = false, string $remark = ''): array
    {
        $draftIds = array_values(array_unique(array_filter(array_map('intval', $draftIds))));
        $result = [
            'apply' => $apply,
            'scanned' => 0,
            'eligible' => 0,
            'closed' => 0,
            'dryRunIds' => [],
            'closedIds' => [],
            'skipped' => [],
            'totals' => [
                'order_count' => 0,
                'order_amount' => 0.0,
                'shipment_fee_deducted' => 0.0,
                'net_amount' => 0.0,
            ],
        ];

        foreach ($this->draftRows($draftIds) as $draft) {
            $result['scanned']++;
            $draftId = (int)$draft['id'];
            $reason = $this->blockReason($draft);
            if ($reason !== '') {
                $result['skipped'][] = ['id' => $draftId, 'reason' => $reason];
                continue;
            }

            $result['eligible']++;
            $result['totals']['order_count'] += (int)$draft['order_count'];
            $result['totals']['order_amount'] += (float)$draft['order_amount'];
            $result['totals']['shipment_fee_deducted'] += (float)$draft['shipment_fee_deducted'];
            $result['totals']['net_amount'] += (float)$draft['net_amount'];
            if (!$apply) {
                $result['dryRunIds'][] = $draftId;
                continue;
            }

            Yii::$app->db->createCommand()->update('{{%mall_settlement_draft}}', [
                'draft_status' => SettlementDraftService::DRAFT_STATUS_CLOSED,
                'remark' => $this->mergeRemark((string)$draft['remark'], $remark),
                'updated_at' => time(),
                'updated_by' => 1,
            ], ['id' => $draftId])->execute();
            $result['closed']++;
            $result['closedIds'][] = $draftId;
        }

        $foundIds = array_map(static function ($row) {
            return (int)$row['id'];
        }, $this->draftRows($draftIds));
        foreach (array_values(array_diff($draftIds, $foundIds)) as $missingId) {
            $result['skipped'][] = ['id' => $missingId, 'reason' => 'draft not found'];
        }

        foreach (['order_amount', 'shipment_fee_deducted', 'net_amount'] as $key) {
            $result['totals'][$key] = round((float)$result['totals'][$key], 2);
        }

        return $result;
    }

    public function blockReason(array $draft): string
    {
        $status = (string)$draft['draft_status'];
        if ($status === SettlementDraftService::DRAFT_STATUS_CLOSED) {
            return 'draft already closed';
        }
        if ($status !== SettlementDraftService::DRAFT_STATUS_APPROVED) {
            return 'draft is not approved';
        }
        if (!$this->hasPayoutEvidence((int)$draft['id'])) {
            return 'payout evidence is required';
        }

        return '';
    }

    public function hasPayoutEvidence(int $draftId): bool
    {
        return (new \yii\db\Query())
            ->from('{{%mall_settlement_payout_evidence}}')
            ->where(['draft_id' => $draftId, 'status' => 1])
            ->exists(Yii::$app->db);
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

    private function mergeRemark(string $oldRemark, string $remark): string
    {
        $marker = 'settlement closed';
        if ($remark !== '') {
            $marker .= ': ' . $remark;
        }
        $combined = trim($oldRemark . ' | ' . $marker, ' |');

        return function_exists('mb_substr') ? mb_substr($combined, 0, 255, 'UTF-8') : substr($combined, 0, 255);
    }
}
