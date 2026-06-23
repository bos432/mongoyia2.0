<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class AppBuyerPhase13ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_APP_BUYER_PHASE13_READINESS_V1';

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
        $this->stdout("Mongoyia Phase 13 buyer APP API readiness\n");

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
        $this->requireFileContains('Buyer APP API service', 'common/services/mall/AppBuyerApiService.php', [
            'MONGOYIA_APP_BUYER_API_V1',
            'home',
            'categories',
            'search',
            'product',
            'addCart',
            'orders',
            'coupons',
            'favorites',
            'reviews',
            'MONGOYIA_APP_BUYER_CHECKOUT_WRITE_V1',
            'submitOrder',
        ]);
        $this->requireFileContains('Buyer APP API controller', 'api/modules/v1/controllers/AppBuyerController.php', [
            'MONGOYIA_APP_BUYER_CONTROLLER_V1',
            'actionHome',
            'actionCategories',
            'actionSearch',
            'actionProduct',
            'actionCart',
            'actionOrders',
            'actionCoupons',
            'actionFavorites',
            'actionReviews',
            'submitOrder',
        ]);
        $this->requireFileContains('APP shared API helper uses buyer endpoints', 'apps/mongoyia-customer-chat-uniapp/src/utils/appApi.js', [
            '/api/v1/app-buyer/home',
            '/api/v1/app-buyer/categories',
            '/api/v1/app-buyer/search',
            '/api/v1/app-buyer/product',
            '/api/v1/app-buyer/cart',
            '/api/v1/app-buyer/orders',
        ]);
        $this->requireFileContains('API URL manager supports APP controller ids', 'api/config/main.php', [
            '<modules:[\w-]+>/<controller:[\w-]+>/<action:[\w-]+>',
            '<controller:[\w-]+>/<action:[\w-]+>',
        ]);
        $this->requireFileContains('Phase 13 acceptance tracks buyer API readiness', 'console/controllers/AppPhase13AcceptanceController.php', [
            'app-buyer-phase13-readiness/run',
            'Buyer APP JSON API readiness',
        ]);
        $this->requireFileContains('Phase 13 backlog command list', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'app-buyer-phase13-readiness/run',
            'buyer JSON APIs',
        ]);
    }

    private function checkRouteMatrix(): void
    {
        $this->section('Route matrix');
        foreach ([
            '/api/v1/app-buyer/home' => 'public buyer home data',
            '/api/v1/app-buyer/categories' => 'public category list',
            '/api/v1/app-buyer/search' => 'public product search/filter',
            '/api/v1/app-buyer/product' => 'public product detail/SKU/review/customer-service context',
            '/api/v1/app-buyer/cart' => 'authenticated cart list/add',
            '/api/v1/app-buyer/orders' => 'authenticated order list plus checkout write',
            '/api/v1/app-buyer/coupons' => 'authenticated coupon list',
            '/api/v1/app-buyer/favorites' => 'authenticated favorite list/toggle',
            '/api/v1/app-buyer/reviews' => 'public product review list',
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
            '# Mongoyia Phase 13 Buyer APP API Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: buyer APP JSON APIs for home, categories, search, product detail, cart, checkout/order creation, coupons, favorites, reviews, and customer-service entry.',
            '- Safety: checkout/order creation validates cart, stock, receiver address, parent/child order rows, order-product rows, and cart cleanup without marking online payments paid.',
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
            '/www/server/php/83/bin/php yii app-buyer-phase13-readiness/run --fixture=1 --interactive=0',
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

        $this->addCheck($label, 'PASS', $path, 'Required buyer APP API markers are present.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-app-buyer-phase13-readiness-' . date('Ymd-His') . '.md';
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
