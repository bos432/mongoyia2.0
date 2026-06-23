<?php

namespace common\services\mall;

use Yii;
use yii\db\Query;

class CustomerServiceComplaintLoopService
{
    public const VERSION = 'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_LOOP_V1';
    public const SOURCE_LOOP = 'customer-service-complaint-loop';
    public const SOURCE_ASSISTANCE_LINK = 'customer-service-complaint-to-assistance';

    public function categories(): array
    {
        return [
            'product_quality' => '商品质量',
            'service_attitude' => '服务态度',
            'logistics' => '物流',
            'payment' => '支付',
            'refund' => '退款',
            'other' => '其他',
        ];
    }

    public function evidenceRoles(): array
    {
        return [
            'user' => '用户证据',
            'service' => '客服补充证据',
            'seller' => '商家举证',
            'platform' => '平台复核',
        ];
    }

    public function statusLabels(): array
    {
        return [
            CustomerServiceAdvancedService::TICKET_STATUS_PENDING => '待受理',
            CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS => '处理中',
            CustomerServiceAdvancedService::TICKET_STATUS_SELLER_PROOF => '待商家举证',
            CustomerServiceAdvancedService::TICKET_STATUS_PLATFORM_REVIEW => '待平台复核',
            CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED => '已解决',
            CustomerServiceAdvancedService::TICKET_STATUS_CLOSED => '已关闭',
            CustomerServiceAdvancedService::TICKET_STATUS_REJECTED => '驳回',
        ];
    }

    public function loopSummary(array $ticket): array
    {
        $evidence = $this->decodeEvidence((string)($ticket['evidence_json'] ?? ''));
        $loop = $this->normalizeLoop($evidence['complaint_loop'] ?? []);

        return [
            'version' => self::VERSION,
            'ticket_id' => (int)($ticket['id'] ?? 0),
            'ticket_status' => (string)($ticket['ticket_status'] ?? ''),
            'category' => $loop['category'],
            'category_label' => $this->categories()[$loop['category']] ?? '',
            'proofs' => $loop['proofs'],
            'conclusion' => $loop['conclusion'],
            'user_feedback' => $loop['user_feedback'],
            'linked_assistance_tickets' => $loop['linked_assistance_tickets'],
            'next_statuses' => (new CustomerServiceAdvancedService())->supportedTransitions()[(string)($ticket['ticket_status'] ?? '')] ?? [],
            'boundaries' => $this->boundaries(),
        ];
    }

    public function dryRunPlan(array $context): array
    {
        $now = time();
        $context = $this->normalizeContext($context);

        return [
            'version' => self::VERSION,
            'context' => $context,
            'rows' => [
                [
                    'table' => 'mall_customer_service_ticket',
                    'operation' => 'update',
                    'purpose' => 'store complaint category/proofs/conclusion/user_feedback in evidence_json',
                    'fields' => ['evidence_json', 'ticket_status', 'result', 'updated_at', 'updated_by'],
                ],
                [
                    'table' => 'mall_customer_service_event',
                    'operation' => 'insert',
                    'purpose' => 'audit complaint loop state/proof/review/feedback transition',
                    'fields' => ['ticket_id', 'event_type', 'from_status', 'to_status', 'operator_user_id', 'operator_type', 'metadata_json'],
                ],
                [
                    'table' => 'mall_customer_service_ticket',
                    'operation' => 'insert',
                    'purpose' => 'optional linked order assistance ticket, approval only',
                    'fields' => ['ticket_type', 'ticket_status', 'order_id', 'product_id', 'evidence_json'],
                ],
            ],
            'now' => $now,
            'boundaries' => $this->boundaries(),
        ];
    }

