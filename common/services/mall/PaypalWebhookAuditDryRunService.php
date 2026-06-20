<?php

namespace common\services\mall;

use common\models\mall\PaymentAttempt;

class PaypalWebhookAuditDryRunService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_WEBHOOK_AUDIT_DRY_RUN_V1';

    public function run(): array
    {
        $validationReport = (new PaypalWebhookDryRunGateService())->run();
        $expected = $validationReport['expected'] ?? [];
        $rows = [];

        foreach (($validationReport['rows'] ?? []) as $row) {
            $rows[] = $this->auditRow($row, $expected);
        }

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersion' => (string)($validationReport['gateVersion'] ?? ''),
            'mode' => 'payment_attempt_audit_dry_run_only',
            'runtimeEnabled' => false,
            'auditRows' => $rows,
            'totals' => $this->totals($rows),
            'gateChecks' => [
                [
                    'key' => 'audit_contract',
                    'status' => 'ready',
                    'details' => 'Future provider=paypal webhook attempts map to provider, event, result, amount, currency, business key, gateway event, and redacted payload hash fields.',
                ],
                [
                    'key' => 'dry_run_only',
                    'status' => 'ready',
                    'details' => 'This service produces a write plan only and does not insert mall_payment_attempt rows.',
                ],
                [
                    'key' => 'provider_calls',
                    'status' => 'disabled',
                    'details' => 'No PayPal, QPay, LianLian, or network call is made.',
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

    private function auditRow(array $validationRow, array $expected): array
    {
        $decision = (string)($validationRow['decision'] ?? '');
        $reason = (string)($validationRow['reason'] ?? '');
        $eventId = (string)($validationRow['event_id'] ?? '');
        $result = $this->resultForDecision($decision);
        $payloadSeed = [
            'provider' => 'paypal',
            'event' => 'webhook',
            'sample' => (string)($validationRow['sample'] ?? ''),
            'event_id' => $eventId,
            'decision' => $decision,
            'reason' => $reason,
        ];

        return [
            'sample' => (string)($validationRow['sample'] ?? ''),
            'provider' => 'paypal',
            'event' => 'webhook',
            'result' => $result,
            'merchant_transaction_id' => (string)($expected['order_no'] ?? ''),
            'gateway_transaction_id' => $eventId,
            'gateway_event_id' => $eventId,
            'business_key' => mb_substr('paypal:webhook:' . ($eventId !== '' ? $eventId : (string)($validationRow['sample'] ?? 'unknown')), 0, 160, 'UTF-8'),
            'amount' => (string)($expected['amount'] ?? ''),
            'currency' => (string)($expected['currency'] ?? ''),
            'payload_hash' => hash('sha256', json_encode($payloadSeed, JSON_UNESCAPED_SLASHES)),
            'error_message' => $result === PaymentAttempt::RESULT_SUCCESS ? '' : mb_substr($reason, 0, 255, 'UTF-8'),
            'write_mode' => 'dry_run',
            'validation_decision' => $decision,
        ];
    }

    private function resultForDecision(string $decision): string
    {
        if ($decision === 'accept_dry_run') {
            return PaymentAttempt::RESULT_SUCCESS;
        }

        if ($decision === 'reject_duplicate') {
            return PaymentAttempt::RESULT_IGNORED;
        }

        return PaymentAttempt::RESULT_FAILED;
    }

    private function totals(array $rows): array
    {
        $totals = [
            'audit_plan_count' => count($rows),
            'dry_run_write_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'ignored_count' => 0,
            'provider_paypal_count' => 0,
        ];

        foreach ($rows as $row) {
            if (($row['provider'] ?? '') === 'paypal') {
                $totals['provider_paypal_count']++;
            }
            if (($row['result'] ?? '') === PaymentAttempt::RESULT_SUCCESS) {
                $totals['success_count']++;
            } elseif (($row['result'] ?? '') === PaymentAttempt::RESULT_IGNORED) {
                $totals['ignored_count']++;
            } elseif (($row['result'] ?? '') === PaymentAttempt::RESULT_FAILED) {
                $totals['failed_count']++;
            }
        }

        return $totals;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Webhook Audit Dry-run Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Source gate version: ' . (string)($report['sourceGateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
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
            '## Audit Plan',
            '',
            '| Sample | Provider | Event | Result | Business key | Gateway event | Amount | Currency | Payload hash | Error |',
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
                . '` | ' . $this->escapeCell((string)$row['error_message'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- PayPal runtime remains disabled.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, or statistic row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['sample,provider,event,result,merchant_transaction_id,gateway_transaction_id,gateway_event_id,business_key,amount,currency,payload_hash,error_message,write_mode,validation_decision'];
        foreach (($report['auditRows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['sample']),
                $this->csvCell((string)$row['provider']),
                $this->csvCell((string)$row['event']),
                $this->csvCell((string)$row['result']),
                $this->csvCell((string)$row['merchant_transaction_id']),
                $this->csvCell((string)$row['gateway_transaction_id']),
                $this->csvCell((string)$row['gateway_event_id']),
                $this->csvCell((string)$row['business_key']),
                $this->csvCell((string)$row['amount']),
                $this->csvCell((string)$row['currency']),
                $this->csvCell((string)$row['payload_hash']),
                $this->csvCell((string)$row['error_message']),
                $this->csvCell((string)$row['write_mode']),
                $this->csvCell((string)$row['validation_decision']),
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
