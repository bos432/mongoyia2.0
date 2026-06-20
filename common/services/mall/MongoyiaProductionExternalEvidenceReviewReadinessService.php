<?php

namespace common\services\mall;

class MongoyiaProductionExternalEvidenceReviewReadinessService
{
    public const EVIDENCE_VERSION = 'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_READINESS_V1';

    private const MODE = 'production_external_evidence_review_readiness_read_only';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $importDryRunPath = trim((string)($input['importDryRunPath'] ?? ''));
        $importDryRunPath = $importDryRunPath !== ''
            ? $this->resolvePath($importDryRunPath)
            : $this->latestHandoverFile('mongoyia-production-external-evidence-import-dry-run-*.md');

        $reviewers = $this->reviewerRows();
        $rowIssues = $this->validateReviewerRows($reviewers);
        $preconditions = [
            $this->importDryRunPrecondition($importDryRunPath),
            $this->documentationPrecondition(),
            $this->packageCheckPrecondition(),
            $this->deliveryIndexPrecondition(),
        ];

        $issues = $rowIssues;
        foreach ($preconditions as $precondition) {
            if (!($precondition['satisfied'] ?? false)) {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }

        $inputValid = empty($issues);

        return [
            'evidenceVersion' => self::EVIDENCE_VERSION,
            'sourceEvidenceVersion' => MongoyiaProductionExternalEvidenceImportDryRunService::EVIDENCE_VERSION,
            'mode' => self::MODE,
            'importDryRunPath' => $this->relativePath($importDryRunPath),
            'reviewInputValid' => $inputValid,
            'reviewAccepted' => false,
            'productionGoLiveAllowed' => false,
            'productionFinalNoGo' => true,
            'reviewerRows' => $reviewers,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($reviewers, $preconditions, $rowIssues, $inputValid),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $inputValid),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function reviewerRows(): array
    {
        $rows = [];
        $index = 0;
        foreach ($this->requiredRoles() as $role) {
            $index++;
            $rows[] = [
                'review_role' => $role,
                'reviewer_ref' => 'reviewer:PROD-' . strtoupper($role),
                'signoff_ref' => 'signoff:PROD-GATE-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'review_status' => 'pending_review',
                'reviewed_at' => '2026-06-19T11:' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . ':00Z',
                'notes' => 'Safe reviewer/signoff reference only; final acceptance stays outside this dry-run.',
            ];
        }

        return $rows;
    }

    private function validateReviewerRows(array $rows): array
    {
        $issues = [];
        $seen = [];
        $required = $this->requiredRoles();
        foreach ($rows as $index => $row) {
            foreach (['review_role', 'reviewer_ref', 'signoff_ref', 'review_status', 'reviewed_at', 'notes'] as $field) {
                if (!array_key_exists($field, $row) || trim((string)$row[$field]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $field;
                }
            }

            $role = (string)($row['review_role'] ?? '');
            if (!in_array($role, $required, true)) {
                $issues[] = 'row_' . $index . '_unknown_review_role';
            }
            if (isset($seen[$role])) {
                $issues[] = 'row_' . $index . '_duplicate_review_role';
            }
            $seen[$role] = true;

            if ((string)($row['review_status'] ?? '') !== 'pending_review') {
                $issues[] = 'row_' . $index . '_invalid_review_status';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($row['reviewed_at'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_reviewed_at';
            }
            foreach (['reviewer_ref', 'signoff_ref'] as $refField) {
                if (!preg_match('/^[A-Za-z0-9:_-]+$/', (string)($row[$refField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_invalid_' . $refField;
                }
            }
            foreach (['reviewer_ref', 'signoff_ref', 'notes'] as $safeField) {
                if ($this->containsForbiddenMarker((string)($row[$safeField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeField;
                }
            }
        }

        foreach ($required as $role) {
            if (!isset($seen[$role])) {
                $issues[] = 'missing_required_review_role_' . $role;
            }
        }

        return array_values(array_unique($issues));
    }

    private function importDryRunPrecondition(string $path): array
    {
        $content = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        $ok = $path !== ''
            && $this->readReportResult($path) === 'PASS'
            && strpos($content, MongoyiaProductionExternalEvidenceImportDryRunService::EVIDENCE_VERSION) !== false
            && strpos($content, 'evidence_import_executed=0') !== false
            && strpos($content, 'production_final_no_go=1') !== false;

        return $this->precondition(
            'import_dry_run_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'Latest production external evidence import dry-run report must PASS while import stays disabled.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS import dry-run report or boundary markers.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-production-external-evidence-review-readiness.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-external-evidence-import-dry-run.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-go-live-gate.md');
        $missing = $this->missingNeedles($content, [
            self::EVIDENCE_VERSION,
            'Mongoyia Production External Evidence Review Readiness',
            'review_accepted=0',
            'production_go_live_allowed=0',
        ]);

        return $this->precondition(
            'documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Production docs describe the external evidence review readiness boundary.',
            empty($missing) ? 'Documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function packageCheckPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaPackageCheckController.php');
        $missing = $this->missingNeedles($content, [
            'MongoyiaProductionExternalEvidenceReviewReadinessController.php',
            'MongoyiaProductionExternalEvidenceReviewReadinessService.php',
            'docs/mongoyia-production-external-evidence-review-readiness.md',
        ]);

        return $this->precondition(
            'package_check_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Package check includes the production external evidence review readiness files.',
            empty($missing) ? 'Package check markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function deliveryIndexPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaDeliveryIndexController.php');
        $missing = $this->missingNeedles($content, [
            'productionExternalEvidenceReviewReadinessPath',
            'mongoyia-production-external-evidence-review-readiness-*.md',
            'Production external evidence review readiness result',
        ]);

        return $this->precondition(
            'delivery_index_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Delivery index auto-references the latest production external evidence review readiness report.',
            empty($missing) ? 'Delivery index markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues, bool $inputValid): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $role = (string)($row['review_role'] ?? '');
            if (in_array($role, $this->requiredRoles(), true)) {
                $seen[$role] = true;
            }
        }
        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }

        return [
            'reviewer_row_count' => count($rows),
            'valid_reviewer_row_count' => empty($rowIssues) ? count($rows) : 0,
            'required_role_count' => count($this->requiredRoles()),
            'covered_required_role_count' => count($seen),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_signoff_count' => count($this->requiredRoles()),
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'review_input_valid' => $inputValid ? 1 : 0,
            'review_accepted' => 0,
            'production_go_live_allowed' => 0,
            'production_final_no_go' => 1,
        ];
    }

    private function gateChecks(array $preconditions, array $rowIssues, bool $inputValid): array
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
            'key' => 'review_input_valid',
            'status' => $inputValid ? 'ready' : 'blocked',
            'details' => $inputValid ? 'Reviewer/signoff metadata is complete.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'review_acceptance',
            'status' => 'disabled',
            'details' => 'review_accepted=0 remains intentional; this command cannot accept evidence.',
        ];
        $checks[] = [
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'This command does not read, copy, hash, import, or store evidence artifacts.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment, chat, file, shipment, fund, ticket, statistic, or signoff row is created or updated.',
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
            '# Mongoyia Production External Evidence Review Readiness',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence version: ' . (string)($report['evidenceVersion'] ?? ''),
            '- Source evidence version: ' . (string)($report['sourceEvidenceVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Import dry-run path: ' . (string)($report['importDryRunPath'] ?? ''),
            '- Review input valid: ' . (($report['reviewInputValid'] ?? false) ? 'yes' : 'no'),
            '- Review accepted: ' . (($report['reviewAccepted'] ?? true) ? 'yes' : 'no'),
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
            '## Reviewer Rows',
            '',
            '| Role | Reviewer ref | Signoff ref | Status | Reviewed at |',
            '|---|---|---|---|---|',
        ]);
        foreach (($report['reviewerRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['review_role'])
                . ' | ' . $this->escapeCell((string)$row['reviewer_ref'])
                . ' | ' . $this->escapeCell((string)$row['signoff_ref'])
                . ' | ' . $this->escapeCell((string)$row['review_status'])
                . ' | ' . $this->escapeCell((string)$row['reviewed_at'])
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
            '- review_input_valid=1 means reviewer metadata fields are present, not that evidence is accepted.',
            '- review_accepted=0 remains intentional; this command cannot accept evidence.',
            '- production_go_live_allowed=0 remains intentional until the separate go-live gate passes.',
            '- production_final_no_go=1 remains intentional for this local readiness report.',
            '- No evidence artifact is read, copied, hashed, imported, or stored.',
            '- No provider, IM, DNS, TLS, backup storage, or monitoring service is called.',
            '- No order, payment, callback, chat, file, shipment, fund, ticket, statistic, or signoff row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['review_role,reviewer_ref,signoff_ref,review_status,reviewed_at,notes'];
        foreach (($report['reviewerRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['review_role']),
                $this->csvCell((string)$row['reviewer_ref']),
                $this->csvCell((string)$row['signoff_ref']),
                $this->csvCell((string)$row['review_status']),
                $this->csvCell((string)$row['reviewed_at']),
                $this->csvCell((string)$row['notes']),
            ]);
        }

        return $lines;
    }

    private function requiredRoles(): array
    {
        return ['business', 'ops', 'security', 'payment', 'engineering', 'finance', 'language'];
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
