<?php

namespace common\services\mall;

use Yii;

class CustomerServiceStatApplyWorkflowService
{
    public function run(
        int $storeId = 0,
        string $dateFrom = '',
        string $dateTo = '',
        int $limit = 500,
        bool $apply = false,
        int $operatorUserId = 1,
        string $remark = ''
    ): array {
        $operatorUserId = max(1, $operatorUserId);
        $remark = trim($remark) !== '' ? trim($remark) : 'customer-service stat apply workflow';
        $gateReport = (new CustomerServiceStatApplyGateService())->run($storeId, $dateFrom, $dateTo, $limit);
        $report = $gateReport;
        $report['apply'] = $apply;
        $report['operatorUserId'] = $operatorUserId;
        $report['batchSn'] = 'CSSAW-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $report['applyTotals'] = $this->emptyApplyTotals();
        $report['auditRows'] = [];

        if (!$this->tableExists('{{%mall_customer_service_stat_apply_log}}')) {
            $report['issues'][] = 'customer-service stat apply log table missing';
            return $report;
        }
        if (!$apply || !empty($report['issues'])) {
            return $report;
        }

        $transaction = null;
        $ownsTransaction = Yii::$app->db->getTransaction() === null;
        if ($ownsTransaction) {
            $transaction = Yii::$app->db->beginTransaction();
        }

        try {
            $now = time();
            foreach (($report['planRows'] ?? []) as $index => $row) {
                $result = $this->applyPlanRow($row, $report['batchSn'], $operatorUserId, $remark, $now);
                $report['planRows'][$index]['applied_status'] = $result['applied_status'];
                $report['planRows'][$index]['applied_stat_id'] = $result['stat_id'];
                $report['planRows'][$index]['audit_log_id'] = $result['audit_log_id'];
                $report['auditRows'][] = $result;
                $this->addApplyTotal($report['applyTotals'], $result['applied_status']);
            }

            if ($ownsTransaction && $transaction !== null) {
                $transaction->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $transaction !== null) {
                $transaction->rollBack();
            }
            $report['issues'][] = 'stat apply workflow failed: ' . $e->getMessage();
        }

        return $report;
    }

