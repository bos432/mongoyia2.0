<?php

namespace common\services\mall;

class MongoyiaProductionLoadTestEvidenceService
{
    public const EVIDENCE_VERSION = 'MONGOYIA_PRODUCTION_LOAD_TEST_EVIDENCE_V1';

    private const MODE = 'production_load_test_evidence_read_only_no_traffic';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $evidenceDir = (string)($input['evidenceDir'] ?? 'runtime/handover');
        $loadSmokePath = trim((string)($input['loadSmokePath'] ?? ''));
        $loadSmokePath = $loadSmokePath !== ''
            ? $this->resolvePath($loadSmokePath)
            : $this->latestFile($this->resolvePath($evidenceDir), 'mongoyia-production-load-smoke-*.md');

        $loadTestReference = trim((string)($input['loadTestReference'] ?? ''));

        $rows = [
            $this->reportRow(
                'load_smoke_baseline',
                'Load smoke baseline',
                $this->readReportResult($loadSmokePath),
                $this->relativePath($loadSmokePath),
                'Latest non-destructive load-smoke report before formal testing.'
            ),
            $this->referenceRow(
                'formal_load_test_report',
                'Formal load-test report',
                $loadTestReference,
                'External formal load-test report or change-ticket reference.'
            ),
            $this->scenarioRow(
                'browsing_scenario',
                'Browsing scenario',
                (string)($input['browsingSignoff'] ?? 'PENDING'),
                $loadTestReference,
                'Homepage, category, product detail, and cart browsing thresholds reviewed.'
            ),
            $this->scenarioRow(
                'checkout_scenario',
                'Checkout scenario',
                (string)($input['checkoutSignoff'] ?? 'PENDING'),
                $loadTestReference,
                'Cart, address, order creation, and order-state thresholds reviewed.'
            ),
            $this->scenarioRow(
                'payment_callback_scenario',
                'Payment callback scenario',
                (string)($input['paymentCallbackSignoff'] ?? 'PENDING'),
                $loadTestReference,
                'Payment callback throughput, idempotency, mismatch, and invalid-signature cases reviewed.'
            ),
            $this->scenarioRow(
                'im_concurrency_scenario',
                'IM concurrency scenario',
                (string)($input['imConcurrencySignoff'] ?? 'PENDING'),
                $loadTestReference,
                'IM WSS connect, send, receive, reconnect, history, and concurrent-user behavior reviewed.'
            ),
            $this->scenarioRow(
                'data_store_resource_scenario',
                'Datastore and resource scenario',
                (string)($input['dataStoreSignoff'] ?? 'PENDING'),
                $loadTestReference,
                'MySQL, Redis, disk, CPU, memory, queues, and slow-query/resource behavior reviewed.'
            ),
            $this->scenarioRow(
                'rollback_monitoring_scenario',
                'Rollback and monitoring scenario',
                (string)($input['rollbackMonitoringSignoff'] ?? 'PENDING'),
                $loadTestReference,
                'Rollback observation, monitoring dashboards, alerting, and operator response reviewed.'
            ),
        ];

        $totals = $this->totals($rows);
        $result = $this->result($totals);

