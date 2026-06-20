<?php

namespace common\services\mall;

use Yii;

class CustomerServiceComplaintEvidenceGateService
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
                'gateChecks' => $this->gateChecks([], true),
                'issues' => ['customer-service ticket table missing'],
            ];
        }

        if ($dateFrom !== '' && $dateTo !== '' && (int)str_replace('-', '', $dateFrom) > (int)str_replace('-', '', $dateTo)) {
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

        $rows = $this->enrichRows($query->all(Yii::$app->db));
        $totals = $this->totals($rows);

        return [
            'storeId' => $storeId,
            'ticketStatus' => $ticketStatus,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'rowsScanned' => count($rows),
            'totals' => $totals,
            'rows' => $rows,
            'gateChecks' => $this->gateChecks($totals, false),
            'issues' => $issues,
        ];
    }

    public function markdownLines(array $report): array
    {
        $totals = $report['totals'] ?? $this->emptyTotals();
        $lines = [
            '# Mongoyia Customer Service Complaint Evidence Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
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
            '| Valid evidence JSON | ' . (int)$totals['valid_evidence_json_count'] . ' |',
            '| Missing evidence | ' . (int)$totals['missing_evidence_count'] . ' |',
            '| Invalid evidence JSON | ' . (int)$totals['invalid_evidence_json_count'] . ' |',
            '| Upload required | ' . (int)$totals['upload_required_count'] . ' |',
            '| Repair required | ' . (int)$totals['repair_required_count'] . ' |',
            '| Manual review | ' . (int)$totals['manual_review_count'] . ' |',
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
            '## Complaint Evidence Rows',
            '',
            '| Ticket | Store | Status | Evidence Status | Suggested Action | Title |',
            '|---:|---:|---|---|---|---|',
        ]);

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['id']
                . ' | ' . (int)$row['store_id']
                . ' | ' . $this->escapeCell((string)$row['ticket_status'])
                . ' | ' . $this->escapeCell((string)$row['evidence_status'])
                . ' | ' . $this->escapeCell((string)$row['suggested_action'])
                . ' | ' . $this->escapeCell((string)$row['title'])
                . ' |';
        }

        if (empty($report['rows'])) {
            $lines[] = '| 0 | 0 | none | none | no_action | none |';
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
            '- Complaint owner reviewed missing-evidence rows: PENDING',
            '- Complaint owner reviewed invalid evidence JSON rows: PENDING',
            '- Upload/write handling remains disabled in backend UI: PENDING',
            '- Apply workflow requires a separate audited implementation before enablement: PENDING',
            '',
            'This report is read-only gate evidence. It does not upload files, create tickets, mutate ticket workflow state, write complaint evidence JSON, send IM messages, change orders, change payments, write fund logs, update statistics, or enable complaint evidence write handling.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['ticket_id,ticket_sn,store_id,ticket_status,evidence_status,suggested_action,title'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(',', [
                (int)$row['id'],
                $this->csvCell((string)$row['ticket_sn']),
                (int)$row['store_id'],
                $this->csvCell((string)$row['ticket_status']),
                $this->csvCell((string)$row['evidence_status']),
                $this->csvCell((string)$row['suggested_action']),
                $this->csvCell((string)$row['title']),
            ]);
        }

        return $lines;
    }

    private function enrichRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $status = $this->evidenceStatus((string)($row['evidence_json'] ?? ''));
            $row['evidence_status'] = $status;
            $row['suggested_action'] = $this->suggestedAction($status);
        }
        unset($row);

        return $rows;
    }

    private function evidenceStatus(string $evidenceJson): string
    {
        $evidenceJson = trim($evidenceJson);
        if ($evidenceJson === '') {
            return 'missing_evidence';
        }

        json_decode($evidenceJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'invalid_evidence_json';
        }

        return 'valid_evidence_json';
    }

    private function suggestedAction(string $status): string
    {
        if ($status === 'missing_evidence') {
            return 'upload_evidence_required';
        }
        if ($status === 'invalid_evidence_json') {
            return 'repair_evidence_json_required';
        }
        if ($status === 'valid_evidence_json') {
            return 'manual_review_required';
        }

        return 'no_action';
    }

    private function totals(array $rows): array
    {
        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            $totals['complaint_count']++;
            $status = (string)($row['evidence_status'] ?? '');
            if (isset($totals[$status . '_count'])) {
                $totals[$status . '_count']++;
            }
            $action = (string)($row['suggested_action'] ?? '');
            if ($action === 'upload_evidence_required') {
                $totals['upload_required_count']++;
            } elseif ($action === 'repair_evidence_json_required') {
                $totals['repair_required_count']++;
            } elseif ($action === 'manual_review_required') {
                $totals['manual_review_count']++;
            }
        }

        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'complaint_count' => 0,
            'valid_evidence_json_count' => 0,
            'missing_evidence_count' => 0,
            'invalid_evidence_json_count' => 0,
            'upload_required_count' => 0,
            'repair_required_count' => 0,
            'manual_review_count' => 0,
        ];
    }

    private function gateChecks(array $totals, bool $schemaMissing): array
    {
        return [
            [
                'key' => 'evidence_json_schema',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'details' => 'ticket evidence_json column is available for complaint evidence metadata',
            ],
            [
                'key' => 'missing_evidence_queue',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'details' => 'missing evidence rows can be reported before upload/write handling is enabled',
            ],
            [
                'key' => 'invalid_evidence_queue',
                'status' => $schemaMissing ? 'blocked' : 'ready',
                'details' => 'invalid evidence JSON rows can be reported before cleanup or repair',
            ],
            [
                'key' => 'upload_transport',
                'status' => 'reserved',
                'details' => 'complaint evidence upload transport remains disabled until file policy, storage, audit, and cleanup land together',
            ],
            [
                'key' => 'write_handler',
                'status' => 'reserved',
                'details' => 'complaint evidence write handling remains disabled until an audited apply workflow lands',
            ],
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
