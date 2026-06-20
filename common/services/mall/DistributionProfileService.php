<?php

namespace common\services\mall;

use Yii;

class DistributionProfileService
{
    const PROFILE_STATUS_PENDING = 'pending';
    const PROFILE_STATUS_APPROVED = 'approved';
    const PROFILE_STATUS_REJECTED = 'rejected';

    const MATERIAL_STATUS_ACTIVE = 'active';
    const MATERIAL_STATUS_DISABLED = 'disabled';

    const RISK_STATUS_OPEN = 'open';
    const RISK_STATUS_CLOSED = 'closed';

    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';
    const ACTION_CLOSE_RISK = 'close-risk';

    public function profile(int $userId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_distribution_profile}}')
            ->where(['distributor_user_id' => $userId, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    public function saveProfile(int $userId, array $data, bool $apply = true): array
    {
        $row = [
            'display_name' => $this->clean($data['display_name'] ?? '', 128),
            'contact_mobile' => $this->clean($data['contact_mobile'] ?? '', 64),
            'contact_email' => $this->clean($data['contact_email'] ?? '', 128),
            'channel' => $this->clean($data['channel'] ?? '', 128),
            'bio' => $this->clean($data['bio'] ?? '', 255),
        ];
        $result = [
            'apply' => $apply,
            'userId' => $userId,
            'created' => 0,
            'updated' => 0,
            'skippedReason' => '',
            'profile' => $row,
        ];

        if ($row['display_name'] === '') {
            $result['skippedReason'] = 'display name required';
            return $result;
        }
        if (!$apply) {
            return $result;
        }

        $existing = $this->profile($userId);
        $payload = $row + [
            'profile_status' => self::PROFILE_STATUS_PENDING,
            'audit_remark' => '',
            'audited_at' => 0,
            'audited_by' => 0,
            'updated_at' => time(),
            'updated_by' => $userId,
        ];

        if ($existing) {
            Yii::$app->db->createCommand()->update('{{%mall_distribution_profile}}', $payload, ['id' => (int)$existing['id']])->execute();
            $result['updated'] = 1;
            return $result;
        }

        Yii::$app->db->createCommand()->insert('{{%mall_distribution_profile}}', $payload + [
            'distributor_user_id' => $userId,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'created_by' => $userId,
        ])->execute();
        $result['created'] = 1;
        return $result;
    }

    public function auditProfile(int $profileId, string $action, bool $apply = true, int $auditorId = 1, string $remark = ''): array
    {
        $action = strtolower(trim($action));
        $row = $this->profileById($profileId);
        $result = [
            'apply' => $apply,
            'profileId' => $profileId,
            'action' => $action,
            'eligible' => 0,
            'updated' => 0,
            'skippedReason' => '',
        ];
        if (!$row) {
            $result['skippedReason'] = 'profile not found';
            return $result;
        }
        if (!in_array($action, [self::ACTION_APPROVE, self::ACTION_REJECT], true)) {
            $result['skippedReason'] = 'unsupported action';
            return $result;
        }
        if ((string)$row['profile_status'] !== self::PROFILE_STATUS_PENDING) {
            $result['skippedReason'] = 'invalid transition from ' . $row['profile_status'];
            return $result;
        }

        $result['eligible'] = 1;
        if (!$apply) {
            return $result;
        }

        Yii::$app->db->createCommand()->update('{{%mall_distribution_profile}}', [
            'profile_status' => $action === self::ACTION_APPROVE ? self::PROFILE_STATUS_APPROVED : self::PROFILE_STATUS_REJECTED,
            'audit_remark' => $this->clean($remark, 255),
            'audited_at' => time(),
            'audited_by' => $auditorId,
            'updated_at' => time(),
            'updated_by' => $auditorId,
        ], ['id' => $profileId])->execute();

        $result['updated'] = 1;
        return $result;
    }

    public function materials(int $limit = 20): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_material}}')
            ->where(['material_status' => self::MATERIAL_STATUS_ACTIVE, 'status' => 1])
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_DESC])
            ->limit(max(1, $limit))
            ->all(Yii::$app->db);
    }

    public function risks(int $userId = 0, int $limit = 50): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_distribution_risk}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(max(1, $limit));
        if ($userId > 0) {
            $query->andWhere(['distributor_user_id' => $userId]);
        }

        return $query->all(Yii::$app->db);
    }

    public function closeRisk(int $riskId, bool $apply = true, int $handlerId = 1): array
    {
        $row = $this->riskById($riskId);
        $result = [
            'apply' => $apply,
            'riskId' => $riskId,
            'eligible' => 0,
            'updated' => 0,
            'skippedReason' => '',
        ];
        if (!$row) {
            $result['skippedReason'] = 'risk not found';
            return $result;
        }
        if ((string)$row['risk_status'] !== self::RISK_STATUS_OPEN) {
            $result['skippedReason'] = 'invalid transition from ' . $row['risk_status'];
            return $result;
        }

        $result['eligible'] = 1;
        if (!$apply) {
            return $result;
        }

        Yii::$app->db->createCommand()->update('{{%mall_distribution_risk}}', [
            'risk_status' => self::RISK_STATUS_CLOSED,
            'handled_at' => time(),
            'handled_by' => $handlerId,
            'updated_at' => time(),
            'updated_by' => $handlerId,
        ], ['id' => $riskId])->execute();

        $result['updated'] = 1;
        return $result;
    }

    private function profileById(int $profileId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_distribution_profile}}')
            ->where(['id' => $profileId, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function riskById(int $riskId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_distribution_risk}}')
            ->where(['id' => $riskId, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function clean(string $value, int $length): string
    {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
