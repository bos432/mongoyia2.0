<?php

namespace common\services\mall;

class MongoyiaProductionGoLiveGateService
{
    public const GATE_VERSION = 'MONGOYIA_PRODUCTION_GO_LIVE_GATE_V1';

    private const MODE = 'production_go_live_read_only_no_traffic_switch';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $evidenceDir = (string)($input['evidenceDir'] ?? 'runtime/handover');
        $changeTicket = trim((string)($input['changeTicket'] ?? ''));
        $acceptancePath = $this->latestAcceptanceFile('mongoyia-acceptance-*.md');
        $evidenceSummaryPath = trim((string)($input['evidenceSummaryPath'] ?? ''));
        $evidenceSummaryPath = $evidenceSummaryPath !== ''
            ? $this->resolvePath($evidenceSummaryPath)
            : $this->latestFile($this->resolvePath($evidenceDir), 'mongoyia-production-evidence-summary-*.md');
        $loadTestPath = $this->latestFile($this->resolvePath($evidenceDir), 'mongoyia-production-load-test-evidence-*.md');
        $externalEvidenceReviewResultApplyPath = $this->latestFile($this->resolvePath($evidenceDir), 'mongoyia-production-external-evidence-review-result-apply-gate-*.md');
        $externalEvidenceFinalAcceptancePath = $this->latestFile($this->resolvePath($evidenceDir), 'mongoyia-production-external-evidence-final-acceptance-gate-*.md');
        $launchSignoffReadinessPath = $this->latestFile($this->resolvePath($evidenceDir), 'mongoyia-production-launch-signoff-readiness-gate-*.md');
        $paypalFinalPath = $this->latestFile($this->resolvePath($evidenceDir), 'mongoyia-payment-provider-paypal-final-go-no-go-gate-*.md');

        $rows = [
            $this->reportRow(
                'latest_acceptance',
                'Latest local/test acceptance',
                $this->readReportResult($acceptancePath),
                $this->relativePath($acceptancePath),
                'Acceptance must PASS before production review.'
            ),
            $this->reportRow(
                'production_evidence_summary',
                'Production evidence summary',
                $this->readReportResult($evidenceSummaryPath),
                $this->relativePath($evidenceSummaryPath),
                'Requires production evidence summary report.'
            ),
            $this->reportRow(
                'formal_load_test',
                'Formal load test',
                $this->readReportResult($loadTestPath),
                $this->relativePath($loadTestPath),
                'Requires formal load-test evidence before production traffic.'
            ),
            $this->reportRow(
                'production_external_evidence_review_result_apply',
                'Production external evidence review-result apply gate',
                $this->readReportResult($externalEvidenceReviewResultApplyPath),
                $this->relativePath($externalEvidenceReviewResultApplyPath),
                'Requires sanitized review-result apply planning evidence while review acceptance stays disabled.'
            ),
            $this->reportRow(
                'production_external_evidence_final_acceptance',
                'Production external evidence final acceptance gate',
                $this->readReportResult($externalEvidenceFinalAcceptancePath),
                $this->relativePath($externalEvidenceFinalAcceptancePath),
                'Requires sanitized final acceptance metadata evidence while evidence acceptance stays disabled.'
            ),
            $this->reportRow(
                'production_launch_signoff_readiness',
                'Production launch signoff readiness gate',
                $this->readReportResult($launchSignoffReadinessPath),
                $this->relativePath($launchSignoffReadinessPath),
                'Requires sanitized launch owner signoff metadata evidence while launch approval stays disabled.'
            ),
            $this->paypalBoundaryRow($paypalFinalPath),
            $this->manualRow(
                'business_launch_approval',
                'Business launch approval',
                (string)($input['businessSignoff'] ?? 'PENDING'),
                (string)($input['approverReference'] ?? ''),
                $changeTicket,
                'Record business owner or launch ticket only.'
            ),
            $this->manualRow(
                'payment_production_readiness',
                'Payment production readiness',
                (string)($input['paymentProductionSignoff'] ?? 'PENDING'),
                (string)($input['paymentProductionReference'] ?? ''),
                $changeTicket,
                'QPay/LianLian production credentials and callbacks reviewed.'
            ),
            $this->manualRow(
                'settlement_reconciliation',
                'Settlement and reconciliation',
                (string)($input['settlementSignoff'] ?? 'PENDING'),
                (string)($input['settlementReference'] ?? ''),
                $changeTicket,
                'Settlement, refund reconciliation, and accounting owner confirmed.'
            ),
            $this->manualRow(
                'monitoring_alerting',
                'Monitoring and alerting',
                (string)($input['monitoringAlertSignoff'] ?? 'PENDING'),
                (string)($input['monitoringAlertReference'] ?? ''),
                $changeTicket,
                'Production scheduler and alerting runbook confirmed.'
            ),
            $this->manualRow(
                'backup_restore_drill',
                'Backup restore drill',
                (string)($input['backupRestoreDrillSignoff'] ?? 'PENDING'),
                (string)($input['backupRestoreDrillReference'] ?? ''),
                $changeTicket,
                'Backup restored to disposable database and verified.'
            ),
            $this->manualRow(
                'rollback_ownership',
                'Rollback ownership',
                (string)($input['rollbackOwnerSignoff'] ?? 'PENDING'),
                (string)($input['rollbackOwnerReference'] ?? ''),
                $changeTicket,
                'Rollback owner and database rollback rule confirmed.'
            ),
            $this->manualRow(
                'security_signoff',
                'Security signoff',
                (string)($input['securitySignoff'] ?? 'PENDING'),
                (string)($input['securityReference'] ?? ''),
                $changeTicket,
                'Secrets, TLS/WSS, callback signatures, upload limits, and access controls reviewed.'
            ),
            $this->manualRow(
                'launch_window_approval',
                'Launch-window approval',
                (string)($input['launchWindowSignoff'] ?? 'PENDING'),
                (string)($input['launchWindowReference'] ?? ''),
                $changeTicket,
                'Operator coverage and launch window approved.'
            ),
        ];

