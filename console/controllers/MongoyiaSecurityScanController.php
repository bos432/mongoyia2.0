<?php

namespace console\controllers;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSecurityScanController extends Controller
{
    public $strict = false;
    public $maxIssues = 80;
    public $imRoot = '../../im后端/im后端';
    public $roots = 'api/controllers,backend/config,backend/controllers,backend/modules/base/controllers,backend/modules/mall,backend/views/site,common/config,common/helpers,common/models/mall,console/config,console/controllers,frontend/config,frontend/controllers,frontend/modules/mall,frontend/views/layouts,web/index.php,web/resources/mall/default/views,web/resources/mall/default/js,.env.example,.env.test.example';

    private $failures = 0;
    private $warnings = 0;
    private $issues = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'strict',
            'maxIssues',
            'imRoot',
            'roots',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia security and hardcode scan\n");

        foreach ($this->scanTargets() as $target) {
            $this->scanTarget($target);
        }

        foreach ($this->issues as $issue) {
            $line = "{$issue['severity']} {$issue['path']}:{$issue['line']} {$issue['message']}";
            $issue['severity'] === 'FAIL' ? $this->stderr($line . "\n") : $this->stdout($line . "\n");
        }

        if (count($this->issues) >= (int)$this->maxIssues) {
            $this->warn('Issue output reached maxIssues; scan stopped early.');
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function scanTargets()
    {
        $targets = [];
        foreach (array_filter(array_map('trim', explode(',', $this->roots))) as $root) {
            $targets[] = $this->projectRoot() . DIRECTORY_SEPARATOR . $root;
        }

        $imRoot = $this->resolvePath($this->imRoot);
        foreach (['main.py', '.env.example', '.env.test.example', 'scripts'] as $path) {
            $targets[] = $imRoot . DIRECTORY_SEPARATOR . $path;
        }

        return $targets;
    }

    private function scanTarget(string $target)
    {
        if (count($this->issues) >= (int)$this->maxIssues || !file_exists($target)) {
            return;
        }

        if (is_file($target)) {
            $this->scanFile(new SplFileInfo($target));
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (count($this->issues) >= (int)$this->maxIssues) {
                return;
            }
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $this->scanFile($file);
            }
        }
    }

