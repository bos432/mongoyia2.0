<?php

namespace common\services\mall;

class PaypalRuntimeContractService
{
    public const CONTRACT_VERSION = 'MONGOYIA_PAYPAL_RUNTIME_CONTRACT_V1';

    public function status(): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'enabled' => false,
            'routeHandlersReady' => true,
            'webhookVerificationReady' => false,
            'uiControlsReady' => false,
            'regressionReady' => false,
            'cleanupReady' => false,
            'currentRuntimeProviders' => ['qpay', 'lianlian'],
            'reservedProvider' => 'paypal',
            'routes' => $this->routeContract(),
            'webhookSignature' => $this->webhookSignatureContract(),
            'enablementPreconditions' => $this->enablementPreconditions(),
        ];
    }

    public function routeContract(): array
    {
        return [
            [
                'name' => 'create',
                'path' => '/mall/payment/paypal',
                'method' => 'POST',
                'enabled' => false,
                'required_marker' => 'MONGOYIA_PAYPAL_CREATE_ROUTE_V1',
            ],
            [
                'name' => 'return',
                'path' => '/mall/payment/paypal-return',
                'method' => 'GET',
                'enabled' => false,
                'required_marker' => 'MONGOYIA_PAYPAL_RETURN_ROUTE_V1',
            ],
            [
                'name' => 'cancel',
                'path' => '/mall/payment/paypal-cancel',
                'method' => 'GET',
                'enabled' => false,
                'required_marker' => 'MONGOYIA_PAYPAL_CANCEL_ROUTE_V1',
            ],
            [
                'name' => 'webhook',
                'path' => '/mall/payment/paypal-webhook',
                'method' => 'POST',
                'enabled' => false,
                'required_marker' => 'MONGOYIA_PAYPAL_WEBHOOK_ROUTE_V1',
            ],
        ];
    }

    public function webhookSignatureContract(): array
    {
        return [
            'enabled' => false,
            'required_headers' => [
                'PAYPAL-AUTH-ALGO',
                'PAYPAL-CERT-URL',
                'PAYPAL-TRANSMISSION-ID',
                'PAYPAL-TRANSMISSION-SIG',
                'PAYPAL-TRANSMISSION-TIME',
            ],
            'required_env' => [
                'PAYPAL_WEBHOOK_ID',
                'PAYPAL_WEBHOOK_HMAC_SECRET',
            ],
            'verification_modes' => [
                'official_paypal_verify_webhook_signature_api',
                'local_hmac_shim_for_test_callbacks_only',
            ],
            'failure_cases' => [
                'missing_signature',
                'invalid_signature',
                'expired_timestamp',
                'wrong_webhook_id',
                'amount_mismatch',
                'duplicate_webhook',
                'non_success_status',
            ],
        ];
    }

    public function enablementPreconditions(): array
    {
        return [
            [
                'key' => 'route_handlers',
                'satisfied' => true,
                'required_evidence' => 'create, return, cancel, and webhook handlers return safe disabled responses while PAYPAL_ENABLED=false',
            ],
            [
                'key' => 'signature_verification',
                'satisfied' => false,
                'required_evidence' => 'official PayPal webhook verification or reviewed local HMAC shim for test callbacks',
            ],
            [
                'key' => 'payment_attempt_audit',
                'satisfied' => false,
                'required_evidence' => 'provider=paypal attempts record create, return, webhook, duplicate, failed, and ignored events',
            ],
            [
                'key' => 'regression_cleanup',
                'satisfied' => false,
                'required_evidence' => 'automated create/return/cancel/webhook regression and cleanup evidence',
            ],
            [
                'key' => 'sandbox_evidence',
                'satisfied' => false,
                'required_evidence' => 'non-sensitive PayPal sandbox callback evidence archived before test-server signoff',
            ],
        ];
    }
}
