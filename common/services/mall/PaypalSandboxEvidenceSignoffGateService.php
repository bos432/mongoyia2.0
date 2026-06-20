<?php

namespace common\services\mall;

class PaypalSandboxEvidenceSignoffGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1';

    private const MODE = 'paypal_sandbox_evidence_signoff_readiness_no_import';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $cases = $this->evidenceCases();
        $manifestFields = $this->manifestFields();
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while sandbox evidence signoff is only a readiness gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->sandboxEvidenceGatePrecondition(),
            $this->liveAuditWriteGatePrecondition(),
            $this->caseContractPrecondition($cases),
            $this->manifestSchemaPrecondition($manifestFields),
            $this->redactionPolicyPrecondition(),
            $this->precondition(
                'signoff_ready',
                false,
                'pending_external',
                'Real PayPal sandbox evidence package and business/technical signoff are required before signoff_ready can become 1.',
                'signoff_ready=0'
            ),
            $this->uiHiddenPrecondition(),
            $this->providerApiBoundaryPrecondition(),
        ];

        $issues = [];
        foreach ($preconditions as $precondition) {
            if (($precondition['status'] ?? '') === 'blocked') {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalSandboxEvidenceGateService::GATE_VERSION,
                PaypalLiveAuditWriteImplementationGateService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'sandboxEvidenceReady' => false,
            'signoffReady' => false,
            'evidenceCases' => $cases,
            'manifestFields' => $manifestFields,
            'preconditions' => $preconditions,
            'totals' => $this->totals($cases, $manifestFields, $preconditions),
            'gateChecks' => $this->gateChecks($preconditions),
            'issues' => $issues,
        ];
    }

    private function evidenceCases(): array
    {
        return [
            $this->caseRow('sandbox_credentials_reference', 'credential_reference', 'ops', 'Non-sensitive sandbox app/webhook id reference with secrets redacted.'),
            $this->caseRow('create_order_or_invoice', 'request_response', 'payment', 'Create-order or invoice request/response evidence with auth and payload secrets redacted.'),
            $this->caseRow('approval_return_capture_success', 'browser_and_provider', 'payment', 'Buyer approval return and capture success on the HTTPS test domain.'),
            $this->caseRow('cancel_return', 'browser', 'payment', 'Buyer cancel return that leaves order/payment state unchanged.'),
            $this->caseRow('webhook_completed', 'webhook', 'payment', 'Completed PayPal webhook with official verification result redacted.'),
            $this->caseRow('duplicate_webhook_idempotency', 'webhook', 'payment', 'Duplicate webhook evidence showing second event ignored/idempotent.'),
            $this->caseRow('amount_mismatch_rejection', 'webhook', 'payment', 'Amount mismatch rejection evidence and audit trail.'),
            $this->caseRow('invalid_signature_rejection', 'webhook', 'security', 'Invalid or missing PayPal signature rejection evidence.'),
            $this->caseRow('expired_transmission_rejection', 'webhook', 'security', 'Expired PAYPAL-TRANSMISSION-TIME replay rejection evidence.'),
            $this->caseRow('payment_attempt_backend_visibility', 'backend', 'ops', 'Backend payment-attempt visibility for success, failed, and ignored PayPal events.'),
            $this->caseRow('cleanup_evidence', 'cleanup', 'ops', 'Generated PayPal sandbox orders, attempts, and temporary evidence can be cleaned up.'),
        ];
    }

    private function caseRow(string $key, string $artifactType, string $ownerRole, string $requiredEvidence): array
    {
        return [
            'key' => $key,
            'status' => 'pending_external',
            'artifact_type' => $artifactType,
            'owner_role' => $ownerRole,
            'required_evidence' => $requiredEvidence,
        ];
    }

    private function manifestFields(): array
    {
        return [
            ['field' => 'case_key', 'required' => true, 'description' => 'One of the required PayPal sandbox evidence case keys.'],
            ['field' => 'status', 'required' => true, 'description' => 'ready, rejected, or pending_external.'],
            ['field' => 'artifact_ref', 'required' => true, 'description' => 'Ticket id, sanitized file name, or evidence bundle reference.'],
            ['field' => 'artifact_sha256', 'required' => true, 'description' => 'SHA256 of sanitized evidence artifact, not a secret value.'],
            ['field' => 'redaction_status', 'required' => true, 'description' => 'Confirms secrets/auth headers/private payloads are redacted.'],
            ['field' => 'reviewer', 'required' => true, 'description' => 'Technical or business reviewer name/role.'],
            ['field' => 'reviewed_at', 'required' => true, 'description' => 'Review timestamp in test-server timezone.'],
            ['field' => 'environment_host', 'required' => true, 'description' => 'HTTPS test host where evidence was collected.'],
            ['field' => 'notes', 'required' => false, 'description' => 'Non-sensitive remarks or rejection reason.'],
        ];
    }

    private function sandboxEvidenceGatePrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-gate-*.md');
        $result = $this->readReportResult($path);
        $sandboxReport = (new PaypalSandboxEvidenceGateService($this->rootPath))->run();
        $ready = (bool)($sandboxReport['sandboxEvidenceReady'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && !$ready;

        return $this->precondition(
            'sandbox_evidence_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal sandbox evidence gate report must exist and PASS, while sandbox_evidence_ready remains 0.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS sandbox evidence gate report or sandbox_evidence_ready is not 0.'
        );
    }

    private function liveAuditWriteGatePrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-audit-write-implementation-gate-*.md');
        $result = $this->readReportResult($path);
        $ok = $path !== '' && $result === 'PASS';

        return $this->precondition(
            'live_audit_write_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal live audit write implementation gate must pass before sandbox evidence can be signed off.',
            $ok ? $this->relativePath($path) : 'Missing or non-PASS live audit write implementation gate report.'
        );
    }

    private function caseContractPrecondition(array $cases): array
    {
        $keys = array_map(static function ($case) {
            return (string)$case['key'];
        }, $cases);
        $ok = count($cases) === 11
            && count(array_unique($keys)) === 11
            && in_array('cleanup_evidence', $keys, true)
            && in_array('duplicate_webhook_idempotency', $keys, true);

        return $this->precondition(
            'evidence_case_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'Sandbox signoff must cover the eleven required PayPal evidence cases, including idempotency and cleanup.',
            $ok ? 'Eleven required PayPal evidence cases are enumerated.' : 'Evidence case contract is incomplete.'
        );
    }

    private function manifestSchemaPrecondition(array $fields): array
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
            'Sandbox evidence signoff manifest must include case, status, artifact reference/hash, redaction, reviewer, review time, and test host.',
            empty($missing) ? 'Manifest schema contract is ready.' : 'Missing fields: ' . implode(', ', $missing)
        );
    }

    private function redactionPolicyPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_SIGNOFF_GATE_V1',
            'Do not store PayPal secrets',
            'artifact_sha256',
            'redaction_status',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'redaction_policy_contract',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment sandbox evidence documentation must define signoff manifest fields and redaction boundaries.',
            empty($missing) ? 'Sandbox evidence documentation carries signoff and redaction markers.' : 'Missing markers: ' . implode(', ', $missing)
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
            'PayPal UI controls must stay hidden while sandbox evidence signoff is pending.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during signoff gating.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
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

    private function totals(array $cases, array $fields, array $preconditions): array
    {
        $totals = [
            'evidence_case_count' => count($cases),
            'manifest_field_count' => count($fields),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => 0,
            'pending_precondition_count' => 0,
            'imported_artifact_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'sandbox_evidence_ready' => 0,
            'signoff_ready' => 0,
        ];

        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $totals['satisfied_precondition_count']++;
            }
            if (($precondition['status'] ?? '') === 'pending_external') {
                $totals['pending_precondition_count']++;
            }
        }

        return $totals;
    }

    private function gateChecks(array $preconditions): array
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
            'key' => 'signoff_manifest_contract',
            'status' => 'ready',
            'details' => 'Future sandbox signoff must use the versioned non-sensitive evidence manifest contract.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this gate.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
        ];

        return $checks;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Sandbox Evidence Signoff Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Sandbox evidence ready: ' . (($report['sandboxEvidenceReady'] ?? true) ? 'yes' : 'no'),
            '- Signoff ready: ' . (($report['signoffReady'] ?? true) ? 'yes' : 'no'),
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
            '## Evidence Cases',
            '',
            '| Key | Status | Artifact type | Owner role | Required evidence |',
            '|---|---|---|---|---|',
        ]);

        foreach (($report['evidenceCases'] ?? []) as $case) {
            $lines[] = '| ' . $this->escapeCell((string)$case['key'])
                . ' | ' . $this->escapeCell((string)$case['status'])
                . ' | ' . $this->escapeCell((string)$case['artifact_type'])
                . ' | ' . $this->escapeCell((string)$case['owner_role'])
                . ' | ' . $this->escapeCell((string)$case['required_evidence'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Manifest Schema',
            '',
            '| Field | Required | Description |',
            '|---|---:|---|',
        ]);

        foreach (($report['manifestFields'] ?? []) as $field) {
            $lines[] = '| ' . $this->escapeCell((string)$field['field'])
                . ' | ' . (($field['required'] ?? false) ? '1' : '0')
                . ' | ' . $this->escapeCell((string)$field['description'])
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
            '- signoff_ready=0 is intentional until real HTTPS PayPal sandbox evidence and reviewer signoff are attached.',
            '- No evidence artifact is imported or copied by this gate.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
            '- Do not store PayPal secrets, auth headers, raw provider private payloads, SSH keys, or real `.env` files in signoff evidence.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,status,artifact_type,owner_role,required_evidence'];
        foreach (($report['evidenceCases'] ?? []) as $case) {
            $lines[] = implode(',', [
                $this->csvCell((string)$case['key']),
                $this->csvCell((string)$case['status']),
                $this->csvCell((string)$case['artifact_type']),
                $this->csvCell((string)$case['owner_role']),
                $this->csvCell((string)$case['required_evidence']),
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
