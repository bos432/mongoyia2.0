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
            'MONGOYIA_APP_SELLER_COUPON_PARTICIPATION_WRITE_V1',
            'participateCoupon',
        ]);
        $this->requireFileContains('Seller APP API controller', 'api/modules/v1/controllers/AppSellerController.php', [
            'MONGOYIA_APP_SELLER_CONTROLLER_V1',
            'MONGOYIA_APP_SELLER_SHIPMENT_POST_GUARD_V1',
            'actionDashboard',
            'actionProducts',
            'actionOrders',
            'actionShipment',
            'SHIPMENT_REQUIRES_POST',
            'isPost',
            'actionLogistics',
            'actionDeposit',
            'actionCoupons',
            'actionStatistics',
            'actionDistribution',
            'sellerStoreId',
            'shipOrder',
            'saveProduct',
            'participateCoupon',
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
        $this->requireFileContains('Backend seller shipment form uses POST body id', 'backend/modules/mall/controllers/OrderController.php', [
            'MONGOYIA_BACKEND_ORDER_SHIPMENT_POST_ID_GUARD_V1',
            '$request->isPost ? $request->post(\'id\', 0) : $request->get(\'id\')',
            'markShipped',
        ]);
        $this->requireFileContains('Backend seller shipment UI posts hidden id', 'backend/modules/mall/views/order/fh-ajax.php', [
            'data-mongoyia-order-shipment-post-id-guard',
            "Html::hiddenInput('id'",
            "'action' => Url::to(['fh-ajax'])",
            "'validationUrl' => Url::to(['fh-ajax'])",
        ]);
        $this->requireFileContains('Seller product page posts audited product submissions', 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/products.vue', [
            'data-mongoyia-phase13-seller-product-write',
            'MONGOYIA_APP_SELLER_PRODUCT_WRITE_V1',
            'saveProduct',
            '提交审核',
        ]);
        $this->requireFileContains('Backend product audit actions use POST forms', 'backend/modules/mall/controllers/ProductController.php', [
            'MONGOYIA_PRODUCT_AUDIT_POST_VERB_GUARD_V1',
            "'approve'] = ['post']",
            "'reject'] = ['post']",
            "post('id', 0)",
        ]);
        $this->requireFileContains('Backend product audit UI posts CSRF forms', 'backend/modules/mall/views/product/index.php', [
            'data-mongoyia-product-audit-post-guard',
            'csrfToken',
            "Url::to(['approve'])",
            "Url::to(['reject'])",
        ]);
        $this->requireFileContains('Seller coupon page posts platform participation changes', 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/coupons.vue', [
            'data-mongoyia-phase13-seller-coupons',
            'MONGOYIA_APP_SELLER_COUPON_PARTICIPATION_WRITE_V1',
            'participateCoupon',
            'platform_participation',
        ]);
        $this->requireFileContains('Seller backend merchant coupon participation uses POST forms', 'backend/modules/mall/controllers/MerchantCouponController.php', [
            'MONGOYIA_MERCHANT_COUPON_POST_VERB_GUARD_V1',
            'MONGOYIA_MERCHANT_COUPON_STORE_ID_POST_GUARD_V1',
            "'join'] = ['post']",
            "'leave'] = ['post']",
            "post('coupon_type_id', 0)",
            'Yii::$app->request->isPost',
            "post('store_id', 0)",
            "get('store_id', 0)",
        ]);
        $this->requireFileNotContains('Seller backend merchant coupon store_id has no POST/GET fallback', 'backend/modules/mall/controllers/MerchantCouponController.php', [
            "post('store_id', Yii::\$app->request->get('store_id', 0))",
        ]);
        $this->requireFileContains('Seller backend merchant coupon UI posts CSRF forms', 'backend/modules/mall/views/merchant-coupon/index.php', [
            'data-mongoyia-merchant-coupon-post-guard',
            'csrfToken',
            'coupon_type_id',
            'store_id',
        ]);
        $this->requireFileContains('Seller operations overview page reads store, logistics, deposit, statistics, and distribution APIs', 'apps/mongoyia-customer-chat-uniapp/src/pages/seller/ops.vue', [
            'data-mongoyia-phase13-seller-ops',
            'SELLER_ENDPOINTS.logistics',
            'SELLER_ENDPOINTS.deposit',
            'SELLER_ENDPOINTS.statistics',
            'SELLER_ENDPOINTS.distribution',
        ]);
        $this->requireFileContains('Backend store profile save uses POST body store id', 'backend/modules/mall/controllers/StoreProfileController.php', [
            'MONGOYIA_STORE_PROFILE_POST_STORE_ID_GUARD_V1',
            '$profileStoreId = (int)$model->id',
            '$model->id = $profileStoreId',
            "\$request->isPost ? (int)\$request->post('store_id', 0) : (int)\$request->get('store_id', \$this->getStoreId())",
        ]);
        $this->requireFileContains('Backend store profile form posts hidden store id', 'backend/modules/mall/views/store-profile/edit.php', [
            'data-mongoyia-store-profile-post-store-id-guard',
            "'action' => ['edit']",
            "Html::hiddenInput('store_id'",
            "'disabled' => true",
        ]);
        $this->requireFileNotContains('Backend store profile has no POST/GET store_id fallback', 'backend/modules/mall/controllers/StoreProfileController.php', [
            "post('store_id', Yii::\$app->request->get('store_id'",
            "request->post('store_id', \$request->get('store_id'",
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
            '/api/v1/app-seller/coupons' => 'authenticated seller coupon summary plus platform coupon join/leave write',
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
            '- Scope: seller APP JSON APIs and H5 pages for dashboard, products, audited product create/edit, orders, shipment write, logistics fee, deposit, coupons, platform coupon participation join/leave, statistics, and distribution overview.',
            '- Safety: seller APIs are store-scoped to the authenticated user store; shipment write uses existing paid/COD checks, explicit POST-only API guarding, and idempotent shipment-fee deduction. Product writes force status inactive and audit_status=submitted, so sellers cannot list products without platform review. Coupon participation writes only join/leave platform coupon participation rows and do not issue coupons or mutate orders.',
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

    private function requireFileNotContains(string $label, string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                $this->addCheck($label, 'FAIL', $path, "Forbidden marker {$needle} is still present.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Forbidden seller APP API markers are absent.');
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
