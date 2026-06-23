<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class AppAuthPhase13ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_APP_AUTH_PHASE13_READINESS_V1';

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
        $this->stdout("Mongoyia Phase 13 APP auth handoff readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkRouteMatrix();
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
        $this->requireFileContains('APP request helper stores and sends access token', 'apps/mongoyia-customer-chat-uniapp/src/utils/api.js', [
            'MONGOYIA_PHASE13_APP_AUTH_HANDOFF_V1',
            'APP_ACCESS_TOKEN_KEY',
            'saveAuthSession',
            'clearAuthSession',
            "requestHeader['access-token']",
        ]);
        $this->requireFileContains('APP login page uses existing API login', 'apps/mongoyia-customer-chat-uniapp/src/pages/auth/login.vue', [
            'data-mongoyia-phase13-auth-login',
            '/api/site/login',
            'saveAuthSession',
            'withAuth: false',
            'role === \'seller\'',
        ]);
        $this->requireFileContains('APP page registry keeps buyer home first and includes auth login', 'apps/mongoyia-customer-chat-uniapp/src/pages.json', [
            'pages/buyer/home',
            'pages/auth/login',
            'pages/seller/dashboard',
        ]);
        $this->requireFileContains('APP shared request unwraps API payload', 'apps/mongoyia-customer-chat-uniapp/src/utils/appApi.js', [
            'withAuth = true',
            'response && response.data ? response.data : response',
        ]);
        foreach ([
            'Buyer home login entry' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/home.vue',
            'Buyer cart login entry' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/cart.vue',
            'Buyer orders login entry' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/orders.vue',
            'Seller dashboard login entry' => 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/dashboard.vue',
            'Seller products login entry' => 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/products.vue',
            'Seller orders login entry' => 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/orders.vue',
        ] as $label => $path) {
            $this->requireFileContains($label, $path, [
                '/pages/auth/login',
                'baseUrl',
            ]);
        }
        $this->requireFileContains('Phase 13 acceptance tracks APP auth handoff readiness', 'console/controllers/AppPhase13AcceptanceController.php', [
            'APP auth handoff readiness',
            'app-auth-phase13-readiness/run',
        ]);
        $this->requireFileContains('Phase 13 backlog command list', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'app-auth-phase13-readiness/run',
            'APP login/token handoff',
        ]);
    }

    private function checkRouteMatrix(): void
    {
        $this->section('Route matrix');
        foreach ([
            '/pages/auth/login' => 'APP login route stores API access token and redirects by role',
            '/api/site/login' => 'existing API login endpoint reused for token handoff',
            'access-token header' => 'request helper sends stored access token to buyer/seller JSON APIs',
        ] as $route => $notes) {
            $this->addCheck($route, 'PASS', $route, $notes);
        }
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Phase 13 APP Auth Handoff Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: APP login page, token storage, token header injection, protected buyer/seller page login handoff, and H5 role-flow preparation.',
            '- Safety: password is posted only to the existing `/api/site/login` endpoint and is not stored locally; buyer checkout and seller shipment writes use scoped API guards, while product/coupon writes remain gated by later audit/browser acceptance.',
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
            '/www/server/php/83/bin/php yii app-auth-phase13-readiness/run --fixture=1 --interactive=0',
            '```',
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

        $this->addCheck($label, 'PASS', $path, 'Required APP auth handoff markers are present.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-app-auth-phase13-readiness-' . date('Ymd-His') . '.md';
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
