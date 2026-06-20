<?php

namespace common\services\mall;

class PaypalExternalEvidenceManifestReviewSignoffImportDryRunService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_SIGNOFF_IMPORT_DRY_RUN_V1';

    private const MODE = 'paypal_external_evidence_manifest_review_signoff_import_dry_run_no_persistence_no_artifact_access';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $fields = $this->templateFields();
        $rows = $this->fixtureRows();
        $rowIssues = $this->validateRows($rows, $fields);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while manifest review signoff import is only a dry-run.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->manifestReviewReadinessPrecondition(),
            $this->documentationPrecondition(),
            $this->templateContractPrecondition($fields),
            $this->fixtureRowsPrecondition($rowIssues),
            $this->acceptanceWiringPrecondition(),
            $this->uiHiddenPrecondition(),
            $this->providerApiBoundaryPrecondition(),
        ];

        $issues = $rowIssues;
        foreach ($preconditions as $precondition) {
            if (($precondition['status'] ?? '') === 'blocked') {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalExternalEvidenceManifestReviewReadinessService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'manifestReviewSignoffInputValid' => empty($issues),
            'manifestReviewSignoffImportApplied' => false,
            'manifestReviewAccepted' => false,
            'evidenceBundleAccepted' => false,
            'paypalEnablementAllowed' => false,
            'fieldSchema' => $fields,
            'fixtureRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($fields, $rows, $preconditions, $rowIssues),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function templateFields(): array
    {
        return [
            $this->field('manifest_review_id', 'safe_ref', 'Sanitized manifest review id, not a file path or local storage path.'),
            $this->field('test_host', 'https_url', 'HTTPS test host that produced the evidence.'),
            $this->field('manifest_ref', 'safe_ref', 'Sanitized external manifest reference.'),
            $this->field('artifact_hash_ref', 'sha256', 'SHA256 supplied by the external sanitization process.'),
            $this->field('reviewer_role', 'enum', 'One of business, security, or technical.'),
            $this->field('reviewer_ref', 'safe_ref', 'Sanitized reviewer role reference, not a personal secret.'),
            $this->field('decision', 'enum', 'One of approve, reject, or needs_rework.'),
            $this->field('reason', 'string', 'Non-sensitive approval, rejection, or rework reason.'),
            $this->field('reviewed_at', 'datetime', 'UTC ISO-8601 review timestamp.'),
            $this->field('cleanup_ref', 'safe_ref', 'Cleanup evidence reference for sandbox orders and attempts.'),
            $this->field('ticket_ref', 'safe_ref', 'External review ticket reference.'),
            $this->field('notes', 'string', 'Optional non-sensitive reviewer notes.'),
        ];
    }

    private function field(string $key, string $type, string $description): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'required' => true,
            'description' => $description,
        ];
    }

    private function fixtureRows(): array
    {
        $base = [
            'manifest_review_id' => 'manifest-review:PAYPAL-EXT-001',
            'test_host' => 'https://test.mongoyia.test',
            'manifest_ref' => 'manifest:paypal-external-TEST-001',
            'artifact_hash_ref' => '7f39c0130f32a57b5b1b4e5f0d2a7c88fd3f8a5ab9b5165d9d4f18a9c3b4d6e1',
            'cleanup_ref' => 'cleanup:paypal-external-TEST-001',
            'ticket_ref' => 'ticket:PAYPAL-EXT-001',
            'notes' => 'dry-run sanitized manifest review sample',
        ];

        return [
            array_merge($base, [
                'reviewer_role' => 'business',
                'reviewer_ref' => 'reviewer:business-owner',
                'decision' => 'approve',
                'reason' => 'Business review accepts the external manifest coverage.',
                'reviewed_at' => '2026-06-19T01:00:00Z',
            ]),
            array_merge($base, [
                'reviewer_role' => 'security',
                'reviewer_ref' => 'reviewer:security-owner',
                'decision' => 'approve',
                'reason' => 'Security review confirms sanitized references and redaction boundary.',
                'reviewed_at' => '2026-06-19T01:05:00Z',
            ]),
            array_merge($base, [
                'reviewer_role' => 'technical',
                'reviewer_ref' => 'reviewer:technical-owner',
                'decision' => 'approve',
                'reason' => 'Technical review confirms manifest references, hashes, and cleanup traceability.',
                'reviewed_at' => '2026-06-19T01:10:00Z',
            ]),
        ];
    }

    private function validateRows(array $rows, array $fields): array
    {
        $issues = [];
        $fieldKeys = array_map(static function ($field) {
            return (string)$field['key'];
        }, $fields);
        $roles = [];
        foreach ($rows as $index => $row) {
            foreach ($fieldKeys as $key) {
                if (!array_key_exists($key, $row) || trim((string)$row[$key]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $key;
                }
            }
            $role = (string)($row['reviewer_role'] ?? '');
            $roles[] = $role;
            if (!in_array($role, ['business', 'security', 'technical'], true)) {
                $issues[] = 'row_' . $index . '_invalid_reviewer_role';
            }
            if (!in_array((string)($row['decision'] ?? ''), ['approve', 'reject', 'needs_rework'], true)) {
                $issues[] = 'row_' . $index . '_invalid_decision';
            }
            if (strpos((string)($row['test_host'] ?? ''), 'https://') !== 0
                || strpos((string)($row['test_host'] ?? ''), 'localhost') !== false
                || strpos((string)($row['test_host'] ?? ''), '127.0.0.1') !== false) {
                $issues[] = 'row_' . $index . '_invalid_test_host';
            }
            if (!preg_match('/^[a-f0-9]{64}$/', (string)($row['artifact_hash_ref'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_artifact_hash_ref';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($row['reviewed_at'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_reviewed_at';
            }
            foreach (['manifest_review_id', 'manifest_ref', 'reviewer_ref', 'cleanup_ref', 'ticket_ref', 'notes', 'reason'] as $safeKey) {
                if ($this->containsForbiddenMarker((string)($row[$safeKey] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeKey;
                }
            }
        }

        foreach (['business', 'security', 'technical'] as $requiredRole) {
            if (!in_array($requiredRole, $roles, true)) {
                $issues[] = 'missing_required_role_' . $requiredRole;
            }
        }

        return array_values(array_unique($issues));
    }

    private function manifestReviewReadinessPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-manifest-review-readiness-*.md');
        $result = $this->readReportResult($path);
        $reviewReport = (new PaypalExternalEvidenceManifestReviewReadinessService($this->rootPath))->run();
        $ready = (bool)($reviewReport['manifestReviewReady'] ?? false);
        $started = (bool)($reviewReport['manifestReviewStarted'] ?? true);
        $accepted = (bool)($reviewReport['manifestReviewAccepted'] ?? true);
        $evidenceAccepted = (bool)($reviewReport['evidenceBundleAccepted'] ?? true);
        $paypalAllowed = (bool)($reviewReport['paypalEnablementAllowed'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && $ready && !$started && !$accepted && !$evidenceAccepted && !$paypalAllowed;

        return $this->precondition(
            'manifest_review_readiness_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal external evidence manifest review readiness report must PASS while review acceptance and PayPal enablement stay disabled.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS manifest review readiness report or disabled flags are not in the expected state.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-provider-contract.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_SIGNOFF_IMPORT_DRY_RUN_V1',
            'PayPal External Evidence Manifest Review Signoff Import Dry Run',
            'manifest_review_signoff_import_applied=0',
            'manifest_review_accepted=0',
            'paypal_enablement_allowed=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'manifest_review_signoff_import_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment provider docs must describe the external evidence manifest review signoff import dry-run.',
            empty($missing) ? 'External manifest review signoff import dry-run documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function templateContractPrecondition(array $fields): array
    {
        $keys = array_map(static function ($field) {
            return (string)$field['key'];
        }, $fields);
        $required = [
            'manifest_review_id',
            'test_host',
            'manifest_ref',
            'artifact_hash_ref',
            'reviewer_role',
            'reviewer_ref',
            'decision',
            'reason',
            'reviewed_at',
            'cleanup_ref',
            'ticket_ref',
            'notes',
        ];
        $missing = array_diff($required, $keys);
        $ok = count($fields) === 12 && empty($missing);

        return $this->precondition(
            'manifest_review_signoff_import_template_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'The manifest review signoff import dry-run template must include the required non-sensitive fields.',
            $ok ? 'Twelve required template fields are available.' : 'Missing fields: ' . implode(', ', $missing)
        );
    }

    private function fixtureRowsPrecondition(array $rowIssues): array
    {
        return $this->precondition(
            'manifest_review_signoff_import_fixture_rows',
            empty($rowIssues),
            empty($rowIssues) ? 'valid' : 'blocked',
            'The dry-run fixture rows must cover business, security, and technical review signoff records with safe references.',
            empty($rowIssues) ? 'Fixture rows are valid and cover all required reviewer roles.' : 'Issues: ' . implode(', ', $rowIssues)
        );
    }

    private function acceptanceWiringPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaAcceptanceController.php');
        $needles = [
            'skipPaymentProviderPaypalExternalEvidenceManifestReviewSignoffImportDryRun',
            'PayPal external evidence manifest review signoff import dry-run Phase 6 closure',
            'payment-provider-paypal-external-evidence-manifest-review-signoff-import-dry-run/run',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'acceptance_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Acceptance must include the external manifest review signoff import dry-run after review readiness and before live enablement.',
            empty($missing) ? 'Acceptance wiring markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function uiHiddenPrecondition(): array
    {
        $content = $this->readRelative('web/resources/mall/default/views/payment/index.php');
        $hidden = strpos($content, '/mall/payment/paypal') === false
            && strpos($content, 'Pay with PayPal') === false
            && strpos($content, 'PAYPAL_CLIENT_ID') === false;

        return $this->precondition(
            'paypal_ui_hidden',
            $hidden,
            $hidden ? 'hidden' : 'blocked',
            'PayPal UI controls must stay hidden while manifest review signoff import is only a dry-run.',
            $hidden ? 'Payment page has no PayPal button or client-id marker.' : 'Payment page exposes PayPal markers.'
        );
    }

    private function providerApiBoundaryPrecondition(): array
    {
        $content = $this->readRelative('frontend/modules/mall/controllers/PaymentController.php');
        $blockedNeedles = [
            'PAYPAL_CLIENT_SECRET',
            'PAYPAL_WEBHOOK_ID',
            'api-m.paypal.com',
            'api-m.sandbox.paypal.com',
        ];
        $found = [];
        foreach ($blockedNeedles as $needle) {
            if (strpos($content, $needle) !== false) {
                $found[] = $needle;
            }
        }

        return $this->precondition(
            'provider_api_boundary',
            empty($found),
            empty($found) ? 'disabled' : 'blocked',
            'PaymentController must not contain live PayPal API URLs or credential reads during manifest review signoff import dry-run.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $fields, array $rows, array $preconditions, array $rowIssues): array
    {
        $roles = [];
        foreach ($rows as $row) {
            $role = (string)($row['reviewer_role'] ?? '');
            if (in_array($role, ['business', 'security', 'technical'], true)) {
                $roles[$role] = true;
            }
        }
        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }
        $validRows = empty($rowIssues) ? count($rows) : 0;
        $valid = empty($rowIssues) && $satisfied === count($preconditions);

        return [
            'template_field_count' => count($fields),
            'fixture_row_count' => count($rows),
            'valid_fixture_row_count' => $validRows,
            'required_role_count' => 3,
            'covered_required_role_count' => count($roles),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => 3,
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'manifest_review_signoff_input_valid' => $valid ? 1 : 0,
            'manifest_review_signoff_import_applied' => 0,
            'manifest_review_accepted' => 0,
            'evidence_bundle_accepted' => 0,
            'paypal_enablement_allowed' => 0,
        ];
    }

    private function gateChecks(array $preconditions, array $rowIssues): array
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
            'key' => 'manifest_review_signoff_input_valid',
            'status' => empty($rowIssues) ? 'ready' : 'blocked',
            'details' => empty($rowIssues) ? 'The sanitized manifest review signoff template and fixture rows are valid.' : 'Row issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'manifest_review_signoff_import_application',
            'status' => 'disabled',
            'details' => 'No manifest review signoff row is persisted by this dry-run command.',
        ];
        $checks[] = [
            'key' => 'manifest_review_acceptance',
            'status' => 'pending',
            'details' => 'Manifest review acceptance remains pending until real external reviewer signatures are approved.',
        ];
        $checks[] = [
            'key' => 'evidence_bundle_acceptance',
            'status' => 'pending',
            'details' => 'The evidence bundle remains unaccepted until external evidence and review signoff are approved.',
        ];
        $checks[] = [
            'key' => 'paypal_enablement',
            'status' => 'disabled',
            'details' => 'This dry-run cannot allow PAYPAL_ENABLED=true and cannot expose PayPal UI.',
        ];
        $checks[] = [
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'This dry-run validates metadata only and does not read, copy, hash, import, or store evidence artifacts.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this dry-run.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, statistic, signoff, or review row is created or updated.',
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
            '# Mongoyia PayPal External Evidence Manifest Review Signoff Import Dry Run',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Manifest review signoff input valid: ' . (($report['manifestReviewSignoffInputValid'] ?? false) ? 'yes' : 'no'),
            '- Manifest review signoff import applied: ' . (($report['manifestReviewSignoffImportApplied'] ?? true) ? 'yes' : 'no'),
            '- Manifest review accepted: ' . (($report['manifestReviewAccepted'] ?? true) ? 'yes' : 'no'),
            '- Evidence bundle accepted: ' . (($report['evidenceBundleAccepted'] ?? true) ? 'yes' : 'no'),
            '- PayPal enablement allowed: ' . (($report['paypalEnablementAllowed'] ?? true) ? 'yes' : 'no'),
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
            '## Template Fields',
            '',
            '| Key | Type | Required | Description |',
            '|---|---|---:|---|',
        ]);
        foreach (($report['fieldSchema'] ?? []) as $field) {
            $lines[] = '| ' . $this->escapeCell((string)$field['key'])
                . ' | ' . $this->escapeCell((string)$field['type'])
                . ' | ' . (($field['required'] ?? false) ? '1' : '0')
                . ' | ' . $this->escapeCell((string)$field['description'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Dry-Run Rows',
            '',
            '| Review id | Role | Decision | Test host | Manifest ref | Cleanup ref |',
            '|---|---|---|---|---|---|',
        ]);
        foreach (($report['fixtureRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['manifest_review_id'])
                . ' | ' . $this->escapeCell((string)$row['reviewer_role'])
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['test_host'])
                . ' | ' . $this->escapeCell((string)$row['manifest_ref'])
                . ' | ' . $this->escapeCell((string)$row['cleanup_ref'])
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
            '- manifest_review_signoff_input_valid=1 means the sanitized signoff input template is valid, not that any review signoff was imported.',
            '- manifest_review_signoff_import_applied=0 remains intentional; this command never persists reviewer rows.',
            '- manifest_review_accepted=0 remains intentional until real external reviewer signatures are approved.',
            '- evidence_bundle_accepted=0 remains intentional until real sanitized evidence and reviewer signatures are externally approved.',
            '- paypal_enablement_allowed=0 remains intentional; this dry-run cannot turn on PayPal.',
            '- The dry-run does not read, copy, hash, import, or store evidence artifacts.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, statistic, signoff, or review row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['manifest_review_id,test_host,manifest_ref,artifact_hash_ref,reviewer_role,reviewer_ref,decision,reason,reviewed_at,cleanup_ref,ticket_ref,notes'];
        foreach (($report['fixtureRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['manifest_review_id']),
                $this->csvCell((string)$row['test_host']),
                $this->csvCell((string)$row['manifest_ref']),
                $this->csvCell((string)$row['artifact_hash_ref']),
                $this->csvCell((string)$row['reviewer_role']),
                $this->csvCell((string)$row['reviewer_ref']),
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['reason']),
                $this->csvCell((string)$row['reviewed_at']),
                $this->csvCell((string)$row['cleanup_ref']),
                $this->csvCell((string)$row['ticket_ref']),
                $this->csvCell((string)$row['notes']),
            ]);
        }

        return $lines;
    }

    private function containsForbiddenMarker(string $value): bool
    {
        $forbidden = [
            'Authorization',
            'Bearer ',
            'client_secret',
            'PAYPAL_CLIENT_SECRET',
            'PAYPAL_WEBHOOK_ID',
            'PRIVATE KEY',
            'BEGIN RSA',
            '.env',
            'C:\\',
            '/home/',
            '/var/',
            '\\\\',
            'mysql://',
            'redis://',
        ];
        foreach ($forbidden as $needle) {
            if (stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function envBool(string $key, bool $default): bool
    {
        if (function_exists('env')) {
            $value = env($key, $default ? 'true' : 'false');
        } else {
            $value = getenv($key);
            if ($value === false) {
                return $default;
            }
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
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

        return (string)$files[0];
    }

    private function readReportResult(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return 'MISSING';
        }
        $content = (string)file_get_contents($path);
        if (preg_match('/^- Result:\s*([A-Z]+)/m', $content, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }

    private function readRelative(string $relativePath): string
    {
        $path = $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            return '';
        }

        return (string)file_get_contents($path);
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        if (strpos($normalized, $root) === 0) {
            return substr($normalized, strlen($root));
        }

        return $normalized;
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