        $totals = $this->totals($rows);
        $result = $this->result($totals);
        $decision = $result === 'PASS' ? 'GO' : 'NO-GO';

        return [
            'gateVersion' => self::GATE_VERSION,
            'mode' => self::MODE,
            'result' => $result,
            'decision' => $decision,
            'goAllowed' => $decision === 'GO',
            'evidenceDir' => $this->relativePath($this->resolvePath($evidenceDir)),
            'acceptancePath' => $this->relativePath($acceptancePath),
            'evidenceSummaryPath' => $this->relativePath($evidenceSummaryPath),
            'loadTestPath' => $this->relativePath($loadTestPath),
            'productionExternalEvidenceReviewResultApplyGatePath' => $this->relativePath($externalEvidenceReviewResultApplyPath),
            'productionExternalEvidenceFinalAcceptanceGatePath' => $this->relativePath($externalEvidenceFinalAcceptancePath),
            'productionLaunchSignoffReadinessGatePath' => $this->relativePath($launchSignoffReadinessPath),
            'paypalFinalGoNoGoGatePath' => $this->relativePath($paypalFinalPath),
            'rows' => $rows,
            'totals' => $totals,
            'issues' => $this->issues($rows),
        ];
    }

    private function paypalBoundaryRow(string $path): array
    {
        $result = $this->readReportResult($path);
        $content = is_file($path) ? (string)file_get_contents($path) : '';
        $keepsNoGo = strpos($content, 'Final decision: NO-GO') !== false
            && strpos($content, 'Go allowed: no') !== false;

        return [
            'key' => 'paypal_final_no_go_boundary',
            'gate' => 'PayPal final no-go boundary',
            'status' => ($result === 'PASS' && $keepsNoGo) ? 'PASS' : ($path === '' ? 'PENDING' : 'WARN'),
            'evidence' => 'Latest PayPal final go/no-go gate',
            'reference' => $this->relativePath($path),
            'notes' => $keepsNoGo ? 'PayPal remains disabled and out of production scope.' : 'PayPal no-go boundary must remain recorded before production review.',
        ];
    }

    private function reportRow(string $key, string $gate, string $status, string $reference, string $notes): array
    {
        return [
            'key' => $key,
            'gate' => $gate,
            'status' => $status,
            'evidence' => $gate,
            'reference' => $reference,
            'notes' => $notes,
        ];
    }

    private function manualRow(string $key, string $gate, string $status, string $reference, string $changeTicket, string $notes): array
    {
        $normalized = $this->normalizeStatus($status);
        $resolvedReference = trim($reference) !== '' ? trim($reference) : $changeTicket;
        if ($normalized === 'PASS' && $resolvedReference === '') {
            $normalized = 'FAIL';
            $notes .= ' PASS requires a non-sensitive reference.';
        }

        return [
            'key' => $key,
            'gate' => $gate,
            'status' => $normalized,
            'evidence' => $gate,
            'reference' => $resolvedReference,
            'notes' => $notes,
        ];
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
            'gate_row_count' => count($rows),
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

        $totals['final_decision_no_go'] = ($totals['fail_count'] > 0 || $totals['pending_count'] > 0 || $totals['warn_count'] > 0 || $totals['blocked_count'] > 0) ? 1 : 0;
        $totals['go_allowed'] = $totals['final_decision_no_go'] === 0 ? 1 : 0;

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
            $status = (string)$row['status'];
            if (in_array($status, ['PASS'], true)) {
                continue;
            }
            $issues[] = (string)$row['key'] . ': ' . $status . ' - ' . (string)$row['notes'];
        }

        return $issues;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Production Go-Live Gate',
            '',
            '- Result: ' . (string)($report['result'] ?? ''),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Final decision: ' . (string)($report['decision'] ?? ''),
            '- Go allowed: ' . (($report['goAllowed'] ?? false) ? 'yes' : 'no'),
            '- Evidence dir: ' . (string)($report['evidenceDir'] ?? ''),
            '- Latest acceptance: ' . (string)($report['acceptancePath'] ?? ''),
            '- Production evidence summary: ' . (string)($report['evidenceSummaryPath'] ?? ''),
            '- Formal load test: ' . (string)($report['loadTestPath'] ?? ''),
            '- Production external evidence review-result apply gate: ' . (string)($report['productionExternalEvidenceReviewResultApplyGatePath'] ?? ''),
            '- Production external evidence final acceptance gate: ' . (string)($report['productionExternalEvidenceFinalAcceptanceGatePath'] ?? ''),
            '- Production launch signoff readiness gate: ' . (string)($report['productionLaunchSignoffReadinessGatePath'] ?? ''),
            '- PayPal final go/no-go gate: ' . (string)($report['paypalFinalGoNoGoGatePath'] ?? ''),
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
            '## Gate Rows',
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
            'This gate is read-only. It does not run checks, switch traffic, restore databases, create orders, trigger payment callbacks, call payment providers, write payment attempts, or mutate business data.',
            '',
            'A PASS report means the recorded evidence is complete enough for a production launch review. Local WARN/NO-GO is expected until production evidence and manual owner signoffs are complete.',
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

    private function latestAcceptanceFile(string $pattern): string
    {
        return $this->latestFile($this->rootPath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'acceptance', $pattern);
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
