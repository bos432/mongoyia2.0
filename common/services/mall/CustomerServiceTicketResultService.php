<?php

namespace common\services\mall;

use Yii;

class CustomerServiceTicketResultService
{
    public function run(
        int $ticketId,
        string $resultText,
        bool $apply = false,
        int $operatorId = 1,
        string $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
        int $storeId = 0
    ): array {
        $ticketId = max(0, $ticketId);
        $resultText = trim($resultText);
        $operatorId = max(1, $operatorId);
        $operatorType = $this->normalizeOperatorType($operatorType);
        $result = [
            'apply' => $apply,
            'ticketId' => $ticketId,
            'storeId' => $storeId,
            'written' => 0,
            'eventId' => 0,
            'dryRun' => false,
            'skipped' => [],
        ];

        if ($ticketId <= 0) {
            $result['skipped'][] = ['id' => 0, 'reason' => 'ticket id is required'];
            return $result;
        }
        if ($resultText === '') {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'result is required'];
            return $result;
        }
        if (!$this->tableExists('{{%mall_customer_service_ticket}}') || !$this->tableExists('{{%mall_customer_service_event}}')) {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'customer-service ticket schema missing'];
            return $result;
        }

        $ticket = $this->ticketRow($ticketId, $storeId);
        if (!$ticket) {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'ticket not found or out of scope'];
            return $result;
        }

        $oldResult = trim((string)$ticket['result']);
        if ($oldResult === $resultText) {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'result unchanged'];
            return $result;
        }

        if (!$apply) {
            $result['dryRun'] = true;
            return $result;
        }

        $now = time();
        $transaction = Yii::$app->db->beginTransaction();
        try {
            Yii::$app->db->createCommand()->update('{{%mall_customer_service_ticket}}', [
                'result' => $this->shortText($resultText, 4000),
                'updated_at' => $now,
                'updated_by' => $operatorId,
            ], [
                'id' => $ticketId,
                'status' => 1,
            ])->execute();

            Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
                'ticket_id' => $ticketId,
                'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_NOTE,
                'from_status' => (string)$ticket['ticket_status'],
                'to_status' => (string)$ticket['ticket_status'],
                'operator_user_id' => $operatorId,
                'operator_type' => $operatorType,
                'content' => $this->shortText($resultText, 4000),
                'metadata_json' => json_encode([
                    'source' => 'customer-service-ticket-result',
                    'old_result' => $this->shortText($oldResult, 255),
                    'order_id' => (int)$ticket['order_id'],
                    'product_id' => (int)$ticket['product_id'],
                    'chat_uuid' => (string)$ticket['chat_uuid'],
                ], JSON_UNESCAPED_SLASHES),
                'remark' => 'customer-service ticket result',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $operatorId,
                'updated_by' => $operatorId,
            ])->execute();

            $eventId = (int)Yii::$app->db->getLastInsertID();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $result['written'] = 1;
        $result['eventId'] = $eventId;

        return $result;
    }

    private function ticketRow(int $ticketId, int $storeId): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId, 'status' => 1]);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        return $query->one(Yii::$app->db) ?: [];
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

    private function shortText(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
