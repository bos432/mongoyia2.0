<?php

namespace common\services\mall;

use Yii;

class CustomerServiceSlaReadinessService
{
    public function run(int $storeId = 0, string $ticketType = '', string $dateFrom = '', string $dateTo = '', int $firstResponseSeconds = 1800, int $resolutionSeconds = 86400, int $limit = 500): array
    {
        $storeId = max(0, $storeId);
        $ticketType = $this->normalizeTicketType($ticketType);
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);
        $firstResponseSeconds = max(60, min(86400, $firstResponseSeconds));
        $resolutionSeconds = max(300, min(2592000, $resolutionSeconds));
        $limit = max(1, min(1000, $limit));
        $issues = [];

        if (!$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return [
                'storeId' => $storeId,
                'ticketType' => $ticketType,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'firstResponseSeconds' => $firstResponseSeconds,
                'resolutionSeconds' => $resolutionSeconds,
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
            ->where(['status' => 1])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
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

        $rows = $this->enrichRows($query->all(Yii::$app->db), $firstResponseSeconds, $resolutionSeconds);

        return [
            'storeId' => $storeId,
            'ticketType' => $ticketType,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'firstResponseSeconds' => $firstResponseSeconds,
            'resolutionSeconds' => $resolutionSeconds,
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
            '# Mongoyia Customer Service SLA Readiness',
            '',
            '- Result: PASS',
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Ticket type: ' . ((string)($report['ticketType'] ?? '') !== '' ? (string)$report['ticketType'] : 'all'),
            '- Date from: ' . ((string)($report['dateFrom'] ?? '') !== '' ? (string)$report['dateFrom'] : 'not limited'),
            '- Date to: ' . ((string)($report['dateTo'] ?? '') !== '' ? (string)$report['dateTo'] : 'not limited'),
            '- First response SLA seconds: ' . (int)($report['firstResponseSeconds'] ?? 0),
            '- Resolution SLA seconds: ' . (int)($report['resolutionSeconds'] ?? 0),
            '- Rows scanned: ' . (int)($report['rowsScanned'] ?? 0),
            '- Export limit: ' . (int)($report['limit'] ?? 0),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Tickets | ' . (int)$totals['ticket_count'] . ' |',
            '| Pending | ' . (int)$totals['pending_count'] . ' |',
            '| In progress | ' . (int)$totals['in_progress_count'] . ' |',
            '| Resolved | ' . (int)$totals['resolved_count'] . ' |',
            '| Closed | ' . (int)$totals['closed_count'] . ' |',
            '| Open tickets | ' . (int)$totals['open_count'] . ' |',
            '| First-response SLA breaches | ' . (int)$totals['first_response_breached_count'] . ' |',
            '| Resolution SLA breaches | ' . (int)$totals['resolution_breached_count'] . ' |',
            '| Resolved/closed without result | ' . (int)$totals['missing_result_count'] . ' |',
            '',
            '## SLA Rows',
            '',
            '| Ticket | Store | Type | Status | First Response Seconds | Resolution Seconds | First Breach | Resolution Breach | Missing Result | Title |',
            '|---:|---:|---|---|---:|---:|---:|---:|---:|---|',
        ];

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['id']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (string)$row['ticket_type']
                . ' | ' . (string)$row['ticket_status']
                . ' | ' . (int)$row['first_response_seconds']
                . ' | ' . (int)$row['resolution_seconds']
                . ' | ' . (int)$row['first_response_breached']
                . ' | ' . (int)$row['resolution_breached']
                . ' | ' . (int)$row['missing_result']
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
            '- Customer-service owner reviewed SLA thresholds: PENDING',
            '- First-response breach handling reviewed: PENDING',
            '- Resolution breach handling reviewed: PENDING',
            '- Result-field completion reviewed: PENDING',
            '- Export archived with acceptance evidence: PENDING',
            '',
            'This report is read-only evidence. It does not create tickets, mutate ticket workflow state, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or change ticket SLA/result fields.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['ticket_id,ticket_sn,ticket_type,store_id,ticket_status,priority,order_id,order_sn,created_at,first_response_at,resolved_at,closed_at,first_response_seconds,resolution_seconds,first_response_breached,resolution_breached,missing_result,title'];
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
                (int)$row['created_at'],
                (int)$row['first_response_at'],
                (int)$row['resolved_at'],
                (int)$row['closed_at'],
                (int)$row['first_response_seconds'],
                (int)$row['resolution_seconds'],
                (int)$row['first_response_breached'],
                (int)$row['resolution_breached'],
                (int)$row['missing_result'],
                $this->csvCell((string)$row['title']),
            ]);
        }

        return $lines;
    }

    private function enrichRows(array $rows, int $firstResponseSla, int $resolutionSla): array
    {
        $now = time();
        foreach ($rows as &$row) {
            $createdAt = (int)($row['created_at'] ?? 0);
            $firstResponseAt = (int)($row['first_response_at'] ?? 0);
            $resolvedAt = (int)($row['resolved_at'] ?? 0);
            $status = (string)($row['ticket_status'] ?? '');
            $isOpen = in_array($status, [
                CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
                CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS,
            ], true);
            $isFinished = in_array($status, [
                CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED,
                CustomerServiceAdvancedService::TICKET_STATUS_CLOSED,
            ], true);

            $firstResponseSeconds = 0;
            if ($createdAt > 0) {
                $firstResponseSeconds = $firstResponseAt > $createdAt ? ($firstResponseAt - $createdAt) : max(0, $now - $createdAt);
            }

            $resolutionSeconds = 0;
            if ($createdAt > 0) {
                if ($resolvedAt > $createdAt) {
                    $resolutionSeconds = $resolvedAt - $createdAt;
                } elseif ($isOpen) {
                    $resolutionSeconds = max(0, $now - $createdAt);
                }
            }

            $row['first_response_seconds'] = $firstResponseSeconds;
            $row['resolution_seconds'] = $resolutionSeconds;
            $row['first_response_breached'] = $firstResponseSeconds > $firstResponseSla ? 1 : 0;
            $row['resolution_breached'] = $resolutionSeconds > $resolutionSla ? 1 : 0;
            $row['missing_result'] = ($isFinished && trim((string)($row['result'] ?? '')) === '') ? 1 : 0;
        }
        unset($row);

        return $rows;
    }

    private function totals(array $rows): array
    {
        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            $totals['ticket_count']++;
            $status = (string)($row['ticket_status'] ?? '');
            if (isset($totals[$status . '_count'])) {
                $totals[$status . '_count']++;
            }
            if (in_array($status, [
                CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
                CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS,
            ], true)) {
                $totals['open_count']++;
            }
            $totals['first_response_breached_count'] += (int)($row['first_response_breached'] ?? 0);
            $totals['resolution_breached_count'] += (int)($row['resolution_breached'] ?? 0);
            $totals['missing_result_count'] += (int)($row['missing_result'] ?? 0);
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'ticket_count' => 0,
            'pending_count' => 0,
            'in_progress_count' => 0,
            'resolved_count' => 0,
            'closed_count' => 0,
            'open_count' => 0,
            'first_response_breached_count' => 0,
            'resolution_breached_count' => 0,
            'missing_result_count' => 0,
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
