<?php

namespace common\services\mall;

use Yii;

class CustomerServiceStatApplyGateService
{
    public function run(int $storeId = 0, string $dateFrom = '', string $dateTo = '', int $limit = 500): array
    {
        $storeId = max(0, $storeId);
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);
        $limit = max(1, min(1000, $limit));
        $issues = [];

        $ticketTableMissing = !$this->tableExists('{{%mall_customer_service_ticket}}');
        $statTableMissing = !$this->tableExists('{{%mall_customer_service_stat_daily}}');
        if ($ticketTableMissing || $statTableMissing) {
            if ($ticketTableMissing) {
                $issues[] = 'customer-service ticket table missing';
            }
            if ($statTableMissing) {
                $issues[] = 'customer-service stat table missing';
            }

            return [
                'storeId' => $storeId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'limit' => $limit,
                'rowsScanned' => 0,
                'totals' => $this->emptyTotals(),
                'planRows' => [],
                'gateChecks' => $this->gateChecks(true),
                'issues' => $issues,
            ];
        }

        if ($dateFrom !== '' && $dateTo !== '' && (int)str_replace('-', '', $dateFrom) > (int)str_replace('-', '', $dateTo)) {
            $issues[] = 'dateFrom is greater than dateTo';
        }

        $tickets = $this->ticketRows($storeId, $dateFrom, $dateTo, $limit);
        $draftRows = $this->draftStatRows($tickets);
        $planRows = $this->planRows($draftRows, $this->existingStatRows($storeId, $dateFrom, $dateTo));

