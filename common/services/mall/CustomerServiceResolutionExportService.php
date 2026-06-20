<?php

namespace common\services\mall;

use Yii;

class CustomerServiceResolutionExportService
{
    public function run(int $storeId = 0, string $ticketType = '', string $dateFrom = '', string $dateTo = '', int $limit = 500): array
    {
        $storeId = max(0, $storeId);
        $ticketType = $this->normalizeTicketType($ticketType);
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);
        $limit = max(1, min(1000, $limit));
        $issues = [];

        if (!$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return [
                'storeId' => $storeId,
                'ticketType' => $ticketType,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'limit' => $limit,
                'rowsScanned' => 0,
                'totals' => $this->emptyTotals(),
                'rows' => [],
                'issues' => ['customer-service ticket table missing'],
            ];
        }

        if ($dateFrom !== '' && $dateTo !== '' && (int)str_replace('-', '', $dateFrom) > (int)str_replace('-', '', $dateTo)) {
            $issues[] = 'dateFrom is greater than dateTo';
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where([
                'ticket_status' => [
                    CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED,
                    CustomerServiceAdvancedService::TICKET_STATUS_CLOSED,
                ],
                'status' => 1,
            ])
            ->orderBy(['updated_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($ticketType !== '') {
            $query->andWhere(['ticket_type' => $ticketType]);
        }
        if ($dateFrom !== '') {
            $query->andWhere(['>=', 'created_at', strtotime($dateFrom . ' 00:00:00')]);
        }
        if ($dateTo !== '') {
            $query->andWhere(['<=', 'created_at', strtotime($dateTo . ' 23:59:59')]);
        }

        $rows = $this->enrichRows($query->all(Yii::$app->db));

        return [
            'storeId' => $storeId,
            'ticketType' => $ticketType,
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
            '# Mongoyia Customer Service Resolution Export',
            '',
            '- Result: PASS',
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Ticket type: ' . ((string)($report['ticketType'] ?? '') !== '' ? (string)$report['ticketType'] : 'all'),
            '- Date from: ' . ((string)($report['dateFrom'] ?? '') !== '' ? (string)$report['dateFrom'] : 'not limited'),
            '- Date to: ' . ((string)($report['dateTo'] ?? '') !== '' ? (string)$report['dateTo'] : 'not limited'),
            '- Rows scanned: ' . (int)($report['rowsScanned'] ?? 0),
            '- Export limit: ' . (int)($report['limit'] ?? 0),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Resolution tickets | ' . (int)$totals['resolution_count'] . ' |',
            '| Order assists | ' . (int)$totals['order_assist_count'] . ' |',
            '| Complaints | ' . (int)$totals['complaint_count'] . ' |',
            '| Resolved | ' . (int)$totals['resolved_count'] . ' |',
            '| Closed | ' . (int)$totals['closed_count'] . ' |',
            '| With result | ' . (int)$totals['with_result_count'] . ' |',
            '| Event count | ' . (int)$totals['event_count'] . ' |',
            '| Status-change events | ' . (int)$totals['status_change_event_count'] . ' |',
            '| Resolution seconds | ' . (int)$totals['resolution_seconds_total'] . ' |',
            '',
            '## Resolution Rows',
            '',
            '| Ticket | Store | Type | Status | Order | Events | Status Events | Resolution Seconds | Result | Title |',
            '|---:|---:|---|---|---:|---:|---:|---:|---:|---|',
        ];

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['id']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (string)$row['ticket_type']
                . ' | ' . (string)$row['ticket_status']
                . ' | ' . (int)$row['order_id']
                . ' | ' . (int)$row['event_count']
                . ' | ' . (int)$row['status_change_event_count']
                . ' | ' . (int)$row['resolution_seconds']
                . ' | ' . (int)$row['has_result']
                . ' | ' . str_replace('|', '/', (string)$row['title'])
                . ' |';
        }

        if (empty($report['rows'])) {
            $lines[] = '| 0 | 0 | none | none | 0 | 0 | 0 | 0 | 0 | none |';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Customer-service owner reviewed resolved/closed ticket results: PENDING',
            '- Store scope reviewed for seller accounts: PENDING',
            '- Resolution time and status-change evidence reviewed: PENDING',
            '- Export archived with acceptance evidence: PENDING',
            '',
            'This report is read-only evidence. It does not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or change ticket result fields.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['ticket_id,ticket_sn,ticket_type,store_id,ticket_status,priority,order_id,order_sn,customer_user_id,merchant_user_id,platform_user_id,event_count,status_change_event_count,first_response_at,resolved_at,closed_at,resolution_seconds,has_result,title,result'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(',', [
                (int)$row['id'],
                $this->csvCell((string)$row['ticket_sn']),
                $this->csvCell((string)$row['ticket_type']),
                (int)$row['store_id'],
                $this->csvCell((string)$row['ticket_status']),
                $this->csvCell((string)$row['priority']),
                (int)$row['order_id'],
                $this->csvCell((string)$row['order_sn']),
                (int)$row['customer_user_id'],
                (int)$row['merchant_user_id'],
                (int)$row['platform_user_id'],
                (int)$row['event_count'],
                (int)$row['status_change_event_count'],
                (int)$row['first_response_at'],
                (int)$row['resolved_at'],
                (int)$row['closed_at'],
                (int)$row['resolution_seconds'],
                (int)$row['has_result'],
                $this->csvCell((string)$row['title']),
                $this->csvCell((string)$row['result']),
            ]);
        }

        return $lines;
    }

    private function enrichRows(array $rows): array
    {
        $eventCounts = $this->eventCounts(array_map('intval', array_column($rows, 'id')));
        foreach ($rows as &$row) {
            $firstResponseAt = (int)($row['first_response_at'] ?? 0);
            $resolvedAt = (int)($row['resolved_at'] ?? 0);
            $row['event_count'] = (int)($eventCounts[(int)$row['id']]['event_count'] ?? 0);
            $row['status_change_event_count'] = (int)($eventCounts[(int)$row['id']]['status_change_event_count'] ?? 0);
            $row['resolution_seconds'] = ($firstResponseAt > 0 && $resolvedAt > $firstResponseAt) ? ($resolvedAt - $firstResponseAt) : 0;
            $row['has_result'] = trim((string)($row['result'] ?? '')) !== '' ? 1 : 0;
        }
        unset($row);

        return $rows;
    }

    private function eventCounts(array $ticketIds): array
    {
        $ticketIds = array_values(array_filter(array_unique($ticketIds)));
        if (!$ticketIds || !$this->tableExists('{{%mall_customer_service_event}}')) {
            return [];
        }

        $rows = (new \yii\db\Query())
            ->select([
                'ticket_id',
                'event_count' => 'COUNT(*)',
                'status_change_event_count' => 'SUM(CASE WHEN event_type = "status_change" THEN 1 ELSE 0 END)',
            ])
            ->from('{{%mall_customer_service_event}}')
            ->where(['ticket_id' => $ticketIds, 'status' => 1])
            ->groupBy('ticket_id')
            ->all(Yii::$app->db);

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['ticket_id']] = [
                'event_count' => (int)$row['event_count'],
                'status_change_event_count' => (int)$row['status_change_event_count'],
            ];
        }

        return $map;
    }

    private function totals(array $rows): array
    {
        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            $totals['resolution_count']++;
            $typeKey = (string)($row['ticket_type'] ?? '') . '_count';
            $statusKey = (string)($row['ticket_status'] ?? '') . '_count';
            if (isset($totals[$typeKey])) {
                $totals[$typeKey]++;
            }
            if (isset($totals[$statusKey])) {
                $totals[$statusKey]++;
            }
            $totals['with_result_count'] += (int)($row['has_result'] ?? 0);
            $totals['event_count'] += (int)($row['event_count'] ?? 0);
            $totals['status_change_event_count'] += (int)($row['status_change_event_count'] ?? 0);
            $totals['resolution_seconds_total'] += (int)($row['resolution_seconds'] ?? 0);
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'resolution_count' => 0,
            'order_assist_count' => 0,
            'complaint_count' => 0,
            'resolved_count' => 0,
            'closed_count' => 0,
            'with_result_count' => 0,
            'event_count' => 0,
            'status_change_event_count' => 0,
            'resolution_seconds_total' => 0,
        ];
    }

    private function normalizeTicketType(string $ticketType): string
    {
        $ticketType = trim($ticketType);
        return in_array($ticketType, (new CustomerServiceAdvancedService())->supportedTicketTypes(), true) ? $ticketType : '';
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

    private function csvCell(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
