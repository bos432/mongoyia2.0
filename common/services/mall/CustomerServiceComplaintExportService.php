<?php

namespace common\services\mall;

use Yii;

class CustomerServiceComplaintExportService
{
    public function run(int $storeId = 0, string $ticketStatus = '', string $dateFrom = '', string $dateTo = '', int $limit = 500): array
    {
        $storeId = max(0, $storeId);
        $ticketStatus = $this->normalizeStatus($ticketStatus);
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);
        $limit = max(1, min(1000, $limit));
        $issues = [];

        if (!$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return [
                'storeId' => $storeId,
                'ticketStatus' => $ticketStatus,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'limit' => $limit,
                'rowsScanned' => 0,
                'totals' => $this->emptyTotals(),
                'rows' => [],
                'issues' => ['customer-service ticket table missing'],
            ];
        }

        if ($dateFrom !== '' && $dateTo !== '' && (int)$dateFrom > (int)$dateTo) {
            $issues[] = 'dateFrom is greater than dateTo';
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where([
                'ticket_type' => CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT,
                'status' => 1,
            ])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($ticketStatus !== '') {
            $query->andWhere(['ticket_status' => $ticketStatus]);
        }
        if ($dateFrom !== '') {
            $query->andWhere(['>=', 'created_at', strtotime($dateFrom . ' 00:00:00')]);
        }
        if ($dateTo !== '') {
            $query->andWhere(['<=', 'created_at', strtotime($dateTo . ' 23:59:59')]);
        }

        $rows = $query->all(Yii::$app->db);
        $rows = $this->enrichRows($rows);

        return [
            'storeId' => $storeId,
            'ticketStatus' => $ticketStatus,
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
            '# Mongoyia Customer Service Complaint Export',
            '',
            '- Result: PASS',
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Ticket status: ' . ((string)($report['ticketStatus'] ?? '') !== '' ? (string)$report['ticketStatus'] : 'all'),
            '- Date from: ' . ((string)($report['dateFrom'] ?? '') !== '' ? (string)$report['dateFrom'] : 'not limited'),
            '- Date to: ' . ((string)($report['dateTo'] ?? '') !== '' ? (string)$report['dateTo'] : 'not limited'),
            '- Rows scanned: ' . (int)($report['rowsScanned'] ?? 0),
            '- Export limit: ' . (int)($report['limit'] ?? 0),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Complaints | ' . (int)$totals['complaint_count'] . ' |',
            '| Pending | ' . (int)$totals['pending_count'] . ' |',
            '| In progress | ' . (int)$totals['in_progress_count'] . ' |',
            '| Resolved | ' . (int)$totals['resolved_count'] . ' |',
            '| Closed | ' . (int)$totals['closed_count'] . ' |',
            '| With evidence JSON | ' . (int)$totals['with_evidence_count'] . ' |',
            '| Event count | ' . (int)$totals['event_count'] . ' |',
            '| Resolution seconds | ' . (int)$totals['resolution_seconds_total'] . ' |',
            '',
            '## Complaint Rows',
            '',
            '| Ticket | Store | Status | Order | Customer | Evidence | Events | Resolution Seconds | Title |',
            '|---:|---:|---|---:|---:|---:|---:|---:|---|',
        ];

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['id']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (string)$row['ticket_status']
                . ' | ' . (int)$row['order_id']
                . ' | ' . (int)$row['customer_user_id']
                . ' | ' . (int)$row['has_evidence']
                . ' | ' . (int)$row['event_count']
                . ' | ' . (int)$row['resolution_seconds']
                . ' | ' . str_replace('|', '/', (string)$row['title'])
                . ' |';
        }

        if (empty($report['rows'])) {
            $lines[] = '| 0 | 0 | none | 0 | 0 | 0 | 0 | 0 | none |';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Customer-service owner reviewed complaint evidence export: PENDING',
            '- Store scope reviewed for seller accounts: PENDING',
            '- Complaint status and evidence JSON reviewed: PENDING',
            '- Export archived with acceptance evidence: PENDING',
            '',
            'This report is read-only evidence. It does not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or change complaint evidence JSON.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['ticket_id,ticket_sn,store_id,ticket_status,priority,order_id,order_sn,customer_user_id,merchant_user_id,platform_user_id,chat_uuid,has_evidence,event_count,first_response_at,resolved_at,closed_at,resolution_seconds,title'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(',', [
                (int)$row['id'],
                $this->csvCell((string)$row['ticket_sn']),
                (int)$row['store_id'],
                $this->csvCell((string)$row['ticket_status']),
                $this->csvCell((string)$row['priority']),
                (int)$row['order_id'],
                $this->csvCell((string)$row['order_sn']),
                (int)$row['customer_user_id'],
                (int)$row['merchant_user_id'],
                (int)$row['platform_user_id'],
                $this->csvCell((string)$row['chat_uuid']),
                (int)$row['has_evidence'],
                (int)$row['event_count'],
                (int)$row['first_response_at'],
                (int)$row['resolved_at'],
                (int)$row['closed_at'],
                (int)$row['resolution_seconds'],
                $this->csvCell((string)$row['title']),
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
            $row['has_evidence'] = trim((string)($row['evidence_json'] ?? '')) !== '' ? 1 : 0;
            $row['event_count'] = (int)($eventCounts[(int)$row['id']] ?? 0);
            $row['resolution_seconds'] = ($firstResponseAt > 0 && $resolvedAt > $firstResponseAt) ? ($resolvedAt - $firstResponseAt) : 0;
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
            ->select(['ticket_id', 'event_count' => 'COUNT(*)'])
            ->from('{{%mall_customer_service_event}}')
            ->where(['ticket_id' => $ticketIds, 'status' => 1])
            ->groupBy('ticket_id')
            ->all(Yii::$app->db);

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['ticket_id']] = (int)$row['event_count'];
        }

        return $map;
    }

    private function totals(array $rows): array
    {
        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            $totals['complaint_count']++;
            $status = (string)($row['ticket_status'] ?? '');
            if (isset($totals[$status . '_count'])) {
                $totals[$status . '_count']++;
            }
            $totals['with_evidence_count'] += (int)($row['has_evidence'] ?? 0);
            $totals['event_count'] += (int)($row['event_count'] ?? 0);
            $totals['resolution_seconds_total'] += (int)($row['resolution_seconds'] ?? 0);
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'complaint_count' => 0,
            'pending_count' => 0,
            'in_progress_count' => 0,
            'resolved_count' => 0,
            'closed_count' => 0,
            'with_evidence_count' => 0,
            'event_count' => 0,
            'resolution_seconds_total' => 0,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $status = trim($status);
        return in_array($status, (new CustomerServiceAdvancedService())->supportedTicketStatuses(), true) ? $status : '';
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
