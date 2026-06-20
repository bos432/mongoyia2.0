<?php

namespace common\services\mall;

use common\models\mall\PaymentAttempt;

class PaypalLiveAuditWriteImplementationGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_LIVE_AUDIT_WRITE_IMPLEMENTATION_GATE_V1';

    private const MODE = 'paypal_live_audit_write_implementation_disabled_by_default';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $auditReport = (new PaypalWebhookAuditDryRunService())->run();
        $writeContracts = $this->writeContracts();
        $preconditions = [
            $this->precondition(
                'live_audit_write_enabled',
                true,
                'disabled',
                'PayPal live mall_payment_attempt writes must stay disabled until official verification, sandbox evidence, UI controls, regression, and cleanup all land.',
                'live_audit_write_enabled=0'
            ),
            $this->precondition(
                'paypal_runtime_disabled',
                !$this->envBool('PAYPAL_ENABLED', false),
                !$this->envBool('PAYPAL_ENABLED', false) ? 'disabled' : 'blocked',
                'PAYPAL_ENABLED must remain false for this implementation gate.',
                $this->envBool('PAYPAL_ENABLED', false) ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->paymentAttemptModelPrecondition(),
            $this->auditPlanPrecondition($auditReport),
            $this->writeContractPrecondition($writeContracts),
            $this->idempotencyPrecondition($auditReport),
            $this->cleanupScopePrecondition(),
            $this->sandboxEvidenceGatePrecondition(),
            $this->precondition(
                'sandbox_evidence_ready',
                false,
                'pending_external',
                'Real PayPal sandbox evidence must still be collected on the HTTPS test domain before enabling writes.',
                'sandbox_evidence_ready=0'
            ),
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
                PaypalWebhookAuditDryRunService::GATE_VERSION,
                PaypalSandboxEvidenceGateService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'runtimeEnabled' => $this->envBool('PAYPAL_ENABLED', false),
            'liveAuditWriteEnabled' => false,
            'sandboxEvidenceReady' => false,
            'writeContracts' => $writeContracts,
            'preconditions' => $preconditions,
            'auditRows' => $auditReport['auditRows'] ?? [],
            'totals' => $this->totals($preconditions, $writeContracts, $auditReport),
            'gateChecks' => $this->gateChecks($preconditions),
            'issues' => $issues,
        ];
    }

    private function writeContracts(): array
    {
        return [
            $this->writeContract('paypal_create_attempt', 'create', [PaymentAttempt::RESULT_PENDING, PaymentAttempt::RESULT_FAILED], [
                'provider',
                'event',
                'result',
                'merchant_transaction_id',
                'gateway_transaction_id',
                'amount',
                'currency',
                'business_key',
                'payload_hash',
                'redacted_payload',
            ]),
            $this->writeContract('paypal_return_attempt', 'return', [PaymentAttempt::RESULT_DISPLAY], [
                'provider',
                'event',
                'result',
                'merchant_transaction_id',
                'gateway_transaction_id',
                'amount',
                'currency',
                'business_key',
                'payload_hash',
                'redacted_payload',
            ]),
            $this->writeContract('paypal_cancel_attempt', 'cancel', [PaymentAttempt::RESULT_IGNORED], [
                'provider',
                'event',
                'result',
                'merchant_transaction_id',
                'business_key',
                'payload_hash',
                'redacted_payload',
            ]),
            $this->writeContract('paypal_webhook_success', 'webhook', [PaymentAttempt::RESULT_SUCCESS], [
                'provider',
                'event',
                'result',
                'merchant_transaction_id',
                'gateway_transaction_id',
                'gateway_event_id',
                'amount',
                'currency',
                'business_key',
                'payload_hash',
                'redacted_payload',
            ]),
            $this->writeContract('paypal_webhook_rejected', 'webhook', [PaymentAttempt::RESULT_FAILED], [
                'provider',
                'event',
                'result',
                'merchant_transaction_id',
                'gateway_transaction_id',
                'gateway_event_id',
                'amount',
                'currency',
                'business_key',
                'payload_hash',
                'redacted_payload',
            ]),
            $this->writeContract('paypal_webhook_duplicate', 'webhook', [PaymentAttempt::RESULT_IGNORED], [
                'provider',
                'event',
                'result',
                'merchant_transaction_id',
                'gateway_transaction_id',
                'gateway_event_id',
                'amount',
                'currency',
                'business_key',
                'payload_hash',
                'redacted_payload',
            ]),
        ];
    }

    private function writeContract(string $key, string $event, array $results, array $requiredFields): array
    {
        return [
            'key' => $key,
            'provider' => 'paypal',
            'event' => $event,
            'expected_results' => $results,
            'required_fields' => $requiredFields,
            'write_mode' => 'future_live_write',
        ];
    }

    private function paymentAttemptModelPrecondition(): array
    {
        $content = $this->readRelative('common/models/mall/PaymentAttempt.php');
        $needles = [
            'class PaymentAttempt',
            'const RESULT_SUCCESS',
            'const RESULT_FAILED',
            'const RESULT_IGNORED',
            'provider',
            'event',
            'business_key',
            'gateway_transaction_id',
            'payload_hash',
            'createForOrder',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'payment_attempt_model_contract',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'PaymentAttempt must support provider, event, success/failed/ignored result, business key, gateway id, payload hash, and createForOrder().',
            empty($missing) ? 'PaymentAttempt model supports the future PayPal audit write fields.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function auditPlanPrecondition(array $auditReport): array
    {
        $totals = $auditReport['totals'] ?? [];
        $ok = (int)($totals['audit_plan_count'] ?? 0) === 8
            && (int)($totals['success_count'] ?? 0) === 1
            && (int)($totals['failed_count'] ?? 0) === 6
            && (int)($totals['ignored_count'] ?? 0) === 1
            && (int)($totals['provider_paypal_count'] ?? 0) === 8
            && (int)($totals['dry_run_write_count'] ?? -1) === 0
            && $this->auditRowsHaveRequiredFields($auditReport['auditRows'] ?? []);

        return $this->precondition(
            'webhook_audit_dry_run_plan',
            $ok,
            $ok ? 'ready' : 'blocked',
            'PaypalWebhookAuditDryRunService must provide eight provider=paypal rows: one success, six failed, and one ignored duplicate, with no writes.',
            $ok ? 'Dry-run audit plan is ready for future live write implementation.' : 'Dry-run audit plan totals or required fields are not ready.'
        );
    }

    private function writeContractPrecondition(array $contracts): array
    {
        $events = [];
        $results = [];
        foreach ($contracts as $contract) {
            $events[(string)$contract['event']] = true;
            foreach (($contract['expected_results'] ?? []) as $result) {
                $results[(string)$result] = true;
            }
        }

        $requiredEvents = ['create', 'return', 'cancel', 'webhook'];
        $requiredResults = [
            PaymentAttempt::RESULT_SUCCESS,
            PaymentAttempt::RESULT_FAILED,
            PaymentAttempt::RESULT_IGNORED,
        ];
        $missingEvents = array_values(array_diff($requiredEvents, array_keys($events)));
        $missingResults = array_values(array_diff($requiredResults, array_keys($results)));
        $ok = empty($missingEvents) && empty($missingResults);

        return $this->precondition(
            'future_write_contract_fields',
            $ok,
            $ok ? 'ready' : 'blocked',
            'Future PayPal audit writes must cover create, return, cancel, webhook, success, failed, ignored, amount/currency, business key, gateway ids, payload hash, and redacted payload.',
            $ok ? 'Future PayPal write contract covers required events, results, and audit fields.' : 'Missing events/results: ' . implode(', ', array_merge($missingEvents, $missingResults))
        );
    }

    private function idempotencyPrecondition(array $auditReport): array
    {
        $ok = false;
        foreach (($auditReport['auditRows'] ?? []) as $row) {
            if (($row['validation_decision'] ?? '') === 'reject_duplicate'
                && ($row['result'] ?? '') === PaymentAttempt::RESULT_IGNORED
                && strpos((string)($row['business_key'] ?? ''), 'paypal:webhook:') === 0
            ) {
                $ok = true;
                break;
            }
        }

        return $this->precondition(
            'idempotency_contract',
            $ok,
            $ok ? 'ready' : 'blocked',
            'Duplicate PayPal webhook events must map to RESULT_IGNORED with a stable paypal:webhook business key.',
            $ok ? 'Duplicate PayPal webhook maps to PaymentAttempt::RESULT_IGNORED.' : 'Duplicate webhook ignored row is missing.'
        );
    }

    private function cleanupScopePrecondition(): array
    {
        $content = $this->readRelative('console/controllers/MongoyiaTestCleanupController.php');
        $needles = [
            'payment_attempts',
            'countPaymentAttempts',
            '{{%mall_payment_attempt}}',
            "['order_id' => \$orderIds]",
            'REGPAY-%',
            'WEBFIX-%',
        ];
        $missing = $this->missingNeedles($content, $needles);

        return $this->precondition(
            'cleanup_scope_contract',
            empty($missing),
            empty($missing) ? 'ready' : 'blocked',
            'Future PayPal test attempts must attach to generated REGPAY/WEBFIX orders so mongoyia-test-cleanup can remove them.',
            empty($missing) ? 'Cleanup command covers generated-order payment attempts.' : 'Missing markers: ' . implode(', ', $missing)
        );
    }

    private function sandboxEvidenceGatePrecondition(): array
    {
        $path = $this->latestHandoverFile('mongoyia-payment-provider-paypal-sandbox-evidence-gate-*.md');
        $result = $this->readReportResult($path);
        $sandboxReport = (new PaypalSandboxEvidenceGateService($this->rootPath))->run();
        $ready = (bool)($sandboxReport['sandboxEvidenceReady'] ?? true);
        $ok = $path !== '' && $result === 'PASS' && !$ready;

        return $this->precondition(
            'sandbox_evidence_gate_report',
            $ok,
            $ok ? 'pass' : 'blocked',
            'The PayPal sandbox evidence gate report must exist and PASS, while sandbox_evidence_ready remains 0.',
            $ok ? $this->relativePath($path) : 'Missing/non-PASS sandbox evidence gate report or sandbox_evidence_ready is not 0.'
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
            'PayPal UI controls must stay hidden while live audit writes are disabled.',
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
            'PaymentController must not contain live PayPal API URLs or credential reads during implementation gating.',
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

    private function auditRowsHaveRequiredFields(array $rows): bool
    {
        $fields = [
            'provider',
            'event',
            'result',
            'merchant_transaction_id',
            'gateway_transaction_id',
            'gateway_event_id',
            'business_key',
            'amount',
            'currency',
            'payload_hash',
            'write_mode',
        ];

        foreach ($rows as $row) {
            foreach ($fields as $field) {
                if (!array_key_exists($field, $row)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function totals(array $preconditions, array $writeContracts, array $auditReport): array
    {
        $totals = [
            'precondition_count' => count($preconditions),
            'satisfied_precondition_count' => 0,
            'pending_precondition_count' => 0,
            'write_contract_row_count' => count($writeContracts),
            'write_contract_event_count' => count(array_unique(array_map(static function ($contract) {
                return (string)$contract['event'];
            }, $writeContracts))),
            'audit_plan_count' => (int)($auditReport['totals']['audit_plan_count'] ?? 0),
            'success_count' => (int)($auditReport['totals']['success_count'] ?? 0),
            'failed_count' => (int)($auditReport['totals']['failed_count'] ?? 0),
            'ignored_count' => (int)($auditReport['totals']['ignored_count'] ?? 0),
            'provider_paypal_count' => (int)($auditReport['totals']['provider_paypal_count'] ?? 0),
            'live_audit_write_enabled' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
            'sandbox_evidence_ready' => 0,
        ];

        foreach ($preconditions as $precondition) {
            if ($precondition['satisfied'] ?? false) {
                $totals['satisfied_precondition_count']++;
            }
            if (($precondition['status'] ?? '') === 'pending_external') {
                $totals['pending_precondition_count']++;
            }
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
            'key' => 'future_write_contract',
            'status' => 'ready',
            'details' => 'Future PayPal live audit writes must follow the versioned contract before PAYPAL_ENABLED can become true.',
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
            '# Mongoyia PayPal Live Audit Write Implementation Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Live audit write enabled: ' . (($report['liveAuditWriteEnabled'] ?? true) ? 'yes' : 'no'),
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
            '## Future Write Contract',
            '',
            '| Key | Event | Expected results | Required fields | Write mode |',
            '|---|---|---|---|---|',
        ]);

        foreach (($report['writeContracts'] ?? []) as $contract) {
            $lines[] = '| ' . $this->escapeCell((string)$contract['key'])
                . ' | ' . $this->escapeCell((string)$contract['event'])
                . ' | ' . $this->escapeCell(implode('/', $contract['expected_results'] ?? []))
                . ' | ' . $this->escapeCell(implode(', ', $contract['required_fields'] ?? []))
                . ' | ' . $this->escapeCell((string)$contract['write_mode'])
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
            '## Source Audit Plan',
            '',
            '| Sample | Provider | Event | Result | Business key | Gateway event | Amount | Currency | Payload hash | Write mode |',
            '|---|---|---|---|---|---|---:|---|---|---|',
        ]);

        foreach (($report['auditRows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['sample'])
                . ' | ' . $this->escapeCell((string)$row['provider'])
                . ' | ' . $this->escapeCell((string)$row['event'])
                . ' | ' . $this->escapeCell((string)$row['result'])
                . ' | ' . $this->escapeCell((string)$row['business_key'])
                . ' | ' . $this->escapeCell((string)$row['gateway_event_id'])
                . ' | ' . $this->escapeCell((string)$row['amount'])
                . ' | ' . $this->escapeCell((string)$row['currency'])
                . ' | `' . $this->escapeCell((string)$row['payload_hash'])
                . '` | ' . $this->escapeCell((string)$row['write_mode'])
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
            '- live_audit_write_enabled=0 is intentional for this increment.',
            '- PayPal runtime remains disabled and PayPal UI remains hidden.',
            '- Future live writes must use provider=paypal, versioned idempotency, redacted payloads, and generated-order cleanup scope.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,event,expected_results,required_fields,write_mode'];
        foreach (($report['writeContracts'] ?? []) as $contract) {
            $lines[] = implode(',', [
                $this->csvCell((string)$contract['key']),
                $this->csvCell((string)$contract['event']),
                $this->csvCell(implode('/', $contract['expected_results'] ?? [])),
                $this->csvCell(implode('|', $contract['required_fields'] ?? [])),
                $this->csvCell((string)$contract['write_mode']),
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
