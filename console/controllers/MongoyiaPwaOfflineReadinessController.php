<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaPwaOfflineReadinessController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $strict = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $infos = 0;
    private $cacheName = '';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'handoverDir',
            'outputPath',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia PWA offline/install readiness\n");

        $this->checkServiceWorkerCacheVersion();
        $assets = $this->checkServiceWorkerCache();
        $this->checkServiceWorkerBoundaries();
        $this->checkOfflinePageFile();
        $this->checkOfflineHttpAsset();
        $this->checkManifestAndLayout($assets);
        $this->checkBrowserSimulationBoundary();

        $result = $this->result();
        $path = $this->writeReport($result, $assets);
        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->infos} info.\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkServiceWorkerCache(): array
    {
        $path = $this->resolvePath('web/pwa-sw.js');
        if (!is_file($path)) {
            $this->addCheck('Service worker cache asset list', 'FAIL', 'web/pwa-sw.js', 'Service worker file is missing.');
            return [];
        }

        $content = (string)file_get_contents($path);
        $assets = $this->extractServiceWorkerAssets($content);
        if (!$assets) {
            $this->addCheck('Service worker cache asset list', 'FAIL', 'web/pwa-sw.js', 'Could not parse MONGOYIA_PWA_ASSETS.');
            return [];
        }

        $expected = ['/', '/manifest.webmanifest', '/pwa-offline.html', '/pwa-icon.svg', '/pwa-maskable.svg'];
        foreach ($expected as $asset) {
            if (!in_array($asset, $assets, true)) {
                $this->addCheck('Service worker cache asset list', 'FAIL', 'web/pwa-sw.js', "Install cache missing {$asset}.");
                return $assets;
            }
        }

        foreach ($assets as $asset) {
            if (!$this->isSafeOfflineAsset($asset)) {
                $this->addCheck('Service worker cache asset list', 'FAIL', 'web/pwa-sw.js', "Install cache contains dynamic or unsafe asset {$asset}.");
                return $assets;
            }
        }

        $this->addCheck('Service worker cache asset list', 'PASS', 'web/pwa-sw.js', 'Install cache contains only safe shell/offline/icon assets.');
        return $assets;
    }

    private function checkServiceWorkerCacheVersion(): void
    {
        $path = $this->resolvePath('web/pwa-sw.js');
        if (!is_file($path)) {
            $this->addCheck('Service worker cache version', 'FAIL', 'web/pwa-sw.js', 'Service worker file is missing.');
            return;
        }

        $content = (string)file_get_contents($path);
        $this->cacheName = $this->extractServiceWorkerCacheName($content);
        if ($this->cacheName === '') {
            $this->addCheck('Service worker cache version', 'FAIL', 'web/pwa-sw.js', 'Could not parse MONGOYIA_PWA_CACHE.');
            return;
        }
        if (!preg_match('/^mongoyia-pwa-shell-v\d+$/', $this->cacheName)) {
            $this->addCheck('Service worker cache version', 'FAIL', 'web/pwa-sw.js', 'Cache name must be versioned like mongoyia-pwa-shell-v1.');
            return;
        }

        $this->addCheck('Service worker cache version', 'PASS', 'web/pwa-sw.js', 'Versioned cache name is ' . $this->cacheName . '.');
    }

    private function checkServiceWorkerBoundaries(): void
    {
        $path = 'web/pwa-sw.js';
        $this->requireFileMarkers('Service worker fetch boundaries', $path, [
            "request.method !== 'GET'",
            'url.origin !== self.location.origin',
            "request.mode === 'navigate'",
            "caches.match('/pwa-offline.html')",
            'response.status !== 200',
            "response.type !== 'basic'",
        ]);
        $this->requireFileMarkers('Service worker lifecycle boundaries', $path, [
            'self.skipWaiting()',
            'self.clients.claim()',
            'caches.delete(key)',
            'caches.open(MONGOYIA_PWA_CACHE)',
        ]);
    }

    private function checkOfflinePageFile(): void
    {
        $path = $this->resolvePath('web/pwa-offline.html');
        if (!is_file($path)) {
            $this->addCheck('Offline fallback page file', 'FAIL', 'web/pwa-offline.html', 'Offline fallback page is missing.');
            return;
        }

        $content = (string)file_get_contents($path);
        $required = [
            '<!doctype html>',
            'name="viewport"',
            'name="theme-color"',
            'Mongoyia is offline',
            'Try again',
            'href="/"',
        ];
        foreach ($required as $marker) {
            if (stripos($content, $marker) === false) {
                $this->addCheck('Offline fallback page file', 'FAIL', 'web/pwa-offline.html', "Missing marker {$marker}.");
                return;
            }
        }

        $forbidden = ['<script', 'http://', 'https://', 'src=', 'rel="stylesheet"'];
        foreach ($forbidden as $marker) {
            if (stripos($content, $marker) !== false) {
                $this->addCheck('Offline fallback page file', 'FAIL', 'web/pwa-offline.html', "Offline fallback should be self-contained; found {$marker}.");
                return;
            }
        }

        $this->addCheck('Offline fallback page file', 'PASS', 'web/pwa-offline.html', 'Offline page is self-contained and has mobile/home markers.');
    }

    private function checkOfflineHttpAsset(): void
    {
        $response = $this->get($this->baseUrl . '/pwa-offline.html');
        if ((int)$response['status'] < 200 || (int)$response['status'] >= 400) {
            $this->addCheck('Offline fallback HTTP asset', 'FAIL', '/pwa-offline.html', 'Expected HTTP 2xx/3xx, got ' . (int)$response['status'] . '.');
            return;
        }
        if (stripos($response['body'], 'Mongoyia is offline') === false || stripos($response['body'], 'Try again') === false) {
            $this->addCheck('Offline fallback HTTP asset', 'FAIL', '/pwa-offline.html', 'HTTP response is missing offline page markers.');
            return;
        }

        $this->addCheck('Offline fallback HTTP asset', 'PASS', '/pwa-offline.html', 'Offline page is reachable over HTTP.');
    }

    private function checkManifestAndLayout(array $assets): void
    {
        $manifestPath = $this->resolvePath('web/manifest.webmanifest');
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            $this->addCheck('Manifest install/offline linkage', 'FAIL', 'web/manifest.webmanifest', 'Manifest JSON is invalid.');
            return;
        }

        foreach (['/pwa-icon.svg', '/pwa-maskable.svg'] as $icon) {
            if (!in_array($icon, $assets, true)) {
                $this->addCheck('Manifest install/offline linkage', 'FAIL', 'web/manifest.webmanifest', "Icon {$icon} must also be in the install cache.");
                return;
            }
        }
        if ((string)($manifest['start_url'] ?? '') !== '/?source=pwa') {
            $this->addCheck('Manifest install/offline linkage', 'FAIL', 'web/manifest.webmanifest', 'Manifest start_url must remain /?source=pwa.');
            return;
        }

        $this->requireFileMarkers('Frontend service-worker registration', 'web/resources/mall/default/views/layouts/main.php', [
            'navigator.serviceWorker.register',
            '/pwa-sw.js',
            "scope: '/'",
            'rel="manifest"',
            '/manifest.webmanifest',
        ]);
        $this->addCheck('Manifest install/offline linkage', 'PASS', 'web/manifest.webmanifest', 'Manifest start URL and icon cache linkage are ready.');
    }

    private function checkBrowserSimulationBoundary(): void
    {
        $this->addCheck('Browser offline simulation evidence', 'INFO', 'not supplied', 'This readiness is source/HTTP based. Use browser/devtools offline mode for final visual signoff when a screenshot tool is available.');
    }

    private function extractServiceWorkerAssets(string $content): array
    {
        if (!preg_match('/MONGOYIA_PWA_ASSETS\s*=\s*\[(.*?)\]\s*;/s', $content, $matches)) {
            return [];
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $matches[1], $assetMatches);
        return array_values(array_unique($assetMatches[1] ?? []));
    }

    private function extractServiceWorkerCacheName(string $content): string
    {
        if (!preg_match('/MONGOYIA_PWA_CACHE\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $matches)) {
            return '';
        }

        return trim((string)$matches[1]);
    }

    private function isSafeOfflineAsset(string $asset): bool
    {
        if ($asset === '/' || in_array($asset, ['/manifest.webmanifest', '/pwa-offline.html', '/pwa-icon.svg', '/pwa-maskable.svg'], true)) {
            return true;
        }
        foreach (['/backend', '/mall/user', '/mall/payment', '/mall/chat', '/mall/order', 'http://', 'https://', '//'] as $unsafe) {
            if (stripos($asset, $unsafe) === 0 || stripos($asset, $unsafe) !== false) {
                return false;
            }
        }

        return false;
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

        $this->addCheck($label, 'PASS', $path, 'Required markers are present.');
    }

    private function get(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => "User-Agent: MongoyiaPwaOfflineReadiness/1.0\r\n",
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
        if ($this->warnings > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function writeReport(string $result, array $assets): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia PWA Offline/Install Readiness',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Scope: source/HTTP readiness for service-worker cache boundaries, offline fallback page, and install linkage.',
            '',
            '## Summary',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Failures | ' . $this->failures . ' |',
            '| Warnings | ' . $this->warnings . ' |',
            '| Info | ' . $this->infos . ' |',
            '| Install cache assets | ' . count($assets) . ' |',
            '| Cache name | `' . $this->mdCell($this->cacheName !== '' ? $this->cacheName : 'unknown') . '` |',
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
            '## Install Cache Assets',
            '',
            '| Asset |',
            '|---|',
        ]);
        foreach ($assets as $asset) {
            $lines[] = '| `' . $this->mdCell($asset) . '` |';
        }

        $lines = array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- POST and other non-GET requests are not cached.',
            '- Cross-origin requests are not cached.',
            '- Navigation requests fall back to `/pwa-offline.html` when neither network nor cache is available.',
            '- Dynamic authenticated, backend, payment, chat, and order pages are not part of the install cache.',
            '- Cache names must stay versioned as `mongoyia-pwa-shell-vN` so old install caches are cleaned during activate.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-pwa-offline-readiness-' . date('Ymd-His') . '.md';
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
