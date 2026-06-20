<?php

namespace common\services\mall;

use Yii;

class CustomerServiceTicketWorkflowService
{
    public function run(
        array $ticketIds,
        string $targetStatus,
        bool $apply = false,
        int $operatorId = 1,
        string $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
        string $remark = '',
        int $storeId = 0
    ): array {
        $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds))));
        $targetStatus = strtolower(trim($targetStatus));
        $operatorId = max(1, $operatorId);
        $operatorType = $this->normalizeOperatorType($operatorType);
        $result = [
            'apply' => $apply,
            'targetStatus' => $targetStatus,
            'storeId' => $storeId,
            'scanned' => 0,
            'eligible' => 0,
            'updated' => 0,
            'dryRunIds' => [],
            'updatedIds' => [],
            'skipped' => [],
        ];

        if (!$ticketIds) {
            $result['skipped'][] = ['id' => 0, 'reason' => 'ticket ids required'];
            return $result;
        }
        if (!$this->isSupportedStatus($targetStatus)) {
            $result['skipped'][] = ['id' => 0, 'reason' => 'unsupported target status'];
            return $result;
        }
        if (!$this->tableExists('{{%mall_customer_service_ticket}}') || !$this->tableExists('{{%mall_customer_service_event}}')) {
            $result['skipped'][] = ['id' => 0, 'reason' => 'customer-service workflow schema missing'];
            return $result;
        }

        $rows = $this->ticketRows($ticketIds, $storeId);
        $seenIds = [];
        $advanced = new CustomerServiceAdvancedService();
        foreach ($rows as $ticket) {
            $result['scanned']++;
            $ticketId = (int)$ticket['id'];
            $seenIds[] = $ticketId;
            $currentStatus = (string)$ticket['ticket_status'];
            $reason = $advanced->transitionBlockReason($currentStatus, $targetStatus);
            if ($reason !== '') {
                $result['skipped'][] = ['id' => $ticketId, 'reason' => $reason];
                continue;
            }

            $result['eligible']++;
            if (!$apply) {
                $result['dryRunIds'][] = $ticketId;
                continue;
            }

            $now = time();
            Yii::$app->db->createCommand()->update('{{%mall_customer_service_ticket}}', $this->updateFields($ticket, $targetStatus, $operatorId, $remark, $now), [
                'id' => $ticketId,
                'status' => 1,
            ])->execute();
            $this->insertEvent($ticketId, $currentStatus, $targetStatus, $operatorId, $operatorType, $remark, $now);
            $result['updated']++;
            $result['updatedIds'][] = $ticketId;
        }

        foreach (array_values(array_diff($ticketIds, $seenIds)) as $missingId) {
            $result['skipped'][] = ['id' => $missingId, 'reason' => 'ticket not found or out of scope'];
        }

        return $result;
    }

    private function ticketRows(array $ticketIds, int $storeId): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketIds, 'status' => 1])
            ->orderBy(['id' => SORT_ASC]);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        return $query->all(Yii::$app->db);
    }

    private function updateFields(array $ticket, string $targetStatus, int $operatorId, string $remark, int $now): array
    {
        $fields = [
            'ticket_status' => $targetStatus,
            'remark' => $this->mergeText((string)$ticket['remark'], $remark),
            'updated_at' => $now,
            'updated_by' => $operatorId,
        ];

        if ($targetStatus === CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS && (int)$ticket['first_response_at'] <= 0) {
            $fields['first_response_at'] = $now;
        }
        if ($targetStatus === CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED) {
            $fields['resolved_at'] = $now;
            if (trim((string)$ticket['result']) === '') {
                $fields['result'] = $remark !== '' ? $remark : 'Resolved by customer-service ticket workflow.';
            }
        }
        if ($targetStatus === CustomerServiceAdvancedService::TICKET_STATUS_CLOSED) {
            $fields['closed_at'] = $now;
        }

        return $fields;
    }

    private function insertEvent(int $ticketId, string $fromStatus, string $toStatus, int $operatorId, string $operatorType, string $remark, int $now): void
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
            'ticket_id' => $ticketId,
            'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_STATUS_CHANGE,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'operator_user_id' => $operatorId,
            'operator_type' => $operatorType,
            'content' => $remark !== '' ? $remark : 'Customer-service ticket workflow status change.',
            'metadata_json' => json_encode(['source' => 'customer-service-ticket-workflow'], JSON_UNESCAPED_SLASHES),
            'remark' => 'customer-service ticket workflow',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $operatorId,
            'updated_by' => $operatorId,
        ])->execute();
    }

    private function isSupportedStatus(string $status): bool
    {
        return in_array($status, (new CustomerServiceAdvancedService())->supportedTicketStatuses(), true);
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

    private function mergeText(string $oldText, string $newText): string
    {
        $combined = trim($oldText . ' | ' . $newText, ' |');

        return function_exists('mb_substr') ? mb_substr($combined, 0, 255, 'UTF-8') : substr($combined, 0, 255);
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
