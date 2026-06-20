<?php

namespace common\services\mall;

class PaypalExternalEvidenceManifestReviewReadinessService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_READINESS_V1';

    private const MODE = 'paypal_external_evidence_manifest_review_readiness_read_only_no_artifact_access';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $items = $this->reviewItems();
        $preconditions = [
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false while manifest review readiness is only a read-only gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->manifestImportDryRunPrecondition(),
            $this->documentationPrecondition(),
            $this->reviewContractPrecondition($items),
            $this->acceptanceWiringPrecondition(),
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
                PaypalExternalEvidenceManifestImportDryRunService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'manifestReviewReady' => empty($issues),
            'manifestReviewStarted' => false,
            'manifestReviewAccepted' => false,
            'evidenceBundleAccepted' => false,
            'paypalEnablementAllowed' => false,
            'reviewItems' => $items,
            'preconditions' => $preconditions,
            'totals' => $this->totals($items, $preconditions),
            'gateChecks' => $this->gateChecks($preconditions),
            'issues' => $issues,
        ];
    }

    private function reviewItems(): array
    {
        return [
            $this->item('manifest_import_dry_run_pass', 'technical', 'Latest external evidence manifest import dry-run report is PASS with `manifest_import_executed=0`.'),
            $this->item('sanitized_manifest_rows_ready', 'technical', 'All required PayPal sandbox evidence cases are represented by sanitized manifest metadata.'),
            $this->item('collector_role_coverage', 'business', 'Business, security, and technical collector roles are available for manual review routing.'),
            $this->item('reviewer_assignment_ready', 'business', 'Business/security/technical reviewer slots are defined before any review can start.'),
            $this->item('rejection_reason_template_ready', 'security', 'Rejected manifest rows must keep a non-sensitive rejection reason and rework reference.'),
            $this->item('artifact_access_boundary', 'security', 'Raw artifacts remain external; this readiness gate may only review references and hashes already supplied.'),
            $this->item('review_result_schema_ready', 'technical', 'Future review result import/apply steps must stay dry-run until explicit signoff is supplied.'),
            $this->item('cleanup_reference_traceable', 'ops', 'Every case keeps cleanup and ticket references for sandbox evidence teardown.'),
            $this->item('final_acceptance_pending', 'business', 'Manifest review readiness does not accept the evidence bundle or enable PayPal.'),
        ];
    }

    private function item(string $key, string $ownerRole, string $requiredEvidence): array
    {
        return [
            'key' => $key,
            'status' => 'ready',
            'owner_role' => $ownerRole,
            'required_evidence' => $requiredEvidence,
        ];
    }

    private function manifestImportDryRunPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-external-evidence-manifest-import-dry-run-*.md');
        $result = $this->readReportResult($path);
        $manifestReport = (new PaypalExternalEvidenceManifestImportDryRunService($this->rootPath))->run();
        $inputValid = (bool)($manifestReport['manifestInputValid'] ?? false);
        $importAllowed = (bool)($manifestReport['manifestImportAllowed'] ?? true);
        $importExecuted = (bool)($manifestReport['manifestImportExecuted'] ?? true);
        $accepted = (bool)($manifestReport['evidenceBundleAccepted'] ?? true);
        $paypalAllowed = (bool)($manifestReport['paypalEnablementAllowed'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && $inputValid && !$importAllowed && !$importExecuted && !$accepted && !$paypalAllowed;

        return $this->precondition(
            'manifest_import_dry_run_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal external evidence manifest import dry-run must PASS while import and PayPal enablement stay disabled.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS manifest import dry-run report or disabled flags are not in the expected state.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-provider-contract.md')
            . "\n"
            . $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_EXTERNAL_EVIDENCE_MANIFEST_REVIEW_READINESS_V1',
            'PayPal External Evidence Manifest Review Readiness',
            'manifest_review_ready=1',
            'manifest_review_accepted=0',
            'paypal_enablement_allowed=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'manifest_review_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment provider docs must describe the external evidence manifest review readiness gate.',
            empty($missing) ? 'External evidence manifest review readiness documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function reviewContractPrecondition(array $items): array
    {
        $keys = array_map(static function ($item) {
            return (string)$item['key'];
        }, $items);
        $ok = count($items) === 9
            && count(array_unique($keys)) === 9
            && in_array('manifest_import_dry_run_pass', $keys, true)
            && in_array('artifact_access_boundary', $keys, true)
            && in_array('final_acceptance_pending', $keys, true);

        return $this->precondition(
            'manifest_review_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'Manifest review readiness must cover dry-run dependency, sanitized rows, reviewers, rejection/rework, artifact boundary, cleanup, and pending acceptance.',
            $ok ? 'Nine required manifest review readiness items are available.' : 'Manifest review readiness item contract is incomplete.'
        );
    }

    private function acceptanceWiringPrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaAcceptanceController.php');
        $needles = [
            'skipPaymentProviderPaypalExternalEvidenceManifestReviewReadiness',
            'PayPal external evidence manifest review readiness Phase 6 closure',
            'payment-provider-paypal-external-evidence-manifest-review-readiness/run',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'acceptance_wiring',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Acceptance must include the external evidence manifest review readiness gate before live enablement.',
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
            'PayPal UI controls must stay hidden while manifest review readiness is only a read-only gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during manifest review readiness.',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found)
        );
    }

    private function totals(array $items, array $preconditions): array
    {
        $totals = [
            'review_item_count' => count($items),
            'ready_review_item_count' => 0,
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => 0,
            'pending_external_count' => 4,
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'manifest_review_ready' => 0,
            'manifest_review_started' => 0,
            'manifest_review_accepted' => 0,
            'evidence_bundle_accepted' => 0,
            'paypal_enablement_allowed' => 0,
        ];

        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'ready') {
                $totals['ready_review_item_count']++;
            }
        }
        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $totals['satisfied_precondition_count']++;
            }
        }
        if ($totals['ready_review_item_count'] === count($items) && $totals['satisfied_precondition_count'] === count($preconditions)) {
            $totals['manifest_review_ready'] = 1;
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
            'key' => 'manifest_review_ready',
            'status' => 'ready',
            'details' => 'The sanitized manifest metadata is ready for external manual review, but review is not started by this command.',
        ];
        $checks[] = [
            'key' => 'manifest_review_start',
            'status' => 'disabled',
            'details' => 'This gate does not start, assign, or persist a manifest review workflow.',
        ];
        $checks[] = [
            'key' => 'manifest_review_acceptance',
            'status' => 'pending',
            'details' => 'Manual manifest review acceptance remains pending external business/security/technical signoff.',
        ];
        $checks[] = [
            'key' => 'evidence_bundle_acceptance',
            'status' => 'pending',
            'details' => 'The evidence bundle remains unaccepted until external reviewers approve it.',
        ];
        $checks[] = [
            'key' => 'paypal_enablement',
            'status' => 'disabled',
            'details' => 'This gate cannot allow PAYPAL_ENABLED=true and cannot expose PayPal UI.',
        ];
        $checks[] = [
            'key' => 'artifact_access',
            'status' => 'disabled',
            'details' => 'This gate validates review readiness only and does not read, copy, hash, import, or store evidence artifacts.',
        ];
        $checks[] = [
            'key' => 'provider_calls',
            'status' => 'disabled',
            'details' => 'No PayPal, QPay, LianLian, or network call is made by this readiness gate.',
        ];
        $checks[] = [
            'key' => 'business_mutation',
            'status' => 'disabled',
            'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, statistic, or review row is created or updated.',
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
            '# Mongoyia PayPal External Evidence Manifest Review Readiness',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Manifest review ready: ' . (($report['manifestReviewReady'] ?? false) ? 'yes' : 'no'),
            '- Manifest review started: ' . (($report['manifestReviewStarted'] ?? true) ? 'yes' : 'no'),
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
            '## Review Items',
            '',
            '| Key | Status | Owner role | Required evidence |',
            '|---|---|---|---|',
        ]);
        foreach (($report['reviewItems'] ?? []) as $item) {
            $lines[] = '| ' . $this->escapeCell((string)$item['key'])
                . ' | ' . $this->escapeCell((string)$item['status'])
                . ' | ' . $this->escapeCell((string)$item['owner_role'])
                . ' | ' . $this->escapeCell((string)$item['required_evidence'])
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
            '- manifest_review_ready=1 means the sanitized manifest can enter manual review, not that review has started.',
            '- manifest_review_started=0 remains intentional; this gate does not assign or start reviewers.',
            '- manifest_review_accepted=0 remains intentional until external business/security/technical reviewers approve the manifest.',
            '- evidence_bundle_accepted=0 remains intentional until real sanitized evidence and approvals are externally accepted.',
            '- paypal_enablement_allowed=0 remains intentional; this gate cannot turn on PayPal.',
            '- The gate does not read, copy, hash, import, or store evidence artifacts.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, statistic, or review row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,status,owner_role,required_evidence'];
        foreach (($report['reviewItems'] ?? []) as $item) {
            $lines[] = implode(',', [
                $this->csvCell((string)$item['key']),
                $this->csvCell((string)$item['status']),
                $this->csvCell((string)$item['owner_role']),
                $this->csvCell((string)$item['required_evidence']),
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
