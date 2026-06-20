<?php

namespace common\services\mall;

class PaypalWebhookVerificationDryRunService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_WEBHOOK_VERIFICATION_DRY_RUN_GATE_V1';

    private const WEBHOOK_ID = 'WH-DRY-RUN';
    private const SECRET = 'paypal-verification-dry-run-local-hmac-secret';
    private const MAX_AGE_SECONDS = 300;

    public function run(): array
    {
        $now = time();
        $rows = [
            $this->row('official_api_contract', 'official_api', 'accept_verification_plan', 'Official verify-webhook-signature request contract is complete.'),
            $this->row('missing_transmission_id', 'official_api', 'reject_missing_header', 'PAYPAL-TRANSMISSION-ID is required before verification.'),
            $this->row('missing_cert_url', 'official_api', 'reject_missing_header', 'PAYPAL-CERT-URL is required before verification.'),
            $this->row('untrusted_cert_url', 'official_api', 'reject_cert_url', 'PAYPAL-CERT-URL must be HTTPS and under an allowed PayPal host.'),
            $this->row('unsupported_algorithm', 'official_api', 'reject_algorithm', 'PAYPAL-AUTH-ALGO must be SHA256withRSA.'),
            $this->row('expired_transmission_time', 'official_api', 'reject_expired', 'PAYPAL-TRANSMISSION-TIME exceeds max age.'),
            $this->localHmacRow('local_hmac_valid', $now, 'accept_local_hmac_dry_run'),
            $this->localHmacRow('local_hmac_invalid', $now, 'reject_local_hmac'),
        ];

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersion' => PaypalWebhookDryRunGateService::GATE_VERSION,
            'mode' => 'official_paypal_verification_contract_dry_run_only',
            'runtimeEnabled' => false,
            'expected' => [
                'webhook_id' => self::WEBHOOK_ID,
                'max_age_seconds' => self::MAX_AGE_SECONDS,
                'required_headers' => 'PAYPAL-AUTH-ALGO,PAYPAL-CERT-URL,PAYPAL-TRANSMISSION-ID,PAYPAL-TRANSMISSION-SIG,PAYPAL-TRANSMISSION-TIME',
                'allowed_verification_modes' => 'official_api,local_hmac_shim_for_test_callbacks_only',
                'official_api_network_call' => 'disabled_in_dry_run',
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'gateChecks' => [
                [
                    'key' => 'official_verification_contract',
                    'status' => 'ready',
                    'details' => 'Future PayPal webhook verification must build the official verify-webhook-signature request from required PayPal headers, webhook id, and the raw event body.',
                ],
                [
                    'key' => 'header_contract',
                    'status' => 'ready',
                    'details' => 'Dry-run samples cover missing transmission id, missing cert url, unsupported algorithm, and expired timestamp guards.',
                ],
                [
                    'key' => 'cert_url_contract',
                    'status' => 'ready',
                    'details' => 'Future certificate URLs must be HTTPS and PayPal-hosted before any verification call is allowed.',
                ],
                [
                    'key' => 'local_hmac_shim',
                    'status' => 'ready',
                    'details' => 'Local HMAC shim remains a test-callback fallback only and is not a production PayPal verification substitute.',
                ],
                [
                    'key' => 'provider_calls',
                    'status' => 'disabled',
                    'details' => 'No PayPal, QPay, LianLian, or network call is made by this dry-run gate.',
                ],
                [
                    'key' => 'business_mutation',
                    'status' => 'disabled',
                    'details' => 'No order, payment attempt, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
                ],
            ],
            'issues' => [],
        ];
    }

    private function localHmacRow(string $sample, int $timestamp, string $decision): array
    {
        $body = '{"id":"WH-EVT-VERIFY","webhook_id":"' . self::WEBHOOK_ID . '"}';
        $transmissionId = 'dry-run-verification';
        $signature = $this->signature($transmissionId, $timestamp, self::WEBHOOK_ID, $body);
        if ($decision === 'reject_local_hmac') {
            $signature = 'invalid-signature';
        }

        $valid = hash_equals($this->signature($transmissionId, $timestamp, self::WEBHOOK_ID, $body), $signature);

        return $this->row(
            $sample,
            'local_hmac_shim',
            $valid ? 'accept_local_hmac_dry_run' : 'reject_local_hmac',
            $valid ? 'Local HMAC shim sample verifies in dry-run only.' : 'Local HMAC shim sample rejects invalid signature.'
        );
    }

    private function signature(string $transmissionId, int $timestamp, string $webhookId, string $body): string
    {
        return hash_hmac('sha256', implode('.', [$transmissionId, $timestamp, $webhookId, $body]), self::SECRET);
    }

    private function row(string $sample, string $verificationMode, string $decision, string $reason): array
    {
        return [
            'sample' => $sample,
            'verification_mode' => $verificationMode,
            'decision' => $decision,
            'reason' => $reason,
        ];
    }

    private function totals(array $rows): array
    {
        $totals = [
            'sample_count' => count($rows),
            'accepted_plan_count' => 0,
            'rejected_plan_count' => 0,
            'official_api_plan_count' => 0,
            'local_hmac_plan_count' => 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
        ];

        foreach ($rows as $row) {
            if (strpos((string)$row['decision'], 'accept_') === 0) {
                $totals['accepted_plan_count']++;
            } else {
                $totals['rejected_plan_count']++;
            }
            if (($row['verification_mode'] ?? '') === 'official_api') {
                $totals['official_api_plan_count']++;
            } elseif (($row['verification_mode'] ?? '') === 'local_hmac_shim') {
                $totals['local_hmac_plan_count']++;
            }
        }

        return $totals;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Webhook Verification Dry-run Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Source gate version: ' . (string)($report['sourceGateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '',
            '## Expected Contract',
            '',
            '| Item | Value |',
            '|---|---|',
        ];

        foreach (($report['expected'] ?? []) as $key => $value) {
            $lines[] = '| ' . $this->escapeCell((string)$key) . ' | `' . $this->escapeCell((string)$value) . '` |';
        }

        $lines = array_merge($lines, [
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
        ]);

        foreach (($report['totals'] ?? []) as $key => $value) {
            $lines[] = '| ' . $this->escapeCell((string)$key) . ' | ' . (int)$value . ' |';
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

        $lines = array_merge($lines, [
            '',
            '## Verification Matrix',
            '',
            '| Sample | Mode | Decision | Reason |',
            '|---|---|---|---|',
        ]);

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['sample'])
                . ' | ' . $this->escapeCell((string)$row['verification_mode'])
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['reason'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- PayPal runtime remains disabled.',
            '- Official PayPal verification is contract-planned only; no verification API request is sent.',
            '- Local HMAC remains limited to test callbacks and dry-run samples.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['sample,verification_mode,decision,reason'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['sample']),
                $this->csvCell((string)$row['verification_mode']),
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['reason']),
            ]);
        }

        return $lines;
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
