<?php

namespace common\services\mall;

class PaypalExternalEvidenceCollectionGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_COLLECTION_GATE_V1';

    private const MODE = 'paypal_external_evidence_collection_input_gate_read_only_no_artifact_access';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $rows = $this->inputRows();
        $rowIssues = $this->validateInputRows($rows);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while external evidence collection is only an input gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->reviewResultApplyGatePrecondition(),
            $this->documentationPrecondition(),
            $this->collectionInputContractPrecondition($rows, $rowIssues),
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

        $valid = empty($issues);

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalSandboxEvidenceReviewResultApplyGateService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'collectionInputValid' => $valid,
            'externalCollectionReady' => $valid,
            'externalCollectionStarted' => false,
            'evidenceBundleAccepted' => false,
            'paypalEnablementAllowed' => false,
            'inputRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $preconditions, $rowIssues, $valid),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $valid),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function inputRows(): array
    {
        $base = [
            'test_host' => 'https://test.mongoyia.test',
            'collection_status' => 'ready_for_external_collection',
            'sanitized_only' => true,
            'artifact_access_allowed' => false,
            'provider_call_allowed' => false,
            'write_allowed' => false,
        ];

        return [
            array_merge($base, [
                'input_key' => 'test_https_domain',
                'input_type' => 'environment',
                'owner_role' => 'technical',
                'reference' => 'env-ref:PAYPAL-SBX-TEST-DOMAIN',
                'required_evidence' => 'Valid HTTPS test domain, TLS certificate, and non-production host reference.',
                'notes' => 'Use the real test domain externally; do not store server credentials.',
            ]),
            array_merge($base, [
                'input_key' => 'paypal_sandbox_account_ref',
                'input_type' => 'credential_reference',
                'owner_role' => 'security',
                'reference' => 'credential-ref:PAYPAL-SBX-001',
                'required_evidence' => 'PayPal sandbox account/client reference with all secrets redacted.',
                'notes' => 'Only the credential owner and ticket reference are allowed in reports.',
            ]),
            array_merge($base, [
                'input_key' => 'callback_base_ref',
                'input_type' => 'callback_reference',
                'owner_role' => 'technical',
                'reference' => 'callback-ref:PAYPAL-SBX-001',
                'required_evidence' => 'Return, cancel, and webhook callback URL reference on the HTTPS test host.',
                'notes' => 'Callback URL evidence must not include signed payloads or auth headers.',
            ]),
            array_merge($base, [
                'input_key' => 'checkout_flow_ref',
                'input_type' => 'flow_reference',
                'owner_role' => 'business',
                'reference' => 'flow-ref:PAYPAL-SBX-CHECKOUT-001',
                'required_evidence' => 'Create order, approval return, capture success, and cancel-return references.',
                'notes' => 'Use sanitized order ids and generated fixture ids only.',
            ]),
            array_merge($base, [
                'input_key' => 'webhook_event_ref',
                'input_type' => 'webhook_reference',
                'owner_role' => 'technical',
                'reference' => 'webhook-ref:PAYPAL-SBX-WEBHOOK-001',
                'required_evidence' => 'Completed, duplicate, invalid signature, expired timestamp, and amount mismatch webhook references.',
                'notes' => 'Headers and raw payloads must be redacted before review.',
            ]),
            array_merge($base, [
                'input_key' => 'payment_attempt_audit_ref',
                'input_type' => 'backend_reference',
                'owner_role' => 'technical',
                'reference' => 'backend-ref:PAYPAL-SBX-AUDIT-001',
                'required_evidence' => 'Backend payment-attempt visibility reference for success, failed, and ignored results.',
                'notes' => 'Reports may include ids, result buckets, and timestamps only.',
            ]),
            array_merge($base, [
                'input_key' => 'cleanup_plan_ref',
                'input_type' => 'cleanup_reference',
                'owner_role' => 'business',
                'reference' => 'cleanup:paypal-sandbox-TEST-001',
                'required_evidence' => 'Generated order, payment-attempt, and evidence fixture cleanup reference.',
                'notes' => 'Cleanup evidence must prove no generated rows remain after acceptance.',
            ]),
            array_merge($base, [
                'input_key' => 'sanitized_manifest_ref',
                'input_type' => 'manifest_reference',
                'owner_role' => 'security',
                'reference' => 'manifest:paypal-sandbox-TEST-001',
                'required_evidence' => 'Sanitized manifest reference with SHA256-shaped artifact hashes only.',
                'notes' => 'This gate validates metadata only and never reads the manifest file.',
            ]),
            array_merge($base, [
                'input_key' => 'reviewer_signoff_ref',
                'input_type' => 'signoff_reference',
                'owner_role' => 'joint',
                'reference' => 'review-result:PAYPAL-SBX-001',
                'required_evidence' => 'Business, security, and technical reviewer signoff reference.',
                'notes' => 'The signoff remains external and is not persisted by this gate.',
            ]),
        ];
    }

    private function validateInputRows(array $rows): array
    {
        $issues = [];
        $seen = [];
        $required = $this->requiredInputKeys();

        foreach ($rows as $index => $row) {
            foreach ([
                'input_key',
                'input_type',
                'owner_role',
                'test_host',
                'reference',
                'required_evidence',
                'collection_status',
                'notes',
            ] as $key) {
                if (!array_key_exists($key, $row) || trim((string)$row[$key]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $key;
                }
            }
            foreach (['sanitized_only', 'artifact_access_allowed', 'provider_call_allowed', 'write_allowed'] as $key) {
                if (!array_key_exists($key, $row)) {
                    $issues[] = 'row_' . $index . '_missing_' . $key;
                }
            }

            $inputKey = (string)($row['input_key'] ?? '');
            if (!in_array($inputKey, $required, true)) {
                $issues[] = 'row_' . $index . '_unknown_input_key';
            }
            if (isset($seen[$inputKey])) {
                $issues[] = 'row_' . $index . '_duplicate_input_key';
            }
            $seen[$inputKey] = true;

            $ownerRole = (string)($row['owner_role'] ?? '');
            if (!in_array($ownerRole, ['business', 'security', 'technical', 'joint'], true)) {
                $issues[] = 'row_' . $index . '_invalid_owner_role';
            }
            if ((string)($row['collection_status'] ?? '') !== 'ready_for_external_collection') {
                $issues[] = 'row_' . $index . '_invalid_collection_status';
            }
            if (strpos((string)($row['test_host'] ?? ''), 'https://') !== 0
                || strpos((string)($row['test_host'] ?? ''), 'localhost') !== false
                || strpos((string)($row['test_host'] ?? ''), '127.0.0.1') !== false) {
                $issues[] = 'row_' . $index . '_invalid_test_host';
            }
            if (!preg_match('/^[A-Za-z0-9:_-]+$/', (string)($row['reference'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_reference_shape';
            }
            foreach (['sanitized_only' => true, 'artifact_access_allowed' => false, 'provider_call_allowed' => false, 'write_allowed' => false] as $key => $expected) {
                if (!array_key_exists($key, $row) || (bool)$row[$key] !== $expected) {
                    $issues[] = 'row_' . $index . '_' . $key . '_invalid';
                }
            }
            foreach (['input_type', 'reference', 'required_evidence', 'notes'] as $safeKey) {
                if ($this->containsForbiddenMarker((string)($row[$safeKey] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeKey;
                }
            }
        }

        foreach ($required as $key) {
            if (!isset($seen[$key])) {
                $issues[] = 'missing_required_input_' . $key;
            }
        }

        return array_values(array_unique($issues));
    }

    private function reviewResultApplyGatePrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-review-result-apply-gate-*.md');
        $result = $this->readReportResult($path);
        $reviewReport = (new PaypalSandboxEvidenceReviewResultApplyGateService($this->rootPath))->run();
        $reviewValid = (bool)($reviewReport['reviewResultValid'] ?? false);
        $applyExecuted = (bool)($reviewReport['reviewResultApplyExecuted'] ?? true);
        $accepted = (bool)($reviewReport['evidenceBundleAccepted'] ?? true);
        $paypalAllowed = (bool)($reviewReport['paypalEnablementAllowed'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && $reviewValid && !$applyExecuted && !$accepted && !$paypalAllowed;

        return $this->precondition(
            'review_result_apply_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal sandbox evidence review-result apply gate must PASS while no result is applied and PayPal stays disabled.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS review-result apply gate report or disabled flags are not in the expected state.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-provider-contract.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_COLLECTION_GATE_V1',
            'PayPal External Evidence Collection Gate',
            'external_collection_started=0',
            'paypal_enablement_allowed=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'external_collection_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment provider docs must describe the external evidence collection input gate.',
            empty($missing) ? 'External evidence collection documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function collectionInputContractPrecondition(array $rows, array $rowIssues): array
    {
        $required = $this->requiredInputKeys();
        $seen = [];
        foreach ($rows as $row) {
            $key = (string)($row['input_key'] ?? '');
            if (in_array($key, $required, true)) {
                $seen[$key] = true;
            }
        }
        $ok = empty($rowIssues) && count($seen) === count($required);

        return $this->precondition(
            'collection_input_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'The gate must define all required sanitized external evidence collection references.',
            $ok ? 'All required external evidence collection inputs are present and safe.' : 'Issues: ' . implode(', ', $rowIssues)
        );
    }

    private function acceptanceWiringPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaAcceptanceController.php');
        $needles = [
            'skipPaymentProviderPaypalExternalEvidenceCollectionGate',
            'PayPal external evidence collection gate Phase 6 closure',
            'payment-provider-paypal-external-evidence-collection-gate/run',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'acceptance_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Acceptance must include the external evidence collection gate before live enablement.',
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
            'PayPal UI controls must stay hidden while external evidence collection is only an input gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during the collection gate.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues, bool $valid): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = (string)($row['input_key'] ?? '');
            if (in_array($key, $this->requiredInputKeys(), true)) {
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
            'collection_input_row_count' => count($rows),
            'valid_collection_input_row_count' => empty($rowIssues) ? count($rows) : 0,
            'required_input_count' => count($this->requiredInputKeys()),
            'covered_required_input_count' => count($seen),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => 5,
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'collection_input_valid' => $valid ? 1 : 0,
            'external_collection_ready' => $valid ? 1 : 0,
            'external_collection_started' => 0,
            'evidence_bundle_accepted' => 0,
            'paypal_enablement_allowed' => 0,
        ];
    }

    private function gateChecks(array $preconditions, array $rowIssues, bool $valid): array
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
            'key' => 'collection_input_valid',
            'status' => $valid ? 'ready' : 'blocked',
            'details' => $valid ? 'Sanitized external evidence collection inputs are valid.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'external_collection_start',
            'status' => 'disabled',
            'details' => 'This gate does not start PayPal sandbox evidence collection or call providers.',
        ];
        $checks[] = [
            'key' => 'evidence_bundle_acceptance',
            'status' => 'pending',
            'details' => 'The evidence bundle remains unaccepted until real sanitized evidence and approvals are supplied.',
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
            '# Mongoyia PayPal External Evidence Collection Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Collection input valid: ' . (($report['collectionInputValid'] ?? false) ? 'yes' : 'no'),
            '- External collection ready: ' . (($report['externalCollectionReady'] ?? false) ? 'yes' : 'no'),
            '- External collection started: ' . (($report['externalCollectionStarted'] ?? true) ? 'yes' : 'no'),
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
            '## External Collection Inputs',
            '',
            '| Input | Type | Owner | Status | Test host | Reference |',
            '|---|---|---|---|---|---|',
        ]);
        foreach (($report['inputRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['input_key'])
                . ' | ' . $this->escapeCell((string)$row['input_type'])
                . ' | ' . $this->escapeCell((string)$row['owner_role'])
                . ' | ' . $this->escapeCell((string)$row['collection_status'])
                . ' | ' . $this->escapeCell((string)$row['test_host'])
                . ' | ' . $this->escapeCell((string)$row['reference'])
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
            '- collection_input_valid=1 means the non-sensitive input references are valid, not that evidence was collected.',
            '- external_collection_ready=1 means local prerequisites are ready for an external manual process.',
            '- external_collection_started=0 remains intentional; this gate never starts PayPal sandbox collection.',
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
        $lines = ['input_key,input_type,owner_role,test_host,reference,collection_status,sanitized_only,artifact_access_allowed,provider_call_allowed,write_allowed,required_evidence,notes'];
        foreach (($report['inputRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['input_key']),
                $this->csvCell((string)$row['input_type']),
                $this->csvCell((string)$row['owner_role']),
                $this->csvCell((string)$row['test_host']),
                $this->csvCell((string)$row['reference']),
                $this->csvCell((string)$row['collection_status']),
                ((bool)$row['sanitized_only']) ? '1' : '0',
                ((bool)$row['artifact_access_allowed']) ? '1' : '0',
                ((bool)$row['provider_call_allowed']) ? '1' : '0',
                ((bool)$row['write_allowed']) ? '1' : '0',
                $this->csvCell((string)$row['required_evidence']),
                $this->csvCell((string)$row['notes']),
            ]);
        }

        return $lines;
    }

    private function requiredInputKeys(): array
    {
        return [
            'test_https_domain',
            'paypal_sandbox_account_ref',
            'callback_base_ref',
            'checkout_flow_ref',
            'webhook_event_ref',
            'payment_attempt_audit_ref',
            'cleanup_plan_ref',
            'sanitized_manifest_ref',
            'reviewer_signoff_ref',
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
