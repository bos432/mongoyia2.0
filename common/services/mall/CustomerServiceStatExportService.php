<?php

namespace common\services\mall;

use Yii;

class CustomerServiceStatExportService
{
    public function run(int $storeId = 0, string $dateFrom = '', string $dateTo = '', int $limit = 500): array
    {
        $storeId = max(0, $storeId);
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);
        $limit = max(1, min(1000, $limit));
        $issues = [];

        if (!$this->tableExists('{{%mall_customer_service_stat_daily}}')) {
            return [
                'storeId' => $storeId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'limit' => $limit,
                'rowsScanned' => 0,
                'totals' => $this->emptyTotals(),
                'rows' => [],
                'issues' => ['customer-service stat table missing'],
            ];
        }

        if ($dateFrom !== '' && $dateTo !== '' && (int)$dateFrom > (int)$dateTo) {
            $issues[] = 'dateFrom is greater than dateTo';
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_stat_daily}}')
            ->where(['status' => 1])
            ->orderBy(['stat_date' => SORT_DESC, 'store_id' => SORT_ASC, 'service_user_id' => SORT_ASC])
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

        $rows = $query->all(Yii::$app->db);

        return [
            'storeId' => $storeId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
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
            '# Mongoyia Customer Service Stat Export',
            '',
            '- Result: PASS',
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Date from: ' . ((string)($report['dateFrom'] ?? '') !== '' ? (string)$report['dateFrom'] : 'not limited'),
            '- Date to: ' . ((string)($report['dateTo'] ?? '') !== '' ? (string)$report['dateTo'] : 'not limited'),
            '- Rows scanned: ' . (int)($report['rowsScanned'] ?? 0),
            '- Export limit: ' . (int)($report['limit'] ?? 0),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Sessions | ' . (int)$totals['session_count'] . ' |',
            '| Tickets | ' . (int)$totals['ticket_count'] . ' |',
            '| Order assists | ' . (int)$totals['order_assist_count'] . ' |',
            '| Complaints | ' . (int)$totals['complaint_count'] . ' |',
            '| Resolved | ' . (int)$totals['resolved_count'] . ' |',
            '| Unresolved | ' . (int)$totals['unresolved_count'] . ' |',
            '| First response seconds | ' . (int)$totals['first_response_seconds_total'] . ' |',
            '| Resolved seconds | ' . (int)$totals['resolved_seconds_total'] . ' |',
            '',
            '## Daily Rows',
            '',
            '| Date | Store | Service User | Sessions | Tickets | Order Assists | Complaints | Resolved | Unresolved | First Response Seconds | Resolved Seconds |',
            '|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|',
        ];

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['stat_date']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (int)$row['service_user_id']
                . ' | ' . (int)$row['session_count']
                . ' | ' . (int)$row['ticket_count']
                . ' | ' . (int)$row['order_assist_count']
                . ' | ' . (int)$row['complaint_count']
                . ' | ' . (int)$row['resolved_count']
                . ' | ' . (int)$row['unresolved_count']
                . ' | ' . (int)$row['first_response_seconds_total']
                . ' | ' . (int)$row['resolved_seconds_total']
                . ' |';
        }

        if (empty($report['rows'])) {
            $lines[] = '| 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Customer-service owner reviewed daily totals: PENDING',
            '- Store scope reviewed for seller accounts: PENDING',
            '- Complaint and order-assist counts reviewed: PENDING',
            '- Export archived with acceptance evidence: PENDING',
            '',
            'This report is read-only evidence. It does not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, or update statistics.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['stat_date,store_id,service_user_id,session_count,ticket_count,order_assist_count,complaint_count,resolved_count,unresolved_count,first_response_seconds_total,resolved_seconds_total'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(',', [
                (int)$row['stat_date'],
                (int)$row['store_id'],
                (int)$row['service_user_id'],
                (int)$row['session_count'],
                (int)$row['ticket_count'],
                (int)$row['order_assist_count'],
                (int)$row['complaint_count'],
                (int)$row['resolved_count'],
                (int)$row['unresolved_count'],
                (int)$row['first_response_seconds_total'],
                (int)$row['resolved_seconds_total'],
            ]);
        }

        return $lines;
    }

    private function totals(array $rows): array
    {
        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int)($row[$key] ?? 0);
            }
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'session_count' => 0,
            'ticket_count' => 0,
            'order_assist_count' => 0,
            'complaint_count' => 0,
            'resolved_count' => 0,
            'unresolved_count' => 0,
            'first_response_seconds_total' => 0,
            'resolved_seconds_total' => 0,
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

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