    public function markdownLines(array $report): array
    {
        $totals = $report['totals'] ?? [];
        $applyTotals = $report['applyTotals'] ?? $this->emptyApplyTotals();
        $lines = [
            '# Mongoyia Customer Service Stat Apply Workflow',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Mode: ' . (!empty($report['apply']) ? 'apply' : 'dry-run'),
            '- Batch SN: ' . (string)($report['batchSn'] ?? ''),
            '- Operator user ID: ' . (int)($report['operatorUserId'] ?? 0),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Date from: ' . ((string)($report['dateFrom'] ?? '') !== '' ? (string)$report['dateFrom'] : 'not limited'),
            '- Date to: ' . ((string)($report['dateTo'] ?? '') !== '' ? (string)$report['dateTo'] : 'not limited'),
            '- Tickets scanned: ' . (int)($report['rowsScanned'] ?? 0),
            '',
            '## Dry-run Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Source tickets | ' . (int)($totals['source_ticket_count'] ?? 0) . ' |',
            '| Draft stat rows | ' . (int)($totals['draft_row_count'] ?? 0) . ' |',
            '| Insert planned | ' . (int)($totals['insert_count'] ?? 0) . ' |',
            '| Update planned | ' . (int)($totals['update_count'] ?? 0) . ' |',
            '| Skip planned | ' . (int)($totals['skip_count'] ?? 0) . ' |',
            '',
            '## Apply Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Applied inserts | ' . (int)$applyTotals['applied_insert_count'] . ' |',
            '| Applied updates | ' . (int)$applyTotals['applied_update_count'] . ' |',
            '| Logged skips | ' . (int)$applyTotals['logged_skip_count'] . ' |',
            '| Audit rows | ' . (int)$applyTotals['audit_log_count'] . ' |',
            '',
            '## Workflow Rows',
            '',
            '| Date | Store | Service User | Operation | Applied Status | Stat ID | Audit Log | Diff Summary |',
            '|---:|---:|---:|---|---|---:|---:|---|',
        ];

        foreach (($report['planRows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['stat_date']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (int)$row['service_user_id']
                . ' | ' . $this->escapeCell((string)$row['operation'])
                . ' | ' . $this->escapeCell((string)($row['applied_status'] ?? 'dry_run'))
                . ' | ' . (int)($row['applied_stat_id'] ?? $row['existing_id'] ?? 0)
                . ' | ' . (int)($row['audit_log_id'] ?? 0)
                . ' | ' . $this->escapeCell((string)$row['diff_summary'])
                . ' |';
        }

        if (empty($report['planRows'])) {
            $lines[] = '| 0 | 0 | 0 | skip | dry_run | 0 | 0 | none |';
        }

        if (!empty($report['issues'])) {
            $lines[] = '';
            $lines[] = '## Issues';
            $lines[] = '';
            foreach ($report['issues'] as $issue) {
                $lines[] = '- ' . $issue;
            }
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Dry-run plan reviewed before apply: PENDING',
            '- Operator and batch number archived: PENDING',
            '- Audit log rows reviewed after apply: PENDING',
            '- Backend statistic write button remains disabled: PENDING',
            '',
            'Dry-run mode does not mutate statistics or audit logs. Apply mode writes only customer-service daily statistics and customer-service stat apply audit logs; it does not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, or write fund logs.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['batch_sn,mode,stat_date,store_id,service_user_id,operation,applied_status,stat_id,audit_log_id,diff_summary'];
        foreach (($report['planRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)($report['batchSn'] ?? '')),
                !empty($report['apply']) ? 'apply' : 'dry-run',
                (int)$row['stat_date'],
                (int)$row['store_id'],
                (int)$row['service_user_id'],
                $this->csvCell((string)$row['operation']),
                $this->csvCell((string)($row['applied_status'] ?? 'dry_run')),
                (int)($row['applied_stat_id'] ?? $row['existing_id'] ?? 0),
                (int)($row['audit_log_id'] ?? 0),
                $this->csvCell((string)$row['diff_summary']),
            ]);
        }

        return $lines;
    }

    private function applyPlanRow(array $row, string $batchSn, int $operatorUserId, string $remark, int $now): array
    {
        $operation = (string)($row['operation'] ?? 'skip');
        $before = [];
        $statId = (int)($row['existing_id'] ?? 0);
        if ($statId > 0) {
            $before = $this->statRowById($statId);
        }

        if ($operation === 'insert') {
            $statId = $this->insertStatRow($row, $operatorUserId, $remark, $now);
            $appliedStatus = 'applied_insert';
        } elseif ($operation === 'update') {
            $this->updateStatRow($statId, $row, $operatorUserId, $remark, $now);
            $appliedStatus = 'applied_update';
        } else {
            $appliedStatus = 'logged_skip';
        }

        $after = $statId > 0 ? $this->statRowById($statId) : [];
        $auditLogId = $this->insertAuditLog($batchSn, $row, $operation, $statId, $before, $after, $operatorUserId, $remark, $now);

        return [
            'applied_status' => $appliedStatus,
            'operation' => $operation,
            'stat_id' => $statId,
            'audit_log_id' => $auditLogId,
        ];
    }

    private function insertStatRow(array $row, int $operatorUserId, string $remark, int $now): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_stat_daily}}', array_merge($this->statWriteFields($row), [
            'remark' => $remark,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $operatorUserId,
            'updated_by' => $operatorUserId,
        ]))->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function updateStatRow(int $statId, array $row, int $operatorUserId, string $remark, int $now): void
    {
        Yii::$app->db->createCommand()->update('{{%mall_customer_service_stat_daily}}', array_merge($this->statWriteFields($row), [
            'remark' => $remark,
            'updated_at' => $now,
            'updated_by' => $operatorUserId,
        ]), ['id' => $statId])->execute();
    }

    private function insertAuditLog(string $batchSn, array $row, string $operation, int $statId, array $before, array $after, int $operatorUserId, string $remark, int $now): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_customer_service_stat_apply_log}}', [
            'batch_sn' => $batchSn,
            'stat_date' => (int)$row['stat_date'],
            'store_id' => (int)$row['store_id'],
            'service_user_id' => (int)$row['service_user_id'],
            'operation' => $operation,
            'stat_id' => $statId,
            'source_ticket_count' => (int)$row['source_ticket_count'],
            'before_json' => json_encode($this->jsonStatFields($before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_json' => json_encode($this->jsonStatFields($after), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'diff_summary' => (string)$row['diff_summary'],
            'operator_user_id' => $operatorUserId,
            'applied_at' => $now,
            'remark' => $remark,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $operatorUserId,
            'updated_by' => $operatorUserId,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function statWriteFields(array $row): array
    {
        return [
            'stat_date' => (int)$row['stat_date'],
            'store_id' => (int)$row['store_id'],
            'service_user_id' => (int)$row['service_user_id'],
            'session_count' => (int)$row['session_count'],
            'ticket_count' => (int)$row['ticket_count'],
            'order_assist_count' => (int)$row['order_assist_count'],
            'complaint_count' => (int)$row['complaint_count'],
            'resolved_count' => (int)$row['resolved_count'],
            'unresolved_count' => (int)$row['unresolved_count'],
            'first_response_seconds_total' => (int)$row['first_response_seconds_total'],
            'resolved_seconds_total' => (int)$row['resolved_seconds_total'],
        ];
    }

    private function jsonStatFields(array $row): array
    {
        if (empty($row)) {
            return [];
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'stat_date' => (int)($row['stat_date'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'service_user_id' => (int)($row['service_user_id'] ?? 0),
            'session_count' => (int)($row['session_count'] ?? 0),
            'ticket_count' => (int)($row['ticket_count'] ?? 0),
            'order_assist_count' => (int)($row['order_assist_count'] ?? 0),
            'complaint_count' => (int)($row['complaint_count'] ?? 0),
            'resolved_count' => (int)($row['resolved_count'] ?? 0),
            'unresolved_count' => (int)($row['unresolved_count'] ?? 0),
            'first_response_seconds_total' => (int)($row['first_response_seconds_total'] ?? 0),
            'resolved_seconds_total' => (int)($row['resolved_seconds_total'] ?? 0),
        ];
    }

    private function statRowById(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        return (new \yii\db\Query())
            ->from('{{%mall_customer_service_stat_daily}}')
            ->where(['id' => $id])
            ->one(Yii::$app->db) ?: [];
    }

    private function addApplyTotal(array &$totals, string $status): void
    {
        if ($status === 'applied_insert') {
            $totals['applied_insert_count']++;
        } elseif ($status === 'applied_update') {
            $totals['applied_update_count']++;
        } elseif ($status === 'logged_skip') {
            $totals['logged_skip_count']++;
        }
        $totals['audit_log_count']++;
    }

    private function emptyApplyTotals(): array
    {
        return [
            'applied_insert_count' => 0,
            'applied_update_count' => 0,
            'logged_skip_count' => 0,
            'audit_log_count' => 0,
        ];
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
