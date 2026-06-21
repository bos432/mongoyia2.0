<?php

namespace common\services\mall;

use Yii;

class CustomerServiceQuickReplyService
{
    private $categories = ['order', 'logistics', 'payment', 'refund', 'complaint', 'presale'];

    public function categories(): array
    {
        return $this->categories;
    }

    public function categoryLabels(): array
    {
        return [
            'order' => '订单',
            'logistics' => '物流',
            'payment' => '支付',
            'refund' => '退款',
            'complaint' => '投诉',
            'presale' => '售前',
        ];
    }

    public function rows(int $scopeStoreId = 0, array $filters = [], int $limit = 200): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_quick_reply}}')
            ->where(['status' => 1])
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_DESC])
            ->limit(max(1, min(500, $limit)));
        if ($scopeStoreId > 0) {
            $query->andWhere(['or', ['store_id' => 0], ['store_id' => $scopeStoreId]]);
        }
        if (!empty($filters['category'])) {
            $query->andWhere(['category' => $this->normalizeCategory((string)$filters['category'])]);
        }
        if (!empty($filters['keyword'])) {
            $keyword = trim((string)$filters['keyword']);
            $query->andWhere(['or', ['like', 'title', $keyword], ['like', 'content', $keyword]]);
        }

        return $query->all(Yii::$app->db);
    }

    public function workbenchRows(int $scopeStoreId = 0): array
    {
        $rows = [];
        foreach ($this->rows($scopeStoreId, [], 500) as $row) {
            $rows[] = [
                'id' => (int)$row['id'],
                'store_id' => (int)$row['store_id'],
                'is_global' => (int)$row['is_global'],
                'category' => (string)$row['category'],
                'title' => (string)$row['title'],
                'content' => (string)$row['content'],
            ];
        }

        return $rows;
    }

    public function save(array $input, bool $isPlatformOperator, int $scopeStoreId, int $operatorId): int
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('Quick reply table missing.');
        }

        $id = max(0, (int)($input['id'] ?? 0));
        $isGlobal = !empty($input['is_global']) ? 1 : 0;
        $storeId = $isPlatformOperator ? max(0, (int)($input['store_id'] ?? 0)) : max(0, $scopeStoreId);
        if (!$isPlatformOperator) {
            $isGlobal = 0;
        }
        if ($isGlobal) {
            $storeId = 0;
        }
        if (!$isGlobal && $storeId <= 0) {
            throw new \RuntimeException('Store id is required for store quick replies.');
        }

        $title = trim((string)($input['title'] ?? ''));
        $content = trim((string)($input['content'] ?? ''));
        if ($title === '' || $content === '') {
            throw new \RuntimeException('Title and content are required.');
        }

        $now = time();
        $data = [
            'store_id' => $storeId,
            'is_global' => $isGlobal,
            'category' => $this->normalizeCategory((string)($input['category'] ?? '')),
            'title' => $this->limitText($title, 120),
            'content' => $this->limitText($content, 1000),
            'sort' => max(0, min(9999, (int)($input['sort'] ?? 50))),
            'updated_at' => $now,
            'updated_by' => $operatorId,
        ];

        if ($id > 0) {
            $old = $this->row($id, $scopeStoreId, $isPlatformOperator);
            if (!$old) {
                throw new \RuntimeException('Quick reply not found or out of scope.');
            }
            Yii::$app->db->createCommand()->update('{{%mall_customer_service_quick_reply}}', $data, [
                'id' => $id,
                'status' => 1,
            ])->execute();

            return $id;
        }

        $data = array_merge($data, [
            'status' => 1,
            'created_at' => $now,
            'created_by' => $operatorId,
        ]);
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_quick_reply}}', $data)->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    public function delete(int $id, bool $isPlatformOperator, int $scopeStoreId, int $operatorId): void
    {
        $row = $this->row($id, $scopeStoreId, $isPlatformOperator);
        if (!$row) {
            throw new \RuntimeException('Quick reply not found or out of scope.');
        }

        Yii::$app->db->createCommand()->update('{{%mall_customer_service_quick_reply}}', [
            'status' => 0,
            'updated_at' => time(),
            'updated_by' => $operatorId,
        ], [
            'id' => $id,
            'status' => 1,
        ])->execute();
    }

    private function row(int $id, int $scopeStoreId, bool $isPlatformOperator): array
    {
        if ($id <= 0 || !$this->tableExists()) {
            return [];
        }
        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_quick_reply}}')
            ->where(['id' => $id, 'status' => 1]);
        if (!$isPlatformOperator) {
            $query->andWhere(['store_id' => max(0, $scopeStoreId)]);
        }

        return $query->one(Yii::$app->db) ?: [];
    }

    private function normalizeCategory(string $category): string
    {
        $category = trim($category);
        return in_array($category, $this->categories, true) ? $category : 'order';
    }

    private function limitText(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }

    private function tableExists(): bool
    {
        return Yii::$app->db->schema->getTableSchema('{{%mall_customer_service_quick_reply}}', true) !== null;
    }
}