        return [
            'evidenceVersion' => self::EVIDENCE_VERSION,
            'mode' => self::MODE,
            'result' => $result,
            'evidenceDir' => $this->relativePath($this->resolvePath($evidenceDir)),
            'loadSmokePath' => $this->relativePath($loadSmokePath),
            'loadTestReference' => $loadTestReference,
            'peakUsers' => trim((string)($input['peakUsers'] ?? '')),
            'durationMinutes' => trim((string)($input['durationMinutes'] ?? '')),
            'p95Ms' => trim((string)($input['p95Ms'] ?? '')),
            'errorRate' => trim((string)($input['errorRate'] ?? '')),
            'tester' => trim((string)($input['tester'] ?? '')),
            'rows' => $rows,
            'totals' => $totals,
            'issues' => $this->issues($rows),
        ];
    }

    private function reportRow(string $key, string $area, string $status, string $reference, string $notes): array
    {
        return [
            'key' => $key,
            'area' => $area,
            'status' => $status,
            'evidence' => $area,
            'reference' => $reference,
            'notes' => $notes,
        ];
    }

    private function referenceRow(string $key, string $area, string $reference, string $notes): array
    {
        return $this->reportRow(
            $key,
            $area,
            $reference !== '' ? 'PASS' : 'PENDING',
            $reference,
            $notes
        );
    }

    private function scenarioRow(string $key, string $area, string $status, string $reference, string $notes): array
    {
        $normalized = $this->normalizeStatus($status);
        if ($normalized === 'PASS' && trim($reference) === '') {
            $normalized = 'FAIL';
            $notes .= ' PASS requires a non-sensitive formal load-test reference.';
        }

        return $this->reportRow($key, $area, $normalized, $reference, $notes);
    }

    private function normalizeStatus(string $status): string
    {
        $upper = strtoupper(trim($status));
        if ($upper === '') {
            return 'PENDING';
        }
        if (in_array($upper, ['PASS', 'WARN', 'FAIL', 'PENDING', 'BLOCKED'], true)) {
            return $upper;
        }

        return 'WARN';
    }

    private function totals(array $rows): array
    {
        $totals = [
            'evidence_row_count' => count($rows),
            'pass_count' => 0,
            'warn_count' => 0,
            'fail_count' => 0,
            'pending_count' => 0,
            'blocked_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)$row['status'];
            if ($status === 'PASS') {
                $totals['pass_count']++;
            } elseif ($status === 'FAIL' || $status === 'UNKNOWN') {
                $totals['fail_count']++;
            } elseif ($status === 'PENDING') {
                $totals['pending_count']++;
            } elseif ($status === 'BLOCKED') {
                $totals['blocked_count']++;
            } else {
                $totals['warn_count']++;
            }
        }

        $totals['ready_for_go_live_gate'] = (
            $totals['fail_count'] === 0
            && $totals['pending_count'] === 0
            && $totals['warn_count'] === 0
            && $totals['blocked_count'] === 0
        ) ? 1 : 0;

        return $totals;
    }

    private function result(array $totals): string
    {
        if ((int)$totals['fail_count'] > 0) {
            return 'FAIL';
        }
        if ((int)$totals['pending_count'] > 0 || (int)$totals['warn_count'] > 0 || (int)$totals['blocked_count'] > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function issues(array $rows): array
    {
        $issues = [];
        foreach ($rows as $row) {
            if ((string)$row['status'] === 'PASS') {
                continue;
            }
            $issues[] = (string)$row['key'] . ': ' . (string)$row['status'] . ' - ' . (string)$row['notes'];
        }

        return $issues;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Production Load-Test Evidence',
            '',
            '- Result: ' . (string)($report['result'] ?? ''),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence version: ' . (string)($report['evidenceVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Evidence dir: ' . (string)($report['evidenceDir'] ?? ''),
            '- Load smoke baseline: ' . (string)($report['loadSmokePath'] ?? ''),
            '- Load-test reference: ' . (string)($report['loadTestReference'] ?? ''),
            '- Peak users: ' . (string)($report['peakUsers'] ?? ''),
            '- Duration minutes: ' . (string)($report['durationMinutes'] ?? ''),
            '- P95 ms: ' . (string)($report['p95Ms'] ?? ''),
            '- Error rate: ' . (string)($report['errorRate'] ?? ''),
            '- Tester: ' . (string)($report['tester'] ?? ''),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
        ];

        foreach (($report['totals'] ?? []) as $key => $value) {
            $lines[] = '| ' . $this->escapeCell((string)$key) . ' | ' . (int)$value . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Evidence Rows',
            '',
            '| Key | Status | Evidence | Reference | Notes |',
            '|---|---|---|---|---|',
        ]);

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['key'])
                . ' | ' . $this->escapeCell((string)$row['status'])
                . ' | ' . $this->escapeCell((string)$row['evidence'])
                . ' | ' . $this->escapeCell((string)$row['reference'])
                . ' | ' . $this->escapeCell((string)$row['notes'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Boundary',
            '',
            'This report is read-only. It records externally run load-test evidence and does not generate traffic, create orders, trigger payment callbacks, call payment providers, connect to IM, write payment attempts, or mutate business data.',
            '',
            'Local WARN is expected until a formal load-test report and owner signoffs are recorded with non-sensitive references.',
        ]);

        return $lines;
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,status,evidence,reference,notes'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = $this->csvRow([
                (string)$row['key'],
                (string)$row['status'],
                (string)$row['evidence'],
                (string)$row['reference'],
                (string)$row['notes'],
            ]);
        }

        return $lines;
    }

    private function readReportResult(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return 'PENDING';
        }
        $content = (string)file_get_contents($path);
        if (preg_match('/^- (?:Result|Status): (PASS|WARN|FAIL)\s*$/m', $content, $matches)) {
            return (string)$matches[1];
        }

        return 'UNKNOWN';
    }

    private function latestFile(string $dir, string $pattern): string
    {
        if (!is_dir($dir)) {
            return '';
        }
        $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pattern) ?: [];
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0] ?? '';
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0) {
            return $path;
        }

        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function relativePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $normalizedRoot = str_replace('\\', '/', $this->rootPath);
        $normalizedPath = str_replace('\\', '/', $path);
        if (strpos($normalizedPath, $normalizedRoot . '/') === 0) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return $path;
    }

    private function escapeCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    private function csvRow(array $cells): string
    {
        return implode(',', array_map(static function ($cell) {
            return '"' . str_replace('"', '""', (string)$cell) . '"';
        }, $cells));
    }
}
