<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class TestStationAccessReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_TEST_STATION_ACCESS_READINESS_V1';

    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $timeout = 20;
    public $strict = false;
    public $sellerUsername = 'zhishichanquan';
    public $sellerPassword = '123456';
    public $checkSellerLogin = true;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $cookies = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'handoverDir',
            'outputPath',
            'timeout',
            'strict',
            'sellerUsername',
            'sellerPassword',
            'checkSellerLogin',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia test-station access readiness\n");

        $this->checkSourceCoverage();
        $this->checkPublicMatrix();
        $this->checkChatDeploymentMarkers();
        $this->checkBackendLoginAccess();
        if ($this->checkSellerLogin) {
            $this->checkSellerLoginAccess();
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
        $this->requireFileContains('docs/mongoyia-optimization-remediation-plan-20260702.md', [
            self::VERSION,
            'test-station-access-readiness/run',
            '测试站只读健康矩阵',
        ]);
        $this->requireFileContains('docs/mongoyia-deployment-guide-20260702.md', [
            self::VERSION,
            'test-station-access-readiness/run',
            'HTTP 444',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaRequirementsClosureAcceptanceController.php', [
            self::VERSION,
            'Test-station access readiness',
            'test-station-access-readiness/run',
        ]);
    }

    private function checkPublicMatrix(): void
    {
        $this->section('Public frontend and APP matrix');
        $routes = [
            '/' => [200],
            '/mall' => [200],
            '/mall/category/view?keyword=111' => [200],
            '/mall/default/search?keyword=111' => [200],
            '/mall/product/view?id=2' => [200],
            '/mall/cart/index' => [200],
            '/mall/default/login' => [200],
            '/mall/default/signup' => [200],
            '/mall/default/request-password-reset' => [200],
            '/mall/default/contact' => [200],
            '/mall/chat/index?gid=2' => [200],
            '/mall/product/review?id=2' => [200, 400],
            '/api/v1/app-buyer/home' => [200],
            '/api/v1/app-buyer/categories' => [200],
            '/api/v1/app-buyer/search?keyword=111' => [200],
            '/api/v1/app-buyer/product?id=2' => [200],
            '/api/v1/app-buyer/reviews?product_id=2' => [200],
            '/api/v1/app-seller/dashboard' => [401],
        ];

        foreach ($routes as $path => $expected) {
            $response = $this->request('GET', $path);
            $status = (int)$response['status'];
            if (in_array($status, $expected, true)) {
                $this->addCheck('Public matrix ' . $path, 'PASS', (string)$status, 'Expected status received.');
                continue;
            }
            $this->addCheck('Public matrix ' . $path, 'FAIL', (string)$status, 'Expected one of ' . implode('/', $expected) . '.');
        }
    }

    private function checkChatDeploymentMarkers(): void
    {
        $this->section('Chat deployment markers');
        $response = $this->request('GET', '/mall/chat/index?gid=2');
        if ((int)$response['status'] !== 200) {
            $this->addCheck('Customer-service chat page', 'FAIL', (string)$response['status'], 'Chat page must open before marker inspection.');
            return;
        }

        $content = (string)$response['body'];
        $markers = [
            'MONGOYIA_CHAT_WEBVIEW_FORMDATA_GUARD_V1',
            'MONGOYIA_CHAT_WEBVIEW_URL_NORMALIZER_COMPAT_V1',
            'MONGOYIA_MINI_PROGRAM_CHAT_QUERY_COMPAT_V1',
        ];
        $found = [];
        foreach ($markers as $marker) {
            if (strpos($content, $marker) !== false) {
                $found[] = $marker;
            }
        }

        if ($found) {
            $this->addCheck('R1 chat compatibility deployed markers', 'PASS', implode(', ', $found), 'At least one current compatibility marker is rendered by the deployed chat entry.');
        } else {
            $this->addCheck('R1 chat compatibility deployed markers', 'FAIL', '/mall/chat/index?gid=2', 'No current R1 marker was rendered. Pull latest code and flush Yii/PHP/opcache/template cache.');
        }

        if (strpos($content, 'URLSearchParams') !== false) {
            $this->addCheck('No deployed URLSearchParams marker', 'FAIL', '/mall/chat/index?gid=2', 'The deployed chat page still contains URLSearchParams.');
        } else {
            $this->addCheck('No deployed URLSearchParams marker', 'PASS', '/mall/chat/index?gid=2', 'The deployed chat page does not expose URLSearchParams.');
        }
    }

    private function checkBackendLoginAccess(): void
    {
        $this->section('Backend read access');
        $this->cookies = [];
        $login = $this->request('GET', '/backend/site/login');
        if ((int)$login['status'] !== 200) {
            $this->addCheck('Backend login page', 'FAIL', (string)$login['status'], 'Backend login page must be readable for browser validation.');
            return;
        }

        if ($this->extractCsrf((string)$login['body']) === null) {
            $this->addCheck('Backend login CSRF', 'FAIL', '/backend/site/login', 'No backend CSRF token found.');
        } else {
            $this->addCheck('Backend login CSRF', 'PASS', '/backend/site/login', 'Backend login page exposes CSRF token.');
        }

        $backend = $this->request('GET', '/backend/');
        if ((int)$backend['status'] === 444) {
            $this->addCheck('Backend root script access', 'FAIL', 'HTTP 444', 'Test-station WAF/Nginx/security policy blocks backend automation. Add a minimal validation whitelist.');
        } elseif (in_array((int)$backend['status'], [200, 302], true)) {
            $this->addCheck('Backend root script access', 'PASS', (string)$backend['status'], 'Backend root is reachable by the validation probe.');
        } else {
            $this->addCheck('Backend root script access', 'WARN', (string)$backend['status'], 'Unexpected status; inspect response and WAF logs.');
        }
    }

    private function checkSellerLoginAccess(): void
    {
        $this->section('Seller login automation access');
        if ($this->sellerUsername === '' || $this->sellerPassword === '') {
            $this->addCheck('Seller login probe', 'WARN', 'missing credentials', 'Pass sellerUsername and sellerPassword to run the login probe.');
            return;
        }

        $this->cookies = [];
        $login = $this->request('GET', '/backend/site/login');
        if ((int)$login['status'] !== 200) {
            $this->addCheck('Seller login GET preflight', 'FAIL', (string)$login['status'], 'Cannot read backend login page.');
            return;
        }

        $csrf = $this->extractCsrf((string)$login['body']);
        if ($csrf === null) {
            $this->addCheck('Seller login CSRF preflight', 'FAIL', '/backend/site/login', 'Cannot parse backend CSRF token.');
            return;
        }

        $body = [
            $csrf['name'] => $csrf['value'],
            'LoginForm[username]' => $this->sellerUsername,
            'LoginForm[password]' => $this->sellerPassword,
            'LoginForm[rememberMe]' => '0',
        ];
        $post = $this->request('POST', '/backend/site/login', $body, [
            'Origin: ' . $this->baseUrl,
            'Referer: ' . $this->baseUrl . '/backend/site/login',
        ]);

        $status = (int)$post['status'];
        if ($status === 444) {
            $this->addCheck('Seller login POST access', 'FAIL', 'HTTP 444', 'WAF/Nginx/security policy blocks even CSRF-valid seller login automation.');
            return;
        }
        if (in_array($status, [302, 303], true)) {
            $this->addCheck('Seller login POST access', 'PASS', (string)$status, 'Login request was accepted and redirected.');
        } elseif ($status === 200 && strpos((string)$post['body'], '退出') !== false) {
            $this->addCheck('Seller login POST access', 'PASS', 'HTTP 200', 'Login response appears authenticated.');
        } else {
            $this->addCheck('Seller login POST access', 'WARN', (string)$status, 'Login was not blocked by 444, but authentication was not conclusively verified.');
        }

        $info = $this->request('GET', '/backend/site/info');
        if ((int)$info['status'] === 444) {
            $this->addCheck('Seller dashboard access', 'FAIL', 'HTTP 444', 'Dashboard access is still blocked after login probe.');
        } elseif ((int)$info['status'] === 200) {
            $this->addCheck('Seller dashboard access', 'PASS', 'HTTP 200', 'Dashboard page is reachable after login probe.');
        } else {
            $this->addCheck('Seller dashboard access', 'WARN', (string)$info['status'], 'Unexpected dashboard status.');
        }
    }

    private function request(string $method, string $path, array $body = [], array $extraHeaders = []): array
    {
        $url = strpos($path, 'http') === 0 ? $path : $this->baseUrl . $path;
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36 MongoyiaAccessReadiness/1.0',
            'Accept: text/html,application/xhtml+xml,application/json,*/*',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        ];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        $content = '';
        if (strtoupper($method) === 'POST') {
            $content = http_build_query($body);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($content);
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => (int)$this->timeout,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $headersOut = isset($http_response_header) ? $http_response_header : [];
        $this->captureCookies($headersOut);

        return [
            'status' => $this->parseStatus($headersOut),
            'headers' => $headersOut,
            'body' => $raw === false ? '' : (string)$raw,
            'url' => $url,
        ];
    }

    private function captureCookies(array $headers): void
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }
            $cookie = trim(substr($header, strlen('Set-Cookie:')));
            $pair = explode(';', $cookie, 2)[0];
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                $this->cookies[$parts[0]] = $parts[1];
            }
        }
    }

    private function parseStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
                return (int)$matches[1];
            }
        }
        return 0;
    }

    private function extractCsrf(string $html): ?array
    {
        if (preg_match('/name="(_csrf-backend|_csrf)"\s+value="([^"]+)"/', $html, $matches)) {
            return ['name' => $matches[1], 'value' => $matches[2]];
        }
        if (preg_match('/value="([^"]+)"\s+name="(_csrf-backend|_csrf)"/', $html, $matches)) {
            return ['name' => $matches[2], 'value' => $matches[1]];
        }
        if (preg_match('/name="csrf-param"\s+content="([^"]+)"/', $html, $param)
            && preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $token)) {
            return ['name' => $param[1], 'value' => $token[1]];
        }
        return null;
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Test-Station Access Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Base URL: ' . $this->baseUrl,
            '- Seller login probe: ' . ($this->checkSellerLogin ? 'enabled' : 'disabled'),
            '- Seller username: ' . ($this->sellerUsername === '' ? '(empty)' : $this->sellerUsername),
            '- Seller password: ' . ($this->sellerPassword === '' ? '(not supplied)' : '(masked)'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: 0',
            '- Afterfill pending: 0',
            '- Scope: read-only/public route matrix, deployed R1 chat markers, backend login CSRF, HTTP 444/WAF diagnostics, and seller login automation access.',
            '- Safety: this command does not create orders, submit payments, approve refunds/reviews/withdrawals, call external providers, mutate funds/stock, or switch production GO.',
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
            'git pull --ff-only',
            'git rev-parse --short HEAD',
            '/www/server/php/83/bin/php yii cache/flush-all --interactive=0',
            '/etc/init.d/php-fpm-83 restart',
            '/www/server/php/83/bin/php yii test-station-access-readiness/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --sellerUsername=zhishichanquan \\',
            '  --sellerPassword=123456 \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
            'If backend routes return HTTP 444, run the read-only WAF diagnostics command before changing security rules:',
            '',
            '```bash',
            '/www/server/php/83/bin/php yii test-station-waf-diagnostics/run \\',
            '  --domain=demo2026.mongoyia.com \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --interactive=0',
            '```',
            '',
            'Inspect BaoTa/Nginx/WAF evidence and add only a minimal validation whitelist for the test station, accepted validation source IP, or specific read-only acceptance paths.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck('Source marker ' . $path, 'FAIL', $path, 'Required file is missing.');
            return;
        }
        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck('Source marker ' . $path, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }
        $this->addCheck('Source marker ' . $path, 'PASS', $path, 'Required access-readiness markers are present.');
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
            . DIRECTORY_SEPARATOR . 'test-station-access-readiness-' . date('Ymd-His') . '.md';
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
