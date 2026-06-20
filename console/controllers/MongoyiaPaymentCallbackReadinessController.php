<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaPaymentCallbackReadinessController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $profile = 'local';
    public $acceptanceDir = 'runtime/acceptance';
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
            'acceptanceDir',
            'handoverDir',
            'outputPath',
            'strict',
            'failOnPending',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia payment callback readiness\n");

        $this->checkCodeHardening();
        $this->checkEnvironmentTemplates();
        $this->checkRegressionEvidence();
        $this->checkPwaPaymentEvidence();
        $this->checkSandboxEvidence();

        $result = $this->result();
        $path = $this->writeReport($result);
        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0)) || ($this->failOnPending && $this->pending > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkCodeHardening(): void
    {
        $paymentController = 'frontend/modules/mall/controllers/PaymentController.php';
        $paymentTest = 'console/controllers/MallPaymentTestController.php';
        $this->requireFileMarkers('Payment callback routes and guards', $paymentController, [
            'public function actionQpayres',
            'public function actionSucceeded',
            'protected function assertCallbackSignature',
            'protected function assertCallbackTimestamp',
            'protected function paymentCallbackLockName',
            'PaymentAttempt::RESULT_IGNORED',
            'assertOrderCanStartPayment',
        ]);
        $this->requireFileMarkers('Payment regression command coverage', $paymentTest, [
            'runQpayHmacProtection',
            'runQpayTimestampProtection',
            'runLianlianHmacProtection',
            'runLianlianTimestampProtection',
            'postQpayCallback',
            'postLianlianCallback',
            'callbackSignature',
        ]);
        $this->requireFileMarkers('Payment attempt audit model', 'common/models/mall/PaymentAttempt.php', [
            'RESULT_SUCCESS',
            'RESULT_FAILED',
            'RESULT_IGNORED',
            'createForOrder',
        ]);
    }

    private function checkEnvironmentTemplates(): void
    {
        foreach (['.env.example', '.env.test.example'] as $path) {
            $this->requireFileMarkers("Callback env template {$path}", $path, [
                'QPAY_CALLBACK_BASE',
                'QPAY_CALLBACK_SECRET',
                'QPAY_CALLBACK_HMAC_SECRET',
                'QPAY_CALLBACK_MAX_AGE_SECONDS',
                'LIANLIAN_CALLBACK_BASE',
                'LIANLIAN_CALLBACK_SECRET',
                'LIANLIAN_CALLBACK_HMAC_SECRET',
                'LIANLIAN_CALLBACK_MAX_AGE_SECONDS',
            ]);
        }

        $this->recordSecretShape('QPay callback HMAC runtime shape', 'QPAY_CALLBACK_HMAC_SECRET', 'QPAY_CALLBACK_MAX_AGE_SECONDS');
        $this->recordSecretShape('LianLian callback HMAC runtime shape', 'LIANLIAN_CALLBACK_HMAC_SECRET', 'LIANLIAN_CALLBACK_MAX_AGE_SECONDS');
    }

    private function checkRegressionEvidence(): void
    {
        $latest = $this->latestAcceptanceWith('### payment regression');
        if ($latest === '') {
            $this->addCheck('Automated payment regression evidence', 'PENDING', 'No acceptance report with payment regression step found.', 'Run full acceptance or `mall-payment-test/run` on the target environment.');
            return;
        }

        $content = (string)file_get_contents($latest);
        $stepStatus = $this->stepExitCode($content, 'payment regression');
        $result = $this->readReportResult($latest);
        if ($stepStatus === 0 && $result !== 'FAIL') {
            $this->addCheck('Automated payment regression evidence', 'PASS', $this->displayPath($latest), 'Payment callback regression step exits 0.');
            return;
        }

        $this->addCheck('Automated payment regression evidence', 'FAIL', $this->displayPath($latest), 'Payment callback regression step is missing or failed.');
    }

    private function checkPwaPaymentEvidence(): void
    {
        $latest = $this->latestHandoverFile('mongoyia-pwa-mobile-ui-evidence-*.md');
        if ($latest === '') {
            $this->addCheck('Mobile payment UI evidence', 'PENDING', 'No PWA mobile UI evidence found.', 'Run `pwa-smoke-test/run` after local/test frontend is reachable.');
            return;
        }

        $content = (string)file_get_contents($latest);
        $markers = [
            'payment page',
            'order detail page',
            'payment cancelled page',
            'payment success page',
        ];
        foreach ($markers as $marker) {
            if (stripos($content, $marker) === false) {
                $this->addCheck('Mobile payment UI evidence', 'WARN', $this->displayPath($latest), "Latest PWA evidence is missing {$marker}.");
                return;
            }
        }

        $status = $this->readReportResult($latest);
        $this->addCheck('Mobile payment UI evidence', $status === 'PASS' ? 'PASS' : 'WARN', $this->displayPath($latest), 'PWA evidence covers payment page, order detail, cancelled page, and success page.');
    }

    private function checkSandboxEvidence(): void
    {
        $latest = $this->latestHandoverFile('mongoyia-payment-sandbox-evidence-*.md');
        if ($latest === '') {
            $this->addCheck('Provider sandbox signoff evidence', 'PENDING', 'No payment sandbox evidence found.', 'Expected until real QPay/LianLian sandbox callbacks are tested on HTTPS test server.');
            return;
        }

        $status = $this->readReportResult($latest);
        $this->addCheck('Provider sandbox signoff evidence', $status === 'PASS' ? 'PASS' : 'WARN', $this->displayPath($latest), 'Non-sensitive provider evidence report exists.');
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

        $this->addCheck($label, 'PASS', $path, 'Required callback/readiness markers are present.');
    }

    private function recordSecretShape(string $label, string $secretKey, string $maxAgeKey): void
    {
        $secret = (string)env($secretKey, '');
        $maxAge = (int)env($maxAgeKey, 0);
        if ($this->profile === 'local' && $secret === '' && $maxAge <= 0) {
            $this->addCheck($label, 'WARN', "{$secretKey}=empty, {$maxAgeKey}=0", 'Expected for local profile; test profile must provision both.');
            return;
        }

        if (strlen($secret) >= 32 && $maxAge > 0) {
            $this->addCheck($label, 'PASS', "{$secretKey}=configured, {$maxAgeKey}={$maxAge}", 'Secret value is not printed.');
            return;
        }

        $this->addCheck($label, 'PENDING', "{$secretKey}=" . ($secret === '' ? 'empty' : 'too-short') . ", {$maxAgeKey}={$maxAge}", 'Provision a >=32 character HMAC secret and positive replay window on test server.');
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
            '# Mongoyia Payment Callback Readiness',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Profile: ' . $this->profile,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Evidence type: non-sensitive readiness report; no provider calls, no order creation, no callbacks triggered.',
            '',
            '| Area | Status | Evidence | Notes |',
            '|---|---:|---|---|',
        ];

        foreach ($this->checks as $check) {
            $lines[] = '| ' . $this->mdCell($check['area']) . ' | ' . $this->mdCell($check['status']) . ' | ' . $this->mdCell($check['evidence']) . ' | ' . $this->mdCell($check['notes']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Test Server Follow-up',
            '',
            '- Run full test-profile acceptance with real HTTPS callback base URLs.',
            '- Run `mall-payment-test/run` after QPay/LianLian callback HMAC secrets and max-age values are configured.',
            '- Record provider-side sandbox evidence with `mongoyia-payment-sandbox-evidence`; store only ticket or screenshot references.',
            '- Run `mongoyia-test-cleanup/run --failOnPending=1 --interactive=0` before signoff.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function latestAcceptanceWith(string $needle): string
    {
        $files = glob($this->resolvePath($this->acceptanceDir) . DIRECTORY_SEPARATOR . 'mongoyia-acceptance-*.md') ?: [];
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content !== false && stripos($content, $needle) !== false) {
                return $file;
            }
        }

        return '';
    }

    private function latestHandoverFile(string $pattern): string
    {
        $files = glob($this->resolvePath($this->handoverDir) . DIRECTORY_SEPARATOR . $pattern) ?: [];
        if (!$files) {
            return '';
        }
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }

    private function stepExitCode(string $content, string $label): ?int
    {
        $pattern = '/^###\s+' . preg_quote($label, '/') . '\s*$(.*?)(?=^###\s+|\z)/ms';
        if (!preg_match($pattern, $content, $matches)) {
            return null;
        }
        if (preg_match('/^-\s+Exit code:\s+(\d+)\s*$/m', $matches[1], $exit)) {
            return (int)$exit[1];
        }

        return null;
    }

    private function readReportResult(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return 'PENDING';
        }
        $content = (string)file_get_contents($path);
        if (preg_match('/^-\s+Result:\s*([A-Z]+)\s*$/m', $content, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }

    private function defaultReportPath(): string
    {
        return $this->projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'handover' . DIRECTORY_SEPARATOR . 'mongoyia-payment-callback-readiness-' . date('Ymd-His') . '.md';
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function displayPath(string $path): string
    {
        if ($path === '') {
            return 'not generated';
        }
        $root = rtrim($this->projectRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root) ? str_replace('\\', '/', substr($path, strlen($root))) : $path;
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
