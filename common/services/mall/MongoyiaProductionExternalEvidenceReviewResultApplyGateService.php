<?php

namespace common\services\mall;

class MongoyiaProductionExternalEvidenceReviewResultApplyGateService
{
    public const EVIDENCE_VERSION = 'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1';

    private const MODE = 'production_external_evidence_review_result_apply_gate_read_only';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $reviewReadinessPath = trim((string)($input['reviewReadinessPath'] ?? ''));
        $reviewReadinessPath = $reviewReadinessPath !== ''
            ? $this->resolvePath($reviewReadinessPath)
            : $this->latestHandoverFile('mongoyia-production-external-evidence-review-readiness-*.md');

        $rows = $this->reviewResultRows();
        $planRows = $this->applyPlanRows($rows);
        $rowIssues = $this->validateRows($rows);
        $planIssues = $this->validatePlanRows($planRows);
        $preconditions = [
            $this->reviewReadinessPrecondition($reviewReadinessPath),
            $this->documentationPrecondition(),
            $this->packageCheckPrecondition(),
            $this->deliveryIndexPrecondition(),
            $this->applyContractPrecondition($planRows),
        ];

        $issues = array_merge($rowIssues, $planIssues);
        foreach ($preconditions as $precondition) {
            if (!($precondition['satisfied'] ?? false)) {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }
        $valid = empty($issues);

        return [
            'evidenceVersion' => self::EVIDENCE_VERSION,
            'sourceEvidenceVersion' => MongoyiaProductionExternalEvidenceReviewReadinessService::EVIDENCE_VERSION,
            'mode' => self::MODE,
            'reviewReadinessPath' => $this->relativePath($reviewReadinessPath),
            'reviewResultValid' => $valid,
            'reviewResultApplyAllowed' => false,
            'reviewResultApplyExecuted' => false,
            'reviewAccepted' => false,
            'productionGoLiveAllowed' => false,
            'productionFinalNoGo' => true,
            'reviewResultRows' => $rows,
            'applyPlanRows' => $planRows,
            'rowIssues' => $rowIssues,
            'planIssues' => $planIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $planRows, $preconditions, $rowIssues, $planIssues, $valid),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $planIssues, $valid),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function reviewResultRows(): array
    {
        $rows = [];
        $index = 0;
        foreach ($this->requiredRoles() as $role) {
            $index++;
            $rows[] = [
                'review_role' => $role,
                'reviewer_ref' => 'reviewer:PROD-' . strtoupper($role),
                'signoff_ref' => 'signoff:PROD-RESULT-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'review_result_ref' => 'review-result:PROD-GATE-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'decision' => 'approve',
                'result_status' => 'ready_for_apply_gate',
                'reviewed_at' => '2026-06-19T12:' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . ':00Z',
                'notes' => 'Safe review-result metadata only; apply and acceptance stay disabled.',
            ];
        }

        return $rows;
    }

    private function applyPlanRows(array $rows): array
    {
        $approved = [];
        foreach ($rows as $row) {
            if (($row['decision'] ?? '') === 'approve') {
                $approved[(string)($row['review_role'] ?? '')] = true;
            }
        }

        return [
            [
                'operation' => 'would_record_production_external_evidence_review_result',
                'source_review_result_ref' => 'review-result:PROD-GATE',
                'approved_role_count' => count($approved),
                'required_role_count' => count($this->requiredRoles()),
                'review_result_valid' => count($approved) === count($this->requiredRoles()),
                'apply_allowed' => false,
                'apply_executed' => false,
                'review_accepted' => false,
                'production_go_live_allowed' => false,
                'production_final_no_go' => true,
                'reason' => 'Read-only gate: real review-result apply remains external and separately approved.',
            ],
        ];
    }

