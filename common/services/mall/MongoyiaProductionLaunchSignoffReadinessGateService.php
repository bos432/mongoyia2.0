<?php

namespace common\services\mall;

class MongoyiaProductionLaunchSignoffReadinessGateService
{
    public const EVIDENCE_VERSION = 'MONGOYIA_PRODUCTION_LAUNCH_SIGNOFF_READINESS_GATE_V1';

    private const MODE = 'production_launch_signoff_readiness_gate_read_only';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $finalAcceptancePath = trim((string)($input['finalAcceptanceGatePath'] ?? ''));
        $finalAcceptancePath = $finalAcceptancePath !== ''
            ? $this->resolvePath($finalAcceptancePath)
            : $this->latestHandoverFile('mongoyia-production-external-evidence-final-acceptance-gate-*.md');

        $rows = $this->signoffRows();
        $rowIssues = $this->validateRows($rows);
        $preconditions = [
            $this->finalAcceptancePrecondition($finalAcceptancePath),
            $this->documentationPrecondition(),
            $this->packageCheckPrecondition(),
            $this->deliveryIndexPrecondition(),
            $this->signoffContractPrecondition($rows),
        ];

        $issues = $rowIssues;
        foreach ($preconditions as $precondition) {
            if (!($precondition['satisfied'] ?? false)) {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }
        $valid = empty($issues);

        return [
            'evidenceVersion' => self::EVIDENCE_VERSION,
            'sourceEvidenceVersion' => MongoyiaProductionExternalEvidenceFinalAcceptanceGateService::EVIDENCE_VERSION,
            'mode' => self::MODE,
            'finalAcceptanceGatePath' => $this->relativePath($finalAcceptancePath),
            'launchSignoffMetadataValid' => $valid,
            'launchSignoffReady' => $valid,
            'launchSignoffAccepted' => false,
            'launchApprovalExecuted' => false,
            'productionGoLiveAllowed' => false,
            'productionFinalNoGo' => true,
            'signoffRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $preconditions, $rowIssues, $valid),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $valid),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function signoffRows(): array
    {
        $rows = [];
        $index = 0;
        foreach ($this->requiredSignoffs() as $key => $owner) {
            $index++;
            $rows[] = [
                'signoff_key' => $key,
                'owner_ref' => 'owner:PROD-LAUNCH-' . strtoupper(str_replace('_', '-', $key)),
                'ticket_ref' => 'ticket:PROD-LAUNCH-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'owner_role' => $owner,
                'decision' => 'ready_for_signoff_review',
                'signoff_status' => 'pending_external_owner_signoff',
                'reviewed_at' => '2026-06-19T14:' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . ':00Z',
                'notes' => 'Safe launch signoff metadata only; owner signoff acceptance and go-live stay disabled.',
            ];
        }

        return $rows;
    }

    private function validateRows(array $rows): array
    {
        $issues = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            foreach (['signoff_key', 'owner_ref', 'ticket_ref', 'owner_role', 'decision', 'signoff_status', 'reviewed_at', 'notes'] as $field) {
                if (!array_key_exists($field, $row) || trim((string)$row[$field]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $field;
                }
            }

            $key = (string)($row['signoff_key'] ?? '');
            if (!array_key_exists($key, $this->requiredSignoffs())) {
                $issues[] = 'row_' . $index . '_unknown_signoff_key';
            }
            if (isset($seen[$key])) {
                $issues[] = 'row_' . $index . '_duplicate_signoff_key';
            }
            $seen[$key] = true;

            if ((string)($row['decision'] ?? '') !== 'ready_for_signoff_review') {
                $issues[] = 'row_' . $index . '_decision_not_ready_for_review';
            }
            if ((string)($row['signoff_status'] ?? '') !== 'pending_external_owner_signoff') {
                $issues[] = 'row_' . $index . '_invalid_signoff_status';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($row['reviewed_at'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_reviewed_at';
            }
            foreach (['signoff_key', 'owner_ref', 'ticket_ref'] as $refField) {
                if (!preg_match('/^[A-Za-z0-9:_-]+$/', (string)($row[$refField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_invalid_' . $refField;
                }
            }
            foreach (['owner_ref', 'ticket_ref', 'owner_role', 'notes'] as $safeField) {
                if ($this->containsForbiddenMarker((string)($row[$safeField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeField;
                }
            }
        }

        foreach (array_keys($this->requiredSignoffs()) as $key) {
            if (!isset($seen[$key])) {
                $issues[] = 'missing_required_signoff_' . $key;
            }
        }

        return array_values(array_unique($issues));
    }

    private function finalAcceptancePrecondition(string $path): array
    {
        $content = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        $ok = $path !== ''
            && $this->readReportResult($path) === 'PASS'
            && strpos($content, MongoyiaProductionExternalEvidenceFinalAcceptanceGateService::EVIDENCE_VERSION) !== false
            && strpos($content, 'evidence_accepted=0') !== false
            && strpos($content, 'final_acceptance_executed=0') !== false
            && strpos($content, 'production_go_live_allowed=0') !== false;

        return $this->precondition(
            'final_acceptance_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'Latest production external evidence final acceptance gate must PASS while evidence acceptance stays disabled.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS final acceptance gate report or boundary markers.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-production-launch-signoff-readiness-gate.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-evidence-summary.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-go-live-gate.md');
        $missing = $this->missingNeedles($content, [
            self::EVIDENCE_VERSION,
            'Mongoyia Production Launch Signoff Readiness Gate',
            'launch_signoff_accepted=0',
            'launch_approval_executed=0',
            'production_go_live_allowed=0',
        ]);

        return $this->precondition(
            'documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Production docs describe the launch owner signoff readiness boundary.',
            empty($missing) ? 'Documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function packageCheckPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaPackageCheckController.php');
        $missing = $this->missingNeedles($content, [
            'MongoyiaProductionLaunchSignoffReadinessGateController.php',
            'MongoyiaProductionLaunchSignoffReadinessGateService.php',
            'docs/mongoyia-production-launch-signoff-readiness-gate.md',
        ]);

        return $this->precondition(
            'package_check_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Package check includes the production launch signoff readiness gate files.',
            empty($missing) ? 'Package check markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function deliveryIndexPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaDeliveryIndexController.php');
        $missing = $this->missingNeedles($content, [
            'productionLaunchSignoffReadinessGatePath',
            'mongoyia-production-launch-signoff-readiness-gate-*.md',
            'Production launch signoff readiness gate result',
        ]);

        return $this->precondition(
            'delivery_index_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Delivery index auto-references the latest production launch signoff readiness gate report.',
            empty($missing) ? 'Delivery index markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function signoffContractPrecondition(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (($row['decision'] ?? '') === 'ready_for_signoff_review') {
                $keys[(string)($row['signoff_key'] ?? '')] = true;
            }
        }
        $ok = count($rows) === count($this->requiredSignoffs()) && count($keys) === count($this->requiredSignoffs());

        return $this->precondition(
            'launch_signoff_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'The gate must validate all launch owner signoff metadata while keeping launch approval and go-live disabled.',
            $ok ? 'Launch signoff metadata is complete and still read-only.' : 'Launch signoff metadata is incomplete.'
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues, bool $valid): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = (string)($row['signoff_key'] ?? '');
            if (array_key_exists($key, $this->requiredSignoffs())) {
                $keys[$key] = true;
            }
        }
        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }

        return [
            'launch_signoff_row_count' => count($rows),
            'valid_launch_signoff_row_count' => empty($rowIssues) ? count($rows) : 0,
            'ready_signoff_count' => count($keys),
            'required_signoff_count' => count($this->requiredSignoffs()),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => count($this->requiredSignoffs()),
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'launch_signoff_metadata_valid' => $valid ? 1 : 0,
            'launch_signoff_ready' => $valid ? 1 : 0,
            'launch_signoff_accepted' => 0,
            'launch_approval_executed' => 0,
            'production_go_live_allowed' => 0,
            'production_final_no_go' => 1,
        ];
    }

    private function gateChecks(array $preconditions, array $rowIssues, bool $valid): array
    {
        $checks = [];
        foreach ($preconditions as $precondition) {
            $checks[] = [
                'key' => (string)$precondition['key'],
                'status' => (string)$precondition['status'],
                'details' => (string)$precondition['evidence'],
            ];
        }
        $checks[] = [
            'key' => 'launch_signoff_metadata',
            'status' => $valid ? 'ready' : 'blocked',
            'details' => empty($rowIssues) ? 'Launch owner signoff metadata is complete.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'launch_signoff_acceptance',
            'status' => 'disabled',
            'details' => 'launch_signoff_accepted=0 and launch_approval_executed=0 remain intentional.',
        ];
        $checks[] = [
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'No evidence artifact is read, copied, hashed, imported, or stored.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No QPay, LianLian, PayPal, IM, DNS, TLS, backup storage, or monitoring call is made.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment, chat, file, shipment, fund, ticket, statistic, signoff, or review row is created or updated.',
        ];
        $checks[] = [
            'key' => 'production_go_live',
            'status' => 'no-go',
            'details' => 'production_go_live_allowed=0 and production_final_no_go=1 remain intentional.',
        ];

        return $checks;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Production Launch Signoff Readiness Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence version: ' . (string)($report['evidenceVersion'] ?? ''),
            '- Source evidence version: ' . (string)($report['sourceEvidenceVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Final acceptance gate path: ' . (string)($report['finalAcceptanceGatePath'] ?? ''),
            '- Launch signoff metadata valid: ' . (($report['launchSignoffMetadataValid'] ?? false) ? 'yes' : 'no'),
            '- Launch signoff ready: ' . (($report['launchSignoffReady'] ?? false) ? 'yes' : 'no'),
            '- Launch signoff accepted: ' . (($report['launchSignoffAccepted'] ?? true) ? 'yes' : 'no'),
            '- Launch approval executed: ' . (($report['launchApprovalExecuted'] ?? true) ? 'yes' : 'no'),
            '- Production go-live allowed: ' . (($report['productionGoLiveAllowed'] ?? true) ? 'yes' : 'no'),
            '- Production final NO-GO: ' . (($report['productionFinalNoGo'] ?? false) ? 'yes' : 'no'),
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
            '## Launch Signoff Rows',
            '',
            '| Signoff | Owner ref | Ticket ref | Owner role | Decision | Status |',
            '|---|---|---|---|---|---|',
        ]);
        foreach (($report['signoffRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['signoff_key'])
                . ' | ' . $this->escapeCell((string)$row['owner_ref'])
                . ' | ' . $this->escapeCell((string)$row['ticket_ref'])
                . ' | ' . $this->escapeCell((string)$row['owner_role'])
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['signoff_status'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Preconditions',
            '',
            '| Key | Status | Satisfied | Evidence | Required evidence |',
            '|---|---|---:|---|---|',
        ]);
        foreach (($report['preconditions'] ?? []) as $precondition) {
            $lines[] = '| ' . $this->escapeCell((string)$precondition['key'])
                . ' | ' . $this->escapeCell((string)$precondition['status'])
                . ' | ' . (($precondition['satisfied'] ?? false) ? '1' : '0')
                . ' | ' . $this->escapeCell((string)$precondition['evidence'])
                . ' | ' . $this->escapeCell((string)$precondition['required_evidence'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Gate Checks',
            '',
            '| Gate | Status | Details |',
            '|---|---|---|',
        ]);
        foreach (($report['gateChecks'] ?? []) as $check) {
            $lines[] = '| ' . $this->escapeCell((string)$check['key'])
                . ' | ' . $this->escapeCell((string)$check['status'])
                . ' | ' . $this->escapeCell((string)$check['details'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- launch_signoff_metadata_valid=1 means launch owner signoff metadata is complete, not that owner signoff is accepted.',
            '- launch_signoff_ready=1 means the local preflight is ready for external/manual owner signoff review.',
            '- launch_signoff_accepted=0 remains intentional; this gate cannot accept launch owner signoff.',
            '- launch_approval_executed=0 remains intentional; this gate never writes signoff or approval rows.',
            '- production_go_live_allowed=0 remains intentional until the separate go-live gate passes with real external approvals.',
            '- production_final_no_go=1 remains intentional for this local gate.',
            '- No evidence artifact is read, copied, hashed, imported, or stored.',
            '- No provider, IM, DNS, TLS, backup storage, or monitoring service is called.',
            '- No order, payment, callback, chat, file, shipment, fund, ticket, statistic, signoff, or review row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['signoff_key,owner_ref,ticket_ref,owner_role,decision,signoff_status,reviewed_at,notes'];
        foreach (($report['signoffRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['signoff_key']),
                $this->csvCell((string)$row['owner_ref']),
                $this->csvCell((string)$row['ticket_ref']),
                $this->csvCell((string)$row['owner_role']),
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['signoff_status']),
                $this->csvCell((string)$row['reviewed_at']),
                $this->csvCell((string)$row['notes']),
            ]);
        }

        return $lines;
    }

    private function requiredSignoffs(): array
    {
        return [
            'business_launch' => 'Business launch owner',
            'payment_production' => 'Payment production owner',
            'settlement_reconciliation' => 'Finance settlement owner',
            'monitoring_alerting' => 'Ops monitoring owner',
            'backup_restore_drill' => 'Backup restore owner',
            'rollback_ownership' => 'Rollback owner',
            'security_signoff' => 'Security owner',
            'launch_window' => 'Launch-window operator owner',
        ];
    }

    private function precondition(string $key, bool $satisfied, string $status, string $requiredEvidence, string $evidence): array
    {
        return [
            'key' => $key,
            'satisfied' => $satisfied,
            'status' => $status,
            'required_evidence' => $requiredEvidence,
            'evidence' => $evidence,
        ];
    }

    private function containsForbiddenMarker(string $value): bool
    {
        foreach (['Authorization', 'Bearer ', 'PRIVATE KEY', 'BEGIN RSA', 'password', '.env', 'C:\\', '/home/', '/var/', '\\\\', 'mysql://', 'redis://', 'QPAY_AUTH_BASIC', 'LIANLIAN_PRIVATE_KEY', 'IM_AUTH_SECRET'] as $needle) {
            if (stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function latestHandoverFile(string $pattern): string
    {
        $dir = $this->rootPath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'handover';
        $files = glob($dir . DIRECTORY_SEPARATOR . $pattern);
        if (!$files) {
            return '';
        }
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }

    private function readReportResult(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return 'PENDING';
        }
        $content = (string)file_get_contents($path);
        if (preg_match('/^- Result:\s*([A-Z]+)\s*$/m', $content, $matches)) {
            return (string)$matches[1];
        }

        return 'UNKNOWN';
    }

    private function readRelative(string $path): string
    {
        $fullPath = $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        return is_file($fullPath) ? (string)file_get_contents($fullPath) : '';
    }

    private function missingNeedles(string $content, array $needles): array
    {
        $missing = [];
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $missing[] = $needle;
            }
        }

        return $missing;
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function relativePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $root = rtrim($this->rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strpos($path, $root) === 0 ? str_replace('\\', '/', substr($path, strlen($root))) : $path;
    }

    private function escapeCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    private function csvCell(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
