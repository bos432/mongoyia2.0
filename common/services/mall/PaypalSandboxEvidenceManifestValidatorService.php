<?php

namespace common\services\mall;

class PaypalSandboxEvidenceManifestValidatorService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1';

    private const MODE = 'paypal_sandbox_evidence_manifest_validator_dry_run_no_import';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $rows = null, string $source = 'fixture'): array
    {
        $manifestRows = $rows === null ? $this->fixtureRows() : $rows;
        $fields = $this->manifestFields();
        $cases = $this->requiredCaseKeys();
        $validation = $this->validateManifestRows($manifestRows, $cases, $fields);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while evidence manifest validation is only a dry-run gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->signoffGatePrecondition(),
            $this->documentationPrecondition(),
            $this->caseContractPrecondition($cases),
            $this->schemaContractPrecondition($fields),
            $this->uiHiddenPrecondition(),
            $this->providerApiBoundaryPrecondition(),
        ];

        $issues = [];
        foreach ($preconditions as $precondition) {
            if (($precondition['status'] ?? '') === 'blocked') {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }
        foreach ($validation['issues'] as $issue) {
            $issues[] = $issue;
        }

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalSandboxEvidenceSignoffGateService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'source' => $source,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'validatorReady' => empty($issues),
            'manifestAccepted' => false,
            'manifestRows' => $validation['rows'],
            'manifestFields' => $fields,
            'preconditions' => $preconditions,
            'totals' => $this->totals($validation, $fields, $cases),
            'gateChecks' => $this->gateChecks($validation, $preconditions),
            'issues' => $issues,
        ];
    }

    public function fixtureRows(): array
    {
        $rows = [];
        foreach ($this->requiredCaseKeys() as $caseKey) {
            $rows[] = [
                'case_key' => $caseKey,
                'status' => 'pending_external',
                'artifact_ref' => 'pending://' . $caseKey,
                'artifact_sha256' => str_repeat('0', 64),
                'redaction_status' => 'not_applicable',
                'reviewer' => 'pending-test-server-reviewer',
                'reviewed_at' => '2026-06-19T00:00:00+08:00',
                'environment_host' => 'https://test.example.invalid',
                'notes' => 'Fixture manifest row; no artifact imported.',
            ];
        }

        return $rows;
    }

    private function requiredCaseKeys(): array
    {
        return [
            'sandbox_credentials_reference',
            'create_order_or_invoice',
            'approval_return_capture_success',
            'cancel_return',
            'webhook_completed',
            'duplicate_webhook_idempotency',
            'amount_mismatch_rejection',
            'invalid_signature_rejection',
            'expired_transmission_rejection',
            'payment_attempt_backend_visibility',
            'cleanup_evidence',
        ];
    }

    private function manifestFields(): array
    {
        return [
            ['field' => 'case_key', 'required' => true],
            ['field' => 'status', 'required' => true],
            ['field' => 'artifact_ref', 'required' => true],
            ['field' => 'artifact_sha256', 'required' => true],
            ['field' => 'redaction_status', 'required' => true],
            ['field' => 'reviewer', 'required' => true],
            ['field' => 'reviewed_at', 'required' => true],
            ['field' => 'environment_host', 'required' => true],
            ['field' => 'notes', 'required' => false],
        ];
    }

    public function validateManifestRows(array $rows, array $requiredCases = [], array $fields = []): array
    {
        $requiredCases = $requiredCases ?: $this->requiredCaseKeys();
        $fields = $fields ?: $this->manifestFields();
        $requiredFields = [];
        foreach ($fields as $field) {
            if ($field['required'] ?? false) {
                $requiredFields[] = (string)$field['field'];
            }
        }

        $seen = [];
        $validatedRows = [];
        $issues = [];
        $secretMarkerCount = 0;

        foreach (array_values($rows) as $index => $row) {
            $rowIssues = [];
            $normalized = [];
            foreach ($fields as $field) {
                $name = (string)$field['field'];
                $normalized[$name] = isset($row[$name]) ? trim((string)$row[$name]) : '';
            }

            foreach ($requiredFields as $field) {
                if ($normalized[$field] === '') {
                    $rowIssues[] = 'missing_' . $field;
                }
            }

            $caseKey = $normalized['case_key'];
            if ($caseKey !== '') {
                if (!in_array($caseKey, $requiredCases, true)) {
                    $rowIssues[] = 'unknown_case_key';
                }
                if (isset($seen[$caseKey])) {
                    $rowIssues[] = 'duplicate_case_key';
                }
                $seen[$caseKey] = true;
            }

            if (!in_array($normalized['status'], ['ready', 'rejected', 'pending_external'], true)) {
                $rowIssues[] = 'invalid_status';
            }
            if (!preg_match('/^[a-f0-9]{64}$/i', $normalized['artifact_sha256'])) {
                $rowIssues[] = 'invalid_artifact_sha256';
            }
            if (!in_array($normalized['redaction_status'], ['redacted', 'not_applicable', 'rejected'], true)) {
                $rowIssues[] = 'invalid_redaction_status';
            }
            if (!$this->isHttpsTestHost($normalized['environment_host'])) {
                $rowIssues[] = 'invalid_environment_host';
            }
            if ($this->artifactRefLooksUnsafe($normalized['artifact_ref'])) {
                $rowIssues[] = 'unsafe_artifact_ref';
            }
            if ($this->containsSecretMarker($normalized)) {
                $rowIssues[] = 'secret_marker_present';
                $secretMarkerCount++;
            }

            $validatedRows[] = [
                'row' => $index + 1,
                'case_key' => $caseKey,
                'status' => $normalized['status'],
                'artifact_ref' => $normalized['artifact_ref'],
                'artifact_sha256' => strtolower($normalized['artifact_sha256']),
                'redaction_status' => $normalized['redaction_status'],
                'reviewer' => $normalized['reviewer'],
                'reviewed_at' => $normalized['reviewed_at'],
                'environment_host' => $normalized['environment_host'],
                'validation_status' => empty($rowIssues) ? 'pass' : 'fail',
                'issues' => implode(';', $rowIssues),
            ];
        }

        $missingCases = array_values(array_diff($requiredCases, array_keys($seen)));
        foreach ($missingCases as $missingCase) {
            $issues[] = 'missing_case_key:' . $missingCase;
        }
        foreach ($validatedRows as $row) {
            if ($row['validation_status'] !== 'pass') {
                $issues[] = 'row_' . $row['row'] . ':' . $row['issues'];
            }
        }

        return [
            'rows' => $validatedRows,
            'issues' => $issues,
            'missingCases' => $missingCases,
            'duplicateCaseCount' => $this->countRowsWithIssue($validatedRows, 'duplicate_case_key'),
            'unknownCaseCount' => $this->countRowsWithIssue($validatedRows, 'unknown_case_key'),
            'invalidRowCount' => $this->countInvalidRows($validatedRows),
            'validRowCount' => count($validatedRows) - $this->countInvalidRows($validatedRows),
            'secretMarkerCount' => $secretMarkerCount,
        ];
    }

    private function signoffGatePrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-signoff-gate-*.md');
        $result = $this->readReportResult($path);
        $signoffReport = (new PaypalSandboxEvidenceSignoffGateService($this->rootPath))->run();
        $ready = (bool)($signoffReport['signoffReady'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && !$ready;

        return $this->precondition(
            'sandbox_evidence_signoff_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal sandbox evidence signoff gate must PASS, while signoff_ready remains 0.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS sandbox evidence signoff gate report or signoff_ready is not 0.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_MANIFEST_VALIDATOR_V1',
            'PayPal Sandbox Evidence Manifest Validator',
            'artifact_sha256',
            'redaction_status',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'manifest_validator_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment sandbox evidence documentation must describe the dry-run manifest validator.',
            empty($missing) ? 'Manifest validator documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function caseContractPrecondition(array $cases): array
    {
        $ok = count($cases) === 11
            && count(array_unique($cases)) === 11
            && in_array('duplicate_webhook_idempotency', $cases, true)
            && in_array('cleanup_evidence', $cases, true);

        return $this->precondition(
            'evidence_case_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'Manifest validator must cover the same eleven PayPal sandbox evidence cases as the signoff gate.',
            $ok ? 'Eleven required case keys are available to the validator.' : 'Required case key contract is incomplete.'
        );
    }

    private function schemaContractPrecondition(array $fields): array
    {
        $names = array_map(static function ($field) {
            return (string)$field['field'];
        }, $fields);
        $required = [
            'case_key',
            'status',
            'artifact_ref',
            'artifact_sha256',
            'redaction_status',
            'reviewer',
            'reviewed_at',
            'environment_host',
        ];
        $missing = array_values(array_diff($required, $names));

        return $this->precondition(
            'manifest_schema_contract',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Manifest validator must require case/status/artifact hash/redaction/reviewer/time/host fields.',
            empty($missing) ? 'Manifest schema contract is ready.' : 'Missing fields: ' . implode(', ', $missing)
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
            'PayPal UI controls must stay hidden while manifest validation is only a dry-run gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during manifest validation.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $validation, array $fields, array $cases): array
    {
        return [
            'manifest_row_count' => count($validation['rows']),
            'manifest_field_count' => count($fields),
            'required_case_count' => count($cases),
            'valid_row_count' => (int)$validation['validRowCount'],
            'invalid_row_count' => (int)$validation['invalidRowCount'],
            'missing_case_count' => count($validation['missingCases']),
            'duplicate_case_count' => (int)$validation['duplicateCaseCount'],
            'unknown_case_count' => (int)$validation['unknownCaseCount'],
            'secret_marker_count' => (int)$validation['secretMarkerCount'],
            'imported_artifact_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'validator_ready' => empty($validation['issues']) ? 1 : 0,
            'manifest_accepted' => 0,
            'signoff_ready' => 0,
        ];
    }

    private function gateChecks(array $validation, array $preconditions): array
    {
        $checks = [
            [
                'key' => 'manifest_validation',
                'status' => empty($validation['issues']) ? 'pass' : 'blocked',
                'details' => empty($validation['issues']) ? 'Manifest rows satisfy the dry-run validator contract.' : implode('; ', $validation['issues']),
            ],
        ];
        foreach ($preconditions as $precondition) {
            $checks[] = [
                'key' => (string)$precondition['key'],
                'status' => (string)$precondition['status'],
                'details' => (string)$precondition['evidence'],
            ];
        }
        $checks[] = [
            'key' => 'artifact_import',
            'status' => 'disabled',
            'details' => 'The validator checks manifest strings only and does not copy, hash, read, or import referenced artifacts.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this validator.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
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

    private function isHttpsTestHost(string $host): bool
    {
        $lower = strtolower($host);
        return strpos($lower, 'https://') === 0
            && strpos($lower, 'localhost') === false
            && strpos($lower, '127.0.0.1') === false
            && strpos($lower, 'example.com') === false;
    }

    private function artifactRefLooksUnsafe(string $ref): bool
    {
        return $ref === ''
            || strpos($ref, '..') !== false
            || strpos($ref, '\\') !== false
            || preg_match('/^[a-z]:/i', $ref)
            || strpos($ref, '/') === 0;
    }

    private function containsSecretMarker(array $row): bool
    {
        $text = strtolower(implode(' ', $row));
        $markers = [
            'client_secret',
            'paypal_client_secret',
            'authorization:',
            'bearer ',
            'private_key',
            'ssh-rsa',
            '.env',
            'set-cookie:',
        ];
        foreach ($markers as $marker) {
            if (strpos($text, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function countInvalidRows(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (($row['validation_status'] ?? '') !== 'pass') {
                $count++;
            }
        }

        return $count;
    }

    private function countRowsWithIssue(array $rows, string $issue): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (strpos((string)($row['issues'] ?? ''), $issue) !== false) {
                $count++;
            }
        }

        return $count;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Sandbox Evidence Manifest Validator',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Source: ' . (string)($report['source'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Validator ready: ' . (($report['validatorReady'] ?? false) ? 'yes' : 'no'),
            '- Manifest accepted: ' . (($report['manifestAccepted'] ?? true) ? 'yes' : 'no'),
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
            '| Row | Case key | Status | Artifact ref | Artifact SHA256 | Redaction | Host | Validation | Issues |',
            '|---:|---|---|---|---|---|---|---|---|',
        ]);

        foreach (($report['manifestRows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['row']
                . ' | ' . $this->escapeCell((string)$row['case_key'])
                . ' | ' . $this->escapeCell((string)$row['status'])
                . ' | ' . $this->escapeCell((string)$row['artifact_ref'])
                . ' | ' . $this->escapeCell((string)$row['artifact_sha256'])
                . ' | ' . $this->escapeCell((string)$row['redaction_status'])
                . ' | ' . $this->escapeCell((string)$row['environment_host'])
                . ' | ' . $this->escapeCell((string)$row['validation_status'])
                . ' | ' . $this->escapeCell((string)$row['issues'])
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
            '- Manifest accepted remains no; this validator is not business signoff.',
            '- Referenced artifacts are not read, copied, imported, hashed, or stored by this command.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
            '- Do not store PayPal secrets, auth headers, raw provider private payloads, SSH keys, or real `.env` files in the manifest.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['row,case_key,status,artifact_ref,artifact_sha256,redaction_status,reviewer,reviewed_at,environment_host,validation_status,issues'];
        foreach (($report['manifestRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                (int)$row['row'],
                $this->csvCell((string)$row['case_key']),
                $this->csvCell((string)$row['status']),
                $this->csvCell((string)$row['artifact_ref']),
                $this->csvCell((string)$row['artifact_sha256']),
                $this->csvCell((string)$row['redaction_status']),
                $this->csvCell((string)$row['reviewer']),
                $this->csvCell((string)$row['reviewed_at']),
                $this->csvCell((string)$row['environment_host']),
                $this->csvCell((string)$row['validation_status']),
                $this->csvCell((string)$row['issues']),
            ]);
        }

        return $lines;
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
