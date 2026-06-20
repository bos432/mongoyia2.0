<?php

namespace common\services\mall;

class PaypalSandboxEvidenceGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1';

    private const MODE = 'paypal_sandbox_evidence_contract_only';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $requirements = $this->requirements();
        $localPreconditions = $this->localPreconditions();
        $issues = [];

        foreach ($localPreconditions as $precondition) {
            if (($precondition['status'] ?? '') === 'blocked') {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalWebhookDryRunGateService::GATE_VERSION,
                PaypalWebhookVerificationDryRunService::GATE_VERSION,
                PaypalWebhookAuditDryRunService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'sandboxEvidenceReady' => false,
            'requirements' => $requirements,
            'localPreconditions' => $localPreconditions,
            'totals' => $this->totals($requirements, $localPreconditions),
            'gateChecks' => $this->gateChecks($requirements, $localPreconditions),
            'issues' => $issues,
        ];
    }

    private function requirements(): array
    {
        return [
            $this->requirement('sandbox_credentials_reference', 'pending_external', 'Non-sensitive ticket or screenshot reference showing sandbox client/webhook ids were provisioned on the test server.'),
            $this->requirement('create_order_or_invoice', 'pending_external', 'PayPal sandbox order/create request evidence with credentials and payload secrets redacted.'),
            $this->requirement('approval_return_capture_success', 'pending_external', 'Buyer approval return and capture success evidence on the HTTPS test domain.'),
            $this->requirement('cancel_return', 'pending_external', 'Buyer cancel return evidence that leaves order/payment state unchanged.'),
            $this->requirement('webhook_completed', 'pending_external', 'CHECKOUT.ORDER.APPROVED or PAYMENT.CAPTURE.COMPLETED webhook evidence with official verification result redacted.'),
            $this->requirement('duplicate_webhook_idempotency', 'pending_external', 'Duplicate webhook evidence showing the second event is ignored/idempotent.'),
            $this->requirement('amount_mismatch_rejection', 'pending_external', 'Amount mismatch callback evidence showing rejection and audit trail.'),
            $this->requirement('invalid_signature_rejection', 'pending_external', 'Invalid or missing PayPal transmission signature evidence showing rejection.'),
            $this->requirement('expired_transmission_rejection', 'pending_external', 'Expired PAYPAL-TRANSMISSION-TIME evidence showing replay rejection.'),
            $this->requirement('payment_attempt_backend_visibility', 'pending_external', 'Backend payment-attempt visibility for success, failed, and ignored PayPal events.'),
            $this->requirement('cleanup_evidence', 'pending_external', 'Generated PayPal sandbox orders, attempts, and temporary evidence can be cleaned up.'),
        ];
    }

    private function localPreconditions(): array
    {
        return [
            $this->localPrecondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                'disabled',
                'PAYPAL_ENABLED=false',
                'PayPal must stay disabled until sandbox evidence and live implementation land.'
            ),
            $this->filePrecondition(
                'payment_sandbox_doc',
                'docs/mongoyia-payment-sandbox-evidence.md',
                ['PayPal Sandbox Evidence Gate', 'MONGOYIA_PAYPAL_SANDBOX_EVIDENCE_GATE_V1']
            ),
            $this->evidencePrecondition(
                'webhook_dry_run_evidence',
                'mongoyia-payment-provider-webhook-dry-run-gate-*.md'
            ),
            $this->evidencePrecondition(
                'webhook_verification_dry_run_evidence',
                'mongoyia-payment-provider-webhook-verification-dry-run-gate-*.md'
            ),
            $this->evidencePrecondition(
                'webhook_audit_dry_run_evidence',
                'mongoyia-payment-provider-webhook-audit-dry-run-*.md'
            ),
            $this->uiHiddenPrecondition(),
            $this->providerApiBoundaryPrecondition(),
        ];
    }

    private function requirement(string $key, string $status, string $requiredEvidence): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'evidence_ready' => false,
            'required_evidence' => $requiredEvidence,
        ];
    }

    private function localPrecondition(string $key, bool $satisfied, string $status, string $evidence, string $requiredEvidence): array
    {
        return [
            'key' => $key,
            'satisfied' => $satisfied,
            'status' => $satisfied ? $status : 'blocked',
            'evidence' => $evidence,
            'required_evidence' => $requiredEvidence,
        ];
    }

    private function filePrecondition(string $key, string $relativePath, array $markers): array
    {
        $content = $this->readRelative($relativePath);
        $missing = [];
        foreach ($markers as $marker) {
            if (strpos($content, $marker) === false) {
                $missing[] = $marker;
            }
        }

        return $this->localPrecondition(
            $key,
            empty($missing),
            'ready',
            empty($missing) ? $relativePath : 'Missing markers: ' . implode(', ', $missing),
            'Sandbox evidence documentation must carry the PayPal sandbox gate marker and command.'
        );
    }

    private function evidencePrecondition(string $key, string $pattern): array
    {
        $path = $this->latestHandoverFile($pattern);
        $result = $this->readReportResult($path);
        $ok = $path !== '' && $result === 'PASS';

        return $this->localPrecondition(
            $key,
            $ok,
            'pass',
            $ok ? $this->relativePath($path) : 'Missing or non-PASS evidence for pattern ' . $pattern,
            'Existing PayPal dry-run evidence must pass before collecting sandbox evidence.'
        );
    }

    private function uiHiddenPrecondition(): array
    {
        $content = $this->readRelative('web/resources/mall/default/views/payment/index.php');
        $hidden = strpos($content, '/mall/payment/paypal') === false
            && strpos($content, 'Pay with PayPal') === false
            && strpos($content, 'PAYPAL_CLIENT_ID') === false;

        return $this->localPrecondition(
            'paypal_ui_hidden',
            $hidden,
            'hidden',
            $hidden ? 'Payment page has no PayPal button or client-id marker.' : 'Payment page exposes PayPal markers.',
            'PayPal UI must stay hidden until the sandbox evidence is accepted.'
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

        return $this->localPrecondition(
            'provider_api_boundary',
            empty($found),
            'disabled',
            empty($found) ? 'PaymentController keeps PayPal provider calls and credentials absent.' : 'Found markers: ' . implode(', ', $found),
            'PaymentController must not contain live PayPal API URLs or credential reads during evidence planning.'
        );
    }

    private function totals(array $requirements, array $localPreconditions): array
    {
        $totals = [
            'requirement_count' => count($requirements),
            'pending_requirement_count' => 0,
            'local_precondition_count' => count($localPreconditions),
            'local_precondition_pass_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'sandbox_evidence_ready' => 0,
        ];

        foreach ($requirements as $requirement) {
            if (!($requirement['evidence_ready'] ?? false)) {
                $totals['pending_requirement_count']++;
            }
        }
        foreach ($localPreconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $totals['local_precondition_pass_count']++;
            }
        }

        return $totals;
    }

    private function gateChecks(array $requirements, array $localPreconditions): array
    {
        $checks = [
            [
                'key' => 'sandbox_evidence_contract',
                'status' => 'ready',
                'details' => 'Required non-sensitive PayPal sandbox evidence cases are enumerated for test-server signoff.',
            ],
            [
                'key' => 'sandbox_evidence',
                'status' => 'pending_external',
                'details' => count($requirements) . ' PayPal sandbox evidence cases still require real HTTPS sandbox execution.',
            ],
        ];

        foreach ($localPreconditions as $precondition) {
            $checks[] = [
                'key' => (string)$precondition['key'],
                'status' => (string)$precondition['status'],
                'details' => (string)$precondition['evidence'],
            ];
        }

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
            '# Mongoyia PayPal Sandbox Evidence Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Sandbox evidence ready: ' . (($report['sandboxEvidenceReady'] ?? true) ? 'yes' : 'no'),
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
            '## Required Sandbox Evidence',
            '',
            '| Key | Status | Ready | Required evidence |',
            '|---|---|---:|---|',
        ]);

        foreach (($report['requirements'] ?? []) as $requirement) {
            $lines[] = '| ' . $this->escapeCell((string)$requirement['key'])
                . ' | ' . $this->escapeCell((string)$requirement['status'])
                . ' | ' . (($requirement['evidence_ready'] ?? false) ? '1' : '0')
                . ' | ' . $this->escapeCell((string)$requirement['required_evidence'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Local Preconditions',
            '',
            '| Key | Status | Satisfied | Evidence |',
            '|---|---|---:|---|',
        ]);

        foreach (($report['localPreconditions'] ?? []) as $precondition) {
            $lines[] = '| ' . $this->escapeCell((string)$precondition['key'])
                . ' | ' . $this->escapeCell((string)$precondition['status'])
                . ' | ' . (($precondition['satisfied'] ?? false) ? '1' : '0')
                . ' | ' . $this->escapeCell((string)$precondition['evidence'])
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
            '- sandbox_evidence_ready=0 is intentional until real HTTPS PayPal sandbox evidence is attached.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
            '- Do not store PayPal secrets, auth headers, raw provider private payloads, SSH keys, or real `.env` files in evidence.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,status,evidence_ready,required_evidence'];
        foreach (($report['requirements'] ?? []) as $requirement) {
            $lines[] = implode(',', [
                $this->csvCell((string)$requirement['key']),
                $this->csvCell((string)$requirement['status']),
                ($requirement['evidence_ready'] ?? false) ? '1' : '0',
                $this->csvCell((string)$requirement['required_evidence']),
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