        return [
            'storeId' => $storeId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'rowsScanned' => count($tickets),
            'totals' => $this->totals($planRows),
            'planRows' => $planRows,
            'gateChecks' => $this->gateChecks(false),
            'issues' => $issues,
        ];
    }

    public function markdownLines(array $report): array
    {
        $totals = $report['totals'] ?? $this->emptyTotals();
        $lines = [
            '# Mongoyia Customer Service Stat Apply Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Date from: ' . ((string)($report['dateFrom'] ?? '') !== '' ? (string)$report['dateFrom'] : 'not limited'),
            '- Date to: ' . ((string)($report['dateTo'] ?? '') !== '' ? (string)$report['dateTo'] : 'not limited'),
            '- Tickets scanned: ' . (int)($report['rowsScanned'] ?? 0),
            '- Export limit: ' . (int)($report['limit'] ?? 0),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Source tickets | ' . (int)$totals['source_ticket_count'] . ' |',
            '| Draft stat rows | ' . (int)$totals['draft_row_count'] . ' |',
            '| Insert planned | ' . (int)$totals['insert_count'] . ' |',
            '| Update planned | ' . (int)$totals['update_count'] . ' |',
            '| Skip planned | ' . (int)$totals['skip_count'] . ' |',
            '| Ready to apply | ' . (int)$totals['ready_to_apply_count'] . ' |',
            '',
            '## Gate Checks',
            '',
            '| Gate | Status | Details |',
            '|---|---|---|',
        ];

        foreach (($report['gateChecks'] ?? []) as $check) {
            $lines[] = '| ' . $this->escapeCell((string)$check['key'])
                . ' | ' . $this->escapeCell((string)$check['status'])
                . ' | ' . $this->escapeCell((string)$check['details'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Dry-run Apply Plan',
            '',
            '| Date | Store | Service User | Operation | Source Tickets | Sessions | Tickets | Order Assists | Complaints | Resolved | Unresolved | Diff Count | Diff Summary |',
            '|---:|---:|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|---|',
        ]);

        foreach (($report['planRows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['stat_date']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (int)$row['service_user_id']
                . ' | ' . $this->escapeCell((string)$row['operation'])
                . ' | ' . (int)$row['source_ticket_count']
                . ' | ' . (int)$row['session_count']
                . ' | ' . (int)$row['ticket_count']
                . ' | ' . (int)$row['order_assist_count']
                . ' | ' . (int)$row['complaint_count']
                . ' | ' . (int)$row['resolved_count']
                . ' | ' . (int)$row['unresolved_count']
                . ' | ' . (int)$row['diff_count']
                . ' | ' . $this->escapeCell((string)$row['diff_summary'])
                . ' |';
        }

        if (empty($report['planRows'])) {
            $lines[] = '| 0 | 0 | 0 | skip | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | none |';
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
            '- Customer-service owner reviewed insert/update/skip plan: PENDING',
            '- Statistic owner approved source-ticket aggregation rules: PENDING',
            '- Audit event and rollback policy reviewed before real apply: PENDING',
            '- Backend statistic write controls remain disabled: PENDING',
            '',
            'This report is read-only apply gate evidence. It does not create tickets, mutate ticket workflow state, write customer-service statistics, send IM messages, upload files, change orders, change payments, write fund logs, or enable statistic apply handling.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['stat_date,store_id,service_user_id,operation,existing_id,source_ticket_count,session_count,ticket_count,order_assist_count,complaint_count,resolved_count,unresolved_count,first_response_seconds_total,resolved_seconds_total,diff_count,diff_summary'];
        foreach (($report['planRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                (int)$row['stat_date'],
                (int)$row['store_id'],
                (int)$row['service_user_id'],
                $this->csvCell((string)$row['operation']),
                (int)$row['existing_id'],
                (int)$row['source_ticket_count'],
                (int)$row['session_count'],
                (int)$row['ticket_count'],
                (int)$row['order_assist_count'],
                (int)$row['complaint_count'],
                (int)$row['resolved_count'],
                (int)$row['unresolved_count'],
                (int)$row['first_response_seconds_total'],
                (int)$row['resolved_seconds_total'],
                (int)$row['diff_count'],
                $this->csvCell((string)$row['diff_summary']),
            ]);
        }

        return $lines;
    }

    private function ticketRows(int $storeId, string $dateFrom, string $dateTo, int $limit): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['status' => 1])
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($dateFrom !== '') {
            $query->andWhere(['>=', 'created_at', strtotime($dateFrom . ' 00:00:00')]);
        }
        if ($dateTo !== '') {
            $query->andWhere(['<=', 'created_at', strtotime($dateTo . ' 23:59:59')]);
        }

        return $query->all(Yii::$app->db);
    }

    private function existingStatRows(int $storeId, string $dateFrom, string $dateTo): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_stat_daily}}')
            ->where(['status' => 1]);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($dateFrom !== '') {
            $query->andWhere(['>=', 'stat_date', (int)str_replace('-', '', $dateFrom)]);
        }
        if ($dateTo !== '') {
            $query->andWhere(['<=', 'stat_date', (int)str_replace('-', '', $dateTo)]);
        }

        $rows = [];
        foreach ($query->all(Yii::$app->db) as $row) {
            $rows[$this->statKey($row)] = $row;
        }

        return $rows;
    }

    private function draftStatRows(array $tickets): array
    {
        $rows = [];
        foreach ($tickets as $ticket) {
            $createdAt = (int)($ticket['created_at'] ?? 0);
            $statDate = (int)date('Ymd', $createdAt > 0 ? $createdAt : time());
            $serviceUserId = $this->serviceUserId($ticket);
            $key = $statDate . ':' . (int)$ticket['store_id'] . ':' . $serviceUserId;
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'stat_date' => $statDate,
                    'store_id' => (int)$ticket['store_id'],
                    'service_user_id' => $serviceUserId,
                    'source_ticket_count' => 0,
                    'session_count' => 0,
                    'ticket_count' => 0,
                    'order_assist_count' => 0,
                    'complaint_count' => 0,
                    'resolved_count' => 0,
                    'unresolved_count' => 0,
                    'first_response_seconds_total' => 0,
                    'resolved_seconds_total' => 0,
                    '_sessions' => [],
                ];
            }

            $rows[$key]['source_ticket_count']++;
            $rows[$key]['ticket_count']++;
            $ticketType = (string)($ticket['ticket_type'] ?? '');
            if ($ticketType === CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST) {
                $rows[$key]['order_assist_count']++;
            } elseif ($ticketType === CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT) {
                $rows[$key]['complaint_count']++;
            }

            $ticketStatus = (string)($ticket['ticket_status'] ?? '');
            if (in_array($ticketStatus, [
                CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED,
                CustomerServiceAdvancedService::TICKET_STATUS_CLOSED,
            ], true)) {
                $rows[$key]['resolved_count']++;
            } else {
                $rows[$key]['unresolved_count']++;
            }

            $chatUuid = trim((string)($ticket['chat_uuid'] ?? ''));
            if ($chatUuid !== '') {
                $rows[$key]['_sessions'][$chatUuid] = true;
            }

            $firstResponseAt = (int)($ticket['first_response_at'] ?? 0);
            if ($createdAt > 0 && $firstResponseAt > $createdAt) {
                $rows[$key]['first_response_seconds_total'] += $firstResponseAt - $createdAt;
            }
            $resolvedAt = (int)($ticket['resolved_at'] ?? 0);
            if ($createdAt > 0 && $resolvedAt > $createdAt) {
                $rows[$key]['resolved_seconds_total'] += $resolvedAt - $createdAt;
            }
        }

        foreach ($rows as &$row) {
            $row['session_count'] = count($row['_sessions']);
            unset($row['_sessions']);
        }
        unset($row);

        return array_values($rows);
    }

    private function planRows(array $draftRows, array $existingRows): array
    {
        $rows = [];
        foreach ($draftRows as $draft) {
            $key = $this->statKey($draft);
            $existing = $existingRows[$key] ?? [];
            $diffs = $this->diffs($draft, $existing);
            $operation = empty($existing) ? 'insert' : (!empty($diffs) ? 'update' : 'skip');
            $draft['operation'] = $operation;
            $draft['existing_id'] = (int)($existing['id'] ?? 0);
            $draft['diff_count'] = count($diffs);
            $draft['diff_summary'] = empty($diffs) ? 'none' : implode('; ', $diffs);
            $rows[] = $draft;
        }

        usort($rows, function ($a, $b) {
            return [$a['stat_date'], $a['store_id'], $a['service_user_id']] <=> [$b['stat_date'], $b['store_id'], $b['service_user_id']];
        });

        return $rows;
    }

    private function diffs(array $draft, array $existing): array
    {
        if (empty($existing)) {
            return ['new stat row'];
        }

        $diffs = [];
        foreach ($this->statFields() as $field) {
            $expected = (int)($draft[$field] ?? 0);
            $actual = (int)($existing[$field] ?? 0);
            if ($expected !== $actual) {
                $diffs[] = $field . ':' . $actual . '->' . $expected;
            }
        }

        return $diffs;
    }

    private function statFields(): array
    {
        return [
            'session_count',
            'ticket_count',
            'order_assist_count',
            'complaint_count',
            'resolved_count',
            'unresolved_count',
            'first_response_seconds_total',
            'resolved_seconds_total',
        ];
    }

    private function serviceUserId(array $ticket): int
    {
        foreach (['merchant_user_id', 'platform_user_id', 'updated_by', 'created_by'] as $field) {
            $value = (int)($ticket[$field] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    private function totals(array $planRows): array
    {
        $totals = $this->emptyTotals();
        foreach ($planRows as $row) {
            $totals['source_ticket_count'] += (int)($row['source_ticket_count'] ?? 0);
            $totals['draft_row_count']++;
            $operation = (string)($row['operation'] ?? '');
            if ($operation === 'insert') {
                $totals['insert_count']++;
                $totals['ready_to_apply_count']++;
            } elseif ($operation === 'update') {
                $totals['update_count']++;
                $totals['ready_to_apply_count']++;
            } elseif ($operation === 'skip') {
                $totals['skip_count']++;
            }
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'source_ticket_count' => 0,
            'draft_row_count' => 0,
            'insert_count' => 0,
            'update_count' => 0,
            'skip_count' => 0,
            'ready_to_apply_count' => 0,
        ];
    }

    private function gateChecks(bool $schemaMissing): array
    {
        return [
            [
                'key' => 'source_ticket_schema',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'details' => 'customer-service tickets can be aggregated into daily stat drafts',
            ],
            [
                'key' => 'stat_unique_key',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'details' => 'stat_date/store_id/service_user_id can be compared before upsert',
            ],
            [
                'key' => 'dry_run_diff',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'details' => 'insert/update/skip rows are reported before any statistic write',
            ],
            [
                'key' => 'apply_audit_event',
                'status' => 'reserved',
                'details' => 'real stat apply must add audited operator metadata and rollback evidence before enablement',
            ],
            [
                'key' => 'write_handler',
                'status' => 'reserved',
                'details' => 'customer-service statistic write handling remains disabled until an audited apply workflow lands',
            ],
        ];
    }

    private function statKey(array $row): string
    {
        return (int)($row['stat_date'] ?? 0) . ':' . (int)($row['store_id'] ?? 0) . ':' . (int)($row['service_user_id'] ?? 0);
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        $date = str_replace(['/', '.'], '-', $date);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
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
