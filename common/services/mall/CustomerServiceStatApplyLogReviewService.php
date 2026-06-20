<?php

namespace common\services\mall;

use Yii;

class CustomerServiceStatApplyLogReviewService
{
    public function run(
        int $storeId = 0,
        string $dateFrom = '',
        string $dateTo = '',
        string $batchSn = '',
        string $operation = '',
        int $limit = 500
    ): array {
        $storeId = max(0, $storeId);
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);
        $batchSn = trim($batchSn);
        $operation = $this->normalizeOperation($operation);
        $limit = max(1, min(1000, $limit));
        $issues = [];

        if (!$this->tableExists('{{%mall_customer_service_stat_apply_log}}')) {
            return [
                'storeId' => $storeId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'batchSn' => $batchSn,
                'operation' => $operation,
                'limit' => $limit,
                'rowsScanned' => 0,
                'totals' => $this->emptyTotals(),
                'rows' => [],
                'issues' => ['customer-service stat apply log table missing'],
            ];
        }

        if ($dateFrom !== '' && $dateTo !== '' && (int)$dateFrom > (int)$dateTo) {
            $issues[] = 'dateFrom is greater than dateTo';
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_stat_apply_log}}')
            ->where(['status' => 1])
            ->orderBy(['applied_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($dateFrom !== '') {
            $query->andWhere(['>=', 'stat_date', (int)$dateFrom]);
        }
        if ($dateTo !== '') {
            $query->andWhere(['<=', 'stat_date', (int)$dateTo]);
        }
        if ($batchSn !== '') {
            $query->andWhere(['batch_sn' => $batchSn]);
        }
        if ($operation !== '') {
            $query->andWhere(['operation' => $operation]);
        }

        $rows = $query->all(Yii::$app->db);

        return [
            'storeId' => $storeId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'batchSn' => $batchSn,
            'operation' => $operation,
            'limit' => $limit,
            'rowsScanned' => count($rows),
            'totals' => $this->totals($rows),
            'rows' => $rows,
            'issues' => $issues,
        ];
    }

    public function markdownLines(array $report): array
    {
        $totals = $report['totals'] ?? $this->emptyTotals();
        $lines = [
            '# Mongoyia Customer Service Stat Apply Log Review',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Date from: ' . ((string)($report['dateFrom'] ?? '') !== '' ? (string)$report['dateFrom'] : 'not limited'),
            '- Date to: ' . ((string)($report['dateTo'] ?? '') !== '' ? (string)$report['dateTo'] : 'not limited'),
            '- Batch SN: ' . ((string)($report['batchSn'] ?? '') !== '' ? (string)$report['batchSn'] : 'not limited'),
            '- Operation: ' . ((string)($report['operation'] ?? '') !== '' ? (string)$report['operation'] : 'all'),
            '- Rows scanned: ' . (int)($report['rowsScanned'] ?? 0),
            '- Review limit: ' . (int)($report['limit'] ?? 0),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Audit rows | ' . (int)$totals['audit_log_count'] . ' |',
            '| Insert logs | ' . (int)$totals['insert_count'] . ' |',
            '| Update logs | ' . (int)$totals['update_count'] . ' |',
            '| Skip logs | ' . (int)$totals['skip_count'] . ' |',
            '| Source tickets | ' . (int)$totals['source_ticket_count'] . ' |',
            '| Batches | ' . (int)$totals['batch_count'] . ' |',
            '| Stores | ' . (int)$totals['store_count'] . ' |',
            '| Operators | ' . (int)$totals['operator_count'] . ' |',
            '',
            '## Audit Log Rows',
            '',
            '| ID | Batch | Date | Store | Service User | Operation | Stat ID | Source Tickets | Operator | Applied At | Diff Summary |',
            '|---:|---|---:|---:|---:|---|---:|---:|---:|---|---|',
        ];

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['id']
                . ' | ' . $this->escapeCell((string)$row['batch_sn'])
                . ' | ' . (int)$row['stat_date']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (int)$row['service_user_id']
                . ' | ' . $this->escapeCell((string)$row['operation'])
                . ' | ' . (int)$row['stat_id']
                . ' | ' . (int)$row['source_ticket_count']
                . ' | ' . (int)$row['operator_user_id']
                . ' | ' . $this->formatTime((int)$row['applied_at'])
                . ' | ' . $this->escapeCell((string)$row['diff_summary'])
                . ' |';
        }

        if (empty($report['rows'])) {
            $lines[] = '| 0 | none | 0 | 0 | 0 | none | 0 | 0 | 0 |  | none |';
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
            '- Finance/customer-service owner reviewed apply batch logs: PENDING',
            '- Store scope reviewed for seller accounts: PENDING',
            '- Insert/update/skip rows matched the approved dry-run plan: PENDING',
            '- Backend statistic write button remains disabled: PENDING',
            '',
            'This report is read-only audit evidence. It does not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or enable backend statistic apply controls.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['id,batch_sn,stat_date,store_id,service_user_id,operation,stat_id,source_ticket_count,operator_user_id,applied_at,diff_summary'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(',', [
                (int)$row['id'],
                $this->csvCell((string)$row['batch_sn']),
                (int)$row['stat_date'],
                (int)$row['store_id'],
                (int)$row['service_user_id'],
                $this->csvCell((string)$row['operation']),
                (int)$row['stat_id'],
                (int)$row['source_ticket_count'],
                (int)$row['operator_user_id'],
                (int)$row['applied_at'],
                $this->csvCell((string)$row['diff_summary']),
            ]);
        }

        return $lines;
    }

    private function totals(array $rows): array
    {
        $totals = $this->emptyTotals();
        $batches = [];
        $stores = [];
        $operators = [];
        foreach ($rows as $row) {
            $totals['audit_log_count']++;
            $operation = (string)($row['operation'] ?? '');
            if ($operation === 'insert') {
                $totals['insert_count']++;
            } elseif ($operation === 'update') {
                $totals['update_count']++;
            } elseif ($operation === 'skip') {
                $totals['skip_count']++;
            }
            $totals['source_ticket_count'] += (int)($row['source_ticket_count'] ?? 0);
            $batches[(string)($row['batch_sn'] ?? '')] = true;
            $stores[(int)($row['store_id'] ?? 0)] = true;
            $operators[(int)($row['operator_user_id'] ?? 0)] = true;
        }
        unset($batches[''], $stores[0], $operators[0]);
        $totals['batch_count'] = count($batches);
        $totals['store_count'] = count($stores);
        $totals['operator_count'] = count($operators);

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'audit_log_count' => 0,
            'insert_count' => 0,
            'update_count' => 0,
            'skip_count' => 0,
            'source_ticket_count' => 0,
            'batch_count' => 0,
            'store_count' => 0,
            'operator_count' => 0,
        ];
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        $date = str_replace(['-', '/', '.'], '', $date);

        return preg_match('/^\d{8}$/', $date) ? $date : '';
    }

    private function normalizeOperation(string $operation): string
    {
        $operation = trim($operation);
        return in_array($operation, ['insert', 'update', 'skip'], true) ? $operation : '';
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }

    private function formatTime(int $time): string
    {
        return $time > 0 ? date('Y-m-d H:i:s', $time) : '';
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
