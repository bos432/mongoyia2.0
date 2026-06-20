<?php

namespace common\services\mall;

use Yii;

class CustomerServiceComplaintEvidenceApplyWorkflowService
{
    public function run(
        int $ticketId,
        string $evidenceJson,
        bool $apply = false,
        int $operatorId = 1,
        string $operatorType = CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
        int $storeId = 0,
        string $remark = ''
    ): array {
        $ticketId = max(0, $ticketId);
        $operatorId = max(1, $operatorId);
        $operatorType = $this->normalizeOperatorType($operatorType);
        $evidenceJson = trim($evidenceJson);
        $remark = trim($remark) !== '' ? trim($remark) : 'customer-service complaint evidence apply workflow';
        $result = [
            'apply' => $apply,
            'ticketId' => $ticketId,
            'storeId' => $storeId,
            'operatorId' => $operatorId,
            'operatorType' => $operatorType,
            'written' => 0,
            'eventId' => 0,
            'dryRun' => false,
            'oldEvidenceJson' => '',
            'newEvidenceJson' => '',
            'skipped' => [],
        ];

        if ($ticketId <= 0) {
            $result['skipped'][] = ['id' => 0, 'reason' => 'ticket id is required'];
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
        if ((string)$ticket['ticket_type'] !== CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT) {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'ticket is not complaint'];
            return $result;
        }

        $normalizedEvidence = $this->normalizeEvidenceJson($evidenceJson);
        if ($normalizedEvidence === '') {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'valid evidence JSON is required'];
            return $result;
        }

        $oldEvidenceJson = trim((string)($ticket['evidence_json'] ?? ''));
        $result['oldEvidenceJson'] = $oldEvidenceJson;
        $result['newEvidenceJson'] = $normalizedEvidence;
        if ($oldEvidenceJson === $normalizedEvidence) {
            $result['skipped'][] = ['id' => $ticketId, 'reason' => 'evidence JSON unchanged'];
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
                'evidence_json' => $normalizedEvidence,
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
                'content' => 'Complaint evidence JSON updated by audited CLI workflow.',
                'metadata_json' => json_encode([
                    'source' => 'customer-service-complaint-evidence-apply',
                    'old_evidence_status' => $this->evidenceStatus($oldEvidenceJson),
                    'new_evidence_status' => 'valid_evidence_json',
                    'order_id' => (int)$ticket['order_id'],
                    'product_id' => (int)$ticket['product_id'],
                    'chat_uuid' => (string)$ticket['chat_uuid'],
                ], JSON_UNESCAPED_SLASHES),
                'remark' => $remark,
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

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Customer Service Complaint Evidence Apply Workflow',
            '',
            '- Result: ' . (empty($report['skipped']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Mode: ' . (!empty($report['apply']) ? 'apply' : 'dry-run'),
            '- Ticket ID: ' . (int)($report['ticketId'] ?? 0),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Operator user ID: ' . (int)($report['operatorId'] ?? 0),
            '- Operator type: ' . (string)($report['operatorType'] ?? ''),
            '- Written: ' . (int)($report['written'] ?? 0),
            '- Event ID: ' . (int)($report['eventId'] ?? 0),
            '- Dry-run: ' . (!empty($report['dryRun']) ? 'yes' : 'no'),
            '',
            '## Evidence Summary',
            '',
            '| Item | Value |',
            '|---|---|',
            '| Old evidence status | ' . $this->escapeCell($this->evidenceStatus((string)($report['oldEvidenceJson'] ?? ''))) . ' |',
            '| New evidence status | ' . $this->escapeCell($this->evidenceStatus((string)($report['newEvidenceJson'] ?? ''))) . ' |',
            '| New evidence length | ' . strlen((string)($report['newEvidenceJson'] ?? '')) . ' |',
        ];

        if (!empty($report['skipped'])) {
            $lines[] = '';
            $lines[] = '## Skipped';
            $lines[] = '';
            foreach ($report['skipped'] as $row) {
                $lines[] = '- #' . (int)($row['id'] ?? 0) . ': ' . (string)($row['reason'] ?? '');
            }
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Complaint owner reviewed evidence JSON before apply: PENDING',
            '- Operator and event audit row archived after apply: PENDING',
            '- Backend complaint evidence upload/write controls remain disabled: PENDING',
            '',
            'Dry-run mode does not mutate tickets or events. Apply mode writes only complaint ticket evidence_json and one customer-service event audit row; it does not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.',
        ]);
    }

    public function csvLines(array $report): array
    {
        return [
            'mode,ticket_id,store_id,operator_user_id,operator_type,written,event_id,dry_run,old_evidence_status,new_evidence_status,new_evidence_length,skipped_reason',
            implode(',', [
                !empty($report['apply']) ? 'apply' : 'dry-run',
                (int)($report['ticketId'] ?? 0),
                (int)($report['storeId'] ?? 0),
                (int)($report['operatorId'] ?? 0),
                $this->csvCell((string)($report['operatorType'] ?? '')),
                (int)($report['written'] ?? 0),
                (int)($report['eventId'] ?? 0),
                !empty($report['dryRun']) ? 1 : 0,
                $this->csvCell($this->evidenceStatus((string)($report['oldEvidenceJson'] ?? ''))),
                $this->csvCell($this->evidenceStatus((string)($report['newEvidenceJson'] ?? ''))),
                strlen((string)($report['newEvidenceJson'] ?? '')),
                $this->csvCell((string)($report['skipped'][0]['reason'] ?? '')),
            ]),
        ];
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

    private function normalizeEvidenceJson(string $evidenceJson): string
    {
        if (trim($evidenceJson) === '') {
            return '';
        }

        $decoded = json_decode($evidenceJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return '';
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function evidenceStatus(string $evidenceJson): string
    {
        $evidenceJson = trim($evidenceJson);
        if ($evidenceJson === '') {
            return 'missing_evidence';
        }
        json_decode($evidenceJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'invalid_evidence_json';
        }

        return 'valid_evidence_json';
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

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }

    private function escapeCell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    private function csvCell(string $value): string
    {
        if (strpbrk($value, "\",\n\r") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
