<?php

namespace console\controllers;

use common\services\mall\PaypalRuntimeContractService;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentProviderReadinessController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $profile = 'local';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $strict = false;
    public $failOnPending = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'profile',
            'handoverDir',
            'outputPath',
            'strict',
            'failOnPending',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia payment provider readiness\n");

        $this->checkProviderContract();
        $this->checkEnvironmentTemplates();
        $this->checkSecurityScanCoverage();
        $this->checkCurrentProviderBoundaries();
        $this->checkPaypalRuntimeContract();
        $this->checkPaypalRuntimeGate();

        $result = $this->result();
        $path = $this->writeReport($result);
        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0)) || ($this->failOnPending && $this->pending > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkProviderContract(): void
    {
        $this->requireFileMarkers('Versioned payment provider contract', 'docs/mongoyia-payment-provider-contract.md', [
            '# Mongoyia Payment Provider Contract',
            'Contract version: 2026-06-19-payment-provider-v1',
            'MONGOYIA_PAYMENT_PROVIDER_CONTRACT_V1',
            'MONGOYIA_PAYPAL_PROVIDER_RESERVED_V1',
            'MONGOYIA_PAYPAL_RUNTIME_CONTRACT_V1',
            '`PAYPAL_ENABLED=false`',
            '`PAYPAL_CLIENT_SECRET`',
            '`/mall/payment/paypal-webhook`',
            'No runtime enablement',
        ]);
    }

    private function checkEnvironmentTemplates(): void
    {
        foreach (['.env.example', '.env.test.example'] as $path) {
            $this->requireFileMarkers("PayPal env template {$path}", $path, [
                'PAYPAL_ENABLED=false',
                'PAYPAL_SANDBOX=true',
                'PAYPAL_CLIENT_ID',
                'PAYPAL_CLIENT_SECRET',
                'PAYPAL_WEBHOOK_ID',
                'PAYPAL_CALLBACK_BASE',
                'PAYPAL_RETURN_PATH=/mall/payment/paypal-return',
                'PAYPAL_CANCEL_PATH=/mall/payment/paypal-cancel',
                'PAYPAL_WEBHOOK_PATH=/mall/payment/paypal-webhook',
                'PAYPAL_WEBHOOK_HMAC_SECRET',
                'PAYPAL_CURRENCY=USD',
            ]);
        }
    }

    private function checkSecurityScanCoverage(): void
    {
        $this->requireFileMarkers('Payment credential security scan coverage', 'console/controllers/MongoyiaSecurityScanController.php', [
            'PAYPAL_CLIENT_SECRET',
            'PAYPAL_WEBHOOK_HMAC_SECRET',
            'Payment credential appears hardcoded in source.',
        ]);
    }

    private function checkCurrentProviderBoundaries(): void
    {
        $paymentController = 'frontend/modules/mall/controllers/PaymentController.php';
        $this->requireFileMarkers('Existing QPay/LianLian payment routes preserved', $paymentController, [
            'public function actionQpay',
            'public function actionQpayres',
            'public function actionLianlian',
            'public function actionSucceeded',
            "buildCallbackUrl('QPAY_CALLBACK_BASE'",
            "buildCallbackUrl('LIANLIAN_CALLBACK_BASE'",
            "PaymentAttempt::RESULT_IGNORED",
            'assertCallbackSignature',
            'assertCallbackTimestamp',
        ]);

        $this->requireFileMarkers('PayPal disabled route handlers guarded', $paymentController, [
            'public function actionPaypal',
            'public function actionPaypalReturn',
            'public function actionPaypalCancel',
            'public function actionPaypalWebhook',
            'MONGOYIA_PAYPAL_CREATE_ROUTE_V1',
            'MONGOYIA_PAYPAL_RETURN_ROUTE_V1',
            'MONGOYIA_PAYPAL_CANCEL_ROUTE_V1',
            'MONGOYIA_PAYPAL_WEBHOOK_ROUTE_V1',
            'paypalDisabledRoute',
            "env('PAYPAL_ENABLED'",
            'PayPal payment is disabled',
        ]);

        $this->requireFileMissingMarkers('PayPal provider credentials remain outside runtime route skeleton', $paymentController, [
            'PAYPAL_CLIENT_SECRET',
            'PAYPAL_WEBHOOK_ID',
            'api-m.paypal.com',
            'api-m.sandbox.paypal.com',
            'Pay with PayPal',
        ]);
        $this->requireFileMissingMarkers('PayPal UI remains reserved', 'web/resources/mall/default/views/payment/index.php', [
            '/mall/payment/paypal',
            'Pay with PayPal',
            'PAYPAL_CLIENT_ID',
        ]);
    }

    private function checkPaypalRuntimeContract(): void
    {
        $this->requireFileMarkers('PayPal runtime contract service', 'common/services/mall/PaypalRuntimeContractService.php', [
            'class PaypalRuntimeContractService',
            'MONGOYIA_PAYPAL_RUNTIME_CONTRACT_V1',
            'routeContract',
            'webhookSignatureContract',
            'enablementPreconditions',
            "'enabled' => false",
            'official_paypal_verify_webhook_signature_api',
        ]);

        $status = (new PaypalRuntimeContractService())->status();
        if (($status['enabled'] ?? true) !== false) {
            $this->addCheck('PayPal disabled runtime contract', 'FAIL', 'enabled', 'PayPal runtime must remain disabled.');
            return;
        }
        if (($status['routeHandlersReady'] ?? false) !== true) {
            $this->addCheck('PayPal disabled runtime contract', 'FAIL', 'routeHandlersReady', 'Disabled PayPal route handlers must be recorded as ready.');
            return;
        }
        foreach (['webhookVerificationReady', 'uiControlsReady', 'regressionReady', 'cleanupReady'] as $key) {
            if (($status[$key] ?? true) !== false) {
                $this->addCheck('PayPal disabled runtime contract', 'FAIL', $key, 'Only disabled PayPal route handlers may be ready before live implementation.');
                return;
            }
        }

        $routes = $status['routes'] ?? [];
        if (!is_array($routes) || count($routes) !== 4) {
            $this->addCheck('PayPal disabled runtime contract', 'FAIL', 'routes', 'Expected four reserved PayPal routes: create, return, cancel, and webhook.');
            return;
        }

        $headers = $status['webhookSignature']['required_headers'] ?? [];
        $requiredHeaders = [
            'PAYPAL-AUTH-ALGO',
            'PAYPAL-CERT-URL',
            'PAYPAL-TRANSMISSION-ID',
            'PAYPAL-TRANSMISSION-SIG',
            'PAYPAL-TRANSMISSION-TIME',
        ];
        $missingHeaders = array_diff($requiredHeaders, is_array($headers) ? $headers : []);
        if ($missingHeaders !== []) {
            $this->addCheck('PayPal disabled runtime contract', 'FAIL', 'webhook headers', 'Missing required PayPal webhook header contract: ' . implode(', ', $missingHeaders));
            return;
        }

        $preconditions = $status['enablementPreconditions'] ?? [];
        if (!is_array($preconditions) || count($preconditions) !== 5) {
            $this->addCheck('PayPal disabled runtime contract', 'FAIL', 'enablement preconditions', 'Expected five PayPal enablement preconditions.');
            return;
        }
        foreach ($preconditions as $precondition) {
            $key = (string)($precondition['key'] ?? 'unknown');
            $satisfied = (bool)($precondition['satisfied'] ?? false);
            if ($key === 'route_handlers') {
                if (!$satisfied) {
                    $this->addCheck('PayPal disabled runtime contract', 'FAIL', $key, 'Disabled PayPal route handlers must be the only satisfied precondition.');
                    return;
                }
                continue;
            }
            if ($satisfied) {
                $this->addCheck('PayPal disabled runtime contract', 'FAIL', $key, 'Webhook verification, audit, regression, cleanup, and sandbox evidence must remain unsatisfied before live implementation.');
                return;
            }
        }

        $this->addCheck(
            'PayPal disabled runtime contract',
            'PASS',
            PaypalRuntimeContractService::CONTRACT_VERSION,
            'Disabled PayPal route handlers are recorded while webhook verification, UI controls, regression, cleanup, and sandbox evidence remain gated.'
        );
    }

    private function checkPaypalRuntimeGate(): void
    {
        $enabled = $this->envBool('PAYPAL_ENABLED', false);
        if (!$enabled) {
            $this->addCheck('PayPal runtime gate', 'PASS', 'PAYPAL_ENABLED=false', 'PayPal is reserved and disabled; QPay/LianLian behavior is unchanged.');
            return;
        }

        $this->addCheck('PayPal runtime gate', 'FAIL', 'PAYPAL_ENABLED=true', 'PayPal must stay disabled until route handlers, webhook validation, UI controls, regression scripts, provider sandbox evidence, and cleanup are implemented together.');
    }

    private function providerContracts(): array
    {
        return [
            [
                'provider' => 'QPay',
                'state' => 'Current / configurable',
                'env' => 'QPAY_AUTH_BASIC, QPAY_INVOICE_CODE, QPAY_AUTH_URL, QPAY_INVOICE_URL, QPAY_CALLBACK_*',
                'routes' => '/mall/payment/qpay, /mall/payment/qpayres',
                'gate' => 'Existing regression and callback readiness.',
            ],
            [
                'provider' => 'LianLian',
                'state' => 'Current / configurable',
                'env' => 'LIANLIAN_MERCHANT_ID, LIANLIAN_PUBLIC_KEY, LIANLIAN_PRIVATE_KEY, LIANLIAN_CALLBACK_*',
                'routes' => '/mall/payment/lianlian, /mall/payment/succeeded',
                'gate' => 'Existing regression and callback readiness.',
            ],
            [
                'provider' => 'PayPal',
                'state' => 'Reserved / disabled',
                'env' => 'PAYPAL_ENABLED=false, PAYPAL_SANDBOX, PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_WEBHOOK_ID, PAYPAL_CALLBACK_BASE, PAYPAL_*_PATH',
                'routes' => '/mall/payment/paypal, /mall/payment/paypal-return, /mall/payment/paypal-cancel, /mall/payment/paypal-webhook',
                'gate' => 'No runtime enablement until the full Phase 6 implementation gate lands.',
            ],
        ];
    }

    private function requireFileMarkers(string $label, string $path, array $markers): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($markers as $marker) {
            if (strpos($content, $marker) === false) {
                $this->addCheck($label, 'FAIL', $path, "Missing marker `{$marker}`.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Required provider/readiness markers are present.');
    }

    private function requireFileMissingMarkers(string $label, string $path, array $markers): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($markers as $marker) {
            if (strpos($content, $marker) !== false) {
                $this->addCheck($label, 'FAIL', $path, "Reserved provider marker `{$marker}` is present before PayPal implementation gate is complete.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Reserved PayPal route/UI markers are not exposed.');
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = env($key, $default ? 'true' : 'false');
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function addCheck(string $area, string $status, string $evidence, string $notes): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') {
            $this->failures++;
        } elseif ($status === 'PENDING') {
            $this->pending++;
        } elseif ($status !== 'PASS') {
            $this->warnings++;
            $status = 'WARN';
        }

        $this->checks[] = [
            'area' => $area,
            'status' => $status,
            'evidence' => $evidence,
            'notes' => $notes,
        ];
        $this->stdout(str_pad($status, 8) . "{$area}\n");
    }

    private function result(): string
    {
        if ($this->failures > 0) {
            return 'FAIL';
        }
        if ($this->warnings > 0 || $this->pending > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Payment Provider Readiness',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Profile: ' . $this->profile,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Evidence type: non-sensitive provider readiness report; no provider calls, no order creation, no callbacks triggered.',
            '',
            '## Checks',
            '',
            '| Status | Area | Evidence | Notes |',
            '|---|---|---|---|',
        ];

        foreach ($this->checks as $check) {
            $lines[] = '| ' . $this->mdCell($check['status']) . ' | '
                . $this->mdCell($check['area']) . ' | `'
                . $this->mdCell($check['evidence']) . '` | '
                . $this->mdCell($check['notes']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Provider Contract',
            '',
            '| Provider | State | Env contract | Route contract | Gate |',
            '|---|---|---|---|---|',
        ]);

        foreach ($this->providerContracts() as $contract) {
            $lines[] = '| ' . $this->mdCell($contract['provider']) . ' | '
                . $this->mdCell($contract['state']) . ' | `'
                . $this->mdCell($contract['env']) . '` | `'
                . $this->mdCell($contract['routes']) . '` | '
                . $this->mdCell($contract['gate']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- Current runtime payment providers remain QPay and LianLian.',
            '- PayPal is documented as a reserved Phase 6 provider contract only; `PAYPAL_ENABLED` must remain `false` until implementation, regression, sandbox evidence, and cleanup land together.',
            '- This command does not call PayPal, QPay, LianLian, create orders, mutate payment attempts, or change callback URLs.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-payment-provider-readiness-' . date('Ymd-His') . '.md';
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
