<?php

namespace common\services\mall;

class PaypalSandboxEvidenceReviewResultApplyGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1';

    private const MODE = 'paypal_sandbox_evidence_review_result_apply_gate_read_only_no_persistence_no_artifact_access';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $rows = $this->reviewRows();
        $planRows = $this->applyPlanRows($rows);
        $rowIssues = $this->validateReviewRows($rows);
        $planIssues = $this->validatePlanRows($planRows);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while review-result apply is only a gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->signoffImportDryRunPrecondition(),
            $this->documentationPrecondition(),
            $this->reviewResultContractPrecondition($planRows),
            $this->reviewResultRowsPrecondition($rowIssues),
            $this->acceptanceWiringPrecondition(),
            $this->uiHiddenPrecondition(),
            $this->providerApiBoundaryPrecondition(),
        ];

        $issues = array_merge($rowIssues, $planIssues);
        foreach ($preconditions as $precondition) {
            if (($precondition['status'] ?? '') === 'blocked') {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalSandboxEvidenceSignoffImportDryRunService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'reviewResultValid' => empty($issues),
            'reviewResultApplyAllowed' => false,
            'reviewResultApplyExecuted' => false,
            'evidenceBundleAccepted' => false,
            'paypalEnablementAllowed' => false,
            'reviewRows' => $rows,
            'applyPlanRows' => $planRows,
            'rowIssues' => $rowIssues,
            'planIssues' => $planIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $planRows, $preconditions, $rowIssues, $planIssues),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $planIssues),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function reviewRows(): array
    {
        $base = [
            'bundle_id' => 'paypal-sandbox-bundle-TEST-001',
            'review_result_ref' => 'review-result:PAYPAL-SBX-001',
            'test_host' => 'https://test.mongoyia.test',
            'manifest_ref' => 'manifest:paypal-sandbox-TEST-001',
            'cleanup_ref' => 'cleanup:paypal-sandbox-TEST-001',
            'ticket_ref' => 'ticket:PAYPAL-SBX-001',
            'artifact_hash_ref' => '8f14e45fceea167a5a36dedd4bea2543b1f7d8e41b3f2c2a2f9b5d9f3f1f4a6b',
        ];

        return [
            array_merge($base, [
                'reviewer_role' => 'business',
                'decision' => 'approve',
                'result_status' => 'ready_for_external_apply',
                'result_reason' => 'Business reviewer approved the sanitized sandbox evidence bundle.',
                'reviewed_at' => '2026-06-19T01:00:00Z',
            ]),
            array_merge($base, [
                'reviewer_role' => 'security',
                'decision' => 'approve',
                'result_status' => 'ready_for_external_apply',
                'result_reason' => 'Security reviewer approved the redacted manifest references.',
                'reviewed_at' => '2026-06-19T01:05:00Z',
            ]),
            array_merge($base, [
                'reviewer_role' => 'technical',
                'decision' => 'approve',
                'result_status' => 'ready_for_external_apply',
                'result_reason' => 'Technical reviewer approved host, cleanup, and hash references.',
                'reviewed_at' => '2026-06-19T01:10:00Z',
            ]),
        ];
    }

    private function applyPlanRows(array $rows): array
    {
        $approvedRoles = [];
        foreach ($rows as $row) {
            if (($row['decision'] ?? '') === 'approve') {
                $approvedRoles[(string)$row['reviewer_role']] = true;
            }
        }

        return [
            [
                'bundle_id' => 'paypal-sandbox-bundle-TEST-001',
                'operation' => 'would_record_review_result',
                'source_review_result_ref' => 'review-result:PAYPAL-SBX-001',
                'approved_role_count' => count($approvedRoles),
                'required_role_count' => 3,
                'review_result_valid' => count($approvedRoles) === 3,
                'apply_allowed' => false,
                'apply_executed' => false,
                'evidence_bundle_accepted' => false,
                'paypal_enablement_allowed' => false,
                'reason' => 'Read-only gate: real apply remains external and manually approved.',
            ],
        ];
    }

    private function validateReviewRows(array $rows): array
    {
        $issues = [];
        $roles = [];
        foreach ($rows as $index => $row) {
            foreach ([
                'bundle_id',
                'review_result_ref',
                'test_host',
                'manifest_ref',
                'cleanup_ref',
                'ticket_ref',
                'artifact_hash_ref',
                'reviewer_role',
                'decision',
                'result_status',
                'result_reason',
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
            if ((string)($row['decision'] ?? '') !== 'approve') {
                $issues[] = 'row_' . $index . '_decision_not_approve';
            }
            if ((string)($row['result_status'] ?? '') !== 'ready_for_external_apply') {
                $issues[] = 'row_' . $index . '_invalid_result_status';
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
            foreach ([
                'bundle_id',
                'review_result_ref',
                'manifest_ref',
                'cleanup_ref',
                'ticket_ref',
                'result_reason',
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

    private function validatePlanRows(array $rows): array
    {
        $issues = [];
        if (count($rows) !== 1) {
            $issues[] = 'apply_plan_row_count_not_one';
        }
        foreach ($rows as $index => $row) {
            if (($row['operation'] ?? '') !== 'would_record_review_result') {
                $issues[] = 'plan_' . $index . '_invalid_operation';
            }
            foreach (['apply_allowed', 'apply_executed', 'evidence_bundle_accepted', 'paypal_enablement_allowed'] as $key) {
                if (!array_key_exists($key, $row) || (bool)$row[$key] !== false) {
                    $issues[] = 'plan_' . $index . '_' . $key . '_must_be_false';
                }
            }
            if ((int)($row['approved_role_count'] ?? 0) !== 3 || (int)($row['required_role_count'] ?? 0) !== 3) {
                $issues[] = 'plan_' . $index . '_role_count_mismatch';
            }
        }

        return array_values(array_unique($issues));
    }

    private function signoffImportDryRunPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-signoff-import-dry-run-*.md');
        $result = $this->readReportResult($path);
        $signoffReport = (new PaypalSandboxEvidenceSignoffImportDryRunService($this->rootPath))->run();
        $inputValid = (bool)($signoffReport['signoffInputValid'] ?? false);
        $applied = (bool)($signoffReport['signoffImportApplied'] ?? true);
        $accepted = (bool)($signoffReport['evidenceBundleAccepted'] ?? true);
        $enablementAllowed = (bool)($signoffReport['paypalEnablementAllowed'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && $inputValid && !$applied && !$accepted && !$enablementAllowed;

        return $this->precondition(
            'signoff_import_dry_run_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal sandbox evidence signoff import dry-run must PASS, while no import, acceptance, or enablement is applied.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS signoff import dry-run report or disabled flags are not in the expected state.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1',
            'PayPal Sandbox Evidence Review Result Apply Gate',
            'review_result_apply_executed=0',
            'evidence_bundle_accepted=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'review_result_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment sandbox evidence documentation must describe the review-result apply gate.',
            empty($missing) ? 'Review-result apply gate documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function reviewResultContractPrecondition(array $planRows): array
    {
        $row = $planRows[0] ?? [];
        $ok = count($planRows) === 1
            && (string)($row['operation'] ?? '') === 'would_record_review_result'
            && (int)($row['approved_role_count'] ?? 0) === 3
            && !((bool)($row['apply_allowed'] ?? true))
            && !((bool)($row['apply_executed'] ?? true))
            && !((bool)($row['evidence_bundle_accepted'] ?? true))
            && !((bool)($row['paypal_enablement_allowed'] ?? true));

        return $this->precondition(
            'review_result_apply_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'The apply gate must produce a single dry-run plan row and keep all apply/acceptance/enablement flags false.',
            $ok ? 'Review-result apply contract is read-only and disabled.' : 'Review-result apply plan does not match the expected disabled contract.'
        );
    }

    private function reviewResultRowsPrecondition(array $rowIssues): array
    {
        return $this->precondition(
            'review_result_fixture_rows',
            empty($rowIssues),
            empty($rowIssues) ? 'valid' : 'blocked',
            'The review-result fixture rows must cover approved business, security, and technical decisions with safe references.',
            empty($rowIssues) ? 'Review-result fixture rows are valid and cover all required reviewer roles.' : 'Issues: ' . implode(', ', $rowIssues)
        );
    }

    private function acceptanceWiringPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaAcceptanceController.php');
        $needles = [
            'skipPaymentProviderPaypalSandboxEvidenceReviewResultApplyGate',
            'PayPal sandbox evidence review-result apply gate Phase 6 closure',
            'payment-provider-paypal-sandbox-evidence-review-result-apply-gate/run',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'acceptance_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Acceptance must include the review-result apply gate after signoff import and before live enablement.',
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
            'PayPal UI controls must stay hidden while review-result apply is only a gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during review-result apply gate.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $rows, array $planRows, array $preconditions, array $rowIssues, array $planIssues): array
    {
        $roles = [];
        $approveCount = 0;
        $rejectCount = 0;
        $needsReworkCount = 0;
        foreach ($rows as $row) {
            $role = (string)($row['reviewer_role'] ?? '');
            if (in_array($role, ['business', 'security', 'technical'], true)) {
                $roles[$role] = true;
            }
            $decision = (string)($row['decision'] ?? '');
            if ($decision === 'approve') {
                $approveCount++;
            } elseif ($decision === 'reject') {
                $rejectCount++;
            } elseif ($decision === 'needs_rework') {
                $needsReworkCount++;
            }
        }
        $satisfied = 0;
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $satisfied++;
            }
        }
        $valid = empty($rowIssues) && empty($planIssues) && $satisfied === count($preconditions);

        return [
            'review_result_row_count' => count($rows),
            'valid_review_result_row_count' => empty($rowIssues) ? count($rows) : 0,
            'approve_count' => $approveCount,
            'reject_count' => $rejectCount,
            'needs_rework_count' => $needsReworkCount,
            'required_role_count' => 3,
            'covered_required_role_count' => count($roles),
            'apply_plan_row_count' => count($planRows),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => 4,
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'review_result_valid' => $valid ? 1 : 0,
            'review_result_apply_allowed' => 0,
            'review_result_apply_executed' => 0,
            'evidence_bundle_accepted' => 0,
            'paypal_enablement_allowed' => 0,
        ];
    }

    private function gateChecks(array $preconditions, array $rowIssues, array $planIssues): array
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
            'status' => empty($issues) ? 'ready' : 'blocked',
            'details' => empty($issues) ? 'Sanitized review-result rows and dry-run plan are valid.' : 'Issues: ' . implode(', ', $issues),
        ];
        $checks[] = [
            'key' => 'review_result_apply',
            'status' => 'disabled',
            'details' => 'No review result is persisted or applied by this gate.',
        ];
        $checks[] = [
            'key' => 'evidence_bundle_acceptance',
            'status' => 'pending',
            'details' => 'The evidence bundle remains unaccepted until real sanitized evidence and external approvals are supplied.',
        ];
        $checks[] = [
            'key' => 'paypal_enablement',
            'status' => 'disabled',
            'details' => 'This gate cannot allow PAYPAL_ENABLED=true and cannot expose PayPal UI.',
        ];
        $checks[] = [
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'This gate validates metadata only and does not read, copy, hash, import, or store evidence artifacts.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this gate.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, statistic, or signoff row is created or updated.',
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
            '# Mongoyia PayPal Sandbox Evidence Review Result Apply Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Review result valid: ' . (($report['reviewResultValid'] ?? false) ? 'yes' : 'no'),
            '- Review result apply allowed: ' . (($report['reviewResultApplyAllowed'] ?? true) ? 'yes' : 'no'),
            '- Review result apply executed: ' . (($report['reviewResultApplyExecuted'] ?? true) ? 'yes' : 'no'),
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
            '## Review Result Rows',
            '',
            '| Bundle | Role | Decision | Result status | Review result ref | Cleanup ref |',
            '|---|---|---|---|---|---|',
        ]);
        foreach (($report['reviewRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['bundle_id'])
                . ' | ' . $this->escapeCell((string)$row['reviewer_role'])
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['result_status'])
                . ' | ' . $this->escapeCell((string)$row['review_result_ref'])
                . ' | ' . $this->escapeCell((string)$row['cleanup_ref'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Dry-Run Apply Plan',
            '',
            '| Bundle | Operation | Approved roles | Required roles | Apply allowed | Apply executed | Evidence accepted | PayPal allowed | Reason |',
            '|---|---|---:|---:|---:|---:|---:|---:|---|',
        ]);
        foreach (($report['applyPlanRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['bundle_id'])
                . ' | ' . $this->escapeCell((string)$row['operation'])
                . ' | ' . (int)$row['approved_role_count']
                . ' | ' . (int)$row['required_role_count']
                . ' | ' . ((bool)$row['apply_allowed'] ? '1' : '0')
                . ' | ' . ((bool)$row['apply_executed'] ? '1' : '0')
                . ' | ' . ((bool)$row['evidence_bundle_accepted'] ? '1' : '0')
                . ' | ' . ((bool)$row['paypal_enablement_allowed'] ? '1' : '0')
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
            '- review_result_valid=1 means the sanitized review-result metadata is valid, not that any result was applied.',
            '- review_result_apply_allowed=0 remains intentional; this gate cannot approve persistence.',
            '- review_result_apply_executed=0 remains intentional; this gate never writes reviewer/signoff rows.',
            '- evidence_bundle_accepted=0 remains intentional until real sanitized evidence and approvals are externally accepted.',
            '- paypal_enablement_allowed=0 remains intentional; this gate cannot turn on PayPal.',
            '- The gate does not read, copy, hash, import, or store evidence artifacts.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, statistic, or signoff row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['bundle_id,review_result_ref,test_host,manifest_ref,cleanup_ref,ticket_ref,artifact_hash_ref,reviewer_role,decision,result_status,result_reason,reviewed_at'];
        foreach (($report['reviewRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['bundle_id']),
                $this->csvCell((string)$row['review_result_ref']),
                $this->csvCell((string)$row['test_host']),
                $this->csvCell((string)$row['manifest_ref']),
                $this->csvCell((string)$row['cleanup_ref']),
                $this->csvCell((string)$row['ticket_ref']),
                $this->csvCell((string)$row['artifact_hash_ref']),
                $this->csvCell((string)$row['reviewer_role']),
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['result_status']),
                $this->csvCell((string)$row['result_reason']),
                $this->csvCell((string)$row['reviewed_at']),
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
