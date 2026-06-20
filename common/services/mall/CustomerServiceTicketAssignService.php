<?php

namespace common\services\mall;

use Yii;

class CustomerServiceTicketAssignService
{
    const ASSIGNMENT_TYPE_MERCHANT = 'merchant';
    const ASSIGNMENT_TYPE_PLATFORM = 'platform';

    public function run(
        int $ticketId,
        string $assignmentType,
        int $assigneeUserId,
        bool $apply = false,
        int $operatorId = 1,
        string $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
        string $remark = '',
        int $storeId = 0
    ): array {
        $ticketId = max(0, $ticketId);
        $assignmentType = strtolower(trim($assignmentType));
        $assigneeUserId = max(0, $assigneeUserId);
        $operatorId = max(1, $operatorId);
        $operatorType = $this->normalizeOperatorType($operatorType);
        $result = [
            'apply' => $apply,
            'ticketId' => $ticketId,
            'storeId' => $storeId,
            'assignmentType' => $assignmentType,
            'assigneeUserId' => $assigneeUserId,
            'assigned' => 0,
            'eventId' => 0,
            'dryRun' => false,
            'skipped' => [],
        ];

        $reason = $this->blockReason($ticketId, $assignmentType, $assigneeUserId, $operatorType);
        if ($reason !== '') {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => $reason];
            return $result;
        }

        $ticket = $this->ticketRow($ticketId, $storeId);
        if (!$ticket) {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'ticket not found or out of scope'];
            return $result;
        }

        $field = $this->assignmentField($assignmentType);
        $fromUserId = (int)$ticket[$field];
        if ($fromUserId === $assigneeUserId) {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'assignee unchanged'];
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
                $field => $assigneeUserId,
                'updated_at' => $now,
                'updated_by' => $operatorId,
            ], [
                'id' => $ticketId,
                'status' => 1,
            ])->execute();

            $this->insertEvent($ticket, $assignmentType, $fromUserId, $assigneeUserId, $operatorId, $operatorType, $remark, $now);
            $eventId = (int)Yii::$app->db->getLastInsertID();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $result['assigned'] = 1;
        $result['eventId'] = $eventId;

        return $result;
    }

    private function blockReason(int $ticketId, string $assignmentType, int $assigneeUserId, string $operatorType): string
    {
        if ($ticketId <= 0) {
            return 'ticket id is required';
        }
        if (!$this->isSupportedAssignmentType($assignmentType)) {
            return 'unsupported assignment type';
        }
        if ($assigneeUserId <= 0) {
            return 'assignee user id is required';
        }
        if ($operatorType === CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT && $assignmentType !== self::ASSIGNMENT_TYPE_MERCHANT) {
            return 'merchant can only assign merchant handler';
        }
        if (!$this->tableExists('{{%mall_customer_service_ticket}}') || !$this->tableExists('{{%mall_customer_service_event}}')) {
            return 'customer-service ticket schema missing';
        }

        return '';
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

    private function insertEvent(array $ticket, string $assignmentType, int $fromUserId, int $toUserId, int $operatorId, string $operatorType, string $remark, int $now): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
            'ticket_id' => (int)$ticket['id'],
            'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_NOTE,
            'from_status' => (string)$ticket['ticket_status'],
            'to_status' => (string)$ticket['ticket_status'],
            'operator_user_id' => $operatorId,
            'operator_type' => $operatorType,
            'content' => $remark !== '' ? $this->shortText($remark, 4000) : 'Customer-service ticket assigned.',
            'metadata_json' => json_encode([
                'source' => 'customer-service-ticket-assign',
                'assignment_type' => $assignmentType,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'order_id' => (int)$ticket['order_id'],
                'product_id' => (int)$ticket['product_id'],
                'chat_uuid' => (string)$ticket['chat_uuid'],
            ], JSON_UNESCAPED_SLASHES),
            'remark' => 'customer-service ticket assign',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $operatorId,
            'updated_by' => $operatorId,
        ])->execute();
    }

    private function assignmentField(string $assignmentType): string
    {
        return $assignmentType === self::ASSIGNMENT_TYPE_PLATFORM ? 'platform_user_id' : 'merchant_user_id';
    }

    private function isSupportedAssignmentType(string $assignmentType): bool
    {
        return in_array($assignmentType, [
            self::ASSIGNMENT_TYPE_MERCHANT,
            self::ASSIGNMENT_TYPE_PLATFORM,
        ], true);
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
