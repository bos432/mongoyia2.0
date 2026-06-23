<?php

namespace common\services\mall;

class CustomerServiceAdvancedService
{
    const TICKET_TYPE_ORDER_ASSIST = 'order_assist';
    const TICKET_TYPE_COMPLAINT = 'complaint';

    const TICKET_STATUS_PENDING = 'pending';
    const TICKET_STATUS_IN_PROGRESS = 'in_progress';
    const TICKET_STATUS_SELLER_PROOF = 'seller_proof';
    const TICKET_STATUS_PLATFORM_REVIEW = 'platform_review';
    const TICKET_STATUS_RESOLVED = 'resolved';
    const TICKET_STATUS_CLOSED = 'closed';
    const TICKET_STATUS_REJECTED = 'rejected';

    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';

    const EVENT_TYPE_NOTE = 'note';
    const EVENT_TYPE_CREATE = 'create';
    const EVENT_TYPE_STATUS_CHANGE = 'status_change';

    const OPERATOR_TYPE_PLATFORM = 'platform';
    const OPERATOR_TYPE_MERCHANT = 'merchant';
    const OPERATOR_TYPE_SYSTEM = 'system';

    public function dryRunPlan(array $context, ?int $now = null): array
    {
        $now = $now ?: time();
        $context = $this->normalizeContext($context);
        $ticketSn = 'CS-DRYRUN-' . date('YmdHis', $now);
        $rows = [
            $this->ticketDraftRow($ticketSn . '-ORDER', self::TICKET_TYPE_ORDER_ASSIST, self::PRIORITY_NORMAL, 'order assistance draft', 'Order assistance dry-run', $context),
            $this->ticketDraftRow($ticketSn . '-COMPLAINT', self::TICKET_TYPE_COMPLAINT, self::PRIORITY_HIGH, 'complaint draft', 'Complaint dry-run', $context),
            $this->eventRow($ticketSn . '-EVENT', 'audit event draft', $context),
            $this->statDailyRow($now, $context),
        ];

        return [
            'context' => $context,
            'rows' => $rows,
            'issues' => $this->validateRows($rows),
        ];
    }

    public function workflowDryRunPlan(array $context, ?int $now = null): array
    {
        $now = $now ?: time();
        $context = $this->normalizeContext($context);
        $ticketSn = 'CS-WORKFLOW-' . date('YmdHis', $now);
        $rows = [
            $this->statusEventRow($ticketSn . '-START', self::TICKET_STATUS_PENDING, self::TICKET_STATUS_IN_PROGRESS, 'first response transition draft', $context),
            $this->ticketStatusUpdateRow($ticketSn . '-ORDER', self::TICKET_STATUS_IN_PROGRESS, 'mark order assistance in progress', [
                'first_response_at' => $now,
            ], $context, $now),
            $this->statusEventRow($ticketSn . '-RESOLVE', self::TICKET_STATUS_IN_PROGRESS, self::TICKET_STATUS_RESOLVED, 'resolution transition draft', $context),
            $this->ticketStatusUpdateRow($ticketSn . '-ORDER', self::TICKET_STATUS_RESOLVED, 'mark order assistance resolved', [
                'resolved_at' => $now + 600,
                'result' => 'Resolved by advanced customer-service workflow dry-run.',
            ], $context, $now),
            $this->statDailyWorkflowRow($now, $context),
        ];

        return [
            'context' => $context,
            'rows' => $rows,
            'issues' => $this->validateWorkflowRows($rows),
        ];
    }

    public function validateRows(array $rows): array
    {
        $issues = [];
        foreach ($rows as $index => $row) {
            foreach (['table', 'operation', 'key', 'purpose', 'fields'] as $field) {
                if (!array_key_exists($field, $row)) {
                    $issues[] = "row {$index} missing {$field}";
                }
            }
            if (!isset($row['fields']) || !is_array($row['fields'])) {
                continue;
            }
            foreach ($this->requiredFieldsForTable((string)($row['table'] ?? '')) as $field) {
                if (!array_key_exists($field, $row['fields'])) {
                    $issues[] = "row {$index} missing field {$field}";
                }
            }
        }

        return $issues;
    }

