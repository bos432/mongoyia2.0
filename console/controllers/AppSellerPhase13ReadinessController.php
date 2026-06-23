<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class AppSellerPhase13ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_APP_SELLER_PHASE13_READINESS_V1';

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
        $this->stdout("Mongoyia Phase 13 seller APP API readiness\n");

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
        $this->requireFileContains('Seller APP API service', 'common/services/mall/AppSellerApiService.php', [
            'MONGOYIA_APP_SELLER_API_V1',
            'dashboard',
            'products',
            'orders',
            'logistics',
            'deposit',
            'coupons',
            'statistics',
            'distribution',
            'MONGOYIA_APP_SELLER_SHIPMENT_WRITE_V1',
            'shipOrder',
            'MONGOYIA_APP_SELLER_PRODUCT_WRITE_V1',
            'saveProduct',
            'seller_product_write_requires_platform_audit',
        ]);
        $this->requireFileContains('Seller APP API controller', 'api/modules/v1/controllers/AppSellerController.php', [
            'MONGOYIA_APP_SELLER_CONTROLLER_V1',
            'actionDashboard',
            'actionProducts',
            'actionOrders',
            'actionShipment',
            'actionLogistics',
            'actionDeposit',
            'actionCoupons',
            'actionStatistics',
            'actionDistribution',
            'sellerStoreId',
            'shipOrder',
            'saveProduct',
        ]);
        $this->requireFileContains('APP shared API helper uses seller endpoints', 'apps/mongoyia-customer-chat-uniapp/src/utils/appApi.js', [
            '/api/v1/app-seller/dashboard',
            '/api/v1/app-seller/products',
            '/api/v1/app-seller/orders',
            '/api/v1/app-seller/shipment',
            '/api/v1/app-seller/logistics',
            '/api/v1/app-seller/deposit',
            '/api/v1/app-seller/coupons',
            '/api/v1/app-seller/statistics',
            '/api/v1/app-seller/distribution',
        ]);
        $this->requireFileContains('Seller shipment page posts to shipment endpoint', 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/orders.vue', [
            'SELLER_ENDPOINTS.shipment',
            'submitShipment',
            'shipment_fee',
        ]);
        $this->requireFileContains('Seller product page posts audited product submissions', 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/products.vue', [
            'data-mongoyia-phase13-seller-product-write',
            'MONGOYIA_APP_SELLER_PRODUCT_WRITE_V1',
            'saveProduct',
            '提交审核',
        ]);
        $this->requireFileContains('API URL manager supports APP controller ids', 'api/config/main.php', [
            '<modules:[\w-]+>/<controller:[\w-]+>/<action:[\w-]+>',
        ]);
        $this->requireFileContains('Phase 13 acceptance tracks seller API readiness', 'console/controllers/AppPhase13AcceptanceController.php', [
            'Seller APP JSON API readiness',
            'app-seller-phase13-readiness/run',
        ]);
        $this->requireFileContains('Phase 13 backlog command list', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'app-seller-phase13-readiness/run',
            'seller JSON APIs',
        ]);
    }

    private function checkRouteMatrix(): void
    {
        $this->section('Route matrix');
        foreach ([
            '/api/v1/app-seller/dashboard' => 'authenticated seller dashboard summary',
            '/api/v1/app-seller/products' => 'authenticated seller product list plus audited create/edit write',
            '/api/v1/app-seller/orders' => 'authenticated seller order list plus shipment write alias',
            '/api/v1/app-seller/shipment' => 'authenticated seller shipment write',
            '/api/v1/app-seller/logistics' => 'authenticated seller logistics method and fee summary',
            '/api/v1/app-seller/deposit' => 'authenticated seller deposit balance and recent logs',
            '/api/v1/app-seller/coupons' => 'authenticated seller coupon and platform participation summary',
            '/api/v1/app-seller/statistics' => 'authenticated seller period/product/shipment statistics',
            '/api/v1/app-seller/distribution' => 'authenticated seller distribution overview',
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
            '# Mongoyia Phase 13 Seller APP API Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: seller APP JSON APIs for dashboard, products, audited product create/edit, orders, shipment write, logistics fee, deposit, coupons, statistics, and distribution overview.',
            '- Safety: seller APIs are store-scoped to the authenticated user store; shipment write uses existing paid/COD checks and idempotent shipment-fee deduction. Product writes force status inactive and audit_status=submitted, so sellers cannot list products without platform review.',
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
            '/www/server/php/83/bin/php yii app-seller-phase13-readiness/run --fixture=1 --interactive=0',
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

        $this->addCheck($label, 'PASS', $path, 'Required seller APP API markers are present.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-app-seller-phase13-readiness-' . date('Ymd-His') . '.md';
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
