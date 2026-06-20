<?php

namespace common\services\mall;

class PaypalLiveVerificationEnablementGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_LIVE_VERIFICATION_ENABLEMENT_GATE_V1';

    private const MODE = 'paypal_live_verification_enablement_readiness_disabled_by_default';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $paypalEnabled = $this->envBool('PAYPAL_ENABLED', false);
        $preconditions = [];

        $preconditions[] = $this->precondition(
            'paypal_enabled_flag',
            !$paypalEnabled,
            $paypalEnabled ? 'blocked' : 'disabled',
            'PAYPAL_ENABLED must remain false until live verification, sandbox evidence, audit writes, UI rollout, regression, and cleanup land together.',
            $paypalEnabled ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
        );

        $envTemplate = $this->envTemplatePrecondition();
        $preconditions[] = $envTemplate;

        $secretValues = $this->secretValuePrecondition();
        $preconditions[] = $secretValues;

        foreach ($this->evidencePreconditions() as $precondition) {
            $preconditions[] = $precondition;
        }

        $preconditions[] = $this->uiHiddenPrecondition();
        $preconditions[] = $this->providerApiBoundaryPrecondition();
        $preconditions[] = $this->precondition(
            'sandbox_evidence',
            false,
            'pending',
            'Non-sensitive PayPal sandbox evidence is still required before enabling live verification.',
            'docs/mongoyia-payment-sandbox-evidence.md remains a future external-input artifact for PayPal.'
        );
        $preconditions[] = $this->precondition(
            'live_provider_implementation',
            false,
            'pending',
            'Live PayPal verification and payment-attempt writes are not implemented in runtime code by design.',
            'Current commands are dry-run and readiness gates only.'
        );

        $issues = [];
        foreach ($preconditions as $precondition) {
            if (($precondition['status'] ?? '') === 'blocked') {
                $issues[] = (string)$precondition['key'] . ': ' . (string)$precondition['evidence'];
            }
        }

        $enablementAllowed = $this->enablementAllowed($preconditions);

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalWebhookDryRunGateService::GATE_VERSION,
                PaypalWebhookVerificationDryRunService::GATE_VERSION,
                PaypalWebhookAuditDryRunService::GATE_VERSION,
                PaypalExternalEvidenceManifestReviewResultApplyGateService::GATE_VERSION,
                PaypalLiveProviderImplementationEvidenceDryRunService::GATE_VERSION,
                PaypalLiveProviderImplementationEvidenceSignoffGateService::GATE_VERSION,
                PaypalLiveExecutionEvidenceReadinessGateService::GATE_VERSION,
                PaypalLiveExecutionEvidenceSignoffImportDryRunService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $paypalEnabled,
            'enablementAllowed' => $enablementAllowed,
            'preconditions' => $preconditions,
            'totals' => $this->totals($preconditions, $enablementAllowed),
            'gateChecks' => $this->gateChecks($preconditions, $enablementAllowed),
            'issues' => $issues,
        ];
    }

    private function envTemplatePrecondition(): array
    {
        $required = [
            'PAYPAL_ENABLED',
            'PAYPAL_SANDBOX',
            'PAYPAL_CLIENT_ID',
            'PAYPAL_CLIENT_SECRET',
            'PAYPAL_WEBHOOK_ID',
            'PAYPAL_CALLBACK_BASE',
            'PAYPAL_RETURN_PATH',
            'PAYPAL_CANCEL_PATH',
            'PAYPAL_WEBHOOK_PATH',
            'PAYPAL_WEBHOOK_HMAC_SECRET',
            'PAYPAL_CURRENCY',
        ];
        $missing = [];

        foreach (['.env.example', '.env.test.example'] as $template) {
            $content = $this->readRelative($template);
            foreach ($required as $key) {
                if (!preg_match('/^' . preg_quote($key, '/') . '=/m', $content)) {
                    $missing[] = $template . ':' . $key;
                }
            }
        }

        return $this->precondition(
            'env_template_contract',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'PayPal environment keys must exist only as redacted placeholders in env templates.',
            empty($missing) ? 'All required PayPal template keys are present.' : 'Missing keys: ' . implode(', ', $missing)
        );
    }

    private function secretValuePrecondition(): array
    {
        $secretKeys = [
            'PAYPAL_CLIENT_SECRET',
            'PAYPAL_WEBHOOK_ID',
            'PAYPAL_WEBHOOK_HMAC_SECRET',
        ];
        $leaks = [];

        foreach (['.env.example', '.env.test.example'] as $template) {
            $values = $this->envValues($template);
            foreach ($secretKeys as $key) {
                $value = trim((string)($values[$key] ?? ''));
                if ($value === '' || strpos($value, 'replace-with-') === 0) {
                    continue;
                }
                $leaks[] = $template . ':' . $key;
            }
        }

        return $this->precondition(
            'repo_secret_values_absent',
            empty($leaks),
            empty($leaks) ? 'ready' : 'blocked',
            'PayPal secret-bearing env template keys must stay empty or placeholder-only.',
            empty($leaks) ? 'No PayPal secret values are present in env templates.' : 'Potential values found: ' . implode(', ', $leaks)
        );
    }

    private function evidencePreconditions(): array
    {
        return [
            $this->evidencePrecondition(
                'webhook_dry_run_evidence',
                'mongoyia-payment-provider-webhook-dry-run-gate-*.md',
                'PayPal webhook dry-run evidence must exist and pass.'
            ),
            $this->evidencePrecondition(
                'webhook_verification_dry_run_evidence',
                'mongoyia-payment-provider-webhook-verification-dry-run-gate-*.md',
                'PayPal webhook verification dry-run evidence must exist and pass.'
            ),
            $this->evidencePrecondition(
                'webhook_audit_dry_run_evidence',
                'mongoyia-payment-provider-webhook-audit-dry-run-*.md',
                'PayPal webhook audit dry-run evidence must exist and pass.'
            ),
            $this->evidencePrecondition(
                'external_manifest_review_result_apply_gate_evidence',
                'mongoyia-payment-provider-paypal-external-evidence-manifest-review-result-apply-gate-*.md',
                'PayPal external evidence manifest review-result apply gate evidence must exist and pass.'
            ),
            $this->evidencePrecondition(
                'live_provider_implementation_evidence_dry_run',
                'mongoyia-payment-provider-paypal-live-provider-implementation-evidence-dry-run-*.md',
                'PayPal live provider implementation evidence dry-run must exist and pass.'
            ),
            $this->evidencePrecondition(
                'live_provider_implementation_evidence_signoff_gate',
                'mongoyia-payment-provider-paypal-live-provider-implementation-evidence-signoff-gate-*.md',
                'PayPal live provider implementation evidence signoff gate must exist and pass.'
            ),
            $this->evidencePrecondition(
                'live_execution_evidence_readiness_gate',
                'mongoyia-payment-provider-paypal-live-execution-evidence-readiness-gate-*.md',
                'PayPal live execution evidence readiness gate must exist and pass.'
            ),
            $this->evidencePrecondition(
                'live_execution_evidence_signoff_import_dry_run',
                'mongoyia-payment-provider-paypal-live-execution-evidence-signoff-import-dry-run-*.md',
                'PayPal live execution evidence signoff import dry-run must exist and pass.'
            ),
        ];
    }

    private function evidencePrecondition(string $key, string $pattern, string $requiredEvidence): array
    {
        $path = $this->latestHandoverFile($pattern);
        $result = $this->readReportResult($path);
        $ok = $path !== '' && $result === 'PASS';

        return $this->precondition(
            $key,
            $ok,
            $ok ? 'pass' : 'blocked',
            $requiredEvidence,
            $ok ? $this->relativePath($path) : 'Missing or non-PASS evidence for pattern ' . $pattern
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
            'PayPal UI controls must stay hidden while PAYPAL_ENABLED=false.',
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
            'Runtime PaymentController must not contain live PayPal API URLs or credential reads.',
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

    private function enablementAllowed(array $preconditions): bool
    {
        foreach ($preconditions as $precondition) {
            if (!($precondition['satisfied'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function totals(array $preconditions, bool $enablementAllowed): array
    {
        $totals = [
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => 0,
            'unsatisfied_precondition_count' => 0,
            'pending_precondition_count' => 0,
            'evidence_pass_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'enablement_allowed' => $enablementAllowed ? 1 : 0,
        ];

        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $totals['satisfied_precondition_count']++;
            } else {
                $totals['unsatisfied_precondition_count']++;
            }
            if (($precondition['status'] ?? '') === 'pending') {
                $totals['pending_precondition_count']++;
            }
            if (strpos((string)$precondition['key'], '_evidence') !== false && ($precondition['status'] ?? '') === 'pass') {
                $totals['evidence_pass_count']++;
            }
        }

        return $totals;
    }

    private function gateChecks(array $preconditions, bool $enablementAllowed): array
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
            'key' => 'enablement_decision',
            'status' => $enablementAllowed ? 'allowed' : 'blocked',
            'details' => $enablementAllowed ? 'All preconditions are satisfied.' : 'enablement_allowed=false until pending PayPal sandbox/live production evidence and runtime implementation land.',
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
            '# Mongoyia PayPal Live Verification Enablement Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Enablement allowed: ' . (($report['enablementAllowed'] ?? true) ? 'yes' : 'no'),
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
            '- enablement_allowed=false is intentional for this increment.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- Official verification, live audit writes, sandbox evidence, and cleanup must land before `PAYPAL_ENABLED=true` is allowed.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,status,satisfied,evidence,required_evidence'];
        foreach (($report['preconditions'] ?? []) as $precondition) {
            $lines[] = implode(',', [
                $this->csvCell((string)$precondition['key']),
                $this->csvCell((string)$precondition['status']),
                ($precondition['satisfied'] ?? false) ? '1' : '0',
                $this->csvCell((string)$precondition['evidence']),
                $this->csvCell((string)$precondition['required_evidence']),
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

    private function envValues(string $relativePath): array
    {
        $values = [];
        foreach (preg_split('/\R/', $this->readRelative($relativePath)) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $values[$key] = trim($value, "\"'");
        }

        return $values;
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
