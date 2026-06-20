<?php

namespace common\services\mall;

class PaypalLiveProviderImplementationEvidenceDryRunService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1';

    private const MODE = 'paypal_live_provider_implementation_evidence_dry_run_no_runtime_enablement_no_persistence';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $rows = $this->implementationRows();
        $rowIssues = $this->validateRows($rows);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while live provider implementation evidence is only a dry-run.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->liveAuditWriteImplementationGatePrecondition(),
            $this->externalManifestReviewResultApplyGatePrecondition(),
            $this->documentationPrecondition(),
            $this->implementationPlanPrecondition($rowIssues),
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
                PaypalLiveAuditWriteImplementationGateService::GATE_VERSION,
                PaypalExternalEvidenceManifestReviewResultApplyGateService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'liveProviderImplementationEvidenceValid' => empty($issues),
            'liveProviderImplementationEvidenceApplied' => false,
            'liveProviderImplementationReady' => false,
            'paypalEnablementAllowed' => false,
            'implementationRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $preconditions, $rowIssues),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function implementationRows(): array
    {
        return [
            $this->row('feature_flag_boundary', 'PAYPAL_ENABLED remains false until a reviewed runtime rollout flips it.', 'PAYPAL_ENABLED=false', 'runtime'),
            $this->row('create_order_request_contract', 'Future create-order handler must build official PayPal order requests from paid parent order totals.', 'provider=paypal create request dry-run evidence', 'provider'),
            $this->row('approval_return_capture_contract', 'Future return handler must capture approved PayPal orders and map gateway transaction ids.', 'capture result -> payment attempt success plan', 'provider'),
            $this->row('cancel_return_contract', 'Future cancel handler must keep orders unpaid and record an ignored/cancelled audit event.', 'cancel result -> payment attempt ignored plan', 'provider'),
            $this->row('official_webhook_verification_contract', 'Future webhook handler must use official verification evidence or an approved signed test shim.', 'verified webhook -> state transition plan', 'security'),
            $this->row('payment_attempt_write_contract', 'Future writes must use provider=paypal audit rows with business keys and redacted payload hashes.', 'mall_payment_attempt provider=paypal write plan', 'audit'),
            $this->row('amount_currency_validation_contract', 'Future capture/webhook processing must reject amount or currency mismatches.', 'amount/currency mismatch rejection plan', 'order'),
            $this->row('duplicate_event_idempotency_contract', 'Future webhook and return flows must treat duplicate gateway events as ignored.', 'duplicate gateway event idempotency plan', 'order'),
            $this->row('order_state_inventory_contract', 'Future success handling must preserve existing parent/child order and stock semantics.', 'parent/child order state and stock guard plan', 'order'),
            $this->row('ui_rollout_contract', 'Future payment UI must remain hidden until provider evidence, sandbox signoff, and rollback are accepted.', 'PayPal button rollout evidence plan', 'ui'),
            $this->row('cleanup_rollback_contract', 'Future fixtures must clean PayPal orders, attempts, callbacks, and generated evidence safely.', 'PAYPALRT cleanup and rollback plan', 'cleanup'),
            $this->row('acceptance_regression_contract', 'Future acceptance must cover create, return, cancel, webhook, duplicate, amount mismatch, and cleanup.', 'PayPal provider acceptance regression plan', 'acceptance'),
        ];
    }

    private function row(string $key, string $requirement, string $evidence, string $area): array
    {
        return [
            'key' => $key,
            'area' => $area,
            'requirement' => $requirement,
            'evidence_ref' => $evidence,
            'status' => 'planned',
            'runtime_enabled' => false,
            'write_enabled' => false,
            'provider_call_enabled' => false,
        ];
    }

    private function validateRows(array $rows): array
    {
        $issues = [];
        $required = [
            'feature_flag_boundary',
            'create_order_request_contract',
            'approval_return_capture_contract',
            'cancel_return_contract',
            'official_webhook_verification_contract',
            'payment_attempt_write_contract',
            'amount_currency_validation_contract',
            'duplicate_event_idempotency_contract',
            'order_state_inventory_contract',
            'ui_rollout_contract',
            'cleanup_rollback_contract',
            'acceptance_regression_contract',
        ];
        $seen = [];

        foreach ($rows as $index => $row) {
            foreach (['key', 'area', 'requirement', 'evidence_ref', 'status'] as $key) {
                if (!array_key_exists($key, $row) || trim((string)$row[$key]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $key;
                }
            }
            $rowKey = (string)($row['key'] ?? '');
            $seen[$rowKey] = true;
            if (!in_array($rowKey, $required, true)) {
                $issues[] = 'row_' . $index . '_unexpected_key';
            }
            if ((string)($row['status'] ?? '') !== 'planned') {
                $issues[] = 'row_' . $index . '_status_not_planned';
            }
            foreach (['runtime_enabled', 'write_enabled', 'provider_call_enabled'] as $flag) {
                if (!array_key_exists($flag, $row) || (bool)$row[$flag] !== false) {
                    $issues[] = 'row_' . $index . '_' . $flag . '_must_be_false';
                }
            }
            foreach (['key', 'area', 'requirement', 'evidence_ref'] as $safeKey) {
                if ($this->containsForbiddenMarker((string)($row[$safeKey] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeKey;
                }
            }
        }

        foreach ($required as $key) {
            if (!isset($seen[$key])) {
                $issues[] = 'missing_required_plan_' . $key;
            }
        }

        return array_values(array_unique($issues));
    }

    private function liveAuditWriteImplementationGatePrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-audit-write-implementation-gate-*.md');
        $result = $this->readReportResult($path);
        $ok = $path !== '' && $result === 'PASS';

        return $this->precondition(
            'live_audit_write_implementation_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal live audit write implementation gate must PASS before live provider implementation evidence can be planned.',
            $ok ? $this->relativePath($path) : 'Missing or non-PASS PayPal live audit write implementation gate report.'
        );
    }

    private function externalManifestReviewResultApplyGatePrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-manifest-review-result-apply-gate-*.md');
        $result = $this->readReportResult($path);
        $ok = $path !== '' && $result === 'PASS';

        return $this->precondition(
            'external_manifest_review_result_apply_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal external manifest review-result apply gate must PASS before live provider implementation evidence can be planned.',
            $ok ? $this->relativePath($path) : 'Missing or non-PASS external manifest review-result apply gate report.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-provider-contract.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_DRY_RUN_V1',
            'PayPal Live Provider Implementation Evidence Dry Run',
            'live_provider_implementation_evidence_applied=0',
            'live_provider_implementation_ready=0',
            'paypal_enablement_allowed=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'live_provider_implementation_evidence_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment provider docs must describe the live provider implementation evidence dry-run.',
            empty($missing) ? 'Live provider implementation evidence documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function implementationPlanPrecondition(array $rowIssues): array
    {
        return $this->precondition(
            'live_provider_implementation_plan',
            empty($rowIssues),
            empty($rowIssues) ? 'valid' : 'blocked',
            'The dry-run plan must cover the live PayPal provider implementation evidence areas while keeping runtime, writes, and provider calls disabled.',
            empty($rowIssues) ? 'All live provider implementation evidence rows are valid and disabled.' : 'Issues: ' . implode(', ', $rowIssues)
        );
    }

    private function acceptanceWiringPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaAcceptanceController.php');
        $needles = [
            'skipPaymentProviderPaypalLiveProviderImplementationEvidenceDryRun',
            'PayPal live provider implementation evidence dry-run Phase 6 closure',
            'payment-provider-paypal-live-provider-implementation-evidence-dry-run/run',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'acceptance_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Acceptance must include the live provider implementation evidence dry-run before live enablement.',
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
            'PayPal UI controls must stay hidden while implementation evidence is only a dry-run.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during implementation evidence dry-run.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues): array
    {
        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }

        return [
            'implementation_plan_row_count' => count($rows),
            'valid_implementation_plan_row_count' => empty($rowIssues) ? count($rows) : 0,
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => 4,
            'runtime_enablement_count' => 0,
            'provider_call_count' => 0,
            'dry_run_write_count' => 0,
            'artifact_read_count' => 0,
            'live_provider_implementation_evidence_valid' => empty($rowIssues) && $satisfied === count($preconditions) ? 1 : 0,
            'live_provider_implementation_evidence_applied' => 0,
            'live_provider_implementation_ready' => 0,
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
            'key' => 'live_provider_implementation_evidence_valid',
            'status' => empty($rowIssues) ? 'ready' : 'blocked',
            'details' => empty($rowIssues) ? 'The live provider implementation evidence dry-run plan is valid.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'live_provider_implementation_application',
            'status' => 'disabled',
            'details' => 'No runtime PayPal provider implementation is enabled by this dry-run.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this dry-run.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, statistic, or evidence row is created or updated.',
        ];
        $checks[] = [
            'key' => 'paypal_enablement',
            'status' => 'disabled',
            'details' => 'This dry-run cannot allow PAYPAL_ENABLED=true and cannot expose PayPal UI.',
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
            '# Mongoyia PayPal Live Provider Implementation Evidence Dry Run',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Live provider implementation evidence valid: ' . (($report['liveProviderImplementationEvidenceValid'] ?? false) ? 'yes' : 'no'),
            '- Live provider implementation evidence applied: ' . (($report['liveProviderImplementationEvidenceApplied'] ?? true) ? 'yes' : 'no'),
            '- Live provider implementation ready: ' . (($report['liveProviderImplementationReady'] ?? true) ? 'yes' : 'no'),
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
            '## Implementation Evidence Plan',
            '',
            '| Key | Area | Status | Runtime | Writes | Provider calls | Evidence ref | Requirement |',
            '|---|---|---|---:|---:|---:|---|---|',
        ]);
        foreach (($report['implementationRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['key'])
                . ' | ' . $this->escapeCell((string)$row['area'])
                . ' | ' . $this->escapeCell((string)$row['status'])
                . ' | ' . ((bool)$row['runtime_enabled'] ? '1' : '0')
                . ' | ' . ((bool)$row['write_enabled'] ? '1' : '0')
                . ' | ' . ((bool)$row['provider_call_enabled'] ? '1' : '0')
                . ' | ' . $this->escapeCell((string)$row['evidence_ref'])
                . ' | ' . $this->escapeCell((string)$row['requirement'])
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
            '- live_provider_implementation_evidence_valid=1 means the evidence plan is valid, not that runtime PayPal provider code is implemented.',
            '- live_provider_implementation_evidence_applied=0 remains intentional; this dry-run never writes implementation evidence rows.',
            '- live_provider_implementation_ready=0 remains intentional; provider implementation still requires reviewed runtime code, sandbox evidence, regression, rollback, and business approval.',
            '- paypal_enablement_allowed=0 remains intentional; this dry-run cannot turn on PayPal.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, statistic, or evidence row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,area,status,runtime_enabled,write_enabled,provider_call_enabled,evidence_ref,requirement'];
        foreach (($report['implementationRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['key']),
                $this->csvCell((string)$row['area']),
                $this->csvCell((string)$row['status']),
                ((bool)$row['runtime_enabled']) ? '1' : '0',
                ((bool)$row['write_enabled']) ? '1' : '0',
                ((bool)$row['provider_call_enabled']) ? '1' : '0',
                $this->csvCell((string)$row['evidence_ref']),
                $this->csvCell((string)$row['requirement']),
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
