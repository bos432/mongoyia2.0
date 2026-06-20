<?php

namespace common\services\mall;

use Yii;

class CustomerServiceStatWidgetReadinessService
{
    public function run(int $storeId = 0, string $dateFrom = '', string $dateTo = '', int $limit = 500): array
    {
        $storeId = max(0, $storeId);
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);
        $limit = max(1, min(1000, $limit));
        $issues = [];

        if ($dateFrom !== '' && $dateTo !== '' && (int)$dateFrom > (int)$dateTo) {
            $issues[] = 'dateFrom is greater than dateTo';
        }

        if (!$this->tableExists('{{%mall_customer_service_stat_daily}}')) {
            return [
                'storeId' => $storeId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'limit' => $limit,
                'rowsScanned' => 0,
                'totals' => $this->emptyTotals(),
                'widgets' => $this->widgets($this->emptyTotals(), 0, 0, true),
                'issues' => array_merge($issues, ['customer-service stat table missing']),
            ];
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
        $totals = $this->totals($rows);

        return [
            'storeId' => $storeId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'rowsScanned' => count($rows),
            'totals' => $totals,
            'widgets' => $this->widgets($totals, count($rows), $this->distinctStoreCount($rows), false),
            'issues' => $issues,
        ];
    }

    public function markdownLines(array $report): array
    {
        $totals = $report['totals'] ?? $this->emptyTotals();
        $lines = [
            '# Mongoyia Customer Service Stat Widget Readiness',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
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
            '## Widget Readiness',
            '',
            '| Widget | Status | Value | Details |',
            '|---|---|---:|---|',
        ];

        foreach (($report['widgets'] ?? []) as $widget) {
            $lines[] = '| ' . $this->escapeCell((string)$widget['key'])
                . ' | ' . $this->escapeCell((string)$widget['status'])
                . ' | ' . $this->escapeCell((string)$widget['value'])
                . ' | ' . $this->escapeCell((string)$widget['details'])
                . ' |';
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
            '- Customer-service owner reviewed widget totals: PENDING',
            '- Store-scoped seller widget behavior reviewed: PENDING',
            '- Statistic write button remains disabled: PENDING',
            '- Apply workflow requires a separate audited implementation before enablement: PENDING',
            '',
            'This report is read-only readiness evidence. It does not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or enable statistic write widgets.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['widget_key,status,value,details'];
        foreach (($report['widgets'] ?? []) as $widget) {
            $lines[] = implode(',', [
                $this->csvCell((string)$widget['key']),
                $this->csvCell((string)$widget['status']),
                $this->csvCell((string)$widget['value']),
                $this->csvCell((string)$widget['details']),
            ]);
        }

        return $lines;
    }

    private function widgets(array $totals, int $rowsScanned, int $storeCount, bool $schemaMissing): array
    {
        $resolvedTotal = (int)$totals['resolved_count'] + (int)$totals['unresolved_count'];
        $resolvedRate = $resolvedTotal > 0 ? round(((int)$totals['resolved_count'] / $resolvedTotal) * 100, 2) : 0;

        return [
            [
                'key' => 'daily_totals',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'value' => (string)$rowsScanned,
                'details' => 'daily stat rows available for dashboard totals',
            ],
            [
                'key' => 'store_scope',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'value' => (string)$storeCount,
                'details' => 'store-scoped dashboard totals can be filtered before seller display',
            ],
            [
                'key' => 'ticket_mix',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'value' => (string)((int)$totals['order_assist_count'] + (int)$totals['complaint_count']),
                'details' => 'order-assist and complaint counts are available for widget breakdown',
            ],
            [
                'key' => 'resolution_rate',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'value' => (string)$resolvedRate,
                'details' => 'resolved vs unresolved counts support a read-only resolution-rate widget',
            ],
            [
                'key' => 'response_time',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'value' => (string)((int)$totals['first_response_seconds_total'] + (int)$totals['resolved_seconds_total']),
                'details' => 'first-response and resolution seconds are available for trend widgets',
            ],
            [
                'key' => 'write_workflow',
                'status' => 'reserved',
                'value' => '0',
                'details' => 'statistic write widgets remain disabled until an audited apply workflow lands',
            ],
        ];
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

    private function distinctStoreCount(array $rows): int
    {
        $storeIds = [];
        foreach ($rows as $row) {
            $storeIds[(int)($row['store_id'] ?? 0)] = true;
        }

        return count($storeIds);
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