    public function validateWorkflowRows(array $rows): array
    {
        $issues = [];
        foreach ($rows as $index => $row) {
            foreach (['table', 'operation', 'key', 'purpose', 'fields'] as $field) {
                if (!array_key_exists($field, $row)) {
                    $issues[] = "workflow row {$index} missing {$field}";
                }
            }
            if (!isset($row['fields']) || !is_array($row['fields'])) {
                continue;
            }

            $table = (string)($row['table'] ?? '');
            $operation = (string)($row['operation'] ?? '');
            if ($table === 'mall_customer_service_ticket' && $operation === 'update') {
                foreach (['ticket_sn', 'ticket_status', 'updated_at', 'updated_by'] as $field) {
                    if (!array_key_exists($field, $row['fields'])) {
                        $issues[] = "workflow row {$index} missing field {$field}";
                    }
                }
            } elseif ($table === 'mall_customer_service_event') {
                foreach (['ticket_id', 'event_type', 'from_status', 'to_status', 'operator_user_id', 'operator_type'] as $field) {
                    if (!array_key_exists($field, $row['fields'])) {
                        $issues[] = "workflow row {$index} missing field {$field}";
                    }
                }
                $fromStatus = (string)($row['fields']['from_status'] ?? '');
                $toStatus = (string)($row['fields']['to_status'] ?? '');
                if ($this->transitionBlockReason($fromStatus, $toStatus) !== '') {
                    $issues[] = "workflow row {$index} has invalid transition";
                }
            } elseif ($table === 'mall_customer_service_stat_daily') {
                foreach (['stat_date', 'store_id', 'service_user_id', 'resolved_count', 'unresolved_count'] as $field) {
                    if (!array_key_exists($field, $row['fields'])) {
                        $issues[] = "workflow row {$index} missing field {$field}";
                    }
                }
            }
        }

        return $issues;
    }

    public function supportedTicketTypes(): array
    {
        return [
            self::TICKET_TYPE_ORDER_ASSIST,
            self::TICKET_TYPE_COMPLAINT,
        ];
    }

    public function supportedTicketStatuses(): array
    {
        return [
            self::TICKET_STATUS_PENDING,
            self::TICKET_STATUS_IN_PROGRESS,
            self::TICKET_STATUS_SELLER_PROOF,
            self::TICKET_STATUS_PLATFORM_REVIEW,
            self::TICKET_STATUS_RESOLVED,
            self::TICKET_STATUS_CLOSED,
            self::TICKET_STATUS_REJECTED,
        ];
    }

    public function supportedTransitions(): array
    {
        return [
            self::TICKET_STATUS_PENDING => [self::TICKET_STATUS_IN_PROGRESS, self::TICKET_STATUS_SELLER_PROOF, self::TICKET_STATUS_REJECTED, self::TICKET_STATUS_CLOSED],
            self::TICKET_STATUS_IN_PROGRESS => [self::TICKET_STATUS_SELLER_PROOF, self::TICKET_STATUS_PLATFORM_REVIEW, self::TICKET_STATUS_RESOLVED, self::TICKET_STATUS_REJECTED, self::TICKET_STATUS_CLOSED],
            self::TICKET_STATUS_SELLER_PROOF => [self::TICKET_STATUS_PLATFORM_REVIEW, self::TICKET_STATUS_IN_PROGRESS, self::TICKET_STATUS_REJECTED, self::TICKET_STATUS_CLOSED],
            self::TICKET_STATUS_PLATFORM_REVIEW => [self::TICKET_STATUS_RESOLVED, self::TICKET_STATUS_REJECTED, self::TICKET_STATUS_IN_PROGRESS, self::TICKET_STATUS_CLOSED],
            self::TICKET_STATUS_RESOLVED => [self::TICKET_STATUS_CLOSED],
            self::TICKET_STATUS_REJECTED => [self::TICKET_STATUS_CLOSED],
            self::TICKET_STATUS_CLOSED => [],
        ];
    }

