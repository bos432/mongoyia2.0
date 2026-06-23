<?php

namespace common\services\mall;

use Yii;
use yii\db\Query;

class DistributionSignoffPhase15Service
{
    public const VERSION = 'MONGOYIA_DISTRIBUTION_SIGNOFF_PHASE15_V1';

    public const TYPE_COMMISSION_RULE = 'commission_rule';
    public const TYPE_WITHDRAW_PAYOUT = 'withdraw_payout';
    public const TYPE_INVITE_REWARD = 'invite_reward';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT = 'reject';

    public function evidenceTypeLabels(): array
    {
        return [
            self::TYPE_COMMISSION_RULE => 'Commission Rule',
            self::TYPE_WITHDRAW_PAYOUT => 'Offline Withdrawal Payout',
            self::TYPE_INVITE_REWARD => 'Invite Reward',
        ];
    }

    public function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
        ];
    }

    public function evidenceRows(string $type = '', string $status = '', int $limit = 100): array
    {
        $query = (new Query())
            ->from('{{%mall_distribution_signoff_evidence}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(max(1, min(500, $limit)));
        if ($type !== '' && isset($this->evidenceTypeLabels()[$type])) {
            $query->andWhere(['evidence_type' => $type]);
        }
        if ($status !== '' && isset($this->statusLabels()[$status])) {
            $query->andWhere(['signoff_status' => $status]);
        }

        return $query->all(Yii::$app->db);
    }

    public function summary(): array
    {
        return (new Query())
            ->select([
                'evidence_type',
                'signoff_status',
                'rows' => 'COUNT(*)',
                'amount' => 'SUM(amount)',
            ])
            ->from('{{%mall_distribution_signoff_evidence}}')
            ->where(['status' => 1])
            ->groupBy(['evidence_type', 'signoff_status'])
            ->orderBy(['evidence_type' => SORT_ASC, 'signoff_status' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    public function saveEvidence(array $data, bool $apply = true, int $actorId = 1): array
    {
        $payload = [
            'evidence_type' => $this->validEvidenceType((string)($data['evidence_type'] ?? self::TYPE_WITHDRAW_PAYOUT)),
            'reference_type' => $this->clean((string)($data['reference_type'] ?? 'manual'), 48),
            'reference_id' => max(0, (int)($data['reference_id'] ?? 0)),
            'distributor_user_id' => max(0, (int)($data['distributor_user_id'] ?? 0)),
            'amount' => round((float)($data['amount'] ?? 0), 2),
            'evidence_title' => $this->clean((string)($data['evidence_title'] ?? ''), 160),
            'evidence_url' => $this->clean((string)($data['evidence_url'] ?? ''), 255),
            'evidence_note' => $this->cleanText((string)($data['evidence_note'] ?? '')),
            'signoff_status' => self::STATUS_PENDING,
            'reviewer_role' => $this->clean((string)($data['reviewer_role'] ?? ''), 64),
            'reviewed_at' => 0,
            'reviewed_by' => 0,
            'review_remark' => '',
            'updated_at' => time(),
            'updated_by' => $actorId,
        ];

        $result = [
            'apply' => $apply,
            'id' => 0,
            'created' => 0,
            'skippedReason' => '',
            'evidence' => $payload,
        ];
        if ($payload['evidence_title'] === '') {
            $result['skippedReason'] = 'evidence title required';
            return $result;
        }
        if ($payload['evidence_url'] === '' && $payload['evidence_note'] === '') {
            $result['skippedReason'] = 'evidence url or note required';
            return $result;
        }
        if (!$apply) {
            return $result;
        }

        Yii::$app->db->createCommand()->insert('{{%mall_distribution_signoff_evidence}}', $payload + [
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'created_by' => $actorId,
        ])->execute();
        $result['id'] = (int)Yii::$app->db->getLastInsertID();
        $result['created'] = 1;
        return $result;
    }

    public function reviewEvidence(int $id, string $action, bool $apply = true, int $reviewerId = 1, string $remark = ''): array
    {
        $action = strtolower(trim($action));
        $row = $this->evidenceById($id);
        $result = [
            'apply' => $apply,
            'id' => $id,
            'action' => $action,
            'eligible' => 0,
            'updated' => 0,
            'skippedReason' => '',
        ];
        if (!$row) {
            $result['skippedReason'] = 'evidence not found';
            return $result;
        }
        if (!in_array($action, [self::ACTION_APPROVE, self::ACTION_REJECT], true)) {
            $result['skippedReason'] = 'unsupported action';
            return $result;
        }
        if ((string)$row['signoff_status'] !== self::STATUS_PENDING) {
            $result['skippedReason'] = 'invalid transition from ' . $row['signoff_status'];
            return $result;
        }

        $result['eligible'] = 1;
        if (!$apply) {
            return $result;
        }

        Yii::$app->db->createCommand()->update('{{%mall_distribution_signoff_evidence}}', [
            'signoff_status' => $action === self::ACTION_APPROVE ? self::STATUS_APPROVED : self::STATUS_REJECTED,
            'reviewed_at' => time(),
            'reviewed_by' => $reviewerId,
            'review_remark' => $this->clean($remark, 255),
            'updated_at' => time(),
            'updated_by' => $reviewerId,
        ], ['id' => $id])->execute();
        $result['updated'] = 1;
        return $result;
    }

    public function evidenceById(int $id): ?array
    {
        $row = (new Query())
            ->from('{{%mall_distribution_signoff_evidence}}')
            ->where(['id' => $id, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function validEvidenceType(string $type): string
    {
        return isset($this->evidenceTypeLabels()[$type]) ? $type : self::TYPE_WITHDRAW_PAYOUT;
    }

    private function clean(string $value, int $length): string
    {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    private function cleanText(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, 4000, 'UTF-8') : substr($value, 0, 4000);
    }
}
