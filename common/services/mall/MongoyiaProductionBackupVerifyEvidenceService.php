<?php

namespace common\services\mall;

class MongoyiaProductionBackupVerifyEvidenceService
{
    public const EVIDENCE_VERSION = 'MONGOYIA_PRODUCTION_BACKUP_VERIFY_EVIDENCE_V1';

    private const MODE = 'production_backup_verify_evidence_read_only';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $evidenceDir = (string)($input['evidenceDir'] ?? 'runtime/handover');
        $backupVerifyPath = trim((string)($input['backupVerifyPath'] ?? ''));
        $backupVerifyPath = $backupVerifyPath !== ''
            ? $this->resolvePath($backupVerifyPath)
            : $this->latestShellBackupVerifyReport($this->resolvePath($evidenceDir));
        $restoreDrillReference = trim((string)($input['restoreDrillReference'] ?? ''));
        $retentionReference = trim((string)($input['retentionReference'] ?? ''));
        $operator = trim((string)($input['operator'] ?? ''));

        $rows = [
            $this->reportRow(
                'backup_verify_report',
                'Backup verify report',
                $this->readReportResult($backupVerifyPath),
                $this->relativePath($backupVerifyPath),
                'Latest non-destructive backup archive checksum/readability report.'
            ),
            $this->signoffRow(
                'database_checksum_review',
                'Database backup checksum review',
                (string)($input['databaseChecksumSignoff'] ?? 'PENDING'),
                $backupVerifyPath !== '' ? $this->relativePath($backupVerifyPath) : '',
                'Database archive checksum and SQL readability reviewed.'
            ),
            $this->signoffRow(
                'upload_archive_review',
                'Upload archive review',
                (string)($input['uploadArchiveSignoff'] ?? 'PENDING'),
                $backupVerifyPath !== '' ? $this->relativePath($backupVerifyPath) : '',
                'Upload archive checksum/readability reviewed or not required by the launch plan.'
            ),
            $this->signoffRow(
                'restore_drill',
                'Restore drill',
                (string)($input['restoreDrillSignoff'] ?? 'PENDING'),
                $restoreDrillReference,
                'Backup restored to a disposable database and smoke-checked.'
            ),
            $this->signoffRow(
                'retention_storage',
                'Retention and storage',
                (string)($input['retentionSignoff'] ?? 'PENDING'),
                $retentionReference,
                'Off-web-root storage, retention, access control, and cleanup window reviewed.'
            ),
            $this->signoffRow(
                'rollback_owner_review',
                'Rollback owner review',
                (string)($input['rollbackOwnerSignoff'] ?? 'PENDING'),
                $operator,
                'Rollback owner and operator have reviewed the backup/restore runbook.'
            ),
        ];

        $totals = $this->totals($rows);

        return [
            'evidenceVersion' => self::EVIDENCE_VERSION,
            'mode' => self::MODE,
            'result' => $this->result($totals),
            'evidenceDir' => $this->relativePath($this->resolvePath($evidenceDir)),
            'backupVerifyPath' => $this->relativePath($backupVerifyPath),
            'restoreDrillReference' => $restoreDrillReference,
            'retentionReference' => $retentionReference,
            'operator' => $operator,
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

    private function signoffRow(string $key, string $area, string $status, string $reference, string $notes): array
    {
        $normalized = $this->normalizeStatus($status);
        if ($normalized === 'PASS' && trim($reference) === '') {
            $normalized = 'FAIL';
            $notes .= ' PASS requires a non-sensitive report, ticket, or owner reference.';
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

        $totals['ready_for_production_evidence_summary'] = (
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
            '# Mongoyia Production Backup Verify Evidence',
            '',
            '- Result: ' . (string)($report['result'] ?? ''),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence version: ' . (string)($report['evidenceVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Evidence dir: ' . (string)($report['evidenceDir'] ?? ''),
            '- Backup verify report: ' . (string)($report['backupVerifyPath'] ?? ''),
            '- Restore drill reference: ' . (string)($report['restoreDrillReference'] ?? ''),
            '- Retention reference: ' . (string)($report['retentionReference'] ?? ''),
            '- Operator: ' . (string)($report['operator'] ?? ''),
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
            'This report is read-only. It indexes backup verification and manual restore-drill evidence. It does not create backups, read archive contents, restore databases, create orders, call payment providers, connect to IM, write payment attempts, or mutate business data.',
            '',
            'Local WARN is expected until a real backup-verify report, restore drill reference, retention/storage review, and rollback-owner signoff are recorded.',
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

    private function latestShellBackupVerifyReport(string $dir): string
    {
        if (!is_dir($dir)) {
            return '';
        }
        $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mongoyia-production-backup-verify-*.md') ?: [];
        $files = array_values(array_filter($files, static function ($path) {
            return preg_match('/mongoyia-production-backup-verify-\d{8}-\d{6}\.md$/', str_replace('\\', '/', $path));
        }));
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
