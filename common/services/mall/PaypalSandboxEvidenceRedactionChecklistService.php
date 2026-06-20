<?php

namespace common\services\mall;

class PaypalSandboxEvidenceRedactionChecklistService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1';

    private const MODE = 'paypal_sandbox_evidence_redaction_checklist_dry_run_no_artifact_access';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $rows = null, string $source = 'fixture'): array
    {
        $checklistRows = $rows === null ? $this->fixtureRows() : $rows;
        $fields = $this->checklistFields();
        $controls = $this->requiredControls();
        $requiredKeys = array_keys($controls);
        $validation = $this->validateChecklistRows($checklistRows, $requiredKeys, $fields);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while redaction checklist validation is only a dry-run gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->manifestValidatorPrecondition(),
            $this->documentationPrecondition(),
            $this->controlContractPrecondition($controls),
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
                PaypalSandboxEvidenceManifestValidatorService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'source' => $source,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'checklistReady' => empty($issues),
            'evidenceBundleAccepted' => false,
            'checklistRows' => $validation['rows'],
            'checklistFields' => $fields,
            'preconditions' => $preconditions,
            'totals' => $this->totals($validation, $fields, $requiredKeys),
            'gateChecks' => $this->gateChecks($validation, $preconditions),
            'issues' => $issues,
        ];
    }

    public function fixtureRows(): array
    {
        $rows = [];
        foreach ($this->requiredControls() as $key => $control) {
            $rows[] = [
                'control_key' => $key,
                'status' => 'ready',
                'redaction_scope' => $control['scope'],
                'required_redaction' => $control['required_redaction'],
                'allowed_evidence' => $control['allowed_evidence'],
                'forbidden_markers' => $control['forbidden_markers'],
                'reviewer' => 'pending-test-server-security-reviewer',
                'reviewed_at' => '2026-06-19T00:00:00+08:00',
                'notes' => 'Fixture policy row; no artifact opened.',
            ];
        }

        return $rows;
    }

    private function requiredControls(): array
    {
        return [
            'paypal_client_secret' => [
                'scope' => 'credentials',
                'required_redaction' => 'Mask or remove every PayPal client secret value before evidence is referenced.',
                'allowed_evidence' => 'Sandbox app name, environment, and non-sensitive client id reference.',
                'forbidden_markers' => 'client secret; raw secret value; .env secret',
            ],
            'oauth_access_token' => [
                'scope' => 'credentials',
                'required_redaction' => 'Redact OAuth access tokens and token response bodies before review.',
                'allowed_evidence' => 'Token request status, expiry metadata, and redacted response hash.',
                'forbidden_markers' => 'bearer token; access_token value; refresh token',
            ],
            'authorization_header' => [
                'scope' => 'headers',
                'required_redaction' => 'Remove or mask Authorization header values while preserving the header-name checklist.',
                'allowed_evidence' => 'Header names and verification result only.',
                'forbidden_markers' => 'Authorization value; Basic credential; Bearer credential',
            ],
            'webhook_signature_headers' => [
                'scope' => 'headers',
                'required_redaction' => 'Mask PayPal transmission signature values and keep only verification outcome metadata.',
                'allowed_evidence' => 'Transmission id, algorithm, timestamp, cert-url host, and redacted signature marker.',
                'forbidden_markers' => 'PAYPAL-TRANSMISSION-SIG raw value; private signature material',
            ],
            'raw_webhook_payload_pii' => [
                'scope' => 'payload',
                'required_redaction' => 'Redact buyer, payer, shipping, and account payload fields before artifact hashing.',
                'allowed_evidence' => 'Event type, resource id suffix, amount, currency, and redacted payload hash.',
                'forbidden_markers' => 'email; phone; shipping address; payer full name',
            ],
            'buyer_personal_data' => [
                'scope' => 'privacy',
                'required_redaction' => 'Mask buyer PII in screenshots, backend pages, logs, and exported reports.',
                'allowed_evidence' => 'Masked user id, masked email, order id, amount, status, and timestamp.',
                'forbidden_markers' => 'full email; phone number; real name; address',
            ],
            'merchant_account_identifiers' => [
                'scope' => 'privacy',
                'required_redaction' => 'Truncate merchant account identifiers unless business approves the reference.',
                'allowed_evidence' => 'Store id, masked merchant id, sandbox label, and reviewer note.',
                'forbidden_markers' => 'full merchant account id; payout email; settlement account',
            ],
            'cookie_session_tokens' => [
                'scope' => 'browser',
                'required_redaction' => 'Remove cookies, sessions, CSRF tokens, and local-storage auth values from browser evidence.',
                'allowed_evidence' => 'URL path, HTTP status, visible page marker, and masked user identity.',
                'forbidden_markers' => 'Set-Cookie; PHPSESSID; csrf token; session token',
            ],
            'server_env_files' => [
                'scope' => 'server',
                'required_redaction' => 'Do not attach raw .env files; record only the redacted env-key checklist.',
                'allowed_evidence' => 'Presence of required key names with placeholder values only.',
                'forbidden_markers' => 'raw .env; password value; callback secret; private key',
            ],
            'private_keys_certificates' => [
                'scope' => 'server',
                'required_redaction' => 'Remove private keys and certificate private material from all evidence artifacts.',
                'allowed_evidence' => 'Certificate host, public fingerprint, and non-sensitive expiry metadata.',
                'forbidden_markers' => 'private key; ssh-rsa private material; PEM secret block',
            ],
            'database_redis_credentials' => [
                'scope' => 'server',
                'required_redaction' => 'Mask database, Redis, and backup credentials in logs and restore evidence.',
                'allowed_evidence' => 'Connection alias, host class, port, and successful readiness marker.',
                'forbidden_markers' => 'database password; redis password; DSN with credentials',
            ],
            'internal_network_paths' => [
                'scope' => 'infrastructure',
                'required_redaction' => 'Remove internal IPs, absolute server paths, and private hostnames unless explicitly approved.',
                'allowed_evidence' => 'Public HTTPS test host, WSS path, route path, and sanitized artifact reference.',
                'forbidden_markers' => 'internal IP; absolute local path; SSH host; private hostname',
            ],
        ];
    }

    private function checklistFields(): array
    {
        return [
            ['field' => 'control_key', 'required' => true],
            ['field' => 'status', 'required' => true],
            ['field' => 'redaction_scope', 'required' => true],
            ['field' => 'required_redaction', 'required' => true],
            ['field' => 'allowed_evidence', 'required' => true],
            ['field' => 'forbidden_markers', 'required' => true],
            ['field' => 'reviewer', 'required' => true],
            ['field' => 'reviewed_at', 'required' => true],
            ['field' => 'notes', 'required' => false],
        ];
    }

    public function validateChecklistRows(array $rows, array $requiredKeys = [], array $fields = []): array
    {
        $requiredKeys = $requiredKeys ?: array_keys($this->requiredControls());
        $fields = $fields ?: $this->checklistFields();
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

            $controlKey = $normalized['control_key'];
            if ($controlKey !== '') {
                if (!in_array($controlKey, $requiredKeys, true)) {
                    $rowIssues[] = 'unknown_control_key';
                }
                if (isset($seen[$controlKey])) {
                    $rowIssues[] = 'duplicate_control_key';
                }
                $seen[$controlKey] = true;
            }

            if (!in_array($normalized['status'], ['ready', 'rejected', 'pending_external'], true)) {
                $rowIssues[] = 'invalid_status';
            }
            if (!$this->redactionRuleLooksReady($normalized['required_redaction'], $normalized['allowed_evidence'])) {
                $rowIssues[] = 'weak_redaction_rule';
            }
            if (!$this->forbiddenMarkersLookReady($normalized['forbidden_markers'])) {
                $rowIssues[] = 'weak_forbidden_markers';
            }
            if (!$this->reviewTimestampLooksReady($normalized['reviewed_at'])) {
                $rowIssues[] = 'invalid_reviewed_at';
            }
            if ($this->containsRawSecretMarker($normalized)) {
                $rowIssues[] = 'raw_secret_marker_present';
                $secretMarkerCount++;
            }

            $validatedRows[] = [
                'row' => $index + 1,
                'control_key' => $controlKey,
                'status' => $normalized['status'],
                'redaction_scope' => $normalized['redaction_scope'],
                'required_redaction' => $normalized['required_redaction'],
                'allowed_evidence' => $normalized['allowed_evidence'],
                'forbidden_markers' => $normalized['forbidden_markers'],
                'reviewer' => $normalized['reviewer'],
                'reviewed_at' => $normalized['reviewed_at'],
                'validation_status' => empty($rowIssues) ? 'pass' : 'fail',
                'issues' => implode(';', $rowIssues),
            ];
        }

        $missingControls = array_values(array_diff($requiredKeys, array_keys($seen)));
        foreach ($missingControls as $missingControl) {
            $issues[] = 'missing_control_key:' . $missingControl;
        }
        foreach ($validatedRows as $row) {
            if ($row['validation_status'] !== 'pass') {
                $issues[] = 'row_' . $row['row'] . ':' . $row['issues'];
            }
        }

        return [
            'rows' => $validatedRows,
            'issues' => $issues,
            'missingControls' => $missingControls,
            'duplicateControlCount' => $this->countRowsWithIssue($validatedRows, 'duplicate_control_key'),
            'unknownControlCount' => $this->countRowsWithIssue($validatedRows, 'unknown_control_key'),
            'invalidRowCount' => $this->countInvalidRows($validatedRows),
            'validRowCount' => count($validatedRows) - $this->countInvalidRows($validatedRows),
            'readyControlCount' => $this->countRowsWithStatus($validatedRows, 'ready'),
            'secretMarkerCount' => $secretMarkerCount,
        ];
    }

    private function manifestValidatorPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-manifest-validator-*.md');
        $result = $this->readReportResult($path);
        $manifestReport = (new PaypalSandboxEvidenceManifestValidatorService($this->rootPath))->run();
        $accepted = (bool)($manifestReport['manifestAccepted'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && !$accepted;

        return $this->precondition(
            'manifest_validator_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal sandbox evidence manifest validator must PASS, while manifest_accepted remains 0.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS manifest validator report or manifest_accepted is not 0.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REDACTION_CHECKLIST_V1',
            'PayPal Sandbox Evidence Redaction Checklist',
            'evidence_bundle_accepted=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'redaction_checklist_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment sandbox evidence documentation must describe the read-only redaction checklist.',
            empty($missing) ? 'Redaction checklist documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function controlContractPrecondition(array $controls): array
    {
        $keys = array_keys($controls);
        $ok = count($keys) === 12
            && count(array_unique($keys)) === 12
            && in_array('authorization_header', $keys, true)
            && in_array('server_env_files', $keys, true)
            && in_array('private_keys_certificates', $keys, true);

        return $this->precondition(
            'redaction_control_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'Redaction checklist must cover credentials, headers, payload PII, browser session data, server secrets, and infrastructure references.',
            $ok ? 'Twelve required redaction controls are available.' : 'Required redaction control contract is incomplete.'
        );
    }

    private function schemaContractPrecondition(array $fields): array
    {
        $names = array_map(static function ($field) {
            return (string)$field['field'];
        }, $fields);
        $required = [
            'control_key',
            'status',
            'redaction_scope',
            'required_redaction',
            'allowed_evidence',
            'forbidden_markers',
            'reviewer',
            'reviewed_at',
        ];
        $missing = array_values(array_diff($required, $names));

        return $this->precondition(
            'checklist_schema_contract',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Redaction checklist must require control/status/scope/rules/allowed/forbidden/reviewer/time fields.',
            empty($missing) ? 'Checklist schema contract is ready.' : 'Missing fields: ' . implode(', ', $missing)
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
            'PayPal UI controls must stay hidden while evidence redaction is only a dry-run gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during redaction checklist gating.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $validation, array $fields, array $requiredKeys): array
    {
        return [
            'checklist_control_count' => count($validation['rows']),
            'checklist_field_count' => count($fields),
            'required_control_count' => count($requiredKeys),
            'ready_control_count' => (int)$validation['readyControlCount'],
            'valid_row_count' => (int)$validation['validRowCount'],
            'invalid_row_count' => (int)$validation['invalidRowCount'],
            'missing_control_count' => count($validation['missingControls']),
            'duplicate_control_count' => (int)$validation['duplicateControlCount'],
            'unknown_control_count' => (int)$validation['unknownControlCount'],
            'secret_marker_count' => (int)$validation['secretMarkerCount'],
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'checklist_ready' => empty($validation['issues']) ? 1 : 0,
            'evidence_bundle_accepted' => 0,
        ];
    }

    private function gateChecks(array $validation, array $preconditions): array
    {
        $checks = [
            [
                'key' => 'checklist_validation',
                'status' => empty($validation['issues']) ? 'pass' : 'blocked',
                'details' => empty($validation['issues']) ? 'Redaction checklist rows satisfy the dry-run contract.' : implode('; ', $validation['issues']),
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
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'The checklist validates policy rows only and does not read, copy, hash, import, or store evidence artifacts.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this checklist gate.',
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

    private function redactionRuleLooksReady(string $requiredRedaction, string $allowedEvidence): bool
    {
        $text = strtolower($requiredRedaction . ' ' . $allowedEvidence);
        foreach (['redact', 'mask', 'remove', 'truncate', 'hash'] as $needle) {
            if (strpos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function forbiddenMarkersLookReady(string $markers): bool
    {
        $lower = strtolower($markers);
        foreach (['secret', 'token', 'authorization', 'cookie', 'private', 'env', 'password', 'credential', 'email', 'phone', 'address', 'account'] as $needle) {
            if (strpos($lower, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function reviewTimestampLooksReady(string $value): bool
    {
        return $value !== '' && strtotime($value) !== false;
    }

    private function containsRawSecretMarker(array $row): bool
    {
        $text = implode("\n", $row);
        $patterns = [
            '/Authorization:\s*(Basic|Bearer)\s+[A-Za-z0-9+\/._~=-]{8,}/i',
            '/Bearer\s+[A-Za-z0-9+\/._~=-]{20,}/i',
            '/client_secret\s*[:=]\s*["\']?[A-Za-z0-9._~=-]{8,}/i',
            '/access_token\s*[:=]\s*["\']?[A-Za-z0-9._~=-]{12,}/i',
            '/password\s*[:=]\s*["\']?[^,\s]{8,}/i',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            '/set-cookie:\s*[^=]+=.+/i',
            '/AKIA[0-9A-Z]{16}/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
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

    private function countRowsWithStatus(array $rows, string $status): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if ((string)($row['status'] ?? '') === $status) {
                $count++;
            }
        }

        return $count;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Sandbox Evidence Redaction Checklist',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Source: ' . (string)($report['source'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Checklist ready: ' . (($report['checklistReady'] ?? false) ? 'yes' : 'no'),
            '- Evidence bundle accepted: ' . (($report['evidenceBundleAccepted'] ?? true) ? 'yes' : 'no'),
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
            '## Checklist Controls',
            '',
            '| Row | Control key | Status | Scope | Required redaction | Allowed evidence | Forbidden markers | Validation | Issues |',
            '|---:|---|---|---|---|---|---|---|---|',
        ]);

        foreach (($report['checklistRows'] ?? []) as $row) {
            $lines[] = '| ' . (int)$row['row']
                . ' | ' . $this->escapeCell((string)$row['control_key'])
                . ' | ' . $this->escapeCell((string)$row['status'])
                . ' | ' . $this->escapeCell((string)$row['redaction_scope'])
                . ' | ' . $this->escapeCell((string)$row['required_redaction'])
                . ' | ' . $this->escapeCell((string)$row['allowed_evidence'])
                . ' | ' . $this->escapeCell((string)$row['forbidden_markers'])
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
            '- evidence_bundle_accepted=0 is intentional; this checklist is not business signoff or PayPal enablement.',
            '- The checklist does not read, copy, hash, import, or store evidence artifacts.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
            '- Do not store PayPal secrets, OAuth tokens, auth headers, raw provider private payloads, cookies, private keys, SSH keys, or real `.env` files in evidence bundles.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['row,control_key,status,redaction_scope,required_redaction,allowed_evidence,forbidden_markers,reviewer,reviewed_at,validation_status,issues'];
        foreach (($report['checklistRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                (int)$row['row'],
                $this->csvCell((string)$row['control_key']),
                $this->csvCell((string)$row['status']),
                $this->csvCell((string)$row['redaction_scope']),
                $this->csvCell((string)$row['required_redaction']),
                $this->csvCell((string)$row['allowed_evidence']),
                $this->csvCell((string)$row['forbidden_markers']),
                $this->csvCell((string)$row['reviewer']),
                $this->csvCell((string)$row['reviewed_at']),
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
