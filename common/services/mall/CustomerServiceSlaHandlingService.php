<?php

namespace common\services\mall;

use Yii;

class CustomerServiceSlaHandlingService
{
    public function run(
        int $storeId = 0,
        string $ticketType = '',
        string $dateFrom = '',
        string $dateTo = '',
        int $firstResponseSeconds = 1800,
        int $resolutionSeconds = 86400,
        int $watchWindowSeconds = 3600,
        int $limit = 500
    ): array {
        $storeId = max(0, $storeId);
        $ticketType = $this->normalizeTicketType($ticketType);
        $dateFrom = $this->normalizeDate($dateFrom);
        $dateTo = $this->normalizeDate($dateTo);
        $firstResponseSeconds = max(60, min(86400, $firstResponseSeconds));
        $resolutionSeconds = max(300, min(2592000, $resolutionSeconds));
        $watchWindowSeconds = max(60, min(86400, $watchWindowSeconds));
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
                'watchWindowSeconds' => $watchWindowSeconds,
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

        $rows = $this->enrichRows($query->all(Yii::$app->db), $firstResponseSeconds, $resolutionSeconds, $watchWindowSeconds);

        return [
            'storeId' => $storeId,
            'ticketType' => $ticketType,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'firstResponseSeconds' => $firstResponseSeconds,
            'resolutionSeconds' => $resolutionSeconds,
            'watchWindowSeconds' => $watchWindowSeconds,
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
            '# Mongoyia Customer Service SLA Handling Dry Run',
            '',
            '- Result: PASS',
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Store ID: ' . ((int)($report['storeId'] ?? 0) > 0 ? (int)$report['storeId'] : 'all'),
            '- Ticket type: ' . ((string)($report['ticketType'] ?? '') !== '' ? (string)$report['ticketType'] : 'all'),
            '- Date from: ' . ((string)($report['dateFrom'] ?? '') !== '' ? (string)$report['dateFrom'] : 'not limited'),
            '- Date to: ' . ((string)($report['dateTo'] ?? '') !== '' ? (string)$report['dateTo'] : 'not limited'),
            '- First response SLA seconds: ' . (int)($report['firstResponseSeconds'] ?? 0),
            '- Resolution SLA seconds: ' . (int)($report['resolutionSeconds'] ?? 0),
            '- Watch window seconds: ' . (int)($report['watchWindowSeconds'] ?? 0),
            '- Rows scanned: ' . (int)($report['rowsScanned'] ?? 0),
            '- Export limit: ' . (int)($report['limit'] ?? 0),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Tickets | ' . (int)$totals['ticket_count'] . ' |',
            '| First response overdue | ' . (int)$totals['first_response_overdue_count'] . ' |',
            '| Resolution overdue | ' . (int)$totals['resolution_overdue_count'] . ' |',
            '| Result writeback required | ' . (int)$totals['result_writeback_required_count'] . ' |',
            '| First response watch | ' . (int)$totals['first_response_watch_count'] . ' |',
            '| Resolution watch | ' . (int)$totals['resolution_watch_count'] . ' |',
            '| No action | ' . (int)$totals['no_action_count'] . ' |',
            '| Action required | ' . (int)$totals['action_required_count'] . ' |',
            '',
            '## Handling Rows',
            '',
            '| Ticket | Store | Type | Status | First Response Seconds | Resolution Seconds | Suggested Action | Title |',
            '|---:|---:|---|---|---:|---:|---|---|',
        ];

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['id']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (string)$row['ticket_type']
                . ' | ' . (string)$row['ticket_status']
                . ' | ' . (int)$row['first_response_seconds']
                . ' | ' . (int)$row['resolution_seconds']
                . ' | ' . (string)$row['suggested_action']
                . ' | ' . str_replace('|', '/', (string)$row['title'])
                . ' |';
        }

        if (empty($report['rows'])) {
            $lines[] = '| 0 | 0 | none | none | 0 | 0 | no_action | none |';
        }

