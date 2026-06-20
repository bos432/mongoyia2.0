<?php

namespace common\services\mall;

use Yii;

class SettlementPayoutEvidenceService
{
    const EVIDENCE_STATUS_RECORDED = 'recorded';

    public function run(int $draftId, float $amount, string $transactionNo, bool $apply = false, array $options = []): array
    {
        $transactionNo = trim($transactionNo);
        $result = [
            'apply' => $apply,
            'draftId' => $draftId,
            'eligible' => 0,
            'created' => 0,
            'amount' => round($amount, 2),
            'transactionNo' => $transactionNo,
            'evidenceId' => null,
            'skipped' => [],
            'draft' => null,
        ];

        $draft = $this->draftRow($draftId);
        if (!$draft) {
            $result['skipped'][] = ['id' => $draftId, 'reason' => 'draft not found'];
            return $result;
        }
        $result['draft'] = $draft;

        $reason = $this->blockReason($draft, $amount, $transactionNo);
        if ($reason !== '') {
            $result['skipped'][] = ['id' => $draftId, 'reason' => $reason];
            return $result;
        }

        $result['eligible'] = 1;
        if (!$apply) {
            return $result;
        }

        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_payout_evidence}}', [
            'store_id' => (int)$draft['store_id'],
            'draft_id' => (int)$draft['id'],
            'draft_sn' => (string)$draft['sn'],
            'amount' => round($amount, 2),
            'currency' => (string)($options['currency'] ?? 'MNT'),
            'channel' => (string)($options['channel'] ?? 'offline'),
            'transaction_no' => $transactionNo,
            'evidence_file' => (string)($options['evidenceFile'] ?? ''),
            'evidence_status' => self::EVIDENCE_STATUS_RECORDED,
            'remark' => $this->limitText((string)($options['remark'] ?? 'settlement payout evidence')),
            'recorded_at' => $now,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        $result['created'] = 1;
        $result['evidenceId'] = (int)Yii::$app->db->getLastInsertID();

        return $result;
    }

    public function blockReason(array $draft, float $amount, string $transactionNo): string
    {
        if ((string)$draft['draft_status'] !== SettlementDraftService::DRAFT_STATUS_APPROVED) {
            return 'draft is not approved';
        }
        if (round((float)$draft['net_amount'], 2) !== round($amount, 2)) {
            return 'amount mismatch';
        }
        if (trim($transactionNo) === '') {
            return 'transaction no is required';
        }
        if ($this->activeEvidenceExists((int)$draft['id'])) {
            return 'payout evidence already exists';
        }

        return '';
    }

    public function activeEvidenceExists(int $draftId): bool
    {
        return (new \yii\db\Query())
            ->from('{{%mall_settlement_payout_evidence}}')
            ->where(['draft_id' => $draftId, 'status' => 1])
            ->exists(Yii::$app->db);
    }

    public function draftRow(int $draftId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_settlement_draft}}')
            ->where(['id' => $draftId, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    public function evidenceRows(array $draftIds): array
    {
        $draftIds = array_values(array_unique(array_filter(array_map('intval', $draftIds))));
        if (!$draftIds) {
            return [];
        }

        return (new \yii\db\Query())
            ->from('{{%mall_settlement_payout_evidence}}')
            ->where(['draft_id' => $draftIds, 'status' => 1])
            ->indexBy('draft_id')
            ->all(Yii::$app->db);
    }

    private function limitText(string $text): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, 255, 'UTF-8') : substr($text, 0, 255);
    }
}
