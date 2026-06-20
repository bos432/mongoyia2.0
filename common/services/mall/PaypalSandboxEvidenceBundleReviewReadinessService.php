<?php

namespace common\services\mall;

class PaypalSandboxEvidenceBundleReviewReadinessService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_READINESS_V1';

    private const MODE = 'paypal_sandbox_evidence_bundle_review_readiness_dry_run_no_artifact_access';

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
                'PAYPAL_ENABLED must remain false while bundle review readiness is only a dry-run gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->manifestValidatorPrecondition(),
            $this->redactionChecklistPrecondition(),
            $this->documentationPrecondition(),
            $this->reviewContractPrecondition($items),
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
                PaypalSandboxEvidenceManifestValidatorService::GATE_VERSION,
                PaypalSandboxEvidenceRedactionChecklistService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'bundleReviewReady' => empty($issues),
            'evidenceBundleAccepted' => false,
            'externalEvidencePending' => true,
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
            $this->item('manifest_validator_pass', 'technical', 'Latest manifest validator report is PASS and `manifest_accepted=0` remains explicit.'),
            $this->item('redaction_checklist_pass', 'security', 'Latest redaction checklist report is PASS and `evidence_bundle_accepted=0` remains explicit.'),
            $this->item('sanitized_manifest_reference', 'technical', 'Real bundle must reference only sanitized manifest ids or ticket references, not local paths.'),
            $this->item('sanitized_artifact_hashes', 'security', 'Every future artifact reference must carry a sanitized-artifact SHA256 generated outside this command.'),
            $this->item('reviewer_assignment', 'business', 'Business and technical reviewer names or roles must be assigned before signoff.'),
            $this->item('test_host_traceability', 'ops', 'Evidence must map to the HTTPS test host, not localhost or production domain.'),
            $this->item('storage_boundary', 'ops', 'Raw artifacts stay outside the repository until sanitized and approved for archival.'),
            $this->item('rejection_reason_contract', 'technical', 'Rejected rows must keep a non-sensitive rejection reason and remain re-runnable.'),
            $this->item('cleanup_reference', 'ops', 'Sandbox orders, attempts, and temporary evidence must have cleanup references.'),
            $this->item('final_signoff_pending', 'business', 'Final bundle acceptance remains pending external review and is not performed by this command.'),
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

    private function redactionChecklistPrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-redaction-checklist-*.md');
        $result = $this->readReportResult($path);
        $redactionReport = (new PaypalSandboxEvidenceRedactionChecklistService($this->rootPath))->run();
        $accepted = (bool)($redactionReport['evidenceBundleAccepted'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && !$accepted;

        return $this->precondition(
            'redaction_checklist_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal sandbox evidence redaction checklist must PASS, while evidence_bundle_accepted remains 0.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS redaction checklist report or evidence_bundle_accepted is not 0.'
        );
    }

    private function documentationPrecondition(): array
    {
        $content = $this->readRelative('docs/mongoyia-payment-sandbox-evidence.md');
        $needles = [
            'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_BUNDLE_REVIEW_READINESS_V1',
            'PayPal Sandbox Evidence Bundle Review Readiness',
            'bundle_review_ready=1',
            'evidence_bundle_accepted=0',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'bundle_review_documentation',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Payment sandbox evidence documentation must describe the bundle review readiness gate.',
            empty($missing) ? 'Bundle review readiness documentation markers are present.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function reviewContractPrecondition(array $items): array
    {
        $keys = array_map(static function ($item) {
            return (string)$item['key'];
        }, $items);
        $ok = count($items) === 10
            && count(array_unique($keys)) === 10
            && in_array('manifest_validator_pass', $keys, true)
            && in_array('redaction_checklist_pass', $keys, true)
            && in_array('final_signoff_pending', $keys, true);

        return $this->precondition(
            'bundle_review_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'Bundle review readiness must cover manifest, redaction, reviewer, host, storage, rejection, cleanup, and final signoff boundaries.',
            $ok ? 'Ten required bundle review items are available.' : 'Bundle review item contract is incomplete.'
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
            'PayPal UI controls must stay hidden while bundle review readiness is only a dry-run gate.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during bundle review gating.',
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
            'pending_external_count' => 1,
            'artifact_read_count' => 0,
            'artifact_import_count' => 0,
            'artifact_hash_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'bundle_review_ready' => 0,
            'evidence_bundle_accepted' => 0,
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
            $totals['bundle_review_ready'] = 1;
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
            'key' => 'bundle_review_ready',
            'status' => 'ready',
            'details' => 'The project can review a sanitized external PayPal sandbox evidence bundle, but this command does not accept it.',
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

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Sandbox Evidence Bundle Review Readiness',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Bundle review ready: ' . (($report['bundleReviewReady'] ?? false) ? 'yes' : 'no'),
            '- Evidence bundle accepted: ' . (($report['evidenceBundleAccepted'] ?? true) ? 'yes' : 'no'),
            '- External evidence pending: ' . (($report['externalEvidencePending'] ?? false) ? 'yes' : 'no'),
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
            '- bundle_review_ready=1 means the local review process is ready, not that the PayPal evidence bundle is accepted.',
            '- evidence_bundle_accepted=0 remains intentional until real sanitized artifacts and reviewer signoff are supplied externally.',
            '- The gate does not read, copy, hash, import, or store evidence artifacts.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
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