        return array_merge($lines, [
            '',
            '## Handling Checklist',
            '',
            '- Customer-service owner reviewed first-response overdue rows: PENDING',
            '- Customer-service owner reviewed resolution overdue rows: PENDING',
            '- Result writeback rows reviewed before closure: PENDING',
            '- Automatic SLA handling remains disabled until an audited apply workflow lands: PENDING',
            '- Report archived with acceptance evidence: PENDING',
            '',
            'This report is dry-run/readiness evidence. It does not create tickets, mutate ticket workflow state, write ticket results, send IM messages, upload files, change orders, change payments, write fund logs, update statistics, or run automatic SLA handling.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['ticket_id,ticket_sn,ticket_type,store_id,ticket_status,priority,order_id,order_sn,created_at,first_response_at,resolved_at,closed_at,first_response_seconds,resolution_seconds,first_response_overdue,resolution_overdue,result_writeback_required,first_response_watch,resolution_watch,suggested_action,title'];
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
                (int)$row['first_response_overdue'],
                (int)$row['resolution_overdue'],
                (int)$row['result_writeback_required'],
                (int)$row['first_response_watch'],
                (int)$row['resolution_watch'],
                $this->csvCell((string)$row['suggested_action']),
                $this->csvCell((string)$row['title']),
            ]);
        }

        return $lines;
    }

    private function enrichRows(array $rows, int $firstResponseSla, int $resolutionSla, int $watchWindow): array
    {
        $now = time();
        foreach ($rows as &$row) {
            $createdAt = (int)($row['created_at'] ?? 0);
            $firstResponseAt = (int)($row['first_response_at'] ?? 0);
            $resolvedAt = (int)($row['resolved_at'] ?? 0);
            $status = (string)($row['ticket_status'] ?? '');
            $isPending = $status === CustomerServiceAdvancedService::TICKET_STATUS_PENDING;
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

            $firstResponseOverdue = ($isPending && $firstResponseAt <= 0 && $firstResponseSeconds > $firstResponseSla) ? 1 : 0;
            $resolutionOverdue = ($isOpen && $resolutionSeconds > $resolutionSla) ? 1 : 0;
            $resultWritebackRequired = ($isFinished && trim((string)($row['result'] ?? '')) === '') ? 1 : 0;
            $firstResponseWatch = ($isPending && $firstResponseAt <= 0 && !$firstResponseOverdue && $firstResponseSeconds >= max(0, $firstResponseSla - $watchWindow)) ? 1 : 0;
            $resolutionWatch = ($isOpen && !$resolutionOverdue && $resolutionSeconds >= max(0, $resolutionSla - $watchWindow)) ? 1 : 0;

            $row['first_response_seconds'] = $firstResponseSeconds;
            $row['resolution_seconds'] = $resolutionSeconds;
            $row['first_response_overdue'] = $firstResponseOverdue;
            $row['resolution_overdue'] = $resolutionOverdue;
            $row['result_writeback_required'] = $resultWritebackRequired;
            $row['first_response_watch'] = $firstResponseWatch;
            $row['resolution_watch'] = $resolutionWatch;
            $row['suggested_action'] = $this->suggestedAction($row);
        }
        unset($row);

        return $rows;
    }

    private function suggestedAction(array $row): string
    {
        if ((int)$row['resolution_overdue'] > 0) {
            return 'resolution_overdue';
        }
        if ((int)$row['first_response_overdue'] > 0) {
            return 'first_response_overdue';
        }
        if ((int)$row['result_writeback_required'] > 0) {
            return 'result_writeback_required';
        }
        if ((int)$row['first_response_watch'] > 0) {
            return 'first_response_watch';
        }
        if ((int)$row['resolution_watch'] > 0) {
            return 'resolution_watch';
        }

        return 'no_action';
    }

    private function totals(array $rows): array
    {
        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            $totals['ticket_count']++;
            $action = (string)($row['suggested_action'] ?? 'no_action');
            $key = $action . '_count';
            if (isset($totals[$key])) {
                $totals[$key]++;
            }
            if ($action !== 'no_action') {
                $totals['action_required_count']++;
            }
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'ticket_count' => 0,
            'first_response_overdue_count' => 0,
            'resolution_overdue_count' => 0,
            'result_writeback_required_count' => 0,
            'first_response_watch_count' => 0,
            'resolution_watch_count' => 0,
            'no_action_count' => 0,
            'action_required_count' => 0,
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
