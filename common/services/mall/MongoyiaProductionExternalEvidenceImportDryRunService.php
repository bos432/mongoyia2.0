<?php

namespace common\services\mall;

class MongoyiaProductionExternalEvidenceImportDryRunService
{
    public const EVIDENCE_VERSION = 'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1';

    private const MODE = 'production_external_evidence_import_dry_run_metadata_only';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $manifestPath = trim((string)($input['manifestPath'] ?? ''));
        $rows = $manifestPath !== ''
            ? $this->loadManifestRows($this->resolvePath($manifestPath))
            : $this->templateRows();
        $rowIssues = $this->validateRows($rows);
        $preconditions = [
            $this->documentationPrecondition(),
            $this->packageCheckPrecondition(),
            $this->deliveryIndexPrecondition(),
            $this->productionEvidenceSummaryPrecondition(),
            $this->goLiveNoGoPrecondition(),
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
            'mode' => self::MODE,
            'manifestPath' => $manifestPath !== '' ? $this->relativePath($this->resolvePath($manifestPath)) : 'built-in-template',
            'externalEvidenceInputValid' => $inputValid,
            'evidenceImportAllowed' => false,
            'evidenceImportExecuted' => false,
            'productionGoLiveAllowed' => false,
            'productionFinalNoGo' => true,
            'manifestRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $preconditions, $rowIssues, $inputValid),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $inputValid),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function loadManifestRows(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['rows']) && is_array($decoded['rows'])) {
            return $decoded['rows'];
        }
        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function templateRows(): array
    {
        $rows = [];
        $index = 0;
        foreach ($this->requiredEvidenceKeys() as $key) {
            $index++;
            $rows[] = [
                'evidence_key' => $key,
                'source_ref' => 'ticket:PROD-GATE-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'artifact_ref' => 'artifact-ref:PROD-GATE-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'artifact_sha256' => hash('sha256', 'mongoyia-production-evidence-' . $key),
                'redaction_status' => 'redacted',
                'owner_role' => $this->ownerRole($key),
                'environment' => 'production',
                'reviewed_at' => '2026-06-19T10:' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . ':00Z',
                'decision' => 'pending_review',
                'notes' => 'Sanitized metadata reference only; raw evidence remains external.',
            ];
        }

        return $rows;
    }

