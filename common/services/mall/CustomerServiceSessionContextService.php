<?php

namespace common\services\mall;

use Yii;
use yii\db\Query;

class CustomerServiceSessionContextService
{
    public const VERSION = 'MONGOYIA_CUSTOMER_SERVICE_SESSION_CONTEXT_V1';

    public function build(array $input, int $scopeStoreId = 0): array
    {
        $context = $this->normalizeInput($input);
        if ($context['order_id'] <= 0) {
            $context['order_id'] = $this->inferOrderId($context);
        }
        if ($context['product_id'] <= 0 || $context['store_id'] <= 0 || $context['customer_user_id'] <= 0) {
            $context = array_merge($context, $this->inferFromChat($context));
        }

        if ($scopeStoreId > 0 && $context['store_id'] > 0 && $context['store_id'] !== $scopeStoreId) {
            return $this->forbiddenResponse($context, $scopeStoreId);
        }

        $order = $this->orderSummary($context['order_id'], $scopeStoreId);
        if ($order) {
            $context['store_id'] = $context['store_id'] ?: (int)$order['store_id'];
            $context['customer_user_id'] = $context['customer_user_id'] ?: (int)$order['user_id'];
        }

        return [
            'version' => self::VERSION,
            'scope_store_id' => $scopeStoreId,
            'context' => $context,
            'user' => $this->userSummary($context['customer_user_id']),
            'product' => $this->productSummary($context['product_id'], $scopeStoreId),
            'order' => $order,
            'tickets' => $this->ticketSummaries($context, $scopeStoreId),
            'boundaries' => [
                'read_only' => true,
                'order_mutation_allowed' => false,
                'payment_mutation_allowed' => false,
                'fund_mutation_allowed' => false,
                'stock_mutation_allowed' => false,
            ],
        ];
    }

    public function normalizeInput(array $input): array
    {
        return [
            'chat_uuid' => trim((string)($input['chat_uuid'] ?? $input['uuid'] ?? '')),
            'customer_uuid' => trim((string)($input['customer_uuid'] ?? $input['uuid'] ?? '')),
            'product_id' => max(0, (int)($input['product_id'] ?? 0)),
            'store_id' => max(0, (int)($input['store_id'] ?? 0)),
            'order_id' => max(0, (int)($input['order_id'] ?? 0)),
            'customer_user_id' => max(0, (int)($input['customer_user_id'] ?? $input['user_id'] ?? 0)),
        ];
    }

    private function inferFromChat(array $context): array
    {
        $fallback = [
            'product_id' => $context['product_id'],
            'store_id' => $context['store_id'],
            'customer_user_id' => $context['customer_user_id'],
        ];
        if (!$this->tableExists('{{%chat}}')) {
            return $fallback;
        }

        $uuid = $context['customer_uuid'] ?: $context['chat_uuid'];
        if ($uuid === '') {
            return $fallback;
        }

        $row = (new Query())
            ->from('{{%chat}}')
            ->where(['uuid' => $uuid])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db);
        if (!$row) {
            return $fallback;
        }

        $fallback['product_id'] = $fallback['product_id'] ?: (int)($row['product_id'] ?? 0);
        $fallback['store_id'] = $fallback['store_id'] ?: (int)($row['store_id'] ?? 0);
        $fallback['customer_user_id'] = $fallback['customer_user_id'] ?: (int)($row['user_id'] ?? 0);