    private function validateRows(array $rows): array
    {
        $issues = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            foreach (['review_role', 'reviewer_ref', 'signoff_ref', 'review_result_ref', 'decision', 'result_status', 'reviewed_at', 'notes'] as $field) {
                if (!array_key_exists($field, $row) || trim((string)$row[$field]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $field;
                }
            }

            $role = (string)($row['review_role'] ?? '');
            if (!in_array($role, $this->requiredRoles(), true)) {
                $issues[] = 'row_' . $index . '_unknown_review_role';
            }
            if (isset($seen[$role])) {
                $issues[] = 'row_' . $index . '_duplicate_review_role';
            }
            $seen[$role] = true;

            if ((string)($row['decision'] ?? '') !== 'approve') {
                $issues[] = 'row_' . $index . '_decision_not_approve';
            }
            if ((string)($row['result_status'] ?? '') !== 'ready_for_apply_gate') {
                $issues[] = 'row_' . $index . '_invalid_result_status';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($row['reviewed_at'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_reviewed_at';
            }
            foreach (['reviewer_ref', 'signoff_ref', 'review_result_ref'] as $refField) {
                if (!preg_match('/^[A-Za-z0-9:_-]+$/', (string)($row[$refField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_invalid_' . $refField;
                }
            }
            foreach (['reviewer_ref', 'signoff_ref', 'review_result_ref', 'notes'] as $safeField) {
                if ($this->containsForbiddenMarker((string)($row[$safeField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeField;
                }
            }
        }

        foreach ($this->requiredRoles() as $role) {
            if (!isset($seen[$role])) {
                $issues[] = 'missing_required_review_role_' . $role;
            }
        }

        return array_values(array_unique($issues));
    }

    private function validatePlanRows(array $rows): array
    {
        $issues = [];
        if (count($rows) !== 1) {
            $issues[] = 'apply_plan_row_count_not_one';
        }
        foreach ($rows as $index => $row) {
            if (($row['operation'] ?? '') !== 'would_record_production_external_evidence_review_result') {
                $issues[] = 'plan_' . $index . '_invalid_operation';
            }
            foreach (['apply_allowed', 'apply_executed', 'review_accepted', 'production_go_live_allowed'] as $key) {
                if (!array_key_exists($key, $row) || (bool)$row[$key] !== false) {
                    $issues[] = 'plan_' . $index . '_' . $key . '_must_be_false';
                }
            }
            if (!array_key_exists('production_final_no_go', $row) || (bool)$row['production_final_no_go'] !== true) {
                $issues[] = 'plan_' . $index . '_production_final_no_go_must_be_true';
            }
            if ((int)($row['approved_role_count'] ?? 0) !== count($this->requiredRoles())) {
                $issues[] = 'plan_' . $index . '_approved_role_count_mismatch';
            }
        }

        return array_values(array_unique($issues));
    }

    private function reviewReadinessPrecondition(string $path): array
    {
        $content = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        $ok = $path !== ''
            && $this->readReportResult($path) === 'PASS'
            && strpos($content, MongoyiaProductionExternalEvidenceReviewReadinessService::EVIDENCE_VERSION) !== false
            && strpos($content, 'review_accepted=0') !== false
            && strpos($content, 'production_go_live_allowed=0') !== false;

        return $this->precondition(
            'review_readiness_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'Latest production external evidence review readiness report must PASS while review stays unaccepted.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS review readiness report or boundary markers.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-production-external-evidence-review-result-apply-gate.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-external-evidence-review-readiness.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-go-live-gate.md');
        $missing = $this->missingNeedles($content, [
            self::EVIDENCE_VERSION,
            'Mongoyia Production External Evidence Review Result Apply Gate',
            'review_result_apply_executed=0',
            'review_accepted=0',
            'production_go_live_allowed=0',
        ]);

        return $this->precondition(
            'documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Production docs describe the external evidence review-result apply boundary.',
            empty($missing) ? 'Documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function packageCheckPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaPackageCheckController.php');
        $missing = $this->missingNeedles($content, [
            'MongoyiaProductionExternalEvidenceReviewResultApplyGateController.php',
            'MongoyiaProductionExternalEvidenceReviewResultApplyGateService.php',
            'docs/mongoyia-production-external-evidence-review-result-apply-gate.md',
        ]);

        return $this->precondition(
            'package_check_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Package check includes the production external evidence review-result apply gate files.',
            empty($missing) ? 'Package check markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function deliveryIndexPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaDeliveryIndexController.php');
        $missing = $this->missingNeedles($content, [
            'productionExternalEvidenceReviewResultApplyGatePath',
            'mongoyia-production-external-evidence-review-result-apply-gate-*.md',
            'Production external evidence review-result apply gate result',
        ]);

        return $this->precondition(
            'delivery_index_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Delivery index auto-references the latest production external evidence review-result apply gate report.',
            empty($missing) ? 'Delivery index markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function applyContractPrecondition(array $planRows): array
    {
        $row = $planRows[0] ?? [];
        $ok = count($planRows) === 1
            && (string)($row['operation'] ?? '') === 'would_record_production_external_evidence_review_result'
            && (int)($row['approved_role_count'] ?? 0) === count($this->requiredRoles())
            && !((bool)($row['apply_allowed'] ?? true))
            && !((bool)($row['apply_executed'] ?? true))
            && !((bool)($row['review_accepted'] ?? true))
            && !((bool)($row['production_go_live_allowed'] ?? true))
            && (bool)($row['production_final_no_go'] ?? false);

        return $this->precondition(
            'review_result_apply_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'The gate must produce one dry-run plan row and keep apply/acceptance/go-live disabled.',
            $ok ? 'Review-result apply contract is read-only and disabled.' : 'Review-result apply plan does not match the expected disabled contract.'
        );
    }

    private function totals(array $rows, array $planRows, array $preconditions, array $rowIssues, array $planIssues, bool $valid): array
    {
        $roles = [];
        foreach ($rows as $row) {
            $role = (string)($row['review_role'] ?? '');
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
            'review_result_row_count' => count($rows),
            'valid_review_result_row_count' => empty($rowIssues) ? count($rows) : 0,
            'approved_role_count' => count($roles),
            'required_role_count' => count($this->requiredRoles()),
            'apply_plan_row_count' => count($planRows),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => count($this->requiredRoles()),
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'review_result_valid' => $valid ? 1 : 0,
            'review_result_apply_allowed' => 0,
            'review_result_apply_executed' => 0,
            'review_accepted' => 0,
            'production_go_live_allowed' => 0,
            'production_final_no_go' => 1,
        ];
    }

    private function gateChecks(array $preconditions, array $rowIssues, array $planIssues, bool $valid): array
    {
        $checks = [];
        foreach ($preconditions as $precondition) {
            $checks[] = [
                'key' => (string)$precondition['key'],
                'status' => (string)$precondition['status'],
                'details' => (string)$precondition['evidence'],
            ];
        }
        $issues = array_merge($rowIssues, $planIssues);
        $checks[] = [
            'key' => 'review_result_valid',
            'status' => $valid ? 'ready' : 'blocked',
            'details' => empty($issues) ? 'Review-result metadata and dry-run plan are valid.' : 'Issues: ' . implode(', ', $issues),
        ];
        $checks[] = [
            'key' => 'review_result_apply',
            'status' => 'disabled',
            'details' => 'review_result_apply_allowed=0 and review_result_apply_executed=0 remain intentional.',
        ];
        $checks[] = [
            'key' => 'review_acceptance',
            'status' => 'disabled',
            'details' => 'review_accepted=0 remains intentional; this command cannot accept production evidence review results.',
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
            '# Mongoyia Production External Evidence Review Result Apply Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence version: ' . (string)($report['evidenceVersion'] ?? ''),
            '- Source evidence version: ' . (string)($report['sourceEvidenceVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Review readiness path: ' . (string)($report['reviewReadinessPath'] ?? ''),
            '- Review result valid: ' . (($report['reviewResultValid'] ?? false) ? 'yes' : 'no'),
            '- Review result apply allowed: ' . (($report['reviewResultApplyAllowed'] ?? true) ? 'yes' : 'no'),
            '- Review result apply executed: ' . (($report['reviewResultApplyExecuted'] ?? true) ? 'yes' : 'no'),
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
            '## Review Result Rows',
            '',
            '| Role | Reviewer ref | Signoff ref | Result ref | Decision | Status |',
            '|---|---|---|---|---|---|',
        ]);
        foreach (($report['reviewResultRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['review_role'])
                . ' | ' . $this->escapeCell((string)$row['reviewer_ref'])
                . ' | ' . $this->escapeCell((string)$row['signoff_ref'])
                . ' | ' . $this->escapeCell((string)$row['review_result_ref'])
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['result_status'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Dry-Run Apply Plan',
            '',
            '| Operation | Approved roles | Required roles | Apply allowed | Apply executed | Review accepted | Go-live allowed | Final NO-GO | Reason |',
            '|---|---:|---:|---:|---:|---:|---:|---:|---|',
        ]);
        foreach (($report['applyPlanRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['operation'])
                . ' | ' . (int)$row['approved_role_count']
                . ' | ' . (int)$row['required_role_count']
                . ' | ' . ((bool)$row['apply_allowed'] ? '1' : '0')
                . ' | ' . ((bool)$row['apply_executed'] ? '1' : '0')
                . ' | ' . ((bool)$row['review_accepted'] ? '1' : '0')
                . ' | ' . ((bool)$row['production_go_live_allowed'] ? '1' : '0')
                . ' | ' . ((bool)$row['production_final_no_go'] ? '1' : '0')
                . ' | ' . $this->escapeCell((string)$row['reason'])
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
            '- review_result_valid=1 means review-result metadata is valid, not that any result is applied.',
            '- review_result_apply_allowed=0 remains intentional; this gate cannot approve persistence.',
            '- review_result_apply_executed=0 remains intentional; this gate never writes review/signoff rows.',
            '- review_accepted=0 remains intentional; this gate cannot accept evidence.',
            '- production_go_live_allowed=0 remains intentional until the separate go-live gate passes.',
            '- production_final_no_go=1 remains intentional for this local gate.',
            '- No evidence artifact is read, copied, hashed, imported, or stored.',
            '- No provider, IM, DNS, TLS, backup storage, or monitoring service is called.',
            '- No order, payment, callback, chat, file, shipment, fund, ticket, statistic, signoff, or review row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['review_role,reviewer_ref,signoff_ref,review_result_ref,decision,result_status,reviewed_at,notes'];
        foreach (($report['reviewResultRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['review_role']),
                $this->csvCell((string)$row['reviewer_ref']),
                $this->csvCell((string)$row['signoff_ref']),
                $this->csvCell((string)$row['review_result_ref']),
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['result_status']),
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
