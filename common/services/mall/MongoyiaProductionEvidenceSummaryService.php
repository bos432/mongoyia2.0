<?php

namespace common\services\mall;

class MongoyiaProductionEvidenceSummaryService
{
    public const EVIDENCE_VERSION = 'MONGOYIA_PRODUCTION_EVIDENCE_SUMMARY_V1';

    private const MODE = 'production_evidence_summary_read_only_index';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $evidenceDir = (string)($input['evidenceDir'] ?? 'runtime/handover');
        $acceptanceDir = (string)($input['acceptanceDir'] ?? 'runtime/acceptance');
        $failOnPending = (bool)($input['failOnPending'] ?? false);
        $resolvedEvidenceDir = $this->resolvePath($evidenceDir);
        $resolvedAcceptanceDir = $this->resolvePath($acceptanceDir);
        $scheduledMonitoringReport = $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-scheduled-check-evidence-*.md');
        if ($scheduledMonitoringReport === '') {
            $scheduledMonitoringReport = $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-scheduled-check-*.md');
        }

        $rows = [
            $this->gateRow(
                'test_server_acceptance',
                'Test-server acceptance',
                'Latest acceptance report',
                $this->latestFile($resolvedAcceptanceDir, 'mongoyia-acceptance-*.md'),
                'QA/business',
                'Required before production launch.'
            ),
            $this->gateRow(
                'p2_evidence_pack',
                'P2 evidence pack',
                'Latest P2 evidence pack report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-p2-evidence-pack-*.md'),
                'QA/Ops',
                'Restore, preflight, acceptance, payment sandbox, and IM WSS review bundle.'
            ),
            $this->gateRow(
                'payment_sandbox_evidence',
                'Payment sandbox evidence',
                'Latest payment sandbox evidence report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-payment-sandbox-evidence-*.md'),
                'Payment/Ops',
                'QPay/LianLian sandbox callback signoff without secrets.'
            ),
            $this->gateRow(
                'im_wss_evidence',
                'IM WSS evidence',
                'Latest IM WSS evidence report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-im-wss-evidence-*.md'),
                'IM/Ops',
                'Public WSS healthcheck, regression, TLS, reverse-proxy, and service-manager evidence.'
            ),
            $this->gateRow(
                'handover_integrity',
                'Handover integrity',
                'Latest handover verification report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-handover-verify-*.md'),
                'Engineering',
                'Confirms package and local checks.'
            ),
            $this->gateRow(
                'test_server_preflight',
                'Test-server preflight',
                'Latest test-server preflight report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-test-server-preflight-*.md'),
                'Ops',
                'Required before restore/apply.'
            ),
            $this->gateRow(
                'scheduled_monitoring',
                'Scheduled monitoring',
                'Latest scheduled-check evidence summary',
                $scheduledMonitoringReport,
                'Ops',
                'Cron/Task Scheduler should alert on failure.'
            ),
            $this->gateRow(
                'production_health',
                'Production health',
                'Latest production health report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-health-*.md'),
                'Engineering/Ops',
                'Includes deploy-check, security, payment audit, order integrity, translation audit, cleanup dry-run.'
            ),
            $this->gateRow(
                'production_monitor',
                'Production monitor',
                'Latest monitor report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-monitor-*.md'),
                'Ops',
                'Runtime/env/Redis/IM/disk/log report.'
            ),
            $this->gateRow(
                'backup_verification',
                'Backup verification',
                'Latest backup-verify report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-backup-verify-*.md'),
                'Ops',
                'Checksum and archive readability evidence.'
            ),
            $this->gateRow(
                'load_smoke',
                'Load smoke',
                'Latest load-smoke report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-load-smoke-*.md'),
                'Engineering/Ops',
                'Non-destructive storefront and optional IM concurrency smoke.'
            ),
            $this->gateRow(
                'formal_load_test',
                'Formal load test',
                'Latest formal load-test evidence report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-load-test-evidence-*.md'),
                'Engineering/Ops/business',
                'Browsing, checkout, payment callback, and IM concurrency load evidence.'
            ),
            $this->gateRow(
                'production_external_evidence_review_result_apply',
                'Production external evidence review-result apply gate',
                'Latest production external evidence review-result apply gate report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-external-evidence-review-result-apply-gate-*.md'),
                'Business/Ops/Security/Payment/Engineering/Finance/Language',
                'Sanitized review-result apply planning evidence; it does not accept evidence or allow go-live.'
            ),
            $this->gateRow(
                'production_external_evidence_final_acceptance',
                'Production external evidence final acceptance gate',
                'Latest production external evidence final acceptance gate report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-external-evidence-final-acceptance-gate-*.md'),
                'Business/Ops/Security/Payment/Engineering/Finance/Language/Rollback',
                'Sanitized final acceptance metadata preflight; it does not accept evidence or allow go-live.'
            ),
            $this->gateRow(
                'production_launch_signoff_readiness',
                'Production launch signoff readiness gate',
                'Latest production launch signoff readiness gate report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-production-launch-signoff-readiness-gate-*.md'),
                'Business/Payment/Finance/Ops/Backup/Rollback/Security/Launch',
                'Sanitized launch owner signoff metadata preflight; it does not accept signoff or allow go-live.'
            ),
            $this->gateRow(
                'mongolian_review',
                'Mongolian review',
                'Latest Mongolian review evidence report',
                $this->latestFile($resolvedEvidenceDir, 'mongoyia-mongolian-review-evidence-*.md'),
                'Native/business reviewer',
                'Human review and image-text signoff evidence.'
            ),
        ];

        $totals = $this->totals($rows);
        $result = $this->result($totals, $failOnPending);

        return [
            'evidenceVersion' => self::EVIDENCE_VERSION,
            'mode' => self::MODE,
            'result' => $result,
            'failOnPending' => $failOnPending,
            'evidenceDir' => $this->relativePath($resolvedEvidenceDir),
            'acceptanceDir' => $this->relativePath($resolvedAcceptanceDir),
            'rows' => $rows,
            'totals' => $totals,
            'issues' => $this->issues($rows),
        ];
    }

    private function gateRow(string $key, string $gate, string $evidence, string $path, string $owner, string $notes): array
    {
        return [
            'key' => $key,
            'gate' => $gate,
            'status' => $this->readReportResult($path),
            'evidence' => $evidence,
            'reference' => $this->relativePath($path),
            'owner' => $owner,
            'notes' => $notes,
        ];
    }

    private function totals(array $rows): array
    {
        $totals = [
            'evidence_row_count' => count($rows),
            'pass_count' => 0,
            'warn_count' => 0,
            'fail_count' => 0,
            'pending_count' => 0,
            'unknown_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)$row['status'];
            if ($status === 'PASS') {
                $totals['pass_count']++;
            } elseif ($status === 'WARN') {
                $totals['warn_count']++;
            } elseif ($status === 'PENDING') {
                $totals['pending_count']++;
            } elseif ($status === 'UNKNOWN') {
                $totals['unknown_count']++;
                $totals['fail_count']++;
            } else {
                $totals['fail_count']++;
            }
        }

        $totals['ready_for_go_live_gate'] = (
            $totals['fail_count'] === 0
            && $totals['pending_count'] === 0
            && $totals['warn_count'] === 0
        ) ? 1 : 0;

        return $totals;
    }

    private function result(array $totals, bool $failOnPending): string
    {
        if ((int)$totals['fail_count'] > 0) {
            return 'FAIL';
        }
        if ($failOnPending && (int)$totals['pending_count'] > 0) {
            return 'FAIL';
        }
        if ((int)$totals['pending_count'] > 0 || (int)$totals['warn_count'] > 0) {
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
            '# Mongoyia Production Evidence Summary',
            '',
            '- Result: ' . (string)($report['result'] ?? ''),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence version: ' . (string)($report['evidenceVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Fail on pending: ' . (($report['failOnPending'] ?? false) ? 'yes' : 'no'),
            '- Evidence dir: ' . (string)($report['evidenceDir'] ?? ''),
            '- Acceptance dir: ' . (string)($report['acceptanceDir'] ?? ''),
            '',
            'This summary is read-only. It does not run checks, restore databases, create orders, trigger payment callbacks, call payment providers, connect to IM, or mutate business data.',
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
            '| Key | Gate | Status | Evidence | Report | Owner | Notes |',
            '|---|---|---:|---|---|---|---|',
        ]);

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['key'])
                . ' | ' . $this->escapeCell((string)$row['gate'])
                . ' | ' . $this->escapeCell((string)$row['status'])
                . ' | ' . $this->escapeCell((string)$row['evidence'])
                . ' | ' . $this->escapeCell((string)$row['reference'])
                . ' | ' . $this->escapeCell((string)$row['owner'])
                . ' | ' . $this->escapeCell((string)$row['notes'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Required Manual Evidence',
            '',
            '- Payment provider sandbox and production credential signoff.',
            '- IM WSS public-domain regression and reverse-proxy/TLS signoff.',
            '- Mongolian native/business content signoff, recorded by `mongoyia-mongolian-review-evidence`.',
            '- Formal load-test signoff, recorded by `mongoyia-production-load-test-evidence`.',
            '- Backup restore drill to a disposable database.',
            '- Rollout owner, rollback owner, and launch-window approval.',
            '',
            'Use `docs/mongoyia-external-integration-inputs.md` and `docs/mongoyia-production-rollout-rollback.md` to record external/manual evidence that cannot be generated locally.',
        ]);

        return $lines;
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,gate,status,evidence,reference,owner,notes'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = $this->csvRow([
                (string)$row['key'],
                (string)$row['gate'],
                (string)$row['status'],
                (string)$row['evidence'],
                (string)$row['reference'],
                (string)$row['owner'],
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
