<?php

namespace common\services\mall;

use Yii;
use yii\db\Query;

class DistributionMaterialPhase15Service
{
    public const VERSION = 'MONGOYIA_DISTRIBUTION_MATERIAL_PHASE15_V1';

    public const ACTION_COPY = 'copy';
    public const ACTION_DOWNLOAD = 'download';

    public function visibleMaterials(string $language = '', int $limit = 50): array
    {
        $language = $this->normalizeLanguage($language);
        $rows = $this->materials('', $language, true, $limit);
        if (!empty($rows)) {
            return $rows;
        }
        if ($language !== DistributionSupportContentService::LANG_ZH) {
            $rows = $this->materials('', DistributionSupportContentService::LANG_ZH, true, $limit);
            if (!empty($rows)) {
                return $rows;
            }
        }

        return $this->materials('', '', true, $limit);
    }

    public function materials(string $type = '', string $language = '', bool $activeOnly = true, int $limit = 100): array
    {
        $query = (new Query())
            ->from('{{%mall_distribution_material}}')
            ->where(['status' => 1])
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_DESC])
            ->limit(max(1, min(500, $limit)));

        if ($type !== '') {
            $query->andWhere(['material_type' => $this->clean($type, 32)]);
        }
        if ($language !== '' && $this->hasMaterialColumn('language')) {
            $query->andWhere(['language' => $this->normalizeLanguage($language)]);
        }
        if ($activeOnly) {
            $query->andWhere(['material_status' => DistributionProfileService::MATERIAL_STATUS_ACTIVE]);
        }

        return $query->all(Yii::$app->db);
    }

    public function saveMaterial(array $data, bool $apply = true, int $actorId = 1): array
    {
        $id = (int)($data['id'] ?? 0);
        $payload = [
            'title' => $this->clean((string)($data['title'] ?? ''), 128),
            'content' => $this->cleanText((string)($data['content'] ?? '')),
            'target_url' => $this->clean((string)($data['target_url'] ?? ''), 255),
            'material_type' => $this->clean((string)($data['material_type'] ?? 'text'), 32),
            'material_status' => $this->validStatus((string)($data['material_status'] ?? DistributionProfileService::MATERIAL_STATUS_ACTIVE)),
            'remark' => $this->clean((string)($data['remark'] ?? ''), 255),
            'sort' => max(0, min(9999, (int)($data['sort'] ?? 50))),
            'updated_at' => time(),
            'updated_by' => $actorId,
        ];

        foreach ($this->phase15Payload($data) as $key => $value) {
            if ($this->hasMaterialColumn($key)) {
                $payload[$key] = $value;
            }
        }

        $result = [
            'apply' => $apply,
            'id' => $id,
            'created' => 0,
            'updated' => 0,
            'skippedReason' => '',
            'material' => $payload,
        ];

        if ($payload['title'] === '') {
            $result['skippedReason'] = 'title required';
            return $result;
        }
        if ($payload['content'] === '' && $payload['target_url'] === '' && (string)($payload['asset_url'] ?? '') === '') {
            $result['skippedReason'] = 'content, target url, or asset url required';
            return $result;
        }
        if (!$apply) {
            return $result;
        }

        if ($id > 0 && $this->materialById($id)) {
            Yii::$app->db->createCommand()->update('{{%mall_distribution_material}}', $payload, ['id' => $id])->execute();
            $result['updated'] = 1;
            return $result;
        }

        Yii::$app->db->createCommand()->insert('{{%mall_distribution_material}}', $payload + [
            'type' => 1,
            'status' => 1,
            'created_at' => time(),
            'created_by' => $actorId,
        ])->execute();
        $result['id'] = (int)Yii::$app->db->getLastInsertID();
        $result['created'] = 1;
        return $result;
    }

    public function disableMaterial(int $id, bool $apply = true, int $actorId = 1): array
    {
        $row = $this->materialById($id);
        $result = [
            'apply' => $apply,
            'id' => $id,
            'eligible' => 0,
            'updated' => 0,
            'skippedReason' => '',
        ];
        if (!$row) {
            $result['skippedReason'] = 'material not found';
            return $result;
        }
        if ((string)$row['material_status'] === DistributionProfileService::MATERIAL_STATUS_DISABLED) {
            $result['skippedReason'] = 'material already disabled';
            return $result;
        }

        $result['eligible'] = 1;
        if (!$apply) {
            return $result;
        }

        Yii::$app->db->createCommand()->update('{{%mall_distribution_material}}', [
            'material_status' => DistributionProfileService::MATERIAL_STATUS_DISABLED,
            'updated_at' => time(),
            'updated_by' => $actorId,
        ], ['id' => $id])->execute();
        $result['updated'] = 1;
        return $result;
    }

    public function recordAction(int $materialId, int $userId, string $actionType, string $channel = 'frontend', string $userAgent = '', bool $apply = true): array
    {
        $actionType = $actionType === self::ACTION_DOWNLOAD ? self::ACTION_DOWNLOAD : self::ACTION_COPY;
        $material = $this->materialById($materialId);
        $result = [
            'apply' => $apply,
            'materialId' => $materialId,
            'actionType' => $actionType,
            'eligible' => 0,
            'created' => 0,
            'skippedReason' => '',
            'redirectUrl' => '',
        ];
        if (!$material || (string)$material['material_status'] !== DistributionProfileService::MATERIAL_STATUS_ACTIVE) {
            $result['skippedReason'] = 'active material not found';
            return $result;
        }
        if ($actionType === self::ACTION_DOWNLOAD && $this->hasMaterialColumn('download_enabled') && (int)$material['download_enabled'] <= 0) {
            $result['skippedReason'] = 'download disabled';
            return $result;
        }

        $result['eligible'] = 1;
        $result['redirectUrl'] = $this->actionUrl($material, $actionType);
        if (!$apply) {
            return $result;
        }

        Yii::$app->db->createCommand()->insert('{{%mall_distribution_material_download_log}}', [
            'material_id' => $materialId,
            'distributor_user_id' => $userId,
            'language' => (string)($material['language'] ?? DistributionSupportContentService::LANG_ZH),
            'action_type' => $actionType,
            'channel' => $this->clean($channel, 64),
            'user_agent_hash' => $userAgent !== '' ? hash('sha256', substr($userAgent, 0, 255)) : '',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => $userId > 0 ? $userId : 1,
            'updated_by' => $userId > 0 ? $userId : 1,
        ])->execute();

        $counter = $actionType === self::ACTION_DOWNLOAD ? 'download_count' : 'copy_count';
        if ($this->hasMaterialColumn($counter)) {
            Yii::$app->db->createCommand()->update('{{%mall_distribution_material}}', [
                $counter => new \yii\db\Expression($counter . ' + 1'),
                'updated_at' => time(),
                'updated_by' => $userId > 0 ? $userId : 1,
            ], ['id' => $materialId])->execute();
        }

        $result['created'] = 1;
        return $result;
    }

    public function materialById(int $id): ?array
    {
        $row = (new Query())
            ->from('{{%mall_distribution_material}}')
            ->where(['id' => $id, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    public function normalizeLanguage(string $language): string
    {
        return (new DistributionSupportContentService())->normalizeLanguage($language);
    }

    private function phase15Payload(array $data): array
    {
        return [
            'language' => $this->normalizeLanguage((string)($data['language'] ?? DistributionSupportContentService::LANG_ZH)),
            'asset_url' => $this->clean((string)($data['asset_url'] ?? ''), 255),
            'qr_code_url' => $this->clean((string)($data['qr_code_url'] ?? ''), 255),
            'download_enabled' => !empty($data['download_enabled']) ? 1 : 0,
        ];
    }

    private function validStatus(string $status): string
    {
        return $status === DistributionProfileService::MATERIAL_STATUS_DISABLED
            ? DistributionProfileService::MATERIAL_STATUS_DISABLED
            : DistributionProfileService::MATERIAL_STATUS_ACTIVE;
    }

    private function actionUrl(array $material, string $actionType): string
    {
        if ($actionType === self::ACTION_DOWNLOAD && (string)($material['asset_url'] ?? '') !== '') {
            return (string)$material['asset_url'];
        }
        if ((string)($material['target_url'] ?? '') !== '') {
            return (string)$material['target_url'];
        }

        return (string)($material['asset_url'] ?? '');
    }

    private function hasMaterialColumn(string $column): bool
    {
        $table = Yii::$app->db->schema->getTableSchema('{{%mall_distribution_material}}');
        return $table !== null && isset($table->columns[$column]);
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
