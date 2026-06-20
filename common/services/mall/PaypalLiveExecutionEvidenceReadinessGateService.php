<?php

namespace common\services\mall;

class PaypalLiveExecutionEvidenceReadinessGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1';

    private const MODE = 'paypal_live_execution_evidence_readiness_read_only_no_provider_no_artifact_access';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $rows = $this->evidenceRows();
        $rowIssues = $this->validateRows($rows);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while live execution evidence readiness is only a read-only gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->implementationEvidenceSignoffPrecondition(),
            $this->documentationPrecondition(),
            $this->evidenceChecklistPrecondition($rowIssues),
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
        $issues = array_values(array_unique($issues));
        $ready = empty($issues);

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalLiveProviderImplementationEvidenceSignoffGateService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'realSandboxLiveEvidenceReady' => $ready,
            'evidenceCollectionStarted' => false,
            'sandboxExecutionEvidenceAccepted' => false,
            'liveProductionEvidenceAccepted' => false,
            'paypalEnablementAllowed' => false,
            'evidenceRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $preconditions, $rowIssues, $ready),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $ready),
            'issues' => $issues,
        ];
    }

    private function evidenceRows(): array
    {
        return [
            $this->row('sandbox_checkout_success_ref', 'sandbox', 'business', 'Sanitized checkout create, approval return, capture success, and order paid evidence reference.'),
            $this->row('sandbox_checkout_cancel_ref', 'sandbox', 'business', 'Sanitized cancel-return evidence proving the order remains unpaid and no stock/accounting drift occurs.'),
            $this->row('sandbox_webhook_completed_ref', 'sandbox', 'technical', 'Sanitized completed webhook reference with verification decision and idempotent state transition.'),
            $this->row('sandbox_webhook_duplicate_ref', 'sandbox', 'technical', 'Sanitized duplicate webhook reference proving repeated events are ignored.'),
            $this->row('sandbox_webhook_rejection_ref', 'sandbox', 'security', 'Sanitized invalid signature, expired timestamp, and wrong webhook id rejection evidence reference.'),
            $this->row('sandbox_amount_currency_mismatch_ref', 'sandbox', 'technical', 'Sanitized amount and currency mismatch rejection evidence reference.'),
            $this->row('sandbox_payment_attempt_audit_ref', 'sandbox', 'technical', 'Sanitized backend payment-attempt audit visibility for success, failure, ignored, and rejected outcomes.'),
            $this->row('sandbox_cleanup_ref', 'sandbox', 'ops', 'Sanitized cleanup proof reference for generated PayPal orders, attempts, callbacks, and fixture evidence.'),
            $this->row('live_credential_holder_ref', 'live', 'security', 'Production credential holder and rotation ticket reference with all secrets redacted.'),
            $this->row('live_callback_domain_ref', 'live', 'technical', 'Production HTTPS callback, webhook endpoint, DNS, TLS, and allowlist readiness reference.'),
            $this->row('live_monitoring_reconciliation_ref', 'live', 'ops', 'Production monitoring, alert, reconciliation, and failed-webhook replay runbook reference.'),
            $this->row('live_cutover_rollback_signoff_ref', 'live', 'business', 'Business/security/technical cutover, rollback, and no-go signoff reference.'),
        ];
    }

    private function row(string $key, string $scope, string $ownerRole, string $requiredEvidence): array
    {
        $prefix = strtoupper(str_replace('_', '-', $key));

        return [
            'evidence_key' => $key,
            'evidence_scope' => $scope,
            'owner_role' => $ownerRole,
            'host_ref' => $scope === 'sandbox' ? 'https://test.mongoyia.test' : 'host-ref:PAYPAL-LIVE-PRODUCTION',
            'evidence_ref' => 'evidence-ref:' . $prefix,
            'ticket_ref' => 'ticket:' . $prefix,
            'cleanup_ref' => $scope === 'sandbox' ? 'cleanup:' . $prefix : 'rollback:' . $prefix,
            'required_evidence' => $requiredEvidence,
            'readiness_status' => 'ready_for_external_evidence',
            'redaction_status' => 'redacted_metadata_only',
            'artifact_access_allowed' => false,
            'provider_call_allowed' => false,
            'write_allowed' => false,
        ];
    }

    private function validateRows(array $rows): array
    {
        $issues = [];
        $seen = [];
        $required = $this->requiredEvidenceKeys();

        foreach ($rows as $index => $row) {
            foreach ([
                'evidence_key',
                'evidence_scope',
                'owner_role',
                'host_ref',
                'evidence_ref',
                'ticket_ref',
                'cleanup_ref',
                'required_evidence',
                'readiness_status',
                'redaction_status',
            ] as $key) {
                if (!array_key_exists($key, $row) || trim((string)$row[$key]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $key;
                }
            }
            foreach (['artifact_access_allowed', 'provider_call_allowed', 'write_allowed'] as $key) {
                if (!array_key_exists($key, $row) || (bool)$row[$key] !== false) {
                    $issues[] = 'row_' . $index . '_' . $key . '_must_be_false';
                }
            }

            $key = (string)($row['evidence_key'] ?? '');
            if (!in_array($key, $required, true)) {
                $issues[] = 'row_' . $index . '_unexpected_evidence_key';
            }
            if (isset($seen[$key])) {
                $issues[] = 'row_' . $index . '_duplicate_evidence_key';
            }
            $seen[$key] = true;

            if (!in_array((string)($row['evidence_scope'] ?? ''), ['sandbox', 'live'], true)) {
                $issues[] = 'row_' . $index . '_invalid_evidence_scope';
            }
            if (!in_array((string)($row['owner_role'] ?? ''), ['business', 'security', 'technical', 'ops'], true)) {
                $issues[] = 'row_' . $index . '_invalid_owner_role';
            }
            if ((string)($row['readiness_status'] ?? '') !== 'ready_for_external_evidence') {
                $issues[] = 'row_' . $index . '_invalid_readiness_status';
            }
            if ((string)($row['redaction_status'] ?? '') !== 'redacted_metadata_only') {
                $issues[] = 'row_' . $index . '_invalid_redaction_status';
            }
            if ((string)($row['evidence_scope'] ?? '') === 'sandbox'
                && (strpos((string)($row['host_ref'] ?? ''), 'https://') !== 0
                    || strpos((string)($row['host_ref'] ?? ''), 'localhost') !== false
                    || strpos((string)($row['host_ref'] ?? ''), '127.0.0.1') !== false)) {
                $issues[] = 'row_' . $index . '_invalid_sandbox_host_ref';
            }
            if ((string)($row['evidence_scope'] ?? '') === 'live'
                && strpos((string)($row['host_ref'] ?? ''), 'host-ref:') !== 0) {
                $issues[] = 'row_' . $index . '_invalid_live_host_ref';
            }
            foreach (['host_ref', 'evidence_ref', 'ticket_ref', 'cleanup_ref', 'required_evidence'] as $safeKey) {
                if ($this->containsForbiddenMarker((string)($row[$safeKey] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeKey;
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

    private function implementationEvidenceSignoffPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-*.md');
        $result = $this->readReportResult($path);
        $report = (new PaypalLiveProviderImplementationEvidenceSignoffGateService($this->rootPath))->run();
        $signoffReady = (bool)($report['implementationEvidenceSignoffReady'] ?? false);
        $accepted = (bool)($report['implementationEvidenceAccepted'] ?? true);
        $implementationReady = (bool)($report['liveProviderImplementationReady'] ?? true);
        $enablementAllowed = (bool)($report['paypalEnablementAllowed'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && $signoffReady && !$accepted && !$implementationReady && !$enablementAllowed;

        return $this->precondition(
            'live_provider_implementation_evidence_signoff_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal live provider implementation evidence signoff gate must PASS while evidence acceptance and PayPal enablement stay disabled.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS implementation evidence signoff gate report or disabled flags are not in the expected state.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-provider-contract.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_LIVE_EXECUTION_EVIDENCE_READINESS_GATE_V1',
            'PayPal Live Execution Evidence Readiness Gate',
            'real_sandbox_live_evidence_ready=1',
            'sandbox_execution_evidence_accepted=0',
            'live_production_evidence_accepted=0',
            'paypal_enablement_allowed=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'live_execution_evidence_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment provider docs must describe the PayPal live execution evidence readiness gate.',
            empty($missing) ? 'Live execution evidence readiness documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function evidenceChecklistPrecondition(array $rowIssues): array
    {
        return $this->precondition(
            'live_execution_evidence_checklist',
            empty($rowIssues),
            empty($rowIssues) ? 'ready' : 'blocked',
            'The gate must define sandbox execution and live production readiness evidence rows as safe redacted references only.',
            empty($rowIssues) ? 'All live execution evidence checklist rows are present and safe.' : 'Issues: ' . implode(', ', $rowIssues)
        );
    }

    private function acceptanceWiringPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaAcceptanceController.php');
        $needles = [
            'skipPaymentProviderPaypalLiveExecutionEvidenceReadinessGate',
            'PayPal live execution evidence readiness gate Phase 6 closure',
            'payment-provider-paypal-live-execution-evidence-readiness-gate/run',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'acceptance_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Acceptance must include the live execution evidence readiness gate before live verification enablement.',
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
            'PayPal UI controls must stay hidden while live execution evidence readiness is only a gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during evidence readiness gating.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues, bool $ready): array
    {
        $sandbox = 0;
        $live = 0;
        foreach ($rows as $row) {
            if (($row['evidence_scope'] ?? '') === 'sandbox') {
                $sandbox++;
            }
            if (($row['evidence_scope'] ?? '') === 'live') {
                $live++;
            }
        }
        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }

        return [
            'evidence_row_count' => count($rows),
            'sandbox_evidence_row_count' => $sandbox,
            'live_evidence_row_count' => $live,
            'valid_evidence_row_count' => empty($rowIssues) ? count($rows) : 0,
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => 5,
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'real_sandbox_live_evidence_ready' => $ready ? 1 : 0,
            'evidence_collection_started' => 0,
            'sandbox_execution_evidence_accepted' => 0,
            'live_production_evidence_accepted' => 0,
            'paypal_enablement_allowed' => 0,
        ];
    }

    private function gateChecks(array $preconditions, array $rowIssues, bool $ready): array
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
            'key' => 'real_sandbox_live_evidence_ready',
            'status' => $ready ? 'ready' : 'blocked',
            'details' => $ready ? 'Sandbox execution and live production evidence references are ready for external collection/review.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'evidence_collection_start',
            'status' => 'disabled',
            'details' => 'This gate does not start PayPal sandbox/live evidence collection or call providers.',
        ];
        $checks[] = [
            'key' => 'sandbox_execution_evidence_acceptance',
            'status' => 'pending',
            'details' => 'Sandbox execution evidence remains unaccepted until real sanitized artifacts and reviewer approvals are supplied.',
        ];
        $checks[] = [
            'key' => 'live_production_evidence_acceptance',
            'status' => 'pending',
            'details' => 'Live production readiness evidence remains unaccepted until credentials, callbacks, monitoring, rollback, and business signoff are externally approved.',
        ];
        $checks[] = [
            'key' => 'paypal_enablement',
            'status' => 'disabled',
            'details' => 'This gate cannot allow PAYPAL_ENABLED=true and cannot expose PayPal UI.',
        ];
        $checks[] = [
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'This gate validates references only and does not read, copy, hash, import, or store evidence artifacts.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this gate.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, statistic, evidence, or signoff row is created or updated.',
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
            '# Mongoyia PayPal Live Execution Evidence Readiness Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Real sandbox/live evidence ready: ' . (($report['realSandboxLiveEvidenceReady'] ?? false) ? 'yes' : 'no'),
            '- Evidence collection started: ' . (($report['evidenceCollectionStarted'] ?? true) ? 'yes' : 'no'),
            '- Sandbox execution evidence accepted: ' . (($report['sandboxExecutionEvidenceAccepted'] ?? true) ? 'yes' : 'no'),
            '- Live production evidence accepted: ' . (($report['liveProductionEvidenceAccepted'] ?? true) ? 'yes' : 'no'),
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
            '## Evidence Checklist',
            '',
            '| Evidence | Scope | Owner | Status | Host/ref | Evidence ref | Cleanup/ref | Required evidence |',
            '|---|---|---|---|---|---|---|---|',
        ]);
        foreach (($report['evidenceRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['evidence_key'])
                . ' | ' . $this->escapeCell((string)$row['evidence_scope'])
                . ' | ' . $this->escapeCell((string)$row['owner_role'])
                . ' | ' . $this->escapeCell((string)$row['readiness_status'])
                . ' | ' . $this->escapeCell((string)$row['host_ref'])
                . ' | ' . $this->escapeCell((string)$row['evidence_ref'])
                . ' | ' . $this->escapeCell((string)$row['cleanup_ref'])
                . ' | ' . $this->escapeCell((string)$row['required_evidence'])
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
            '- real_sandbox_live_evidence_ready=1 means the redacted evidence checklist is ready, not that real PayPal sandbox/live evidence is accepted.',
            '- evidence_collection_started=0 remains intentional; this gate does not start evidence collection.',
            '- sandbox_execution_evidence_accepted=0 and live_production_evidence_accepted=0 remain intentional until external evidence and approvals are supplied.',
            '- paypal_enablement_allowed=0 remains intentional; this gate cannot turn on PayPal.',
            '- The gate does not read, copy, hash, import, or store evidence artifacts.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, statistic, evidence, or signoff row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['evidence_key,evidence_scope,owner_role,host_ref,evidence_ref,ticket_ref,cleanup_ref,readiness_status,redaction_status,artifact_access_allowed,provider_call_allowed,write_allowed,required_evidence'];
        foreach (($report['evidenceRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['evidence_key']),
                $this->csvCell((string)$row['evidence_scope']),
                $this->csvCell((string)$row['owner_role']),
                $this->csvCell((string)$row['host_ref']),
                $this->csvCell((string)$row['evidence_ref']),
                $this->csvCell((string)$row['ticket_ref']),
                $this->csvCell((string)$row['cleanup_ref']),
                $this->csvCell((string)$row['readiness_status']),
                $this->csvCell((string)$row['redaction_status']),
                ((bool)$row['artifact_access_allowed']) ? '1' : '0',
                ((bool)$row['provider_call_allowed']) ? '1' : '0',
                ((bool)$row['write_allowed']) ? '1' : '0',
                $this->csvCell((string)$row['required_evidence']),
            ]);
        }

        return $lines;
    }

    private function requiredEvidenceKeys(): array
    {
        return [
            'sandbox_checkout_success_ref',
            'sandbox_checkout_cancel_ref',
            'sandbox_webhook_completed_ref',
            'sandbox_webhook_duplicate_ref',
            'sandbox_webhook_rejection_ref',
            'sandbox_amount_currency_mismatch_ref',
            'sandbox_payment_attempt_audit_ref',
            'sandbox_cleanup_ref',
            'live_credential_holder_ref',
            'live_callback_domain_ref',
            'live_monitoring_reconciliation_ref',
            'live_cutover_rollback_signoff_ref',
        ];
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
