<?php

namespace common\services\mall;

use Yii;

class CustomerServiceRatingService
{
    private $ratings = ['satisfied' => 3, 'neutral' => 2, 'dissatisfied' => 1];

    public function labels(): array
    {
        return [
            'satisfied' => '满意',
            'neutral' => '一般',
            'dissatisfied' => '不满意',
        ];
    }

    public function submit(array $input): array
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('Customer-service rating table missing.');
        }

        $chatUuid = trim((string)($input['chat_uuid'] ?? ''));
        $customerUuid = trim((string)($input['customer_uuid'] ?? ''));
        $rating = (string)($input['rating'] ?? '');
        if ($chatUuid === '' || $customerUuid === '') {
            throw new \RuntimeException('Chat session is required.');
        }
        if (!isset($this->ratings[$rating])) {
            throw new \RuntimeException('Rating is required.');
        }

        $exists = (new \yii\db\Query())
            ->from('{{%mall_customer_service_rating}}')
            ->where([
                'chat_uuid' => $chatUuid,
                'customer_uuid' => $customerUuid,
                'status' => 1,
            ])
            ->exists(Yii::$app->db);
        if ($exists) {
            throw new \RuntimeException('This chat session has already been rated.');
        }

        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_rating}}', [
            'store_id' => max(0, (int)($input['store_id'] ?? 0)),
            'product_id' => max(0, (int)($input['product_id'] ?? 0)),
            'order_id' => max(0, (int)($input['order_id'] ?? 0)),
            'ticket_id' => max(0, (int)($input['ticket_id'] ?? 0)),
            'customer_user_id' => max(0, (int)($input['customer_user_id'] ?? 0)),
            'customer_uuid' => $customerUuid,
            'chat_uuid' => $chatUuid,
            'rating' => $rating,
            'rating_score' => $this->ratings[$rating],
            'reason' => $this->limitText(trim((string)($input['reason'] ?? '')), 255),
            'remark' => $this->limitText(trim((string)($input['remark'] ?? '')), 1000),
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => max(0, (int)($input['customer_user_id'] ?? 0)),
            'updated_by' => max(0, (int)($input['customer_user_id'] ?? 0)),
        ])->execute();

        return [
            'id' => (int)Yii::$app->db->getLastInsertID(),
            'rating' => $rating,
            'rating_score' => $this->ratings[$rating],
        ];
    }

    public function rowsForTicket(array $ticket): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_rating}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(20);

        $or = ['or'];
        if (!empty($ticket['ticket_id'])) {
            $or[] = ['ticket_id' => (int)$ticket['ticket_id']];
        }
        if (!empty($ticket['id'])) {
            $or[] = ['ticket_id' => (int)$ticket['id']];
        }
        if (!empty($ticket['chat_uuid'])) {
            $or[] = ['chat_uuid' => (string)$ticket['chat_uuid']];
        }
        if (!empty($ticket['order_id'])) {
            $or[] = ['order_id' => (int)$ticket['order_id']];
        }
        if (count($or) === 1) {
            return [];
        }

        $query->andWhere($or);
        if (!empty($ticket['store_id'])) {
            $query->andWhere(['store_id' => (int)$ticket['store_id']]);
        }

        return $query->all(Yii::$app->db);
    }

    public function existing(string $chatUuid, string $customerUuid): array
    {
        if (!$this->tableExists() || $chatUuid === '' || $customerUuid === '') {
            return [];
        }

        return (new \yii\db\Query())
            ->from('{{%mall_customer_service_rating}}')
            ->where([
                'chat_uuid' => $chatUuid,
                'customer_uuid' => $customerUuid,
                'status' => 1,
            ])
            ->one(Yii::$app->db) ?: [];
    }

    private function tableExists(): bool
    {
        return Yii::$app->db->schema->getTableSchema('{{%mall_customer_service_rating}}', true) !== null;
    }

    private function limitText(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }
}
