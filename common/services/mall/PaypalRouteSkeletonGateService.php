<?php

namespace common\services\mall;

class PaypalRouteSkeletonGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_ROUTE_SKELETON_GATE_V1';

    public function run(): array
    {
        $routes = $this->routeSkeletons();
        $auditFields = $this->auditFields();
        $cleanupScopes = $this->cleanupScopes();
        $preconditions = $this->enablementPreconditions();

        return [
            'gateVersion' => self::GATE_VERSION,
            'runtimeEnabled' => false,
            'routes' => $routes,
            'auditFields' => $auditFields,
            'cleanupScopes' => $cleanupScopes,
            'enablementPreconditions' => $preconditions,
            'totals' => [
                'route_count' => count($routes),
                'disabled_route_count' => $this->countByValue($routes, 'enabled', false),
                'audit_field_count' => count($auditFields),
                'cleanup_scope_count' => count($cleanupScopes),
                'precondition_count' => count($preconditions),
                'unsatisfied_precondition_count' => $this->countByValue($preconditions, 'satisfied', false),
            ],
            'gateChecks' => [
                [
                    'key' => 'paypal_runtime',
                    'status' => 'disabled',
                    'details' => 'PAYPAL_ENABLED must remain false and PayPal PaymentController actions must return safe disabled responses.',
                ],
                [
                    'key' => 'route_skeletons',
                    'status' => 'ready',
                    'details' => 'Create, return, cancel, and webhook route contracts are documented before implementation.',
                ],
                [
                    'key' => 'audit_contract',
                    'status' => 'ready',
                    'details' => 'Future provider=paypal attempts must carry order, amount, gateway, result, failure, and redacted payload metadata.',
                ],
                [
                    'key' => 'cleanup_contract',
                    'status' => 'ready',
                    'details' => 'Future PayPal sandbox fixtures must be removable before acceptance signoff.',
                ],
                [
                    'key' => 'provider_calls',
                    'status' => 'disabled',
                    'details' => 'This gate does not call PayPal, QPay, LianLian, or any network service.',
                ],
                [
                    'key' => 'business_mutation',
                    'status' => 'disabled',
                    'details' => 'This gate must not create orders, payment attempts, callbacks, chats, files, shipments, funds, or statistics.',
                ],
            ],
            'issues' => [],
        ];
    }

    public function routeSkeletons(): array
    {
        return [
            [
                'name' => 'create',
                'path' => '/mall/payment/paypal',
                'method' => 'POST',
                'future_action' => 'actionPaypal',
                'feature_flag' => 'PAYPAL_ENABLED',
                'enabled' => false,
                'required_inputs' => ['order_id', 'csrf_token', 'authenticated_user'],
                'audit_events' => ['paypal_create_requested', 'paypal_create_rejected', 'paypal_create_redirected'],
                'disabled_response' => 'reserved_not_found',
            ],
            [
                'name' => 'return',
                'path' => '/mall/payment/paypal-return',
                'method' => 'GET',
                'future_action' => 'actionPaypalReturn',
                'feature_flag' => 'PAYPAL_ENABLED',
                'enabled' => false,
                'required_inputs' => ['token', 'payer_id', 'merchant_order_no'],
                'audit_events' => ['paypal_return_received', 'paypal_return_rejected', 'paypal_return_pending_capture'],
                'disabled_response' => 'reserved_not_found',
            ],
            [
                'name' => 'cancel',
                'path' => '/mall/payment/paypal-cancel',
                'method' => 'GET',
                'future_action' => 'actionPaypalCancel',
                'feature_flag' => 'PAYPAL_ENABLED',
                'enabled' => false,
                'required_inputs' => ['token', 'merchant_order_no'],
                'audit_events' => ['paypal_cancel_received', 'paypal_cancel_ignored'],
                'disabled_response' => 'reserved_not_found',
            ],
            [
                'name' => 'webhook',
                'path' => '/mall/payment/paypal-webhook',
                'method' => 'POST',
                'future_action' => 'actionPaypalWebhook',
                'feature_flag' => 'PAYPAL_ENABLED',
                'enabled' => false,
                'required_inputs' => ['raw_body', 'paypal_transmission_headers', 'webhook_id'],
                'audit_events' => ['paypal_webhook_received', 'paypal_webhook_completed', 'paypal_webhook_rejected', 'paypal_webhook_duplicate'],
                'disabled_response' => 'reserved_not_found',
            ],
        ];
    }

    public function auditFields(): array
    {
        return [
            'provider',
            'pay_order_sn',
            'order_id',
            'amount',
            'currency',
            'gateway_order_id',
            'gateway_transaction_id',
            'gateway_event_id',
            'result',
            'failure_reason',
            'redacted_payload_hash',
            'received_at',
        ];
    }

    public function cleanupScopes(): array
    {
        return [
            'PAYPALRT-* parent order fixtures',
            'PAYPALRT-* child order fixtures',
            'provider=paypal payment_attempt fixtures',
            'gateway_event_id=WH-DRY-RUN* audit fixtures',
            'temporary PayPal handover evidence CSV/Markdown references',
        ];
    }

    public function enablementPreconditions(): array
    {
        return [
            [
                'key' => 'disabled_by_default_routes',
                'satisfied' => true,
                'required_evidence' => 'PaymentController handlers exist behind PAYPAL_ENABLED=false and return safe disabled responses until configured.',
            ],
            [
                'key' => 'official_webhook_verification',
                'satisfied' => false,
                'required_evidence' => 'PayPal official webhook verification or reviewed gateway-facing HMAC shim is implemented.',
            ],
            [
                'key' => 'payment_attempt_audit',
                'satisfied' => false,
                'required_evidence' => 'provider=paypal attempts record create, return, cancel, webhook, duplicate, failed, and ignored events.',
            ],
            [
                'key' => 'ui_rollout_control',
                'satisfied' => false,
                'required_evidence' => 'Frontend/PWA PayPal controls appear only when PAYPAL_ENABLED=true.',
            ],
            [
                'key' => 'sandbox_regression',
                'satisfied' => false,
                'required_evidence' => 'Create, return, cancel, webhook success, duplicate, invalid signature, and amount mismatch flows pass in sandbox.',
            ],
            [
                'key' => 'cleanup_and_rollback',
                'satisfied' => false,
                'required_evidence' => 'Generated PayPal sandbox orders, payment attempts, and evidence can be cleaned up and rollback steps are documented.',
            ],
        ];
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Route Skeleton Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
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
            '## Route Skeletons',
            '',
            '| Name | Method | Path | Future action | Enabled | Audit events |',
            '|---|---|---|---|---|---|',
        ]);

        foreach (($report['routes'] ?? []) as $route) {
            $lines[] = '| ' . $this->escapeCell((string)$route['name'])
                . ' | ' . $this->escapeCell((string)$route['method'])
                . ' | `' . $this->escapeCell((string)$route['path'])
                . '` | `' . $this->escapeCell((string)$route['future_action'])
                . '` | ' . (($route['enabled'] ?? true) ? 'yes' : 'no')
                . ' | ' . $this->escapeCell(implode(', ', $route['audit_events']))
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Audit Fields',
            '',
            implode(', ', array_map([$this, 'escapeCell'], $report['auditFields'] ?? [])),
            '',
            '## Cleanup Scopes',
            '',
        ]);

        foreach (($report['cleanupScopes'] ?? []) as $scope) {
            $lines[] = '- ' . $scope;
        }

        $lines = array_merge($lines, [
            '',
            '## Enablement Preconditions',
            '',
            '| Key | Satisfied | Required evidence |',
            '|---|---|---|',
        ]);

        foreach (($report['enablementPreconditions'] ?? []) as $precondition) {
            $lines[] = '| ' . $this->escapeCell((string)$precondition['key'])
                . ' | ' . (($precondition['satisfied'] ?? true) ? 'yes' : 'no')
                . ' | ' . $this->escapeCell((string)$precondition['required_evidence'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- PayPal runtime remains disabled.',
            '- PayPal route handlers are present in `PaymentController` but return safe disabled responses while `PAYPAL_ENABLED=false`.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No order, payment attempt, callback audit, chat, file, shipment, fund, or statistic row is created or updated.',
        ]);

        return $lines;
    }

    public function csvLines(array $report): array
    {
        $lines = ['name,method,path,future_action,enabled,audit_events'];
        foreach (($report['routes'] ?? []) as $route) {
            $lines[] = implode(',', [
                $this->csvCell((string)$route['name']),
                $this->csvCell((string)$route['method']),
                $this->csvCell((string)$route['path']),
                $this->csvCell((string)$route['future_action']),
                ($route['enabled'] ?? true) ? '1' : '0',
                $this->csvCell(implode(';', $route['audit_events'])),
            ]);
        }

        return $lines;
    }

    private function countByValue(array $rows, string $key, $expected): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $expected) {
                $count++;
            }
        }

        return $count;
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
