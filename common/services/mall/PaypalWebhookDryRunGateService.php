<?php

namespace common\services\mall;

class PaypalWebhookDryRunGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_WEBHOOK_DRY_RUN_GATE_V1';

    private const SECRET = 'paypal-dry-run-local-hmac-secret';
    private const WEBHOOK_ID = 'WH-DRY-RUN';
    private const ORDER_NO = 'PAYPAL-DRY-RUN-ORDER';
    private const AMOUNT = '10.00';
    private const CURRENCY = 'USD';
    private const MAX_AGE_SECONDS = 300;

    public function run(): array
    {
        $now = time();
        $samples = [
            $this->sample('valid_completed', 'WH-EVT-OK', self::WEBHOOK_ID, self::ORDER_NO, self::AMOUNT, self::CURRENCY, 'COMPLETED', $now, 'valid'),
            $this->sample('missing_signature', 'WH-EVT-MISSING-SIG', self::WEBHOOK_ID, self::ORDER_NO, self::AMOUNT, self::CURRENCY, 'COMPLETED', $now, 'missing'),
            $this->sample('invalid_signature', 'WH-EVT-BAD-SIG', self::WEBHOOK_ID, self::ORDER_NO, self::AMOUNT, self::CURRENCY, 'COMPLETED', $now, 'invalid'),
            $this->sample('expired_timestamp', 'WH-EVT-EXPIRED', self::WEBHOOK_ID, self::ORDER_NO, self::AMOUNT, self::CURRENCY, 'COMPLETED', $now - self::MAX_AGE_SECONDS - 60, 'valid'),
            $this->sample('wrong_webhook_id', 'WH-EVT-WRONG-ID', 'WH-WRONG', self::ORDER_NO, self::AMOUNT, self::CURRENCY, 'COMPLETED', $now, 'valid'),
            $this->sample('amount_mismatch', 'WH-EVT-AMOUNT', self::WEBHOOK_ID, self::ORDER_NO, '11.00', self::CURRENCY, 'COMPLETED', $now, 'valid'),
            $this->sample('duplicate_webhook', 'WH-EVT-OK', self::WEBHOOK_ID, self::ORDER_NO, self::AMOUNT, self::CURRENCY, 'COMPLETED', $now, 'valid'),
            $this->sample('non_success_status', 'WH-EVT-DENIED', self::WEBHOOK_ID, self::ORDER_NO, self::AMOUNT, self::CURRENCY, 'DENIED', $now, 'valid'),
        ];

        $seenIds = [];
        $rows = [];
        foreach ($samples as $sample) {
            $row = $this->evaluateSample($sample, $seenIds, $now);
            $rows[] = $row;
            if ($row['decision'] === 'accept_dry_run') {
                $seenIds[] = (string)$sample['event_id'];
            }
        }

        return [
            'gateVersion' => self::GATE_VERSION,
            'mode' => 'local_hmac_shim_for_test_callbacks_only',
            'runtimeEnabled' => false,
            'expected' => [
                'webhook_id' => self::WEBHOOK_ID,
                'order_no' => self::ORDER_NO,
                'amount' => self::AMOUNT,
                'currency' => self::CURRENCY,
                'max_age_seconds' => self::MAX_AGE_SECONDS,
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'gateChecks' => [
                [
                    'key' => 'runtime_routes',
                    'status' => 'disabled',
                    'details' => 'PayPal create, return, cancel, and webhook routes remain reserved.',
                ],
                [
                    'key' => 'signature_fixture',
                    'status' => 'ready',
                    'details' => 'Local HMAC shim samples cover valid, missing, invalid, and expired signatures.',
                ],
                [
                    'key' => 'business_validation_fixture',
                    'status' => 'ready',
                    'details' => 'Samples cover wrong webhook id, amount mismatch, duplicate event id, and non-success status.',
                ],
                [
                    'key' => 'provider_calls',
                    'status' => 'disabled',
                    'details' => 'Dry-run gate does not call PayPal, QPay, LianLian, or any network service.',
                ],
                [
                    'key' => 'data_mutation',
                    'status' => 'disabled',
                    'details' => 'Dry-run gate must not create orders, payment attempts, callbacks, chats, files, or fund logs.',
                ],
            ],
            'issues' => [],
        ];
    }

    public function evaluateSample(array $sample, array $seenIds, int $now): array
    {
        $headers = $sample['headers'];
        $payload = $sample['payload'];
        $body = $this->canonicalJson($payload);
        $eventId = (string)($sample['event_id'] ?? '');

        if (($headers['PAYPAL-TRANSMISSION-SIG'] ?? '') === '') {
            return $this->row($sample, 'reject_missing_signature', 'PAYPAL-TRANSMISSION-SIG is required');
        }
        if (abs($now - (int)($headers['PAYPAL-TRANSMISSION-TIME'] ?? 0)) > self::MAX_AGE_SECONDS) {
            return $this->row($sample, 'reject_expired', 'transmission timestamp exceeds max age');
        }
        if ((string)($payload['webhook_id'] ?? '') !== self::WEBHOOK_ID) {
            return $this->row($sample, 'reject_webhook_id', 'webhook id does not match expected contract');
        }
        if (!$this->verifySignature($headers, (string)($payload['webhook_id'] ?? ''), $body)) {
            return $this->row($sample, 'reject_invalid_signature', 'local HMAC signature check failed');
        }
        if ((string)($payload['resource']['status'] ?? '') !== 'COMPLETED') {
            return $this->row($sample, 'reject_status', 'PayPal capture status is not COMPLETED');
        }
        if ((string)($payload['resource']['invoice_id'] ?? '') !== self::ORDER_NO) {
            return $this->row($sample, 'reject_order', 'merchant order number does not match expected contract');
        }
        if ((string)($payload['resource']['amount']['value'] ?? '') !== self::AMOUNT || (string)($payload['resource']['amount']['currency_code'] ?? '') !== self::CURRENCY) {
            return $this->row($sample, 'reject_amount', 'amount or currency does not match expected order total');
        }
        if (in_array($eventId, $seenIds, true)) {
            return $this->row($sample, 'reject_duplicate', 'webhook event id was already accepted in this dry-run batch');
        }

        return $this->row($sample, 'accept_dry_run', 'sample would be accepted by dry-run validation only');
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Webhook Dry-run Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
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
            '## Sample Matrix',
            '',
            '| Sample | Event ID | Decision | Reason |',
            '|---|---|---|---|',
        ]);

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['sample'])
                . ' | ' . $this->escapeCell((string)$row['event_id'])
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['reason'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- PayPal runtime remains disabled.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No order, payment attempt, callback audit, chat, file, shipment, fund, or statistic row is created or updated.',
            '- This dry-run uses a local non-secret HMAC shim only to prove callback validation branches before real PayPal sandbox integration.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['sample,event_id,decision,reason'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['sample']),
                $this->csvCell((string)$row['event_id']),
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['reason']),
            ]);
        }

        return $lines;
    }

    private function sample(string $name, string $eventId, string $webhookId, string $orderNo, string $amount, string $currency, string $status, int $timestamp, string $signatureMode): array
    {
        $payload = [
            'id' => $eventId,
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'webhook_id' => $webhookId,
            'resource' => [
                'invoice_id' => $orderNo,
                'status' => $status,
                'amount' => [
                    'value' => $amount,
                    'currency_code' => $currency,
                ],
            ],
        ];
        $body = $this->canonicalJson($payload);
        $transmissionId = 'dry-run-' . strtolower(str_replace('_', '-', $name));
        $headers = [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api-m.sandbox.paypal.com/certs/dry-run.pem',
            'PAYPAL-TRANSMISSION-ID' => $transmissionId,
            'PAYPAL-TRANSMISSION-TIME' => (string)$timestamp,
            'PAYPAL-TRANSMISSION-SIG' => $this->signature($transmissionId, $timestamp, $webhookId, $body),
        ];
        if ($signatureMode === 'missing') {
            unset($headers['PAYPAL-TRANSMISSION-SIG']);
        } elseif ($signatureMode === 'invalid') {
            $headers['PAYPAL-TRANSMISSION-SIG'] = 'invalid-signature';
        }

        return [
            'name' => $name,
            'event_id' => $eventId,
            'headers' => $headers,
            'payload' => $payload,
        ];
    }

    private function verifySignature(array $headers, string $webhookId, string $body): bool
    {
        $expected = $this->signature(
            (string)($headers['PAYPAL-TRANSMISSION-ID'] ?? ''),
            (int)($headers['PAYPAL-TRANSMISSION-TIME'] ?? 0),
            $webhookId,
            $body
        );

        return hash_equals($expected, (string)($headers['PAYPAL-TRANSMISSION-SIG'] ?? ''));
    }

    private function signature(string $transmissionId, int $timestamp, string $webhookId, string $body): string
    {
        return hash_hmac('sha256', implode('.', [$transmissionId, $timestamp, $webhookId, $body]), self::SECRET);
    }

    private function canonicalJson(array $payload): string
    {
        return (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function row(array $sample, string $decision, string $reason): array
    {
        return [
            'sample' => (string)$sample['name'],
            'event_id' => (string)$sample['event_id'],
            'decision' => $decision,
            'reason' => $reason,
        ];
    }

    private function totals(array $rows): array
    {
        $totals = [
            'sample_count' => count($rows),
            'accept_dry_run_count' => 0,
            'reject_missing_signature_count' => 0,
            'reject_invalid_signature_count' => 0,
            'reject_expired_count' => 0,
            'reject_webhook_id_count' => 0,
            'reject_amount_count' => 0,
            'reject_duplicate_count' => 0,
            'reject_status_count' => 0,
        ];
        foreach ($rows as $row) {
            $key = (string)$row['decision'] . '_count';
            if (isset($totals[$key])) {
                $totals[$key]++;
            }
        }

        return $totals;
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
