<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class PaymentCallbackRegressionReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_PAYMENT_CALLBACK_REGRESSION_READINESS_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia payment callback regression readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkRegressionMatrix();
        }

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Source coverage');

        $this->requireFileContains('Frontend callback guards', 'frontend/modules/mall/controllers/PaymentController.php', [
            'public function actionQpayres',
            'public function actionSucceeded',
            'public function actionPaypalWebhook',
            'PAYPAL_DISABLED',
            'QPay test config missing',
            'LianLian test config missing',
            'assertSuccessfulPaymentStatus',
            'Payment status is not successful',
            'assertPaidAmountMatches',
            'Payment amount mismatch',
            'PaymentAttempt::RESULT_IGNORED',
            'Duplicate paid callback ignored',
            'Duplicate PayPal webhook ignored',
            'assertCallbackSignatureValue',
            'Payment callback signature is required',
            'Invalid payment callback signature',
        ]);
        $this->requireFileContains('HTTP payment callback regression command', 'console/controllers/MallPaymentTestController.php', [
            'runSuccessfulCallback',
            'runAmountMismatch',
            'runQpayHmacProtection',
            'runLianlianSuccessfulCallback',
            'runLianlianAmountMismatch',
            'runLianlianHmacProtection',
            'PaymentAttempt::RESULT_IGNORED',
            'assertFailedAttempt',
        ]);
        $this->requireFileContains('Payment attempt audit model', 'common/models/mall/PaymentAttempt.php', [
            'RESULT_SUCCESS',
            'RESULT_FAILED',
            'RESULT_IGNORED',
            'payload_hash',
            'createForOrder',
        ]);
        $this->requireFileContains('PayPal runtime readiness command', 'console/controllers/OperationalConfigPaypalTestController.php', [
            'verifyPaypalWebhook',
            'PaymentAttempt::RESULT_IGNORED',
            'PayPal runtime routes exist while the frontend payment button remains reserved',
        ]);
    }

    private function checkRegressionMatrix(): void
    {
        $this->section('Regression matrix');

        $files = [
            'payment' => $this->readFile('frontend/modules/mall/controllers/PaymentController.php'),
            'mall_test' => $this->readFile('console/controllers/MallPaymentTestController.php'),
            'paypal_test' => $this->readFile('console/controllers/OperationalConfigPaypalTestController.php'),
            'attempt' => $this->readFile('common/models/mall/PaymentAttempt.php'),
        ];
        if (in_array(null, $files, true)) {
            return;
        }

        foreach ($this->regressionMatrix() as $case) {
            $missing = [];
            foreach ($case['markers'] as $fileKey => $markers) {
                foreach ($markers as $marker) {
                    if (strpos((string)$files[$fileKey], $marker) === false) {
                        $missing[] = "{$fileKey}:{$marker}";
                    }
                }
            }

            if ($missing) {
                $this->addCheck($case['label'], 'FAIL', $case['evidence'], 'Missing regression markers: ' . implode(', ', $missing));
                continue;
            }

            $this->addCheck($case['label'], 'PASS', $case['evidence'], $case['notes']);
        }
    }

    private function regressionMatrix(): array
    {
        return [
            [
                'label' => 'Disabled provider/channel guard',
                'evidence' => 'PaymentController disabled-provider branches',
                'notes' => 'QPay, LianLian, and PayPal reject missing or disabled provider configuration before live traffic.',
                'markers' => [
                    'payment' => ['QPay test config missing', 'LianLian test config missing', 'PAYPAL_DISABLED'],
                    'paypal_test' => ['PayPal runtime routes exist while the frontend payment button remains reserved'],
                ],
            ],
            [
                'label' => 'Failure status callback guard',
                'evidence' => 'PaymentController::assertSuccessfulPaymentStatus',
                'notes' => 'Provider callbacks with non-success status are rejected and audited as failed attempts.',
                'markers' => [
                    'payment' => ['assertSuccessfulPaymentStatus', 'Payment status is not successful', 'PaymentAttempt::RESULT_FAILED'],
                    'mall_test' => ['payment_status', 'assertFailedAttempt'],
                ],
            ],
            [
                'label' => 'Duplicate callback/idempotency guard',
                'evidence' => 'PaymentAttempt ignored result plus callback lock',
                'notes' => 'Repeated QPay/LianLian callbacks and repeated PayPal webhooks are ignored without double stock or payment mutation.',
                'markers' => [
                    'payment' => ['paymentCallbackLockName', 'Duplicate paid callback ignored', 'Duplicate PayPal webhook ignored', 'PaymentAttempt::RESULT_IGNORED'],
                    'mall_test' => ['duplicate callback does not deduct stock again', 'PaymentAttempt::RESULT_IGNORED'],
                ],
            ],
            [
                'label' => 'Amount mismatch guard',
                'evidence' => 'PaymentController::assertPaidAmountMatches',
                'notes' => 'QPay, LianLian, and PayPal callback amounts are compared to order amount before marking paid.',
                'markers' => [
                    'payment' => ['assertPaidAmountMatches', 'Payment amount mismatch', 'paypalCaptureAmount'],
                    'mall_test' => ['runAmountMismatch', 'runLianlianAmountMismatch', 'Payment amount mismatch'],
                ],
            ],
            [
                'label' => 'Signature/HMAC guard',
                'evidence' => 'PaymentController::assertCallbackSignatureValue',
                'notes' => 'Missing or invalid QPay/LianLian HMAC signatures fail closed; PayPal webhook verification remains required.',
                'markers' => [
                    'payment' => ['assertCallbackSignatureValue', 'Payment callback signature is required', 'Invalid payment callback signature', 'verifyPaypalWebhook'],
                    'mall_test' => ['runQpayHmacProtection', 'runLianlianHmacProtection', 'Invalid payment callback signature'],
                    'paypal_test' => ['verify-webhook-signature'],
                ],
            ],
            [
                'label' => 'Audit row coverage',
                'evidence' => 'PaymentAttempt model plus regression assertions',
                'notes' => 'Success, failed, ignored, and payload hash audit fields are available for backend review and statistics.',
                'markers' => [
                    'payment' => ['logPaymentAttempt', 'updatePaymentAttemptResult'],
                    'mall_test' => ['assertFailedAttempt', 'PaymentAttempt::RESULT_IGNORED'],
                    'attempt' => ['payload_hash', 'RESULT_SUCCESS', 'RESULT_FAILED', 'RESULT_IGNORED'],
                ],
            ],
        ];
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Payment Callback Regression Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: disabled-channel, failure callback, duplicate callback, amount mismatch, signature/HMAC error, and audit-row coverage.',
            '- Safety: this command does not call payment providers, create orders, send callbacks, enable live payment, mutate order status, mutate funds, or store secrets.',
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
            '## BaoTa Verification Command',
            '',
            '```bash',
            'cd /www/wwwroot/demo2026.mongoyia.com',
            'git pull',
            '/www/server/php/83/bin/php yii payment-callback-regression-readiness/run --fixture=1 --strict=1 --interactive=0',
            '/www/server/php/83/bin/php yii payment-phase11-acceptance/run --runChildChecks=1 --fixture=1 --interactive=0',
            '```',
            '',
            'Real sandbox/browser signoff remains separate and must only be accepted after non-sensitive provider evidence and browser evidence are reviewed.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function requireFileContains(string $label, string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck($label, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Required callback regression markers are present.');
    }

    private function readFile(string $path): ?string
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck('Regression matrix source file', 'FAIL', $path, 'Required file is missing.');
            return null;
        }

        return (string)file_get_contents($full);
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function addCheck(string $area, string $status, string $evidence, string $notes): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') {
            $this->failures++;
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
        if ($this->warnings > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-payment-callback-regression-readiness-' . date('Ymd-His') . '.md';
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }
}
