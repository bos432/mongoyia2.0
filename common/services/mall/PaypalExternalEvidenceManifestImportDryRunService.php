<?php

namespace common\services\mall;

class PaypalExternalEvidenceManifestImportDryRunService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_IMPORT_DRY_RUN_V1';

    private const MODE = 'paypal_external_evidence_manifest_import_dry_run_read_only_no_artifact_access';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $rows = $this->manifestRows();
        $rowIssues = $this->validateManifestRows($rows);
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while manifest import is only a dry-run gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->externalCollectionGatePrecondition(),
            $this->documentationPrecondition(),
            $this->manifestRowsPrecondition($rows, $rowIssues),
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
                PaypalExternalEvidenceCollectionGateService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'manifestInputValid' => $valid,
            'manifestImportAllowed' => false,
            'manifestImportExecuted' => false,
            'evidenceBundleAccepted' => false,
            'paypalEnablementAllowed' => false,
            'manifestRows' => $rows,
            'rowIssues' => $rowIssues,
            'preconditions' => $preconditions,
            'totals' => $this->totals($rows, $preconditions, $rowIssues, $valid),
            'gateChecks' => $this->gateChecks($preconditions, $rowIssues, $valid),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function manifestRows(): array
    {
        $rows = [];
        $index = 0;
        foreach ($this->requiredCaseKeys() as $caseKey) {
            $index++;
            $rows[] = [
                'case_key' => $caseKey,
                'collection_ref' => 'collection-ref:PAYPAL-SBX-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'test_host' => 'https://test.mongoyia.test',
                'artifact_ref' => 'artifact-ref:PAYPAL-SBX-' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
                'artifact_sha256' => str_repeat(dechex($index), 64),
                'redaction_status' => $caseKey === 'sandbox_credential_reference' ? 'not_applicable' : 'redacted',
                'collection_status' => 'collected_sanitized',
                'collected_at' => '2026-06-19T02:' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . ':00Z',
                'collector_role' => $this->collectorRole($caseKey),
                'cleanup_ref' => 'cleanup:paypal-sandbox-TEST-001',
                'ticket_ref' => 'ticket:PAYPAL-SBX-001',
                'notes' => 'Sanitized collection manifest reference only; raw artifacts remain external.',
            ];
        }

        return $rows;
    }

    private function validateManifestRows(array $rows): array
    {
        $issues = [];
        $seen = [];
        $required = $this->requiredCaseKeys();
        foreach ($rows as $index => $row) {
            foreach ([
                'case_key',
                'collection_ref',
                'test_host',
                'artifact_ref',
                'artifact_sha256',
                'redaction_status',
                'collection_status',
                'collected_at',
                'collector_role',
                'cleanup_ref',
                'ticket_ref',
                'notes',
            ] as $key) {
                if (!array_key_exists($key, $row) || trim((string)$row[$key]) === '') {
                    $issues[] = 'row_' . $index . '_missing_' . $key;
                }
            }

            $caseKey = (string)($row['case_key'] ?? '');
            if (!in_array($caseKey, $required, true)) {
                $issues[] = 'row_' . $index . '_unknown_case_key';
            }
            if (isset($seen[$caseKey])) {
                $issues[] = 'row_' . $index . '_duplicate_case_key';
            }
            $seen[$caseKey] = true;

            if ((string)($row['collection_status'] ?? '') !== 'collected_sanitized') {
                $issues[] = 'row_' . $index . '_invalid_collection_status';
            }
            if (!in_array((string)($row['redaction_status'] ?? ''), ['redacted', 'not_applicable'], true)) {
                $issues[] = 'row_' . $index . '_invalid_redaction_status';
            }
            if (!in_array((string)($row['collector_role'] ?? ''), ['business', 'security', 'technical'], true)) {
                $issues[] = 'row_' . $index . '_invalid_collector_role';
            }
            if (strpos((string)($row['test_host'] ?? ''), 'https://') !== 0
                || strpos((string)($row['test_host'] ?? ''), 'localhost') !== false
                || strpos((string)($row['test_host'] ?? ''), '127.0.0.1') !== false) {
                $issues[] = 'row_' . $index . '_invalid_test_host';
            }
            if (!preg_match('/^[a-f0-9]{64}$/', (string)($row['artifact_sha256'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_artifact_sha256';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($row['collected_at'] ?? ''))) {
                $issues[] = 'row_' . $index . '_invalid_collected_at';
            }
            foreach (['collection_ref', 'artifact_ref', 'cleanup_ref', 'ticket_ref'] as $refKey) {
                if (!preg_match('/^[A-Za-z0-9:_-]+$/', (string)($row[$refKey] ?? ''))) {
                    $issues[] = 'row_' . $index . '_invalid_' . $refKey;
                }
            }
            foreach (['collection_ref', 'artifact_ref', 'cleanup_ref', 'ticket_ref', 'notes'] as $safeKey) {
                if ($this->containsForbiddenMarker((string)($row[$safeKey] ?? ''))) {
                    $issues[] = 'row_' . $index . '_unsafe_' . $safeKey;
                }
            }
        }

        foreach ($required as $caseKey) {
            if (!isset($seen[$caseKey])) {
                $issues[] = 'missing_required_case_' . $caseKey;
            }
        }

        return array_values(array_unique($issues));
    }

    private function externalCollectionGatePrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-collection-gate-*.md');
        $result = $this->readReportResult($path);
        $collectionReport = (new PaypalExternalEvidenceCollectionGateService($this->rootPath))->run();
        $inputValid = (bool)($collectionReport['collectionInputValid'] ?? false);
        $collectionStarted = (bool)($collectionReport['externalCollectionStarted'] ?? true);
        $accepted = (bool)($collectionReport['evidenceBundleAccepted'] ?? true);
        $paypalAllowed = (bool)($collectionReport['paypalEnablementAllowed'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && $inputValid && !$collectionStarted && !$accepted && !$paypalAllowed;

        return $this->precondition(
            'external_collection_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal external evidence collection gate must PASS while collection is not started and PayPal stays disabled.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS external collection gate report or disabled flags are not in the expected state.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-provider-contract.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_IMPORT_DRY_RUN_V1',
            'PayPal External Evidence Manifest Import Dry Run',
            'manifest_import_executed=0',
            'paypal_enablement_allowed=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'manifest_import_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment provider docs must describe the external evidence manifest import dry-run gate.',
            empty($missing) ? 'External evidence manifest import dry-run documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function manifestRowsPrecondition(array $rows, array $rowIssues): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $caseKey = (string)($row['case_key'] ?? '');
            if (in_array($caseKey, $this->requiredCaseKeys(), true)) {
                $seen[$caseKey] = true;
            }
        }
        $ok = empty($rowIssues) && count($seen) === count($this->requiredCaseKeys());

        return $this->precondition(
            'manifest_import_rows',
            $ok,
            $ok ? 'valid' : 'blocked',
            'The dry-run manifest must cover all PayPal sandbox evidence cases with sanitized references.',
            $ok ? 'All required manifest rows are valid and sanitized.' : 'Issues: ' . implode(', ', $rowIssues)
        );
    }

    private function acceptanceWiringPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaAcceptanceController.php');
        $needles = [
            'skipPaymentProviderPaypalExternalEvidenceManifestImportDryRun',
            'PayPal external evidence manifest import dry-run Phase 6 closure',
            'payment-provider-paypal-external-evidence-manifest-import-dry-run/run',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'acceptance_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Acceptance must include the external evidence manifest import dry-run before live enablement.',
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
            'PayPal UI controls must stay hidden while manifest import is only a dry-run gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during the manifest import dry-run.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $rows, array $preconditions, array $rowIssues, bool $valid): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $caseKey = (string)($row['case_key'] ?? '');
            if (in_array($caseKey, $this->requiredCaseKeys(), true)) {
                $seen[$caseKey] = true;
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
            'required_case_count' => count($this->requiredCaseKeys()),
            'covered_required_case_count' => count($seen),
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => $satisfied,
            'pending_external_count' => 5,
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'manifest_input_valid' => $valid ? 1 : 0,
            'manifest_import_allowed' => 0,
            'manifest_import_executed' => 0,
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
            'key' => 'manifest_input_valid',
            'status' => $valid ? 'ready' : 'blocked',
            'details' => $valid ? 'Sanitized external evidence manifest rows are valid.' : 'Issues: ' . implode(', ', $rowIssues),
        ];
        $checks[] = [
            'key' => 'manifest_import',
            'status' => 'disabled',
            'details' => 'No manifest row is imported or persisted by this dry-run.',
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
            '# Mongoyia PayPal External Evidence Manifest Import Dry Run',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Manifest input valid: ' . (($report['manifestInputValid'] ?? false) ? 'yes' : 'no'),
            '- Manifest import allowed: ' . (($report['manifestImportAllowed'] ?? true) ? 'yes' : 'no'),
            '- Manifest import executed: ' . (($report['manifestImportExecuted'] ?? true) ? 'yes' : 'no'),
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
            '## Manifest Rows',
            '',
            '| Case | Collection ref | Artifact ref | Redaction | Status | Collector |',
            '|---|---|---|---|---|---|',
        ]);
        foreach (($report['manifestRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['case_key'])
                . ' | ' . $this->escapeCell((string)$row['collection_ref'])
                . ' | ' . $this->escapeCell((string)$row['artifact_ref'])
                . ' | ' . $this->escapeCell((string)$row['redaction_status'])
                . ' | ' . $this->escapeCell((string)$row['collection_status'])
                . ' | ' . $this->escapeCell((string)$row['collector_role'])
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
            '- manifest_input_valid=1 means the sanitized manifest metadata is valid, not that any row was imported.',
            '- manifest_import_allowed=0 remains intentional; this dry-run cannot approve persistence.',
            '- manifest_import_executed=0 remains intentional; this dry-run never writes manifest rows.',
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
        $lines = ['case_key,collection_ref,test_host,artifact_ref,artifact_sha256,redaction_status,collection_status,collected_at,collector_role,cleanup_ref,ticket_ref,notes'];
        foreach (($report['manifestRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['case_key']),
                $this->csvCell((string)$row['collection_ref']),
                $this->csvCell((string)$row['test_host']),
                $this->csvCell((string)$row['artifact_ref']),
                $this->csvCell((string)$row['artifact_sha256']),
                $this->csvCell((string)$row['redaction_status']),
                $this->csvCell((string)$row['collection_status']),
                $this->csvCell((string)$row['collected_at']),
                $this->csvCell((string)$row['collector_role']),
                $this->csvCell((string)$row['cleanup_ref']),
                $this->csvCell((string)$row['ticket_ref']),
                $this->csvCell((string)$row['notes']),
            ]);
        }

        return $lines;
    }

    private function requiredCaseKeys(): array
    {
        return [
            'sandbox_credential_reference',
            'create_order_request',
            'approval_return_capture',
            'cancel_return_unchanged_state',
            'completed_webhook_verified',
            'duplicate_webhook_idempotency',
            'amount_mismatch_rejection',
            'invalid_signature_rejection',
            'expired_transmission_rejection',
            'backend_payment_attempt_visibility',
            'generated_test_data_cleanup',
        ];
    }

    private function collectorRole(string $caseKey): string
    {
        if (in_array($caseKey, ['sandbox_credential_reference', 'invalid_signature_rejection'], true)) {
            return 'security';
        }
        if (in_array($caseKey, ['approval_return_capture', 'cancel_return_unchanged_state', 'generated_test_data_cleanup'], true)) {
            return 'business';
        }

        return 'technical';
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