        return $fallback;
    }

    private function inferOrderId(array $context): int
    {
        if (!$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return 0;
        }

        $query = (new Query())
            ->select('order_id')
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['status' => 1])
            ->andWhere(['>', 'order_id', 0])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1);
        if ($context['chat_uuid'] !== '') {
            $query->andWhere(['chat_uuid' => $context['chat_uuid']]);
        } elseif ($context['customer_uuid'] !== '') {
            $query->andWhere(['customer_uuid' => $context['customer_uuid']]);
        } elseif ($context['customer_user_id'] > 0) {
            $query->andWhere(['customer_user_id' => $context['customer_user_id']]);
        } else {
            return 0;
        }

        return (int)$query->scalar(Yii::$app->db);
    }

    private function userSummary(int $userId): array
    {
        if ($userId <= 0 || !$this->tableExists('{{%user}}')) {
            return [];
        }

        $row = (new Query())
            ->select(['id', 'username', 'name', 'email', 'mobile', 'avatar', 'last_login_at', 'consume_count', 'consume_amount'])
            ->from('{{%user}}')
            ->where(['id' => $userId])
            ->one(Yii::$app->db);

        return $row ? [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'mobile' => (string)$row['mobile'],
            'avatar' => (string)$row['avatar'],
            'last_login_at' => (int)$row['last_login_at'],
            'consume_count' => (int)$row['consume_count'],
            'consume_amount' => (float)$row['consume_amount'],
        ] : [];
    }

    private function productSummary(int $productId, int $scopeStoreId): array
    {
        if ($productId <= 0 || !$this->tableExists('{{%mall_product}}')) {
            return [];
        }

        $query = (new Query())
            ->select(['id', 'store_id', 'name', 'sku', 'thumb', 'price', 'stock', 'status'])
            ->from('{{%mall_product}}')
            ->where(['id' => $productId]);
        if ($scopeStoreId > 0) {
            $query->andWhere(['store_id' => $scopeStoreId]);
        }

        $row = $query->one(Yii::$app->db);

        return $row ? [
            'id' => (int)$row['id'],
            'store_id' => (int)$row['store_id'],
            'name' => (string)$row['name'],
            'sku' => (string)$row['sku'],
            'thumb' => (string)$row['thumb'],
            'price' => (float)$row['price'],
            'stock' => (int)$row['stock'],
            'status' => (int)$row['status'],
        ] : [];
    }

    private function orderSummary(int $orderId, int $scopeStoreId): array
    {
        if ($orderId <= 0 || !$this->tableExists('{{%mall_order}}')) {
            return [];
        }

        $query = (new Query())
            ->select(['id', 'store_id', 'user_id', 'sn', 'name', 'amount', 'product_amount', 'payment_status', 'shipment_status', 'mobile', 'email', 'created_at'])
            ->from('{{%mall_order}}')
            ->where(['id' => $orderId]);
        if ($scopeStoreId > 0) {
            $query->andWhere(['store_id' => $scopeStoreId]);
        }

        $row = $query->one(Yii::$app->db);

        return $row ? [
            'id' => (int)$row['id'],
            'store_id' => (int)$row['store_id'],
            'user_id' => (int)$row['user_id'],
            'sn' => (string)$row['sn'],
            'name' => (string)$row['name'],
            'amount' => (float)$row['amount'],
            'product_amount' => (float)$row['product_amount'],
            'payment_status' => (int)$row['payment_status'],
            'shipment_status' => (int)$row['shipment_status'],
            'mobile' => (string)$row['mobile'],
            'email' => (string)$row['email'],
            'created_at' => (int)$row['created_at'],
        ] : [];
    }

    private function ticketSummaries(array $context, int $scopeStoreId): array
    {
        if (!$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return [];
        }

        $query = (new Query())
            ->select(['id', 'ticket_sn', 'ticket_type', 'ticket_status', 'priority', 'store_id', 'order_id', 'title', 'result', 'created_at', 'updated_at'])
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(10);
        if ($scopeStoreId > 0) {
            $query->andWhere(['store_id' => $scopeStoreId]);
        } elseif ($context['store_id'] > 0) {
            $query->andWhere(['store_id' => $context['store_id']]);
        }

        $or = ['or'];
        if ($context['order_id'] > 0) {
            $or[] = ['order_id' => $context['order_id']];
        }
        if ($context['chat_uuid'] !== '') {
            $or[] = ['chat_uuid' => $context['chat_uuid']];
        }
        if ($context['customer_uuid'] !== '') {
            $or[] = ['customer_uuid' => $context['customer_uuid']];
        }
        if ($context['customer_user_id'] > 0) {
            $or[] = ['customer_user_id' => $context['customer_user_id']];
        }
        if (count($or) > 1) {
            $query->andWhere($or);
        } else {
            return [];
        }

        return array_map(static function ($row) {
            return [
                'id' => (int)$row['id'],
                'ticket_sn' => (string)$row['ticket_sn'],
                'ticket_type' => (string)$row['ticket_type'],
                'ticket_status' => (string)$row['ticket_status'],
                'priority' => (string)$row['priority'],
                'store_id' => (int)$row['store_id'],
                'order_id' => (int)$row['order_id'],
                'title' => (string)$row['title'],
                'result' => (string)$row['result'],
                'created_at' => (int)$row['created_at'],
                'updated_at' => (int)$row['updated_at'],
            ];
        }, $query->all(Yii::$app->db));
    }

    private function forbiddenResponse(array $context, int $scopeStoreId): array
    {
        return [
            'version' => self::VERSION,
            'scope_store_id' => $scopeStoreId,
            'context' => $context,
            'user' => [],
            'product' => [],
            'order' => [],
            'tickets' => [],
            'error' => 'Store scope denied for customer-service session context.',
            'boundaries' => [
                'read_only' => true,
                'order_mutation_allowed' => false,
                'payment_mutation_allowed' => false,
                'fund_mutation_allowed' => false,
                'stock_mutation_allowed' => false,
            ],
        ];
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
