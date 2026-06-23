<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class AppPhase13AcceptanceController extends Controller
{
    public const VERSION = 'MONGOYIA_APP_PHASE13_ACCEPTANCE_V1';

    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $buyerApiAccepted = false;
    public $sellerApiAccepted = false;
    public $browserAccepted = false;
    public $appAccepted = false;
    public $buyerEvidencePath = '';
    public $sellerEvidencePath = '';
    public $browserEvidencePath = '';
    public $appEvidencePath = '';

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
            'buyerApiAccepted',
            'sellerApiAccepted',
            'browserAccepted',
            'appAccepted',
            'buyerEvidencePath',
            'sellerEvidencePath',
            'browserEvidencePath',
            'appEvidencePath',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia Phase 13 APP acceptance\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkRouteMatrix();
        }
        $this->checkManualAcceptanceInputs();

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0))) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Phase 13 source coverage');
        $this->requireFileContains('Phase 13 backlog registration', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'Full buyer and seller APP completion',
            'app-phase13-acceptance/run',
        ]);
        $this->requireFileContains('uni-app package manifest', 'apps/mongoyia-customer-chat-uniapp/package.json', [
            'mongoyia-customer-chat-uniapp',
            'dev:h5',
            'build:h5',
        ]);
        $this->requireFileContains('uni-app route registry', 'apps/mongoyia-customer-chat-uniapp/src/pages.json', [
            'pages/buyer/home',
            'pages/buyer/category',
            'pages/buyer/search',
            'pages/buyer/product',
            'pages/buyer/cart',
            'pages/buyer/orders',
            'pages/buyer/account',
            'pages/seller/dashboard',
            'pages/seller/products',
            'pages/seller/orders',
            'pages/seller/coupons',
            'pages/seller/ops',
            'pages/chat/index',
            'tabBar',
        ]);
        $this->requireFileContains('Phase 13 shared APP API helper', 'apps/mongoyia-customer-chat-uniapp/src/utils/appApi.js', [
            'MONGOYIA_PHASE13_APP_SHELL_V1',
            'BUYER_ENDPOINTS',
            'SELLER_ENDPOINTS',
            'normalizeListPayload',
        ]);
        $this->requireFileContains('Buyer APP JSON API service', 'common/services/mall/AppBuyerApiService.php', [
            'MONGOYIA_APP_BUYER_API_V1',
            'MONGOYIA_APP_BUYER_CHECKOUT_WRITE_V1',
            'home',
            'categories',
            'search',
            'product',
            'addCart',
            'submitOrder',
            'coupons',
            'favorites',
            'myReviews',
        ]);
        $this->requireFileContains('Buyer APP JSON API controller', 'api/modules/v1/controllers/AppBuyerController.php', [
            'MONGOYIA_APP_BUYER_CONTROLLER_V1',
            'actionHome',
            'actionCategories',
            'actionSearch',
            'actionProduct',
            'actionCart',
            'actionOrders',
            'submitOrder',
            'actionCoupons',
            'actionFavorites',
            'actionMyReviews',
        ]);
        $this->requireFileContains('Buyer APP JSON API readiness', 'console/controllers/AppBuyerPhase13ReadinessController.php', [
            'MONGOYIA_APP_BUYER_PHASE13_READINESS_V1',
            'app-buyer-phase13-readiness/run',
            'checkout/order creation validates cart',
        ]);
        $this->requireFileContains('Seller APP JSON API service', 'common/services/mall/AppSellerApiService.php', [
            'MONGOYIA_APP_SELLER_API_V1',
            'dashboard',
            'products',
            'orders',
            'MONGOYIA_APP_SELLER_SHIPMENT_WRITE_V1',
            'shipOrder',
            'MONGOYIA_APP_SELLER_PRODUCT_WRITE_V1',
            'saveProduct',
            'MONGOYIA_APP_SELLER_COUPON_PARTICIPATION_WRITE_V1',
            'participateCoupon',
        ]);
        $this->requireFileContains('Seller APP JSON API controller', 'api/modules/v1/controllers/AppSellerController.php', [
            'MONGOYIA_APP_SELLER_CONTROLLER_V1',
            'actionDashboard',
            'actionProducts',
            'actionOrders',
            'actionShipment',
            'sellerStoreId',
            'shipOrder',
            'saveProduct',
            'participateCoupon',
        ]);
        $this->requireFileContains('Seller APP JSON API readiness', 'console/controllers/AppSellerPhase13ReadinessController.php', [
            'MONGOYIA_APP_SELLER_PHASE13_READINESS_V1',
            'app-seller-phase13-readiness/run',
            'shipment write uses existing paid/COD checks',
        ]);
        $this->requireFileContains('APP auth handoff readiness', 'console/controllers/AppAuthPhase13ReadinessController.php', [
            'MONGOYIA_APP_AUTH_PHASE13_READINESS_V1',
            'app-auth-phase13-readiness/run',
            'access-token header',
        ]);
        $this->requireFileContains('APP API URL manager hyphen route support', 'api/config/main.php', [
            '<modules:[\w-]+>/<controller:[\w-]+>/<action:[\w-]+>',
        ]);
        foreach ($this->pageMarkers() as $label => $config) {
            $this->requireFileContains($label, $config['path'], $config['markers']);
        }
        $this->requireFileContains('Phase 9 customer-service APP route reused', 'apps/mongoyia-customer-chat-uniapp/src/pages/chat/index.vue', [
            'MONGOYIA_CUSTOMER_SERVICE_UNIAPP_CHAT_V1',
            'data-mongoyia-customer-service-uniapp',
            'submitRating',
        ]);
    }

    private function checkRouteMatrix(): void
    {
        $this->section('Phase 13 route matrix');
        foreach ($this->pageMarkers() as $label => $config) {
            $this->addCheck($label, is_file($this->resolvePath($config['path'])) ? 'PASS' : 'FAIL', $config['path'], $config['notes']);
        }
    }

    private function checkManualAcceptanceInputs(): void
    {
        $this->section('Phase 13 implementation and external evidence');
        $this->manualFlag(
            'Buyer APP JSON API acceptance',
            $this->buyerApiAccepted,
            $this->buyerEvidencePath,
            'Buyer APP home/category/search/product/cart/order APIs were accepted.',
            'Validate buyer APP JSON APIs for home, category, search, product detail, cart, checkout order creation, coupons, favorites, reviews, and customer-service entry.'
        );
        $this->manualFlag(
            'Seller APP JSON API acceptance',
            $this->sellerApiAccepted,
            $this->sellerEvidencePath,
            'Seller APP dashboard/product/order/shipment APIs were accepted.',
            'Validate seller APP JSON APIs for store profile, product list, order shipment write, logistics fee, deposit, coupons, statistics, and distribution overview.'
        );
        $this->manualFlag(
            'Browser H5 APP role-flow acceptance',
            $this->browserAccepted,
            $this->browserEvidencePath !== '' ? $this->browserEvidencePath : $this->baseUrl,
            'H5 APP buyer and seller role-flow browser evidence was accepted.',
            'Validate H5 buyer order flow, seller shipment flow, customer-service chat, refresh persistence, and mobile layout in browser.'
        );
        $this->manualFlag(
            'APP development package acceptance',
            $this->appAccepted,
            $this->appEvidencePath,
            'uni-app/H5 development package was accepted.',
            'Run the uni-app/H5 package, verify routing, role flows, refresh persistence, and absence of blocking frontend errors.'
        );
    }

    private function pageMarkers(): array
    {
        return [
            'Buyer home route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/home.vue',
                'markers' => ['data-mongoyia-phase13-buyer-home', 'BUYER_ENDPOINTS.home', 'openSearch', 'openAccount'],
                'notes' => 'Buyer home/search/category/cart/order/seller navigation shell is present.',
            ],
            'Buyer category route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/category.vue',
                'markers' => ['data-mongoyia-phase13-buyer-category', 'BUYER_ENDPOINTS.category', 'openSearch'],
                'notes' => 'Buyer category list shell is present.',
            ],
            'Buyer search route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/search.vue',
                'markers' => ['data-mongoyia-phase13-buyer-search', 'BUYER_ENDPOINTS.search', 'filters'],
                'notes' => 'Buyer search/filter result shell is present.',
            ],
            'Buyer product route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/product.vue',
                'markers' => ['data-mongoyia-phase13-buyer-product', 'BUYER_ENDPOINTS.product', 'openChat', 'addCart', 'toggleFavorite'],
                'notes' => 'Buyer product detail/cart/customer-service shell is present.',
            ],
            'Buyer cart route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/cart.vue',
                'markers' => ['data-mongoyia-phase13-buyer-cart', 'BUYER_ENDPOINTS.cart', 'checkout'],
                'notes' => 'Buyer cart/checkout shell is present.',
            ],
            'Buyer orders route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/orders.vue',
                'markers' => ['data-mongoyia-phase13-buyer-orders', 'BUYER_ENDPOINTS.orders', 'submitOrder'],
                'notes' => 'Buyer order list/submit shell is present.',
            ],
            'Buyer account route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/account.vue',
                'markers' => ['data-mongoyia-phase13-buyer-account', 'BUYER_ENDPOINTS.coupons', 'BUYER_ENDPOINTS.favorites', 'BUYER_ENDPOINTS.storeFavorites', 'BUYER_ENDPOINTS.myReviews'],
                'notes' => 'Buyer coupons, product favorites, store favorites, and own reviews shell is present.',
            ],
            'Seller dashboard route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/dashboard.vue',
                'markers' => ['data-mongoyia-phase13-seller-dashboard', 'SELLER_ENDPOINTS.dashboard', 'goProducts', 'goOrders', 'goCoupons', 'goOps'],
                'notes' => 'Seller dashboard shell is present.',
            ],
            'Seller products route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/products.vue',
                'markers' => ['data-mongoyia-phase13-seller-products', 'data-mongoyia-phase13-seller-product-write', 'SELLER_ENDPOINTS.products', 'loadProducts', 'saveProduct'],
                'notes' => 'Seller product management list and audited create/edit shell is present.',
            ],
            'Seller orders route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/orders.vue',
                'markers' => ['data-mongoyia-phase13-seller-orders', 'SELLER_ENDPOINTS.orders', 'submitShipment'],
                'notes' => 'Seller order shipment shell is present.',
            ],
            'Seller coupons route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/coupons.vue',
                'markers' => ['data-mongoyia-phase13-seller-coupons', 'data-mongoyia-phase13-seller-coupon-write', 'SELLER_ENDPOINTS.coupons', 'participateCoupon'],
                'notes' => 'Seller coupon summary and platform participation join/leave shell is present.',
            ],
            'Seller operations route shell' => [
                'path' => 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/ops.vue',
                'markers' => ['data-mongoyia-phase13-seller-ops', 'SELLER_ENDPOINTS.logistics', 'SELLER_ENDPOINTS.deposit', 'SELLER_ENDPOINTS.statistics', 'SELLER_ENDPOINTS.distribution'],
                'notes' => 'Seller store profile, logistics fee, deposit, statistics, and distribution overview shell is present.',
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
            '# Mongoyia Phase 13 APP Acceptance',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Scope: buyer APP, seller APP workbench, audited seller product create/edit, seller coupon participation join/leave, seller store/logistics/deposit/statistics/distribution overview, shared backend APIs, customer-service entry, H5 development package, and role-flow evidence.',
            '- Safety: this command does not mutate orders, carts, products, shipment rows, funds, stock, or credentials.',
            '- Boundary: Phase 13 verifies the APP route shell, buyer checkout write, seller shipment write, seller product create/edit submission, and seller platform coupon participation join/leave. Seller product writes are forced inactive/submitted for platform review, and coupon participation writes do not issue coupons or mutate orders. Browser/APP role-flow evidence remains pending until later acceptance.',
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
            '/www/server/php/83/bin/php yii app-seller-phase13-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii app-auth-phase13-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii app-phase13-acceptance/run --fixture=1 --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function manualFlag(string $area, bool $accepted, string $evidence, string $passNotes, string $pendingNotes): void
    {
        if ($accepted) {
            $this->addCheck($area, 'PASS', $evidence !== '' ? $evidence : 'external evidence recorded', $passNotes);
            return;
        }

        $this->addCheck($area, 'PENDING', $evidence !== '' ? $evidence : 'pending external evidence', $pendingNotes);
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

        $this->addCheck($label, 'PASS', $path, 'Required Phase 13 markers are present.');
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

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-app-phase13-acceptance-' . date('Ymd-His') . '.md';
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