    private function scanFile(SplFileInfo $file)
    {
        $path = $file->getPathname();
        if (!$this->shouldScan($path)) {
            return;
        }

        if ($file->getSize() > 1024 * 1024) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (count($this->issues) >= (int)$this->maxIssues) {
                return;
            }
            $this->checkLine($path, $index + 1, $line);
        }
    }

    private function shouldScan(string $path)
    {
        $relative = $this->relativePath($path);
        $normalized = str_replace('\\', '/', $relative);
        if ($normalized === 'console/controllers/MongoyiaSecurityScanController.php') {
            return false;
        }

        foreach ([
            '/vendor/',
            '/runtime/',
            '/web/attachment/',
            '/node_modules/',
            '/.git/',
            '/common/messages/',
            '/common/components/assets/resources/',
        ] as $skip) {
            if (str_contains('/' . $normalized . '/', $skip)) {
                return false;
            }
        }

        if (preg_match('/\.(min\.js|map|png|jpe?g|gif|webp|ico|woff2?|ttf|sql)$/i', $normalized)) {
            return false;
        }

        $basename = basename($normalized);
        if (in_array($basename, ['.env.example', '.env.test.example'], true)) {
            return true;
        }

        return (bool)preg_match('/\.(php|js|css|py|ps1|sh|service|conf|ini|json|ya?ml)$/i', $normalized);
    }

    private function checkLine(string $path, int $lineNumber, string $line)
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return;
        }

        if (preg_match('/1qaz2wsx/i', $line)) {
            $this->addIssue('FAIL', $path, $lineNumber, 'Local database password is hardcoded.');
        }
        if (preg_match('/local-im-auth-secret/i', $line) && !$this->isAllowedLocalSecretLine($path, $line)) {
            $this->addIssue('FAIL', $path, $lineNumber, 'Local IM auth secret is hardcoded.');
        }
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $line)) {
            $this->addIssue('FAIL', $path, $lineNumber, 'Private key material is committed in source.');
        }
        if (preg_match('/^\s*DB_PASSWORD\s*=\s*(?!$|change-me$|replace-with-)/i', $trimmed)) {
            $this->addIssue('FAIL', $path, $lineNumber, 'Committed DB_PASSWORD is not a placeholder.');
        }
        if (preg_match('/mysql:host=(?!localhost\b|127\.0\.0\.1\b|\$\{|%|env\()[^;\'"\s]+/i', $line)) {
            $this->addIssue('FAIL', $path, $lineNumber, 'Remote MySQL DSN appears hardcoded.');
        }

        if (preg_match('/funboot\.mayicun\.com|mn\.zlck888\.com|funpay\.funboot\.net|www\.funboot\.net|zlck888/i', $line) && !$this->isAllowedLegacyDomainLine($path, $line)) {
            $this->addIssue('WARN', $path, $lineNumber, 'Legacy handover domain remains in source.');
        }
        if (preg_match('/\bfunpay\b|Funboot开发指南|return\s+[\'"]funboot[\'"]/i', $line) && !$this->isAllowedLegacyBrandLine($path, $line)) {
            $this->addIssue('WARN', $path, $lineNumber, 'Legacy FunPay/Funboot brand text remains in source.');
        }
        if (preg_match('/\b(?:https?|wss?):\/\/(?:127\.0\.0\.1|localhost)\b/i', $line) && !$this->isAllowedLocalUrlLine($path, $line)) {
            $this->addIssue('WARN', $path, $lineNumber, 'Localhost URL remains in non-template source.');
        }
        if (preg_match('/[\'"]password[\'"]\s*=>\s*[\'"][^\'"]{4,}[\'"]/i', $line) && !preg_match('/env\s*\(/i', $line)) {
            $this->addIssue('WARN', $path, $lineNumber, 'Literal password value appears in PHP config/code.');
        }
        if (preg_match('/^\s*YII_DEBUG\s*=\s*true\s*$/i', $trimmed) && !$this->isAllowedDebugLine($path)) {
            $this->addIssue('FAIL', $path, $lineNumber, 'YII_DEBUG=true is not allowed outside local-only templates.');
        }
        if (preg_match('/^\s*DB_PASSWORD\s*=\s*(["\']?)([^"\']+)\1\s*$/i', $trimmed, $matches) && !$this->isAllowedEnvPlaceholder($matches[2])) {
            $this->addIssue('FAIL', $path, $lineNumber, 'Database password value appears in a scanned source/template file.');
        }
        if (preg_match('/^\s*IM_AUTH_SECRET\s*=\s*(["\']?)([^"\']*)\1\s*$/i', $trimmed, $matches) && !$this->isAllowedEnvPlaceholder($matches[2]) && strlen(trim($matches[2])) < 32) {
            $this->addIssue('WARN', $path, $lineNumber, 'IM_AUTH_SECRET is shorter than 32 characters.');
        }
        if (preg_match('/^\s*(QPAY_CALLBACK_HMAC_SECRET|LIANLIAN_CALLBACK_HMAC_SECRET|PAYPAL_WEBHOOK_HMAC_SECRET)\s*=\s*(["\']?)([^"\']*)\2\s*$/i', $trimmed, $matches) && !$this->isAllowedEnvPlaceholder($matches[3]) && strlen(trim($matches[3])) < 32) {
            $this->addIssue('FAIL', $path, $lineNumber, 'Payment callback HMAC secret is too short for production use.');
        }
        if (preg_match('/(QPAY_AUTH_BASIC|LIANLIAN_PRIVATE_KEY|LIANLIAN_PUBLIC_KEY|PAYPAL_CLIENT_SECRET|PAYPAL_WEBHOOK_HMAC_SECRET)\s*[=:]\s*[\'"][A-Za-z0-9+\/=_-]{24,}[\'"]/i', $line) && !$this->isEnvTemplate($path)) {
            $this->addIssue('FAIL', $path, $lineNumber, 'Payment credential appears hardcoded in source.');
        }
    }

    private function isAllowedDebugLine(string $path)
    {
        $relative = str_replace('\\', '/', $this->relativePath($path));
        return $relative === '.env.example';
    }

    private function isAllowedEnvPlaceholder(string $value)
    {
        $value = trim($value);
        return $value === '' || $value === 'change-me' || $value === 'password' || str_starts_with($value, 'replace-with-');
    }

    private function isEnvTemplate(string $path)
    {
        $relative = str_replace('\\', '/', $this->relativePath($path));
        return in_array(basename($relative), ['.env.example', '.env.test.example'], true);
    }

    private function isAllowedLocalSecretLine(string $path, string $line)
    {
        $relative = str_replace('\\', '/', $this->relativePath($path));
        if ($relative === 'console/controllers/DeployCheckController.php' && str_contains($line, 'local-im-auth-secret')) {
            return true;
        }

        return false;
    }

    private function isAllowedLegacyBrandLine(string $path, string $line)
    {
        $relative = str_replace('\\', '/', $this->relativePath($path));
        if (str_contains($relative, 'console/controllers/MongoyiaAcceptanceController.php')) {
            return true;
        }
        if (str_contains($relative, 'common/widgets/funboot/') || str_contains($relative, 'backend/assets/')) {
            return true;
        }
        if (preg_match('/github\.com\/funson86\/funboot/i', $line)) {
            return true;
        }

        return preg_match('/funPay|adminEmail|adminName/i', $line) === 1;
    }

    private function isAllowedLegacyDomainLine(string $path, string $line)
    {
        $relative = str_replace('\\', '/', $this->relativePath($path));
        if (str_contains($line, 'LEGACY_HOST_DOMAINS') || str_contains($line, 'legacyHostDomains')) {
            return in_array($relative, ['web/index.php', 'common/helpers/CommonHelper.php', 'backend/modules/base/controllers/StoreController.php', 'console/controllers/MongoyiaDataReadinessController.php', 'console/controllers/MongoyiaHostCleanupController.php', '.env.example', '.env.test.example'], true);
        }
        if (str_contains($line, 'hostSmokeHosts')) {
            return $relative === 'console/controllers/MallSmokeTestController.php';
        }

        return false;
    }

    private function isAllowedLocalUrlLine(string $path, string $line)
    {
        $relative = str_replace('\\', '/', $this->relativePath($path));
        if (in_array(basename($relative), ['.env.example'], true)) {
            return true;
        }
        if ($relative === 'common/config/params.php') {
            return true;
        }
        if (str_contains($line, "Yii::\$app->params['imWebsocketUrl']") || str_contains($line, 'imWebsocketUrl')) {
            return true;
        }
        if (str_contains(str_replace('\\', '/', $path), '/im后端/im后端/scripts/')) {
            return true;
        }

        return preg_match('/console\/controllers\/(MongoyiaAcceptance|MallSmokeTest|MallPaymentTest|BackendSmokeTest|PaymentProviderReadiness)Controller\.php$/', $relative) === 1;
    }

    private function addIssue(string $severity, string $path, int $line, string $message)
    {
        if (count($this->issues) >= (int)$this->maxIssues) {
            return;
        }

        $severity === 'FAIL' ? $this->failures++ : $this->warnings++;
        $this->issues[] = [
            'severity' => $severity,
            'path' => $this->relativePath($path),
            'line' => $line,
            'message' => $message,
        ];
    }

    private function resolvePath(string $path)
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . $path;
    }

    private function relativePath(string $path)
    {
        $root = rtrim(str_replace('\\', '/', $this->projectRoot()), '/');
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, $root . '/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return $normalized;
    }

    private function projectRoot()
    {
        return dirname(__DIR__, 2);
    }

    private function warn(string $message)
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }
}
