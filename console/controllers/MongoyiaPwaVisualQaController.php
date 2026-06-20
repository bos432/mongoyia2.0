<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaPwaVisualQaController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $pwaEvidencePath = '';
    public $screenshotsDir = '';
    public $productId = 90;
    public $requireScreenshots = false;
    public $minScreenshotBytes = 4096;
    public $strict = false;

    private $checks = [];
    private $routes = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;
    private $infos = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'handoverDir',
            'outputPath',
            'pwaEvidencePath',
            'screenshotsDir',
            'productId',
            'requireScreenshots',
            'minScreenshotBytes',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia PWA visual QA readiness\n");

        $this->checkStaticReadiness();
        $this->checkPublicRoutes();
        $this->checkLatestPwaEvidence();
        $this->checkScreenshotInputs();

        $result = $this->result();
        $path = $this->writeReport($result);
        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending, {$this->infos} info.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0))) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkStaticReadiness(): void
    {
        $this->requireFileMarkers('PWA install/mobile readiness command', 'console/controllers/PwaSmokeTestController.php', [
            'checkInstallReadiness',
            'checkMobileUiReadiness',
            'writeMobileUiEvidenceReport',
            'Mongoyia PWA Mobile UI Evidence',
        ]);
        $this->requireFileMarkers('Frontend PWA density CSS', 'web/resources/mall/default/css/style.css', [
            'MONGOYIA_FRONTEND_PWA_DENSITY',
            '.mongoyia-pwa-shell .shop-cart-table',
            '.mongoyia-pwa-shell .product-top-bar',
            '.mongoyia-pwa-shell .payment-result',
            '.mongoyia-pwa-shell .order-detail',
        ]);
        $this->requireFileMarkers('Frontend PWA shell layout', 'web/resources/mall/default/views/layouts/main.php', [
            'rel="manifest"',
            'name="viewport"',
            'navigator.serviceWorker.register',
            'mongoyia-pwa-shell',
            'data-mongoyia-pwa',
        ]);
    }

    private function checkPublicRoutes(): void
    {
        foreach ($this->visualRoutes() as $route) {
            if (!empty($route['requiresLogin']) || !empty($route['requiresBackendLogin'])) {
                continue;
            }

            $response = $this->get($this->baseUrl . $route['path']);
            $status = (int)$response['status'];
            if (!empty($route['authGate'])) {
                if ($status >= 300 && $status < 400) {
                    $this->addCheck('Visual route ' . $route['label'], 'PASS', $route['path'], "Auth-gated route redirects with HTTP {$status}.");
                    continue;
                }
                if ($status === 200 && $this->containsAny($response['body'], ['data-mongoyia-mobile-ui="login"', 'LoginEmailForm[email]', '/mall/default/login'])) {
                    $this->addCheck('Visual route ' . $route['label'], 'PASS', $route['path'], 'Auth-gated route renders login markers.');
                    continue;
                }
                $this->addCheck('Visual route ' . $route['label'], 'FAIL', $route['path'], "Expected login gate, got HTTP {$status}.");
                continue;
            }

            if ($status < 200 || $status >= 400) {
                $this->addCheck('Visual route ' . $route['label'], 'FAIL', $route['path'], "Expected HTTP 2xx/3xx, got {$status}.");
                continue;
            }
            if (!$this->containsAll($response['body'], $route['markers'])) {
                $this->addCheck('Visual route ' . $route['label'], 'FAIL', $route['path'], 'Missing expected stable PWA/page markers.');
                continue;
            }
            if ($this->containsAny($response['body'], $this->fatalMarkers())) {
                $this->addCheck('Visual route ' . $route['label'], 'FAIL', $route['path'], 'Fatal/debug marker detected in route HTML.');
                continue;
            }

            $this->addCheck('Visual route ' . $route['label'], 'PASS', $route['path'], "HTTP {$status}, stable markers present.");
        }
    }

    private function checkLatestPwaEvidence(): void
    {
        $path = $this->pwaEvidencePath !== ''
            ? $this->resolvePath($this->pwaEvidencePath)
            : $this->latestHandoverFile('mongoyia-pwa-mobile-ui-evidence-*.md');
        if ($path === '' || !is_file($path)) {
            $this->addCheck('Latest PWA mobile UI evidence', 'PENDING', 'not generated', 'Run `php yii pwa-smoke-test/run --baseUrl=<base-url> --interactive=0` first.');
            return;
        }

        $content = (string)file_get_contents($path);
        $markers = [
            'customer-service chat',
            'authenticated user order',
            'authenticated user history',
            'authenticated user coupons',
            'authenticated user favorites',
            'authenticated user address',
            'authenticated user setting',
            'authenticated distribution',
            'merchant dashboard',
            'merchant products',
            'merchant orders',
            'merchant store profile',
            'merchant merchant statistics',
            'merchant merchant coupons',
            'merchant logistics methods',
            'merchant merchant deposit',
            'payment page',
            'order detail page',
            'payment cancelled page',
            'payment success page',
        ];
        if (!$this->containsAll($content, $markers)) {
            $this->addCheck('Latest PWA mobile UI evidence', 'WARN', $this->displayPath($path), 'Latest PWA evidence is missing one or more visual QA target areas.');
            return;
        }

        $result = $this->readReportResult($path);
        $this->addCheck('Latest PWA mobile UI evidence', $result === 'PASS' ? 'PASS' : 'WARN', $this->displayPath($path), 'Latest mobile UI evidence covers visual QA target areas.');
    }

    private function checkScreenshotInputs(): void
    {
        if ($this->screenshotsDir === '') {
            $this->addCheck('Screenshot capture evidence', 'INFO', 'not supplied', 'Readiness plan generated. Re-run with --screenshotsDir=<dir> --requireScreenshots=1 after browser screenshot capture.');
            return;
        }

        $dir = $this->resolvePath($this->screenshotsDir);
        if (!is_dir($dir)) {
            $this->addCheck('Screenshot capture evidence', $this->requireScreenshots ? 'FAIL' : 'WARN', $this->screenshotsDir, 'Screenshot directory does not exist.');
            return;
        }

        $missing = [];
        $invalid = [];
        foreach ($this->screenshotPlan() as $shot) {
            $file = $dir . DIRECTORY_SEPARATOR . $shot['file'];
            if (!is_file($file) || filesize($file) <= 0) {
                $missing[] = $shot['file'];
                continue;
            }

            $issue = $this->screenshotIssue($file, $shot);
            if ($issue !== '') {
                $invalid[] = $shot['file'] . ' (' . $issue . ')';
            }
        }

        if ($missing) {
            $status = $this->requireScreenshots ? 'FAIL' : 'WARN';
            $this->addCheck('Screenshot capture evidence', $status, $this->displayPath($dir), 'Missing screenshots: ' . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ', ...' : ''));
            return;
        }
        if ($invalid) {
            $status = $this->requireScreenshots ? 'FAIL' : 'WARN';
            $this->addCheck('Screenshot capture evidence', $status, $this->displayPath($dir), 'Invalid screenshots: ' . implode(', ', array_slice($invalid, 0, 5)) . (count($invalid) > 5 ? ', ...' : ''));
            return;
        }

        $this->addCheck('Screenshot capture evidence', 'PASS', $this->displayPath($dir), 'All planned screenshot files exist and pass PNG size/dimension checks.');
    }

    private function visualRoutes(): array
    {
        return [
            [
                'scope' => 'frontend',
                'label' => 'home',
                'path' => '/',
                'markers' => ['data-mongoyia-mobile-ui="home"', 'mongoyia-pwa-shell', 'property-gallery'],
            ],
            [
                'scope' => 'frontend',
                'label' => 'category-search',
                'path' => '/mall/category/view?keyword=',
                'markers' => ['data-mongoyia-mobile-ui="category"', 'product-top-bar', 'product-sort'],
            ],
            [
                'scope' => 'frontend',
                'label' => 'product',
                'path' => '/product/' . (int)$this->productId,
                'markers' => ['data-mongoyia-mobile-ui="product"', 'product-details-text', 'addToCart'],
            ],
            [
                'scope' => 'frontend',
                'label' => 'cart',
                'path' => '/mall/cart/index',
                'markers' => ['data-mongoyia-mobile-ui="cart"', 'shop-cart'],
            ],
            [
                'scope' => 'frontend',
                'label' => 'login',
                'path' => '/mall/default/login',
                'markers' => ['data-mongoyia-mobile-ui="login"', 'login-form', 'LoginEmailForm[email]'],
            ],
            [
                'scope' => 'frontend',
                'label' => 'chat',
                'path' => '/mall/chat/index?gid=' . (int)$this->productId,
                'markers' => ['data-mongoyia-mobile-ui="chat"', 'chat-container', 'messagesContainer'],
            ],
            [
                'scope' => 'frontend',
                'label' => 'user-orders-auth-gate',
                'path' => '/mall/user/order',
                'markers' => [],
                'authGate' => true,
            ],
            [
                'scope' => 'frontend',
                'label' => 'distribution-auth-gate',
                'path' => '/mall/user/distribution',
                'markers' => [],
                'authGate' => true,
            ],
            [
                'scope' => 'frontend-auth',
                'label' => 'user-orders',
                'path' => '/mall/user/order',
                'markers' => ['data-mongoyia-mobile-ui="user-order"'],
                'requiresLogin' => true,
            ],
            [
                'scope' => 'frontend-auth',
                'label' => 'user-setting',
                'path' => '/mall/user/setting',
                'markers' => ['data-mongoyia-mobile-ui="user-setting"'],
                'requiresLogin' => true,
            ],
            [
                'scope' => 'frontend-auth',
                'label' => 'distribution',
                'path' => '/mall/user/distribution',
                'markers' => ['data-mongoyia-mobile-ui="distribution"'],
                'requiresLogin' => true,
            ],
            [
                'scope' => 'merchant',
                'label' => 'merchant-dashboard',
                'path' => '/backend/site/info',
                'markers' => ['mongoyia-merchant-pwa-shell'],
                'requiresBackendLogin' => true,
            ],
            [
                'scope' => 'merchant',
                'label' => 'merchant-products',
                'path' => '/backend/mall/product/index',
                'markers' => ['mongoyia-merchant-pwa-shell'],
                'requiresBackendLogin' => true,
            ],
            [
                'scope' => 'merchant',
                'label' => 'merchant-orders',
                'path' => '/backend/mall/order/index',
                'markers' => ['mongoyia-merchant-pwa-shell'],
                'requiresBackendLogin' => true,
            ],
        ];
    }

    private function screenshotPlan(): array
    {
        $shots = [];
        foreach ($this->visualRoutes() as $route) {
            foreach ($this->viewportsForRoute($route) as $viewport) {
                $shots[] = [
                    'scope' => $route['scope'],
                    'label' => $route['label'],
                    'path' => $route['path'],
                    'viewport' => $viewport['label'],
                    'size' => $viewport['width'] . 'x' . $viewport['height'],
                    'width' => $viewport['width'],
                    'height' => $viewport['height'],
                    'login' => !empty($route['requiresBackendLogin']) ? 'seller backend' : (!empty($route['requiresLogin']) ? 'frontend user' : 'none'),
                    'file' => $route['scope'] . '-' . $route['label'] . '-' . $viewport['label'] . '.png',
                ];
            }
        }

        return $shots;
    }

    private function viewportsForRoute(array $route): array
    {
        $mobile = ['label' => 'mobile-390x844', 'width' => 390, 'height' => 844];
        $desktop = ['label' => 'desktop-1280x900', 'width' => 1280, 'height' => 900];
        if (($route['scope'] ?? '') === 'merchant' || !empty($route['requiresLogin']) || !empty($route['authGate'])) {
            return [$mobile];
        }

        return [$mobile, $desktop];
    }

    private function requireFileMarkers(string $label, string $path, array $markers): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        if (!$this->containsAll($content, $markers)) {
            $this->addCheck($label, 'FAIL', $path, 'Missing one or more required markers.');
            return;
        }

        $this->addCheck($label, 'PASS', $path, 'Required readiness markers are present.');
    }

    private function get(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1 MongoyiaPwaVisualQa/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                    $status = (int)$matches[1];
                }
            }
        }

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    }

    private function addCheck(string $area, string $status, string $evidence, string $notes): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') {
            $this->failures++;
        } elseif ($status === 'PENDING') {
            $this->pending++;
        } elseif ($status === 'INFO') {
            $this->infos++;
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
            '# Mongoyia PWA Visual QA Readiness',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Evidence type: Browser-independent visual QA route/readiness plan. Screenshot files are optional unless `--requireScreenshots=1` is used.',
            '- Screenshot validation: PNG signature, IHDR dimensions, planned viewport width allowing browser scrollbar width, minimum viewport height, and minimum byte size.',
            '',
            '## Summary',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Failures | ' . $this->failures . ' |',
            '| Warnings | ' . $this->warnings . ' |',
            '| Pending | ' . $this->pending . ' |',
            '| Info | ' . $this->infos . ' |',
            '| Planned screenshots | ' . count($this->screenshotPlan()) . ' |',
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
            '## Screenshot Plan',
            '',
            '| Scope | Page | Path | Viewport | Login | Expected file |',
            '|---|---|---|---|---|---|',
        ]);

        foreach ($this->screenshotPlan() as $shot) {
            $lines[] = '| ' . $this->mdCell($shot['scope']) . ' | '
                . $this->mdCell($shot['label']) . ' | `'
                . $this->mdCell($shot['path']) . '` | '
                . $this->mdCell($shot['size']) . ' | '
                . $this->mdCell($shot['login']) . ' | `'
                . $this->mdCell($shot['file']) . '` |';
        }

        $lines = array_merge($lines, [
            '',
            '## Follow-up',
            '',
            '- Capture screenshots for the listed files with a browser tool on local or test server.',
            '- Re-run this command with `--screenshotsDir=<dir> --requireScreenshots=1` to turn missing or invalid screenshots into failures.',
            '- Planned PNG files must be at least `' . (int)$this->minScreenshotBytes . '` bytes, match the planned viewport width or browser content width after scrollbar, and be at least the planned viewport height.',
            '- This command does not call payment providers, WSS, or production credentials.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function latestHandoverFile(string $pattern): string
    {
        $files = glob($this->resolvePath($this->handoverDir) . DIRECTORY_SEPARATOR . $pattern);
        if (!$files) {
            return '';
        }

        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }

    private function readReportResult(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return 'not generated';
        }

        $content = (string)file_get_contents($path);
        if (preg_match('/^- Result:\s*([A-Z]+)\s*$/m', $content, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    private function containsAll(string $content, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (stripos($content, (string)$marker) === false) {
                return false;
            }
        }

        return true;
    }

    private function containsAny(string $content, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (stripos($content, (string)$marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function fatalMarkers(): array
    {
        return [
            'PHP Fatal error',
            'yii\\base\\ErrorException',
            'Stack trace',
            'Call to undefined',
            'SQLSTATE[',
        ];
    }

    private function screenshotIssue(string $file, array $shot): string
    {
        $size = filesize($file);
        if ($size === false || $size < (int)$this->minScreenshotBytes) {
            return 'file too small';
        }

        $info = $this->pngInfo($file);
        if ($info === null) {
            return 'not a valid PNG';
        }

        $expectedWidth = (int)($shot['width'] ?? 0);
        $expectedMinHeight = (int)($shot['height'] ?? 0);
        if ($expectedWidth > 0 && !$this->isAcceptableScreenshotWidth((int)$info['width'], $expectedWidth)) {
            return 'width ' . (int)$info['width'] . ' expected ' . $expectedWidth . ' or scrollbar-adjusted width';
        }
        if ($expectedMinHeight > 0 && (int)$info['height'] < $expectedMinHeight) {
            return 'height ' . (int)$info['height'] . ' below ' . $expectedMinHeight;
        }

        return '';
    }

    private function isAcceptableScreenshotWidth(int $actualWidth, int $expectedWidth): bool
    {
        if ($actualWidth === $expectedWidth) {
            return true;
        }

        return $actualWidth < $expectedWidth && $actualWidth >= ($expectedWidth - 20);
    }

    private function pngInfo(string $file): ?array
    {
        $handle = @fopen($file, 'rb');
        if (!$handle) {
            return null;
        }

        $header = fread($handle, 24);
        fclose($handle);
        if (!is_string($header) || strlen($header) < 24) {
            return null;
        }
        if (substr($header, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        if (substr($header, 12, 4) !== 'IHDR') {
            return null;
        }

        $width = unpack('N', substr($header, 16, 4));
        $height = unpack('N', substr($header, 20, 4));
        if (!$width || !$height || (int)$width[1] <= 0 || (int)$height[1] <= 0) {
            return null;
        }

        return [
            'width' => (int)$width[1],
            'height' => (int)$height[1],
        ];
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-pwa-visual-qa-' . date('Ymd-His') . '.md';
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