    public function transitionBlockReason(string $fromStatus, string $toStatus): string
    {
        $transitions = $this->supportedTransitions();
        if (!array_key_exists($fromStatus, $transitions)) {
            return 'unsupported from status';
        }
        if (!in_array($toStatus, $transitions[$fromStatus], true)) {
            return 'invalid transition from ' . $fromStatus . ' to ' . $toStatus;
        }

        return '';
    }

    public function ticketRows(int $storeId = 0, int $limit = 100, array $filters = []): array
    {
        if (!$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return [];
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(max(1, min(500, $limit)));
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if (!empty($filters['ticket_type'])) {
            $query->andWhere(['ticket_type' => (string)$filters['ticket_type']]);
        }
        if (!empty($filters['ticket_status'])) {
            $query->andWhere(['ticket_status' => (string)$filters['ticket_status']]);
        }

        return $query->all(\Yii::$app->db);
    }

    public function ticketRow(int $id, int $storeId = 0): array
    {
        if ($id <= 0 || !$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return [];
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $id, 'status' => 1]);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        return $query->one(\Yii::$app->db) ?: [];
    }

    public function eventRows(int $ticketId): array
    {
        if ($ticketId <= 0 || !$this->tableExists('{{%mall_customer_service_event}}')) {
            return [];
        }

        return (new \yii\db\Query())
            ->from('{{%mall_customer_service_event}}')
            ->where(['ticket_id' => $ticketId, 'status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all(\Yii::$app->db);
    }

    public function statRows(int $storeId = 0, int $limit = 14): array
    {
        if (!$this->tableExists('{{%mall_customer_service_stat_daily}}')) {
            return [];
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_stat_daily}}')
            ->where(['status' => 1])
            ->orderBy(['stat_date' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(max(1, min(60, $limit)));
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        return $query->all(\Yii::$app->db);
    }

    private function ticketDraftRow(string $ticketSn, string $type, string $priority, string $purpose, string $title, array $context): array
    {
        return [
            'table' => 'mall_customer_service_ticket',
            'operation' => 'insert',
            'key' => $ticketSn,
            'purpose' => $purpose,
            'fields' => [
                'ticket_sn' => $ticketSn,
                'ticket_type' => $type,
                'ticket_status' => self::TICKET_STATUS_PENDING,
                'priority' => $priority,
                'store_id' => $context['store_id'],
                'product_id' => $context['product_id'],
                'order_id' => $context['order_id'],
                'order_sn' => $context['order_sn'],
                'customer_user_id' => $context['customer_user_id'],
                'customer_uuid' => $context['customer_uuid'],
                'merchant_user_id' => $context['merchant_user_id'],
                'platform_user_id' => $context['operator_user_id'],
                'chat_uuid' => $context['chat_uuid'],
                'title' => $title,
            ],
        ];
    }

    private function ticketStatusUpdateRow(string $ticketSn, string $status, string $purpose, array $extraFields, array $context, int $now): array
    {
        return [
            'table' => 'mall_customer_service_ticket',
            'operation' => 'update',
            'key' => $ticketSn,
            'purpose' => $purpose,
            'fields' => array_merge([
                'ticket_sn' => $ticketSn,
                'ticket_status' => $status,
                'updated_at' => $now,
                'updated_by' => $context['operator_user_id'],
            ], $extraFields),
        ];
    }

    private function eventRow(string $key, string $purpose, array $context): array
    {
        return [
            'table' => 'mall_customer_service_event',
            'operation' => 'insert',
            'key' => $key,
            'purpose' => $purpose,
            'fields' => [
                'ticket_id' => '<new-ticket-id>',
                'event_type' => self::EVENT_TYPE_NOTE,
                'from_status' => '',
                'to_status' => self::TICKET_STATUS_PENDING,
                'operator_user_id' => $context['operator_user_id'],
                'operator_type' => self::OPERATOR_TYPE_PLATFORM,
            ],
        ];
    }

    private function statusEventRow(string $key, string $fromStatus, string $toStatus, string $purpose, array $context): array
    {
        return [
            'table' => 'mall_customer_service_event',
            'operation' => 'insert',
            'key' => $key,
            'purpose' => $purpose,
            'fields' => [
                'ticket_id' => '<new-ticket-id>',
                'event_type' => self::EVENT_TYPE_STATUS_CHANGE,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'operator_user_id' => $context['operator_user_id'],
                'operator_type' => self::OPERATOR_TYPE_PLATFORM,
            ],
        ];
    }

    private function statDailyRow(int $now, array $context): array
    {
        $statDate = (int)date('Ymd', $now);
        return [
            'table' => 'mall_customer_service_stat_daily',
            'operation' => 'upsert',
            'key' => $statDate . ':' . $context['store_id'] . ':' . $context['operator_user_id'],
            'purpose' => 'daily service stat draft',
            'fields' => [
                'stat_date' => $statDate,
                'store_id' => $context['store_id'],
                'service_user_id' => $context['operator_user_id'],
                'session_count' => 0,
                'ticket_count' => 2,
                'order_assist_count' => 1,
                'complaint_count' => 1,
                'resolved_count' => 0,
                'unresolved_count' => 2,
            ],
        ];
    }

    private function statDailyWorkflowRow(int $now, array $context): array
    {
        $statDate = (int)date('Ymd', $now);
        return [
            'table' => 'mall_customer_service_stat_daily',
            'operation' => 'upsert',
            'key' => $statDate . ':' . $context['store_id'] . ':' . $context['operator_user_id'] . ':workflow',
            'purpose' => 'daily service workflow stat draft',
            'fields' => [
                'stat_date' => $statDate,
                'store_id' => $context['store_id'],
                'service_user_id' => $context['operator_user_id'],
                'resolved_count' => 1,
                'unresolved_count' => 1,
                'first_response_seconds_total' => 60,
                'resolved_seconds_total' => 600,
            ],
        ];
    }

    private function normalizeContext(array $context): array
    {
        return [
            'product_id' => (int)($context['product_id'] ?? 0),
            'store_id' => (int)($context['store_id'] ?? 0),
            'merchant_user_id' => (int)($context['merchant_user_id'] ?? 0),
            'order_id' => (int)($context['order_id'] ?? 0),
            'order_sn' => (string)($context['order_sn'] ?? ''),
            'customer_user_id' => (int)($context['customer_user_id'] ?? 0),
            'customer_uuid' => (string)($context['customer_uuid'] ?? ''),
            'chat_uuid' => (string)($context['chat_uuid'] ?? ''),
            'operator_user_id' => (int)($context['operator_user_id'] ?? 0),
        ];
    }

    private function requiredFieldsForTable(string $table): array
    {
        $map = [
            'mall_customer_service_ticket' => [
                'ticket_sn',
                'ticket_type',
                'ticket_status',
                'priority',
                'store_id',
                'product_id',
                'order_id',
                'customer_user_id',
                'merchant_user_id',
                'platform_user_id',
                'title',
            ],
            'mall_customer_service_event' => [
                'ticket_id',
                'event_type',
                'operator_user_id',
                'operator_type',
                'to_status',
            ],
            'mall_customer_service_stat_daily' => [
                'stat_date',
                'store_id',
                'service_user_id',
                'ticket_count',
                'order_assist_count',
                'complaint_count',
            ],
        ];

        return $map[$table] ?? [];
    }

    private function tableExists(string $table): bool
    {
        return \Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
