<?php

namespace common\services\mall;

class MongoyiaProductionExternalEvidenceFinalAcceptanceGateService
{
    public const EVIDENCE_VERSION = 'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1';

    private const MODE = 'production_external_evidence_final_acceptance_gate_read_only';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $applyGatePath = trim((string)($input['reviewResultApplyGatePath'] ?? ''));
        $applyGatePath = $applyGatePath !== ''
            ? $this->resolvePath($applyGatePath)
            : $this->latestHandoverFile('mongoyia-production-external-evidence-review-result-apply-gate-*.md');

        $rows = $this->acceptanceRows();
        $rowIssues = $this->validateRows($rows);
        $preconditions = [
            $this->reviewResultApplyGatePrecondition($applyGatePath),
            $this->documentationPrecondition(),
            $this->packageCheckPrecondition(),
            $this->deliveryIndexPrecondition(),
            $this->acceptanceContractPrecondition($rows),
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
            'sourceEvidenceVersion' => MongoyiaProductionExternalEvidenceReviewResultApplyGateService::EVIDENCE_VERSION,
            'mode' => self::MODE,
            'reviewResultApplyGatePath' => $this->relativePath($applyGatePath),
            'finalAcceptanceMetadataValid' => $valid,
            'finalAcceptanceReady' => $valid,
            'evidenceAccepted' => false,
            'finalAcceptanceExecuted' => false,
            'productionGoLiveAllowed' => false,
            'productionFinalNoGo' => true,
            'acceptanceRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $preconditions, $rowIssues, $valid),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $valid),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function acceptanceRows(): array
    {
        $rows = [];
        $index = 0;
        foreach ($this->requiredRoles() as $role) {
            $index++;
            $rows[] = [
                'acceptance_role' => $role,
                'owner_ref' => 'owner:PROD-FINAL-' . strtoupper(str_replace('_', '-', $role)),
                'signoff_ref' => 'signoff:PROD-FINAL-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'ticket_ref' => 'ticket:PROD-GATE-FINAL-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'decision' => 'ready',
                'acceptance_status' => 'pending_external_acceptance',
                'signed_at' => '2026-06-19T13:' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . ':00Z',
                'notes' => 'Safe final acceptance metadata only; evidence acceptance and go-live stay disabled.',
            ];
        }

        return $rows;
    }

    private function validateRows(array $rows): array
    {
        $issues = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            foreach (['acceptance_role', 'owner_ref', 'signoff_ref', 'ticket_ref', 'decision', 'acceptance_status', 'signed_at', 'notes'] as $field) {
                if (!array_key_exists($field, $row) || trim((string)$row[$field]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $field;
                }
            }

            $role = (string)($row['acceptance_role'] ?? '');
            if (!in_array($role, $this->requiredRoles(), true)) {
                $issues[] = 'row_' . $index . '_unknown_acceptance_role';
            }
            if (isset($seen[$role])) {
                $issues[] = 'row_' . $index . '_duplicate_acceptance_role';
            }
            $seen[$role] = true;

            if ((string)($row['decision'] ?? '') !== 'ready') {
                $issues[] = 'row_' . $index . '_decision_not_ready';
            }
            if ((string)($row['acceptance_status'] ?? '') !== 'pending_external_acceptance') {
                $issues[] = 'row_' . $index . '_invalid_acceptance_status';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($row['signed_at'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_signed_at';
            }
            foreach (['owner_ref', 'signoff_ref', 'ticket_ref'] as $refField) {
                if (!preg_match('/^[A-Za-z0-9:_-]+$/', (string)($row[$refField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_invalid_' . $refField;
                }
            }
            foreach (['owner_ref', 'signoff_ref', 'ticket_ref', 'notes'] as $safeField) {
                if ($this->containsForbiddenMarker((string)($row[$safeField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeField;
                }
            }
        }

        foreach ($this->requiredRoles() as $role) {
            if (!isset($seen[$role])) {
                $issues[] = 'missing_required_acceptance_role_' . $role;
            }
        }

        return array_values(array_unique($issues));
    }

    private function reviewResultApplyGatePrecondition(string $path): array
    {
        $content = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        $ok = $path !== ''
            && $this->readReportResult($path) === 'PASS'
            && strpos($content, MongoyiaProductionExternalEvidenceReviewResultApplyGateService::EVIDENCE_VERSION) !== false
            && strpos($content, 'review_result_apply_executed=0') !== false
            && strpos($content, 'review_accepted=0') !== false
            && strpos($content, 'production_go_live_allowed=0') !== false;

        return $this->precondition(
            'review_result_apply_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'Latest production external evidence review-result apply gate must PASS while apply and acceptance stay disabled.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS review-result apply gate report or boundary markers.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-production-external-evidence-final-acceptance-gate.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-evidence-summary.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-go-live-gate.md');
        $missing = $this->missingNeedles($content, [
            self::EVIDENCE_VERSION,
            'Mongoyia Production External Evidence Final Acceptance Gate',
            'evidence_accepted=0',
            'final_acceptance_executed=0',
            'production_go_live_allowed=0',
        ]);

        return $this->precondition(
            'documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Production docs describe the external evidence final acceptance boundary.',
            empty($missing) ? 'Documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function packageCheckPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaPackageCheckController.php');
        $missing = $this->missingNeedles($content, [
            'MongoyiaProductionExternalEvidenceFinalAcceptanceGateController.php',
            'MongoyiaProductionExternalEvidenceFinalAcceptanceGateService.php',
            'docs/mongoyia-production-external-evidence-final-acceptance-gate.md',
        ]);

        return $this->precondition(
            'package_check_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Package check includes the production external evidence final acceptance gate files.',
            empty($missing) ? 'Package check markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function deliveryIndexPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaDeliveryIndexController.php');
        $missing = $this->missingNeedles($content, [
            'productionExternalEvidenceFinalAcceptanceGatePath',
            'mongoyia-production-external-evidence-final-acceptance-gate-*.md',
            'Production external evidence final acceptance gate result',
        ]);

        return $this->precondition(
            'delivery_index_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Delivery index auto-references the latest production external evidence final acceptance gate report.',
            empty($missing) ? 'Delivery index markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function acceptanceContractPrecondition(array $rows): array
    {
        $roles = [];
        foreach ($rows as $row) {
            if (($row['decision'] ?? '') === 'ready') {
                $roles[(string)($row['acceptance_role'] ?? '')] = true;
            }
        }
        $ok = count($rows) === count($this->requiredRoles()) && count($roles) === count($this->requiredRoles());

        return $this->precondition(
            'final_acceptance_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'The gate must validate all final owner/signoff metadata while keeping evidence acceptance and go-live disabled.',
            $ok ? 'Final acceptance metadata is complete and still read-only.' : 'Final acceptance metadata is incomplete.'
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues, bool $valid): array
    {
        $roles = [];
        foreach ($rows as $row) {
            $role = (string)($row['acceptance_role'] ?? '');
            if (in_array($role, $this->requiredRoles(), true)) {
                $roles[$role] = true;
            }
        }
        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }

        return [
            'final_acceptance_row_count' => count($rows),
            'valid_final_acceptance_row_count' => empty($rowIssues) ? count($rows) : 0,
            'ready_role_count' => count($roles),
            'required_role_count' => count($this->requiredRoles()),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => count($this->requiredRoles()),
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'final_acceptance_metadata_valid' => $valid ? 1 : 0,
            'final_acceptance_ready' => $valid ? 1 : 0,
            'evidence_accepted' => 0,
            'final_acceptance_executed' => 0,
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
            'key' => 'final_acceptance_metadata',
            'status' => $valid ? 'ready' : 'blocked',
            'details' => empty($rowIssues) ? 'Final owner/signoff metadata is complete.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'evidence_acceptance',
            'status' => 'disabled',
            'details' => 'evidence_accepted=0 and final_acceptance_executed=0 remain intentional.',
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
            '# Mongoyia Production External Evidence Final Acceptance Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence version: ' . (string)($report['evidenceVersion'] ?? ''),
            '- Source evidence version: ' . (string)($report['sourceEvidenceVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Review-result apply gate path: ' . (string)($report['reviewResultApplyGatePath'] ?? ''),
            '- Final acceptance metadata valid: ' . (($report['finalAcceptanceMetadataValid'] ?? false) ? 'yes' : 'no'),
            '- Final acceptance ready: ' . (($report['finalAcceptanceReady'] ?? false) ? 'yes' : 'no'),
            '- Evidence accepted: ' . (($report['evidenceAccepted'] ?? true) ? 'yes' : 'no'),
            '- Final acceptance executed: ' . (($report['finalAcceptanceExecuted'] ?? true) ? 'yes' : 'no'),
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
            '## Final Acceptance Rows',
            '',
            '| Role | Owner ref | Signoff ref | Ticket ref | Decision | Status |',
            '|---|---|---|---|---|---|',
        ]);
        foreach (($report['acceptanceRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['acceptance_role'])
                . ' | ' . $this->escapeCell((string)$row['owner_ref'])
                . ' | ' . $this->escapeCell((string)$row['signoff_ref'])
                . ' | ' . $this->escapeCell((string)$row['ticket_ref'])
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['acceptance_status'])
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
            '- final_acceptance_metadata_valid=1 means final acceptance metadata is complete, not that evidence is accepted.',
            '- final_acceptance_ready=1 means the local preflight is ready for external/manual acceptance review.',
            '- evidence_accepted=0 remains intentional; this gate cannot accept production evidence.',
            '- final_acceptance_executed=0 remains intentional; this gate never writes signoff or acceptance rows.',
            '- production_go_live_allowed=0 remains intentional until the separate go-live gate passes with real external approvals.',
            '- production_final_no_go=1 remains intentional for this local gate.',
            '- No evidence artifact is read, copied, hashed, imported, or stored.',
            '- No provider, IM, DNS, TLS, backup storage, or monitoring service is called.',
            '- No order, payment, callback, chat, file, shipment, fund, ticket, statistic, signoff, or review row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['acceptance_role,owner_ref,signoff_ref,ticket_ref,decision,acceptance_status,signed_at,notes'];
        foreach (($report['acceptanceRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['acceptance_role']),
                $this->csvCell((string)$row['owner_ref']),
                $this->csvCell((string)$row['signoff_ref']),
                $this->csvCell((string)$row['ticket_ref']),
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['acceptance_status']),
                $this->csvCell((string)$row['signed_at']),
                $this->csvCell((string)$row['notes']),
            ]);
        }

        return $lines;
    }

    private function requiredRoles(): array
    {
        return ['business', 'ops', 'security', 'payment', 'engineering', 'finance', 'language', 'rollback'];
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
