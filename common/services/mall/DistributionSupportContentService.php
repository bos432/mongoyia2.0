<?php

namespace common\services\mall;

use Yii;
use yii\db\Query;

class DistributionSupportContentService
{
    public const VERSION = 'MONGOYIA_DISTRIBUTION_SUPPORT_CONTENT_PHASE15_V1';

    public const TYPE_TRAINING = 'training';
    public const TYPE_FAQ = 'faq';
    public const TYPE_RULE = 'rule';
    public const TYPE_SUPPORT = 'support';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const LANG_ZH = 'zh-CN';
    public const LANG_EN = 'en';
    public const LANG_MN = 'mn';

    public function typeLabels(): array
    {
        return [
            self::TYPE_TRAINING => 'Training',
            self::TYPE_FAQ => 'FAQ',
            self::TYPE_RULE => 'Platform Rule',
            self::TYPE_SUPPORT => 'Support Entry',
        ];
    }

    public function languageLabels(): array
    {
        return [
            self::LANG_ZH => '中文',
            self::LANG_EN => 'English',
            self::LANG_MN => 'Монгол',
        ];
    }

    public function statusLabels(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_DISABLED => 'Disabled',
        ];
    }

    public function contents(string $type = '', string $language = '', bool $activeOnly = true, int $limit = 100): array
    {
        $query = (new Query())
            ->from('{{%mall_distribution_support_content}}')
            ->where(['status' => 1])
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_DESC])
            ->limit(max(1, min(500, $limit)));

        if ($type !== '' && isset($this->typeLabels()[$type])) {
            $query->andWhere(['content_type' => $type]);
        }
        if ($language !== '') {
            $query->andWhere(['language' => $this->normalizeLanguage($language)]);
        }
        if ($activeOnly) {
            $query->andWhere(['content_status' => self::STATUS_ACTIVE]);
        }

        return $query->all(Yii::$app->db);
    }

    public function visibleForDistributor(string $language = '', int $limit = 50): array
    {
        $language = $this->normalizeLanguage($language);
        $rows = $this->contents('', $language, true, $limit);
        if (!empty($rows)) {
            return $rows;
        }
        if ($language !== self::LANG_ZH) {
            $rows = $this->contents('', self::LANG_ZH, true, $limit);
            if (!empty($rows)) {
                return $rows;
            }
        }

        return $this->contents('', '', true, $limit);
    }

    public function saveContent(array $data, bool $apply = true, int $actorId = 1): array
    {
        $id = (int)($data['id'] ?? 0);
        $payload = [
            'content_type' => $this->validType((string)($data['content_type'] ?? self::TYPE_TRAINING)),
            'language' => $this->normalizeLanguage((string)($data['language'] ?? self::LANG_ZH)),
            'category' => $this->clean((string)($data['category'] ?? ''), 64),
            'title' => $this->clean((string)($data['title'] ?? ''), 160),
            'body' => $this->cleanText((string)($data['body'] ?? '')),
            'support_url' => $this->clean((string)($data['support_url'] ?? ''), 255),
            'content_status' => $this->validStatus((string)($data['content_status'] ?? self::STATUS_ACTIVE)),
            'sort' => max(0, min(9999, (int)($data['sort'] ?? 50))),
            'updated_at' => time(),
            'updated_by' => $actorId,
        ];

        $result = [
            'apply' => $apply,
            'id' => $id,
            'created' => 0,
            'updated' => 0,
            'skippedReason' => '',
            'content' => $payload,
        ];

        if ($payload['title'] === '') {
            $result['skippedReason'] = 'title required';
            return $result;
        }
        if ($payload['body'] === '' && $payload['support_url'] === '') {
            $result['skippedReason'] = 'body or support url required';
            return $result;
        }
        if (!$apply) {
            return $result;
        }

        if ($id > 0 && $this->contentById($id)) {
            Yii::$app->db->createCommand()->update('{{%mall_distribution_support_content}}', $payload, ['id' => $id])->execute();
            $result['updated'] = 1;
            return $result;
        }

        Yii::$app->db->createCommand()->insert('{{%mall_distribution_support_content}}', $payload + [
            'type' => 1,
            'status' => 1,
            'created_at' => time(),
            'created_by' => $actorId,
        ])->execute();
        $result['id'] = (int)Yii::$app->db->getLastInsertID();
        $result['created'] = 1;
        return $result;
    }

    public function disableContent(int $id, bool $apply = true, int $actorId = 1): array
    {
        $row = $this->contentById($id);
        $result = [
            'apply' => $apply,
            'id' => $id,
            'eligible' => 0,
            'updated' => 0,
            'skippedReason' => '',
        ];
        if (!$row) {
            $result['skippedReason'] = 'content not found';
            return $result;
        }
        if ((string)$row['content_status'] === self::STATUS_DISABLED) {
            $result['skippedReason'] = 'content already disabled';
            return $result;
        }

        $result['eligible'] = 1;
        if (!$apply) {
            return $result;
        }

        Yii::$app->db->createCommand()->update('{{%mall_distribution_support_content}}', [
            'content_status' => self::STATUS_DISABLED,
            'updated_at' => time(),
            'updated_by' => $actorId,
        ], ['id' => $id])->execute();
        $result['updated'] = 1;
        return $result;
    }

    public function contentById(int $id): ?array
    {
        $row = (new Query())
            ->from('{{%mall_distribution_support_content}}')
            ->where(['id' => $id, 'status' => 1])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    public function normalizeLanguage(string $language): string
    {
        $language = trim($language);
        if (stripos($language, 'mn') === 0) {
            return self::LANG_MN;
        }
        if (stripos($language, 'en') === 0) {
            return self::LANG_EN;
        }

        return self::LANG_ZH;
    }

    private function validType(string $type): string
    {
        return isset($this->typeLabels()[$type]) ? $type : self::TYPE_TRAINING;
    }

    private function validStatus(string $status): string
    {
        return isset($this->statusLabels()[$status]) ? $status : self::STATUS_ACTIVE;
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
