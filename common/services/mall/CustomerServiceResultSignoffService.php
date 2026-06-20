<?php

namespace common\services\mall;

use Yii;

class CustomerServiceResultSignoffService
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
            ->where(['status' => 1])
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
            '# Mongoyia Customer Service Result Signoff',
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
            '| Tickets | ' . (int)$totals['ticket_count'] . ' |',
            '| Ready for signoff | ' . (int)$totals['ready_for_signoff_count'] . ' |',
            '| Needs result writeback | ' . (int)$totals['needs_result_writeback_count'] . ' |',
            '| Premature result review | ' . (int)$totals['premature_result_review_count'] . ' |',
            '| Continue workflow | ' . (int)$totals['continue_workflow_count'] . ' |',
            '| Signoff blocked | ' . (int)$totals['signoff_blocked_count'] . ' |',
            '',
            '## Signoff Rows',
            '',
            '| Ticket | Store | Type | Status | Result Length | Suggested Action | Title |',
            '|---:|---:|---|---|---:|---|---|',
        ];

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['id']
                . ' | ' . (int)$row['store_id']
                . ' | ' . (string)$row['ticket_type']
                . ' | ' . (string)$row['ticket_status']
                . ' | ' . (int)$row['result_length']
                . ' | ' . (string)$row['suggested_action']
                . ' | ' . str_replace('|', '/', (string)$row['title'])
                . ' |';
        }

        if (empty($report['rows'])) {
            $lines[] = '| 0 | 0 | none | none | 0 | none | none |';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Customer-service owner reviewed missing result rows: PENDING',
            '- Premature result rows reviewed before closure: PENDING',
            '- Ready rows approved for archival signoff: PENDING',
            '- Result writeback remains disabled until an audited workflow lands: PENDING',
            '- Export archived with acceptance evidence: PENDING',
            '',
            'This report is read-only evidence. It does not create tickets, mutate ticket workflow state, write ticket results, send IM messages, upload files, change orders, change payments, write fund logs, or update statistics.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['ticket_id,ticket_sn,ticket_type,store_id,ticket_status,priority,order_id,order_sn,result_length,suggested_action,created_at,updated_at,resolved_at,closed_at,title'];
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
                (int)$row['result_length'],
                $this->csvCell((string)$row['suggested_action']),
                (int)$row['created_at'],
                (int)$row['updated_at'],
                (int)$row['resolved_at'],
                (int)$row['closed_at'],
                $this->csvCell((string)$row['title']),
            ]);
        }

        return $lines;
    }

    private function enrichRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $status = (string)($row['ticket_status'] ?? '');
            $result = trim((string)($row['result'] ?? ''));
            $hasResult = $result !== '';
            $isFinished = in_array($status, [
                CustomerServiceAdvancedService::TICKET_STATUS_RESOLVED,
                CustomerServiceAdvancedService::TICKET_STATUS_CLOSED,
            ], true);
            $isOpen = in_array($status, [
                CustomerServiceAdvancedService::TICKET_STATUS_PENDING,
                CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS,
            ], true);

            if ($isFinished && $hasResult) {
                $action = 'ready_for_signoff';
            } elseif ($isFinished) {
                $action = 'needs_result_writeback';
            } elseif ($isOpen && $hasResult) {
                $action = 'premature_result_review';
            } else {
                $action = 'continue_workflow';
            }

            $row['result_length'] = strlen($result);
            $row['suggested_action'] = $action;
        }
        unset($row);

        return $rows;
    }

    private function totals(array $rows): array
    {
        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            $totals['ticket_count']++;
            $action = (string)($row['suggested_action'] ?? '');
            $key = $action . '_count';
            if (isset($totals[$key])) {
                $totals[$key]++;
            }
            if (in_array($action, ['needs_result_writeback', 'premature_result_review'], true)) {
                $totals['signoff_blocked_count']++;
            }
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'ticket_count' => 0,
            'ready_for_signoff_count' => 0,
            'needs_result_writeback_count' => 0,
            'premature_result_review_count' => 0,
            'continue_workflow_count' => 0,
            'signoff_blocked_count' => 0,
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
