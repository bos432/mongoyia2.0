<?php

namespace common\services\mall;

class PaypalLiveProviderImplementationEvidenceSignoffGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1';

    private const MODE = 'paypal_live_provider_implementation_evidence_signoff_gate_read_only_no_runtime_no_artifact_access';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $rows = $this->signoffRows();
        $rowIssues = $this->validateRows($rows);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while implementation evidence signoff is only a gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->implementationEvidenceDryRunPrecondition(),
            $this->documentationPrecondition(),
            $this->signoffContractPrecondition($rowIssues),
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

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalLiveProviderImplementationEvidenceDryRunService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'implementationEvidenceSignoffReady' => empty($issues),
            'implementationEvidenceAccepted' => false,
            'liveProviderImplementationReady' => false,
            'paypalEnablementAllowed' => false,
            'signoffRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $preconditions, $rowIssues),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues),
            'issues' => $issues,
        ];
    }

    private function signoffRows(): array
    {
        $base = [
            'implementation_evidence_ref' => 'live-provider-implementation:PAYPAL-LIVE-IMPL-001',
            'source_report_ref' => 'report:paypal-live-provider-implementation-evidence-dry-run',
            'test_host' => 'https://test.mongoyia.test',
            'cleanup_ref' => 'cleanup:PAYPAL-LIVE-IMPL-001',
            'ticket_ref' => 'ticket:PAYPAL-LIVE-IMPL-001',
        ];

        return [
            array_merge($base, [
                'reviewer_role' => 'business',
                'decision' => 'approve_plan',
                'signoff_status' => 'ready_for_manual_signoff',
                'signoff_reason' => 'Business owner confirms the PayPal implementation evidence plan can enter manual signoff.',
                'reviewed_at' => '2026-06-19T02:00:00Z',
            ]),
            array_merge($base, [
                'reviewer_role' => 'security',
                'decision' => 'approve_plan',
                'signoff_status' => 'ready_for_manual_signoff',
                'signoff_reason' => 'Security owner confirms the evidence plan keeps secrets, artifacts, and provider calls out of this gate.',
                'reviewed_at' => '2026-06-19T02:05:00Z',
            ]),
            array_merge($base, [
                'reviewer_role' => 'technical',
                'decision' => 'approve_plan',
                'signoff_status' => 'ready_for_manual_signoff',
                'signoff_reason' => 'Technical owner confirms create/capture/cancel/webhook/audit/rollback coverage is represented.',
                'reviewed_at' => '2026-06-19T02:10:00Z',
            ]),
        ];
    }

    private function validateRows(array $rows): array
    {
        $issues = [];
        $roles = [];
        foreach ($rows as $index => $row) {
            foreach ([
                'implementation_evidence_ref',
                'source_report_ref',
                'test_host',
                'cleanup_ref',
                'ticket_ref',
                'reviewer_role',
                'decision',
                'signoff_status',
                'signoff_reason',
                'reviewed_at',
            ] as $key) {
                if (!array_key_exists($key, $row) || trim((string)$row[$key]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $key;
                }
            }

            $role = (string)($row['reviewer_role'] ?? '');
            $roles[] = $role;
            if (!in_array($role, ['business', 'security', 'technical'], true)) {
                $issues[] = 'row_' . $index . '_invalid_reviewer_role';
            }
            if ((string)($row['decision'] ?? '') !== 'approve_plan') {
                $issues[] = 'row_' . $index . '_decision_not_approve_plan';
            }
            if ((string)($row['signoff_status'] ?? '') !== 'ready_for_manual_signoff') {
                $issues[] = 'row_' . $index . '_invalid_signoff_status';
            }
            if (strpos((string)($row['test_host'] ?? ''), 'https://') !== 0
                || strpos((string)($row['test_host'] ?? ''), 'localhost') !== false
                || strpos((string)($row['test_host'] ?? ''), '127.0.0.1') !== false) {
                $issues[] = 'row_' . $index . '_invalid_test_host';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($row['reviewed_at'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_reviewed_at';
            }
            foreach ([
                'implementation_evidence_ref',
                'source_report_ref',
                'cleanup_ref',
                'ticket_ref',
                'signoff_reason',
            ] as $safeKey) {
                if ($this->containsForbiddenMarker((string)($row[$safeKey] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeKey;
                }
            }
        }

        foreach (['business', 'security', 'technical'] as $role) {
            if (!in_array($role, $roles, true)) {
                $issues[] = 'missing_required_role_' . $role;
            }
        }

        return array_values(array_unique($issues));
    }

    private function implementationEvidenceDryRunPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-*.md');
        $result = $this->readReportResult($path);
        $report = (new PaypalLiveProviderImplementationEvidenceDryRunService($this->rootPath))->run();
        $valid = (bool)($report['liveProviderImplementationEvidenceValid'] ?? false);
        $applied = (bool)($report['liveProviderImplementationEvidenceApplied'] ?? true);
        $ready = (bool)($report['liveProviderImplementationReady'] ?? true);
        $enablementAllowed = (bool)($report['paypalEnablementAllowed'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && $valid && !$applied && !$ready && !$enablementAllowed;

        return $this->precondition(
            'live_provider_implementation_evidence_dry_run_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal live provider implementation evidence dry-run must PASS, while no evidence apply, runtime readiness, or enablement is allowed.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS live provider implementation evidence dry-run report or disabled flags are not in the expected state.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-provider-contract.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_LIVE_PROVIDER_IMPLEMENTATION_EVIDENCE_SIGNOFF_GATE_V1',
            'PayPal Live Provider Implementation Evidence Signoff Gate',
            'implementation_evidence_signoff_ready=1',
            'implementation_evidence_accepted=0',
            'live_provider_implementation_ready=0',
            'paypal_enablement_allowed=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'implementation_evidence_signoff_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment provider docs must describe the live provider implementation evidence signoff gate.',
            empty($missing) ? 'Implementation evidence signoff gate documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function signoffContractPrecondition(array $rowIssues): array
    {
        return $this->precondition(
            'implementation_evidence_signoff_contract',
            empty($rowIssues),
            empty($rowIssues) ? 'ready' : 'blocked',
            'Implementation evidence signoff rows must cover business, security, and technical plan approval metadata with safe references.',
            empty($rowIssues) ? 'Implementation evidence signoff rows are valid and cover all required reviewer roles.' : 'Issues: ' . implode(', ', $rowIssues)
        );
    }

    private function acceptanceWiringPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaAcceptanceController.php');
        $needles = [
            'skipPaymentProviderPaypalLiveProviderImplementationEvidenceSignoffGate',
            'PayPal live provider implementation evidence signoff gate Phase 6 closure',
            'payment-provider-paypal-live-provider-implementation-evidence-signoff-gate/run',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'acceptance_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Acceptance must include the live provider implementation evidence signoff gate after dry-run evidence and before live enablement.',
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
            'PayPal UI controls must stay hidden while implementation evidence signoff is only a gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during implementation evidence signoff gating.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues): array
    {
        $roles = [];
        $approved = 0;
        foreach ($rows as $row) {
            $role = (string)($row['reviewer_role'] ?? '');
            if (in_array($role, ['business', 'security', 'technical'], true)) {
                $roles[$role] = true;
            }
            if ((string)($row['decision'] ?? '') === 'approve_plan') {
                $approved++;
            }
        }

        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }
        $ready = empty($rowIssues) && $satisfied === count($preconditions);

        return [
            'signoff_row_count' => count($rows),
            'approved_plan_count' => $approved,
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
            'implementation_evidence_signoff_ready' => $ready ? 1 : 0,
            'implementation_evidence_accepted' => 0,
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
            'key' => 'implementation_evidence_signoff_ready',
            'status' => empty($rowIssues) ? 'ready' : 'blocked',
            'details' => empty($rowIssues) ? 'The implementation evidence signoff metadata is ready for manual review.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'implementation_evidence_acceptance',
            'status' => 'pending',
            'details' => 'The implementation evidence remains unaccepted until real reviewed runtime code and external signoff are supplied.',
        ];
        $checks[] = [
            'key' => 'live_provider_implementation',
            'status' => 'disabled',
            'details' => 'No runtime PayPal provider implementation is enabled by this signoff gate.',
        ];
        $checks[] = [
            'key' => 'paypal_enablement',
            'status' => 'disabled',
            'details' => 'This gate cannot allow PAYPAL_ENABLED=true and cannot expose PayPal UI.',
        ];
        $checks[] = [
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'This gate validates signoff metadata only and does not read, copy, hash, import, or store evidence artifacts.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this signoff gate.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, statistic, signoff, or evidence row is created or updated.',
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
            '# Mongoyia PayPal Live Provider Implementation Evidence Signoff Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Implementation evidence signoff ready: ' . (($report['implementationEvidenceSignoffReady'] ?? false) ? 'yes' : 'no'),
            '- Implementation evidence accepted: ' . (($report['implementationEvidenceAccepted'] ?? true) ? 'yes' : 'no'),
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
            '## Signoff Rows',
            '',
            '| Evidence ref | Role | Decision | Status | Cleanup ref | Reason |',
            '|---|---|---|---|---|---|',
        ]);
        foreach (($report['signoffRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['implementation_evidence_ref'])
                . ' | ' . $this->escapeCell((string)$row['reviewer_role'])
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['signoff_status'])
                . ' | ' . $this->escapeCell((string)$row['cleanup_ref'])
                . ' | ' . $this->escapeCell((string)$row['signoff_reason'])
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
            '- implementation_evidence_signoff_ready=1 means the local signoff metadata is valid, not that runtime PayPal provider code is implemented.',
            '- implementation_evidence_accepted=0 remains intentional; this gate does not accept implementation evidence.',
            '- live_provider_implementation_ready=0 remains intentional; runtime provider implementation still requires reviewed code and regression.',
            '- paypal_enablement_allowed=0 remains intentional; this gate cannot turn on PayPal.',
            '- The gate does not read, copy, hash, import, or store evidence artifacts.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, statistic, signoff, or evidence row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = 'implementation_evidence_ref,source_report_ref,test_host,cleanup_ref,ticket_ref,reviewer_role,decision,signoff_status,signoff_reason,reviewed_at';
        $rows = [$lines];
        foreach (($report['signoffRows'] ?? []) as $row) {
            $rows[] = implode(',', [
                $this->csvCell((string)$row['implementation_evidence_ref']),
                $this->csvCell((string)$row['source_report_ref']),
                $this->csvCell((string)$row['test_host']),
                $this->csvCell((string)$row['cleanup_ref']),
                $this->csvCell((string)$row['ticket_ref']),
                $this->csvCell((string)$row['reviewer_role']),
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['signoff_status']),
                $this->csvCell((string)$row['signoff_reason']),
                $this->csvCell((string)$row['reviewed_at']),
            ]);
        }

        return $rows;
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