    private function validateRows(array $rows): array
    {
        $issues = [];
        $seen = [];
        $required = $this->requiredEvidenceKeys();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $issues[] = 'row_' . $index . '_not_object';
                continue;
            }
            foreach ([
                'evidence_key',
                'source_ref',
                'artifact_ref',
                'artifact_sha256',
                'redaction_status',
                'owner_role',
                'environment',
                'reviewed_at',
                'decision',
                'notes',
            ] as $field) {
                if (!array_key_exists($field, $row) || trim((string)$row[$field]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $field;
                }
            }

            $evidenceKey = (string)($row['evidence_key'] ?? '');
            if (!in_array($evidenceKey, $required, true)) {
                $issues[] = 'row_' . $index . '_unknown_evidence_key';
            }
            if (isset($seen[$evidenceKey])) {
                $issues[] = 'row_' . $index . '_duplicate_evidence_key';
            }
            $seen[$evidenceKey] = true;

            if (!preg_match('/^[a-f0-9]{64}$/', (string)($row['artifact_sha256'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_artifact_sha256';
            }
            if (!in_array((string)($row['redaction_status'] ?? ''), ['redacted', 'not_applicable'], true)) {
                $issues[] = 'row_' . $index . '_invalid_redaction_status';
            }
            if (!in_array((string)($row['owner_role'] ?? ''), ['business', 'ops', 'security', 'payment', 'engineering', 'language', 'finance'], true)) {
                $issues[] = 'row_' . $index . '_invalid_owner_role';
            }
            if ((string)($row['environment'] ?? '') !== 'production') {
                $issues[] = 'row_' . $index . '_invalid_environment';
            }
            if ((string)($row['decision'] ?? '') !== 'pending_review') {
                $issues[] = 'row_' . $index . '_invalid_decision';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($row['reviewed_at'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_reviewed_at';
            }
            foreach (['source_ref', 'artifact_ref'] as $refField) {
                if (!preg_match('/^[A-Za-z0-9:_-]+$/', (string)($row[$refField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_invalid_' . $refField;
                }
            }
            foreach (['source_ref', 'artifact_ref', 'notes'] as $safeField) {
                if ($this->containsForbiddenMarker((string)($row[$safeField] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeField;
                }
            }
        }

        foreach ($required as $key) {
            if (!isset($seen[$key])) {
                $issues[] = 'missing_required_evidence_' . $key;
            }
        }

        return array_values(array_unique($issues));
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-production-external-evidence-import-dry-run.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-go-live-gate.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-production-evidence-summary.md');
        $missing = $this->missingNeedles($content, [
            self::EVIDENCE_VERSION,
            'Mongoyia Production External Evidence Import Dry Run',
            'evidence_import_executed=0',
            'production_final_no_go=1',
        ]);

        return $this->precondition(
            'documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Production docs describe the external evidence import dry-run boundary.',
            empty($missing) ? 'Documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function packageCheckPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaPackageCheckController.php');
        $missing = $this->missingNeedles($content, [
            'MongoyiaProductionExternalEvidenceImportDryRunController.php',
            'MongoyiaProductionExternalEvidenceImportDryRunService.php',
            'docs/mongoyia-production-external-evidence-import-dry-run.md',
        ]);

        return $this->precondition(
            'package_check_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Package check includes the new production external evidence import dry-run files.',
            empty($missing) ? 'Package check markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function deliveryIndexPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaDeliveryIndexController.php');
        $missing = $this->missingNeedles($content, [
            'productionExternalEvidenceImportDryRunPath',
            'mongoyia-production-external-evidence-import-dry-run-*.md',
            'Production external evidence import dry-run result',
        ]);

        return $this->precondition(
            'delivery_index_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Delivery index auto-references the latest production external evidence import dry-run report.',
            empty($missing) ? 'Delivery index markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function productionEvidenceSummaryPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-production-evidence-summary-*.md');
        $result = $this->readReportResult($path);
        $ok = $path !== '' && in_array($result, ['PASS', 'WARN', 'FAIL'], true);

        return $this->precondition(
            'production_evidence_summary_indexed',
            $ok,
            $ok ? strtolower($result) : 'blocked',
            'A production evidence summary report exists for traceability.',
            $ok ? $this->relativePath($path) : 'No production evidence summary report found.'
        );
    }

    private function goLiveNoGoPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-production-go-live-gate-*.md');
        $content = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
        $ok = $path !== ''
            && strpos($content, 'Final decision: NO-GO') !== false
            && strpos($content, 'go_allowed') !== false;

        return $this->precondition(
            'go_live_gate_no_go_boundary',
            $ok,
            $ok ? 'no-go' : 'blocked',
            'Latest go-live gate must remain NO-GO while this dry-run only validates metadata.',
            $ok ? $this->relativePath($path) : 'Missing latest go-live gate NO-GO marker.'
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues, bool $inputValid): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = is_array($row) ? (string)($row['evidence_key'] ?? '') : '';
            if (in_array($key, $this->requiredEvidenceKeys(), true)) {
                $seen[$key] = true;
            }
        }
        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }

        return [
            'manifest_row_count' => count($rows),
            'valid_manifest_row_count' => empty($rowIssues) ? count($rows) : 0,
            'required_evidence_count' => count($this->requiredEvidenceKeys()),
            'covered_required_evidence_count' => count($seen),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => count($this->requiredEvidenceKeys()),
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'external_evidence_input_valid' => $inputValid ? 1 : 0,
            'evidence_import_allowed' => 0,
            'evidence_import_executed' => 0,
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
            'key' => 'external_evidence_input_valid',
            'status' => $inputValid ? 'ready' : 'blocked',
            'details' => $inputValid ? 'Sanitized production external evidence metadata is valid.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'evidence_import',
            'status' => 'disabled',
            'details' => 'No production evidence row is imported or persisted by this dry-run.',
        ];
        $checks[] = [
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'This dry-run validates metadata only and does not read, copy, hash, import, or store artifacts.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No QPay, LianLian, PayPal, IM, DNS, TLS, backup storage, or monitoring network call is made.',
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

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Production External Evidence Import Dry Run',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence version: ' . (string)($report['evidenceVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Manifest path: ' . (string)($report['manifestPath'] ?? ''),
            '- External evidence input valid: ' . (($report['externalEvidenceInputValid'] ?? false) ? 'yes' : 'no'),
            '- Evidence import allowed: ' . (($report['evidenceImportAllowed'] ?? true) ? 'yes' : 'no'),
            '- Evidence import executed: ' . (($report['evidenceImportExecuted'] ?? true) ? 'yes' : 'no'),
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
            '## Manifest Rows',
            '',
            '| Evidence key | Source ref | Artifact ref | Redaction | Owner | Environment | Decision |',
            '|---|---|---|---|---|---|---|',
        ]);
        foreach (($report['manifestRows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '| ' . $this->escapeCell((string)($row['evidence_key'] ?? ''))
                . ' | ' . $this->escapeCell((string)($row['source_ref'] ?? ''))
                . ' | ' . $this->escapeCell((string)($row['artifact_ref'] ?? ''))
                . ' | ' . $this->escapeCell((string)($row['redaction_status'] ?? ''))
                . ' | ' . $this->escapeCell((string)($row['owner_role'] ?? ''))
                . ' | ' . $this->escapeCell((string)($row['environment'] ?? ''))
                . ' | ' . $this->escapeCell((string)($row['decision'] ?? ''))
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
            '- external_evidence_input_valid=1 means sanitized metadata is valid, not that any evidence is accepted.',
            '- evidence_import_allowed=0 remains intentional; this dry-run cannot approve persistence.',
            '- evidence_import_executed=0 remains intentional; this dry-run never writes evidence rows.',
            '- production_go_live_allowed=0 remains intentional until the separate go-live gate passes.',
            '- production_final_no_go=1 remains intentional for this local dry-run.',
            '- The command does not read, copy, hash, import, or store evidence artifacts.',
            '- No provider, IM, DNS, TLS, backup storage, or monitoring service is called.',
            '- No order, payment, callback, chat, file, shipment, fund, ticket, statistic, or signoff row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['evidence_key,source_ref,artifact_ref,artifact_sha256,redaction_status,owner_role,environment,reviewed_at,decision,notes'];
        foreach (($report['manifestRows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = implode(',', [
                $this->csvCell((string)($row['evidence_key'] ?? '')),
                $this->csvCell((string)($row['source_ref'] ?? '')),
                $this->csvCell((string)($row['artifact_ref'] ?? '')),
                $this->csvCell((string)($row['artifact_sha256'] ?? '')),
                $this->csvCell((string)($row['redaction_status'] ?? '')),
                $this->csvCell((string)($row['owner_role'] ?? '')),
                $this->csvCell((string)($row['environment'] ?? '')),
                $this->csvCell((string)($row['reviewed_at'] ?? '')),
                $this->csvCell((string)($row['decision'] ?? '')),
                $this->csvCell((string)($row['notes'] ?? '')),
            ]);
        }

        return $lines;
    }

    private function requiredEvidenceKeys(): array
    {
        return [
            'prod_config_snapshot',
            'https_wss_dns_tls',
            'payment_production_credentials',
            'payment_callback_security',
            'im_wss_production',
            'backup_restore_drill',
            'monitoring_alert_route',
            'scheduled_check_registration',
            'formal_load_test',
            'security_hardening_review',
            'settlement_reconciliation_signoff',
            'mongolian_human_review',
            'rollback_launch_approval',
        ];
    }

    private function ownerRole(string $key): string
    {
        if (in_array($key, ['payment_production_credentials', 'payment_callback_security'], true)) {
            return 'payment';
        }
        if (in_array($key, ['security_hardening_review', 'https_wss_dns_tls'], true)) {
            return 'security';
        }
        if (in_array($key, ['backup_restore_drill', 'monitoring_alert_route', 'scheduled_check_registration', 'im_wss_production'], true)) {
            return 'ops';
        }
        if ($key === 'mongolian_human_review') {
            return 'language';
        }
        if ($key === 'settlement_reconciliation_signoff') {
            return 'finance';
        }
        if ($key === 'formal_load_test') {
            return 'engineering';
        }

        return 'business';
    }

    private function containsForbiddenMarker(string $value): bool
    {
        $forbidden = [
            'Authorization',
            'Bearer ',
            'PRIVATE KEY',
            'BEGIN RSA',
            'client_secret',
            'password',
            '.env',
            'C:\\',
            '/home/',
            '/var/',
            '\\\\',
            'mysql://',
            'redis://',
            'QPAY_AUTH_BASIC',
            'LIANLIAN_PRIVATE_KEY',
            'IM_AUTH_SECRET',
        ];
        foreach ($forbidden as $needle) {
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
        $escaped = str_replace('"', '""', $value);
        return '"' . $escaped . '"';
    }
}
