<?php

namespace common\services\mall;

use Yii;

class CustomerServiceTicketCreateService
{
    public function run(
        array $context,
        string $ticketType,
        bool $apply = false,
        int $operatorId = 1,
        string $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
        int $scopeStoreId = 0
    ): array {
        $context = $this->normalizeContext($context);
        $ticketType = strtolower(trim($ticketType));
        $operatorId = max(1, $operatorId);
        $operatorType = $this->normalizeOperatorType($operatorType);
        $result = [
            'apply' => $apply,
            'ticketType' => $ticketType,
            'storeId' => $context['store_id'],
            'created' => 0,
            'ticketId' => 0,
            'ticketSn' => '',
            'dryRun' => false,
            'skipped' => [],
        ];

        $reason = $this->blockReason($context, $ticketType, $scopeStoreId);
        if ($reason !== '') {
            $result['skipped'][] = ['reason' => $reason];
            return $result;
        }

        $duplicate = $this->duplicateTicket($context, $ticketType);
        if ($duplicate) {
            $result['skipped'][] = ['reason' => 'active ticket already exists', 'ticketId' => (int)$duplicate['id']];
            return $result;
        }

        if (!$apply) {
            $result['dryRun'] = true;
            $result['ticketSn'] = $this->ticketSn($ticketType);
            return $result;
        }

        $now = time();
        $ticketSn = $this->ticketSn($ticketType);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            Yii::$app->db->createCommand()->insert('{{%mall_customer_service_ticket}}', [
                'ticket_sn' => $ticketSn,
                'ticket_type' => $ticketType,
                'ticket_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
                'priority' => $ticketType === CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT ? CustomerServiceAdvancedService::PRIORITY_HIGH : CustomerServiceAdvancedService::PRIORITY_NORMAL,
                'store_id' => $context['store_id'],
                'product_id' => $context['product_id'],
                'order_id' => $context['order_id'],
                'order_sn' => $context['order_sn'],
                'customer_user_id' => $context['customer_user_id'],
                'customer_uuid' => $context['customer_uuid'],
                'merchant_user_id' => $context['merchant_user_id'],
                'platform_user_id' => $operatorType === CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM ? $operatorId : $context['platform_user_id'],
                'chat_uuid' => $context['chat_uuid'],
                'title' => $context['title'],
                'content' => $context['content'],
                'result' => '',
                'evidence_json' => '{}',
                'first_response_at' => 0,
                'resolved_at' => 0,
                'closed_at' => 0,
                'remark' => $context['remark'],
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $operatorId,
                'updated_by' => $operatorId,
            ])->execute();

            $ticketId = (int)Yii::$app->db->getLastInsertID();
            $this->insertCreateEvent($ticketId, $operatorId, $operatorType, $context, $now);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $result['created'] = 1;
        $result['ticketId'] = $ticketId;
        $result['ticketSn'] = $ticketSn;

        return $result;
    }

    private function blockReason(array $context, string $ticketType, int $scopeStoreId): string
    {
        if (!$this->tableExists('{{%mall_customer_service_ticket}}') || !$this->tableExists('{{%mall_customer_service_event}}')) {
            return 'customer-service ticket schema missing';
        }
        if (!in_array($ticketType, (new CustomerServiceAdvancedService())->supportedTicketTypes(), true)) {
            return 'unsupported ticket type';
        }
        if ($context['store_id'] <= 0) {
            return 'store_id is required';
        }
        if ($scopeStoreId > 0 && $context['store_id'] !== $scopeStoreId) {
            return 'store scope mismatch';
        }
        if ($context['title'] === '') {
            return 'title is required';
        }

        return '';
    }

    private function duplicateTicket(array $context, string $ticketType): array
    {
        if ($context['order_id'] <= 0) {
            return [];
        }

        return (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where([
                'store_id' => $context['store_id'],
                'order_id' => $context['order_id'],
                'ticket_type' => $ticketType,
                'status' => 1,
            ])
            ->andWhere(['<>', 'ticket_status', CustomerServiceAdvancedService::TICKET_STATUS_CLOSED])
            ->orderBy(['id' => SORT_ASC])
            ->one(Yii::$app->db) ?: [];
    }

    private function insertCreateEvent(int $ticketId, int $operatorId, string $operatorType, array $context, int $now): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
            'ticket_id' => $ticketId,
            'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_CREATE,
            'from_status' => '',
            'to_status' => CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
            'operator_user_id' => $operatorId,
            'operator_type' => $operatorType,
            'content' => $context['content'] !== '' ? $context['content'] : 'Customer-service ticket created.',
            'metadata_json' => json_encode([
                'source' => $context['source'] ?: 'customer-service-ticket-create',
                'order_id' => $context['order_id'],
                'product_id' => $context['product_id'],
                'chat_uuid' => $context['chat_uuid'],
            ], JSON_UNESCAPED_SLASHES),
            'remark' => 'customer-service ticket create',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $operatorId,
            'updated_by' => $operatorId,
        ])->execute();
    }

    private function normalizeContext(array $context): array
    {
        return [
            'store_id' => (int)($context['store_id'] ?? 0),
            'product_id' => (int)($context['product_id'] ?? 0),
            'order_id' => (int)($context['order_id'] ?? 0),
            'order_sn' => $this->shortText((string)($context['order_sn'] ?? ''), 64),
            'customer_user_id' => (int)($context['customer_user_id'] ?? 0),
            'customer_uuid' => $this->shortText((string)($context['customer_uuid'] ?? ''), 128),
            'merchant_user_id' => (int)($context['merchant_user_id'] ?? 0),
            'platform_user_id' => (int)($context['platform_user_id'] ?? 0),
            'chat_uuid' => $this->shortText((string)($context['chat_uuid'] ?? ''), 128),
            'title' => $this->shortText(trim((string)($context['title'] ?? '')), 255),
            'content' => trim((string)($context['content'] ?? '')),
            'remark' => $this->shortText((string)($context['remark'] ?? ''), 255),
            'source' => $this->shortText((string)($context['source'] ?? ''), 64),
        ];
    }

    private function normalizeOperatorType(string $operatorType): string
    {
        $operatorType = strtolower(trim($operatorType));
        $allowed = [
            CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
            CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT,
            CustomerServiceAdvancedService::OPERATOR_TYPE_SYSTEM,
        ];

        return in_array($operatorType, $allowed, true) ? $operatorType : CustomerServiceAdvancedService::OPERATOR_TYPE_SYSTEM;
    }

    private function ticketSn(string $ticketType): string
    {
        $prefix = $ticketType === CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT ? 'CSC' : 'CSO';
        return $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
    }

    private function shortText(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