    public function recordStep(
        int $ticketId,
        array $input,
        bool $apply = false,
        int $operatorId = 1,
        string $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
        int $scopeStoreId = 0
    ): array {
        $ticket = $this->ticketRow($ticketId, $scopeStoreId);
        $this->assertComplaintTicket($ticket);
        $operatorType = $this->normalizeOperatorType($operatorType);
        $step = $this->normalizeInput($input);
        $targetStatus = $step['target_status'];
        $fromStatus = (string)$ticket['ticket_status'];
        if ($targetStatus !== '' && $targetStatus !== $fromStatus) {
            $reason = (new CustomerServiceAdvancedService())->transitionBlockReason($fromStatus, $targetStatus);
            if ($reason !== '') {
                throw new \InvalidArgumentException($reason);
            }
        }

        $plan = [
            'apply' => $apply,
            'ticketId' => $ticketId,
            'fromStatus' => $fromStatus,
            'toStatus' => $targetStatus !== '' ? $targetStatus : $fromStatus,
            'step' => $step,
            'updated' => 0,
            'eventId' => 0,
            'linkedAssistanceTicketId' => 0,
            'boundaries' => $this->boundaries(),
        ];
        if (!$apply) {
            $plan['dryRun'] = true;
            return $plan;
        }

        $now = time();
        $evidence = $this->decodeEvidence((string)($ticket['evidence_json'] ?? ''));
        $loop = $this->normalizeLoop($evidence['complaint_loop'] ?? []);
        if ($step['category'] !== '') {
            $loop['category'] = $step['category'];
        }
        if ($step['evidence_note'] !== '') {
            $loop['proofs'][] = [
                'role' => $step['evidence_role'],
                'note' => $step['evidence_note'],
                'operator_user_id' => $operatorId,
                'operator_type' => $operatorType,
                'created_at' => $now,
            ];
        }
        if ($step['conclusion'] !== '') {
            $loop['conclusion'] = [
                'content' => $step['conclusion'],
                'operator_user_id' => $operatorId,
                'operator_type' => $operatorType,
                'created_at' => $now,
            ];
        }
        if ($step['user_feedback'] !== '') {
            $loop['user_feedback'] = [
                'content' => $step['user_feedback'],
                'operator_user_id' => $operatorId,
                'operator_type' => $operatorType,
                'created_at' => $now,
            ];
        }
        $evidence['complaint_loop'] = $loop;
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode complaint loop evidence.');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            Yii::$app->db->createCommand()->update('{{%mall_customer_service_ticket}}', [
                'ticket_status' => $plan['toStatus'],
                'result' => $step['conclusion'] !== '' ? $step['conclusion'] : (string)($ticket['result'] ?? ''),
                'evidence_json' => $json,
                'updated_at' => $now,
                'updated_by' => $operatorId,
            ], ['id' => $ticketId, 'status' => 1])->execute();

            Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
                'ticket_id' => $ticketId,
                'event_type' => $targetStatus !== '' && $targetStatus !== $fromStatus
                    ? CustomerServiceAdvancedService::EVENT_TYPE_STATUS_CHANGE
                    : CustomerServiceAdvancedService::EVENT_TYPE_NOTE,
                'from_status' => $fromStatus,
                'to_status' => $plan['toStatus'],
                'operator_user_id' => $operatorId,
                'operator_type' => $operatorType,
                'content' => $step['event_content'] !== '' ? $step['event_content'] : 'Complaint loop step recorded.',
                'metadata_json' => json_encode([
                    'source' => self::SOURCE_LOOP,
                    'category' => $loop['category'],
                    'evidence_role' => $step['evidence_role'],
                    'has_conclusion' => $step['conclusion'] !== '',
                    'has_user_feedback' => $step['user_feedback'] !== '',
                    'order_id' => (int)$ticket['order_id'],
                    'product_id' => (int)$ticket['product_id'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'remark' => 'backend complaint full-loop workflow',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $operatorId,
                'updated_by' => $operatorId,
            ])->execute();
            $plan['eventId'] = (int)Yii::$app->db->getLastInsertID();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $plan['updated'] = 1;
        return $plan;
    }

    public function linkAssistancePlan(array $ticket, string $assistanceType): array
    {
        return [
            'source' => self::SOURCE_ASSISTANCE_LINK,
            'ticket_id' => (int)($ticket['id'] ?? 0),
            'order_id' => (int)($ticket['order_id'] ?? 0),
            'product_id' => (int)($ticket['product_id'] ?? 0),
            'store_id' => (int)($ticket['store_id'] ?? 0),
            'assistance_type' => $assistanceType,
            'creates_order_assist_ticket_only' => true,
            'direct_refund_or_compensation_allowed' => false,
        ];
    }

    public function recordAssistanceLink(
        int $ticketId,
        array $link,
        bool $apply = false,
        int $operatorId = 1,
        string $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
        int $scopeStoreId = 0
    ): array {
        $operatorType = $this->normalizeOperatorType($operatorType);
        $link = $this->normalizeAssistanceLink($link);
        $result = [
            'apply' => $apply,
            'ticketId' => $ticketId,
            'linkedAssistanceTicketId' => $link['linked_ticket_id'],
            'assistanceType' => $link['assistance_type'],
            'updated' => 0,
            'eventId' => 0,
            'boundaries' => $this->boundaries(),
        ];
        if ($link['linked_ticket_id'] <= 0 || $link['assistance_type'] === '') {
            throw new \InvalidArgumentException('Linked assistance ticket and assistance type are required.');
        }
        if (!$apply) {
            $result['dryRun'] = true;
            return $result;
        }

        $ticket = $this->ticketRow($ticketId, $scopeStoreId);
        $this->assertComplaintTicket($ticket);
        $now = time();
        $evidence = $this->decodeEvidence((string)($ticket['evidence_json'] ?? ''));
        $loop = $this->normalizeLoop($evidence['complaint_loop'] ?? []);
        $exists = false;
        foreach ($loop['linked_assistance_tickets'] as $existing) {
            if ((int)($existing['ticket_id'] ?? 0) === $link['linked_ticket_id']) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $loop['linked_assistance_tickets'][] = [
                'ticket_id' => $link['linked_ticket_id'],
                'ticket_sn' => $link['linked_ticket_sn'],
                'assistance_type' => $link['assistance_type'],
                'created' => $link['created'],
                'note' => $link['note'],
                'operator_user_id' => $operatorId,
                'operator_type' => $operatorType,
                'created_at' => $now,
            ];
        }
        $evidence['complaint_loop'] = $loop;
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode complaint assistance link.');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            Yii::$app->db->createCommand()->update('{{%mall_customer_service_ticket}}', [
                'evidence_json' => $json,
                'updated_at' => $now,
                'updated_by' => $operatorId,
            ], ['id' => $ticketId, 'status' => 1])->execute();

            Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
                'ticket_id' => $ticketId,
                'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_NOTE,
                'from_status' => (string)$ticket['ticket_status'],
                'to_status' => (string)$ticket['ticket_status'],
                'operator_user_id' => $operatorId,
                'operator_type' => $operatorType,
                'content' => 'Complaint linked to assistance ticket #' . $link['linked_ticket_id'] . '.',
                'metadata_json' => json_encode([
                    'source' => self::SOURCE_ASSISTANCE_LINK,
                    'assistance_type' => $link['assistance_type'],
                    'linked_ticket_id' => $link['linked_ticket_id'],
                    'linked_ticket_sn' => $link['linked_ticket_sn'],
                    'created' => $link['created'],
                    'direct_refund_or_compensation_allowed' => false,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'remark' => 'backend complaint assistance link',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $operatorId,
                'updated_by' => $operatorId,
            ])->execute();
            $result['eventId'] = (int)Yii::$app->db->getLastInsertID();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $result['updated'] = 1;
        return $result;
    }

    public function boundaries(): array
    {
        return [
            'complaint_loop_updates_ticket_only' => true,
            'seller_proof_is_evidence_only' => true,
            'platform_review_is_status_and_result_only' => true,
            'complaint_to_assistance_creates_ticket_only' => true,
            'refund_mutation_allowed' => false,
            'compensation_mutation_allowed' => false,
            'order_mutation_allowed' => false,
            'payment_mutation_allowed' => false,
            'fund_mutation_allowed' => false,
            'stock_mutation_allowed' => false,
        ];
    }

    private function normalizeInput(array $input): array
    {
        $category = strtolower(trim((string)($input['category'] ?? '')));
        $role = strtolower(trim((string)($input['evidence_role'] ?? 'service')));
        $targetStatus = strtolower(trim((string)($input['target_status'] ?? '')));
        return [
            'category' => isset($this->categories()[$category]) ? $category : '',
            'evidence_role' => isset($this->evidenceRoles()[$role]) ? $role : 'service',
            'evidence_note' => $this->shortText((string)($input['evidence_note'] ?? ''), 1000),
            'target_status' => in_array($targetStatus, (new CustomerServiceAdvancedService())->supportedTicketStatuses(), true) ? $targetStatus : '',
            'conclusion' => $this->shortText((string)($input['conclusion'] ?? ''), 4000),
            'user_feedback' => $this->shortText((string)($input['user_feedback'] ?? ''), 2000),
            'event_content' => $this->shortText((string)($input['event_content'] ?? ''), 1000),
        ];
    }

    private function normalizeAssistanceLink(array $link): array
    {
        $assistanceType = strtolower(trim((string)($link['assistance_type'] ?? '')));
        $types = (new CustomerServiceAssistanceService())->assistanceTypes();

        return [
            'assistance_type' => isset($types[$assistanceType]) ? $assistanceType : '',
            'linked_ticket_id' => max(0, (int)($link['linked_ticket_id'] ?? 0)),
            'linked_ticket_sn' => $this->shortText((string)($link['linked_ticket_sn'] ?? ''), 64),
            'created' => !empty($link['created']) ? 1 : 0,
            'note' => $this->shortText((string)($link['note'] ?? ''), 1000),
        ];
    }

    private function normalizeLoop($loop): array
    {
        $loop = is_array($loop) ? $loop : [];
        $category = (string)($loop['category'] ?? '');
        return [
            'category' => isset($this->categories()[$category]) ? $category : '',
            'proofs' => isset($loop['proofs']) && is_array($loop['proofs']) ? array_values($loop['proofs']) : [],
            'conclusion' => isset($loop['conclusion']) && is_array($loop['conclusion']) ? $loop['conclusion'] : [],
            'user_feedback' => isset($loop['user_feedback']) && is_array($loop['user_feedback']) ? $loop['user_feedback'] : [],
            'linked_assistance_tickets' => isset($loop['linked_assistance_tickets']) && is_array($loop['linked_assistance_tickets']) ? array_values($loop['linked_assistance_tickets']) : [],
        ];
    }

    private function decodeEvidence(string $json): array
    {
        $decoded = trim($json) !== '' ? json_decode($json, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }
        if (!isset($decoded['files']) || !is_array($decoded['files'])) {
            $decoded['files'] = [];
        }
        $decoded['version'] = (int)($decoded['version'] ?? 1);

        return $decoded;
    }

    private function normalizeContext(array $context): array
    {
        return [
            'ticket_id' => (int)($context['ticket_id'] ?? 0),
            'store_id' => (int)($context['store_id'] ?? 0),
            'order_id' => (int)($context['order_id'] ?? 0),
            'product_id' => (int)($context['product_id'] ?? 0),
            'category' => (string)($context['category'] ?? ''),
        ];
    }

    private function ticketRow(int $ticketId, int $scopeStoreId): array
    {
        if ($ticketId <= 0 || !$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return [];
        }
        $query = (new Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId, 'status' => 1]);
        if ($scopeStoreId > 0) {
            $query->andWhere(['store_id' => $scopeStoreId]);
        }

        return $query->one(Yii::$app->db) ?: [];
    }

    private function assertComplaintTicket(array $ticket): void
    {
        if (!$ticket) {
            throw new \RuntimeException('Complaint ticket not found or out of scope.');
        }
        if ((string)$ticket['ticket_type'] !== CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT) {
            throw new \RuntimeException('Only complaint tickets can use complaint loop workflow.');
        }
        if (!$this->tableExists('{{%mall_customer_service_event}}')) {
            throw new \RuntimeException('Customer-service event schema missing.');
        }
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

    private function shortText(string $text, int $limit): string
    {
        $text = trim($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
