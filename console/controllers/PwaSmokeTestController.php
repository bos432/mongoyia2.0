<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PwaSmokeTestController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $timeout = 15;
    public $strict = false;
    public $productId = 90;
    public $categoryId = '';
    public $checkMobilePages = true;
    public $checkShortcutRoutes = true;
    public $checkInstallReadiness = true;
    public $checkMobileUiReadiness = true;
    public $checkAuthenticatedPages = true;
    public $frontendEmail = 'codex_payment_test_71@acceptance.local';
    public $frontendPassword = 'CodexPay123';
    public $checkMerchantBackendPages = true;
    public $sellerUsername = 'zhishichanquan';
    public $sellerPassword = '123456';
    public $checkMobileForms = true;
    public $writeEvidence = true;
    public $evidencePath = '';

    private $failures = 0;
    private $warnings = 0;
    private $startedAt = 0;
    private $mobileUiEvidenceRows = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'timeout',
            'strict',
            'productId',
            'categoryId',
            'checkMobilePages',
            'checkShortcutRoutes',
            'checkInstallReadiness',
            'checkMobileUiReadiness',
            'checkAuthenticatedPages',
            'frontendEmail',
            'frontendPassword',
            'checkMerchantBackendPages',
            'sellerUsername',
            'sellerPassword',
            'checkMobileForms',
            'writeEvidence',
            'evidencePath',
        ]);
    }

    public function actionRun()
    {
        $this->startedAt = time();
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia PWA smoke test against {$this->baseUrl}\n");

        $this->checkFiles();
        $this->checkManifest();
        if ($this->checkInstallReadiness) {
            $this->checkInstallReadiness();
        }
        $this->checkServiceWorker();
        $this->checkOfflinePage();
        $this->checkLayoutMarkers();
        $this->checkHttpAssets();
        if ($this->checkShortcutRoutes) {
            $this->checkManifestShortcutRoutes();
        }
        if ($this->checkMobilePages) {
            $this->checkMobilePages();
        }
        if ($this->checkAuthenticatedPages) {
            $this->checkAuthenticatedMobilePages();
        }
        if ($this->checkMerchantBackendPages) {
            $this->checkMerchantBackendMobilePages();
        }
        if ($this->checkMobileUiReadiness) {
            $this->checkMobileUiReadiness();
        }
        if ($this->checkMobileForms) {
            $this->checkMobileFormSubmits();
        }

        $this->writeMobileUiEvidenceReport($this->failures === 0 && (!$this->strict || $this->warnings === 0));

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles(): void
    {
        $this->section('Files');
        foreach ([
            'web/manifest.webmanifest',
            'web/pwa-sw.js',
            'web/pwa-offline.html',
            'web/pwa-icon.svg',
            'web/pwa-maskable.svg',
        ] as $path) {
            $this->requireFile($path);
        }
        $this->requireFileContains('web/resources/mall/default/views/layouts/main.php', [
            'rel="manifest"',
            'theme-color',
            'serviceWorker',
            '/pwa-sw.js',
            'mongoyia-pwa-shell',
            'data-mongoyia-pwa',
        ]);
        foreach ([
            'web/resources/mall/default/views/default/index.php' => 'data-mongoyia-mobile-ui="home"',
            'web/resources/mall/default/views/category/view.php' => 'data-mongoyia-mobile-ui="category"',
            'web/resources/mall/default/views/product/view.php' => 'data-mongoyia-mobile-ui="product"',
            'web/resources/mall/default/views/cart/index.php' => 'data-mongoyia-mobile-ui="cart"',
            'web/resources/mall/default/views/default/login.php' => 'data-mongoyia-mobile-ui="login"',
            'web/resources/mall/default/views/payment/index.php' => 'data-mongoyia-mobile-ui="payment"',
            'web/resources/mall/default/views/payment/cancelled.php' => 'data-mongoyia-mobile-ui="payment-cancelled"',
            'web/resources/mall/default/views/payment/succeeded.php' => 'data-mongoyia-mobile-ui="payment-succeeded"',
            'web/resources/mall/default/views/chat/index.php' => 'data-mongoyia-mobile-ui="chat"',
            'web/resources/mall/default/views/order/view.php' => 'data-mongoyia-mobile-ui="order-detail"',
            'web/resources/mall/default/views/user/order.php' => 'data-mongoyia-mobile-ui="user-order"',
            'web/resources/mall/default/views/user/history.php' => 'data-mongoyia-mobile-ui="user-history"',
            'web/resources/mall/default/views/user/coupon.php' => 'data-mongoyia-mobile-ui="user-coupon"',
            'web/resources/mall/default/views/user/favorite.php' => 'data-mongoyia-mobile-ui="user-favorite"',
            'web/resources/mall/default/views/user/address.php' => 'data-mongoyia-mobile-ui="user-address"',
            'web/resources/mall/default/views/user/setting.php' => 'data-mongoyia-mobile-ui="user-setting"',
            'web/resources/mall/default/views/user/distribution.php' => 'data-mongoyia-mobile-ui="distribution"',
        ] as $path => $marker) {
            $this->requireFileContains($path, [$marker]);
        }
        $this->requireFileContains('frontend/modules/mall/controllers/AddressController.php', [
            'MONGOYIA_BUYER_ADDRESS_DELETE_POST_GUARD_V1',
            'MONGOYIA_BUYER_ADDRESS_EDIT_POST_ID_GUARD_V1',
            "'delete' => ['POST']",
            "isPost",
            "post('id', 0)",
        ]);
        $this->requireFileContains('web/resources/mall/default/views/address/edit.php', [
            'data-mongoyia-address-edit-post-id-guard',
            "Html::hiddenInput('id'",
        ]);
        $this->requireFileContains('web/resources/mall/default/views/user/address_.php', [
            'data-mongoyia-address-delete-post-guard',
            "Html::beginForm(['/mall/address/delete'], 'post'",
            "hiddenInput('id'",
        ]);
        $this->requireFileContains('frontend/modules/mall/controllers/UserController.php', [
            'MONGOYIA_USER_COUPON_CLAIM_POST_GUARD_V1',
            "'getcode' => ['POST']",
            'MONGOYIA_DISTRIBUTION_FRONTEND_POST_VERB_GUARD_V1',
            "'distribution-profile' => ['POST']",
            "'distribution-withdraw' => ['POST']",
        ]);
        $this->requireFileContains('web/resources/mall/default/views/user/distribution.php', [
            'data-mongoyia-distribution-frontend-post-guard="profile"',
            'data-mongoyia-distribution-frontend-post-guard="withdraw"',
        ]);
        $this->requireFileContains('frontend/modules/mall/controllers/CartController.php', [
            'MONGOYIA_CART_STALE_ROW_GUARD_V1',
            'MONGOYIA_CART_AJAX_POST_GUARD_V1',
            "'edit-ajax' => ['POST']",
            "'update-ajax' => ['POST']",
            'Unavailable product',
            '/mall/cart/index',
        ]);
        $this->requireFileContains('web/resources/mall/default/views/cart/index.php', [
            'Unavailable product',
            '/mall/cart/index',
        ]);
        $this->requireFileContains('web/resources/mall/default/views/product/view.php', [
            '/mall/cart/index',
        ]);
        $this->requireFileContains('frontend/modules/mall/controllers/ProductController.php', [
            'MONGOYIA_PRODUCT_FAVORITE_POST_READ_GUARD_V1',
            'MONGOYIA_PRODUCT_REVIEW_AJAX_GET_GUARD_V1',
            "'favorite' => ['GET', 'POST']",
            "'store-favorite' => ['GET', 'POST']",
            "'review' => ['GET']",
            "post('product_id', 0)",
            "get('product_id', 0)",
            "post('store_id', 0)",
            "get('store_id', 0)",
        ]);
        $this->requireFileContains('backend/views/layouts/main-store.php', [
            'mongoyia-merchant-pwa-shell',
        ]);
        $this->requireFileContains('backend/views/layouts/content-store.php', [
            'data-mongoyia-merchant-pwa',
            'mongoyia-merchant-content',
        ]);
        $this->requireFileContains('web/resources/css/site.css', [
            'MONGOYIA_MERCHANT_PWA_DENSITY',
            '.mongoyia-merchant-content .grid-view',
            '.mongoyia-merchant-pwa-shell .main-sidebar',
        ]);
        $this->requireFileContains('web/resources/mall/default/css/style.css', [
            'MONGOYIA_FRONTEND_PWA_DENSITY',
            '.mongoyia-pwa-shell',
            '.mongoyia-pwa-shell .shop-cart-table',
            '.mongoyia-pwa-shell .product-details-text .cart-btn',
            '.mongoyia-pwa-shell .product-top-bar',
            '.mongoyia-pwa-shell .user-card-header-tabs',
            '.mongoyia-pwa-shell .payment-result',
            '.mongoyia-pwa-shell .order-detail',
            'touch-action: manipulation',
        ]);
    }

    private function checkManifest(): void
    {
        $this->section('Manifest');
        $path = $this->projectRoot() . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'manifest.webmanifest';
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest)) {
            $this->fail('manifest.webmanifest is not valid JSON.');
            return;
        }

        $this->assertSame('Mongoyia', (string)($manifest['short_name'] ?? ''), 'Manifest short_name is Mongoyia.');
        $this->assertSame('/?source=pwa', (string)($manifest['start_url'] ?? ''), 'Manifest start_url is PWA home.');
        $this->assertSame('/', (string)($manifest['scope'] ?? ''), 'Manifest scope is root.');
        $this->assertSame('standalone', (string)($manifest['display'] ?? ''), 'Manifest display is standalone.');
        $this->assertSame('portrait', (string)($manifest['orientation'] ?? ''), 'Manifest orientation is portrait.');
        $this->assertSame('#0f766e', strtolower((string)($manifest['theme_color'] ?? '')), 'Manifest theme color is configured.');
        $this->assertTrue(!empty($manifest['icons']) && count($manifest['icons']) >= 2, 'Manifest has app icons.');
        $this->assertTrue(!empty($manifest['shortcuts']) && count($manifest['shortcuts']) >= 3, 'Manifest has core shortcuts.');
        $this->checkManifestShortcuts($manifest['shortcuts'] ?? []);
    }

    private function checkManifestShortcuts(array $shortcuts): void
    {
        $required = [
            'Categories' => '/mall/category/view?source=pwa',
            'Cart' => '/mall/cart/index?source=pwa',
            'Orders' => '/mall/user/order?source=pwa',
        ];
        $actual = [];
        foreach ($shortcuts as $shortcut) {
            if (!is_array($shortcut)) {
                continue;
            }
            $name = (string)($shortcut['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $actual[$name] = $shortcut;
        }

        foreach ($required as $name => $url) {
            if (empty($actual[$name]) || !is_array($actual[$name])) {
                $this->fail("Manifest missing {$name} shortcut.");
                return;
            }
            if ((string)($actual[$name]['url'] ?? '') !== $url) {
                $this->fail("Manifest {$name} shortcut expected {$url}, got " . (string)($actual[$name]['url'] ?? '') . '.');
                return;
            }
            if (empty($actual[$name]['icons']) || !is_array($actual[$name]['icons'])) {
                $this->fail("Manifest {$name} shortcut missing icon.");
                return;
            }
        }

        $this->ok('Manifest shortcuts target core PWA routes and have icons.');
    }

    private function checkInstallReadiness(): void
    {
        $this->section('Install readiness');
        $path = $this->projectRoot() . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'manifest.webmanifest';
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest)) {
            $this->fail('manifest.webmanifest is not valid JSON for install readiness.');
            return;
        }

        foreach ([
            'name',
            'short_name',
            'description',
            'id',
            'start_url',
            'scope',
            'display',
            'orientation',
            'background_color',
            'theme_color',
            'lang',
        ] as $field) {
            if (trim((string)($manifest[$field] ?? '')) === '') {
                $this->fail("Manifest install readiness missing {$field}.");
                return;
            }
        }

        if (!$this->isSameOriginManifestPath((string)$manifest['id']) || strpos((string)$manifest['id'], 'source=pwa') === false) {
            $this->fail('Manifest id must be a same-origin PWA URL with source=pwa.');
            return;
        }
        if (!$this->isSameOriginManifestPath((string)$manifest['start_url']) || strpos((string)$manifest['start_url'], 'source=pwa') === false) {
            $this->fail('Manifest start_url must be a same-origin PWA URL with source=pwa.');
            return;
        }
        if ((string)$manifest['scope'] !== '/') {
            $this->fail('Manifest scope must remain root for install readiness.');
            return;
        }
        if (!in_array((string)$manifest['display'], ['standalone', 'fullscreen', 'minimal-ui'], true)) {
            $this->fail('Manifest display must be installable standalone/fullscreen/minimal-ui.');
            return;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$manifest['theme_color'])
            || !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$manifest['background_color'])) {
            $this->fail('Manifest theme/background colors must be six-digit hex colors.');
            return;
        }
        if (empty($manifest['categories']) || !is_array($manifest['categories']) || !in_array('shopping', $manifest['categories'], true)) {
            $this->fail('Manifest categories must include shopping.');
            return;
        }

        $icons = $manifest['icons'] ?? [];
        if (!$this->checkManifestIconReferences('manifest icons', is_array($icons) ? $icons : [], true)) {
            return;
        }

        $shortcuts = $manifest['shortcuts'] ?? [];
        if (empty($shortcuts) || !is_array($shortcuts)) {
            $this->fail('Manifest install readiness requires shortcut entries.');
            return;
        }
        foreach ($shortcuts as $shortcut) {
            if (!is_array($shortcut)) {
                $this->fail('Manifest shortcut entry must be an object.');
                return;
            }
            $name = trim((string)($shortcut['name'] ?? ''));
            $shortName = trim((string)($shortcut['short_name'] ?? ''));
            $url = (string)($shortcut['url'] ?? '');
            if ($name === '' || $shortName === '') {
                $this->fail('Manifest shortcut needs name and short_name for install readiness.');
                return;
            }
            if (!$this->isSameOriginManifestPath($url) || strpos($url, 'source=pwa') === false) {
                $this->fail("Manifest shortcut {$name} must use a same-origin PWA URL with source=pwa.");
                return;
            }
            $shortcutIcons = $shortcut['icons'] ?? [];
            if (!$this->checkManifestIconReferences("manifest shortcut {$name} icons", is_array($shortcutIcons) ? $shortcutIcons : [], false)) {
                return;
            }
        }

        $serviceWorker = (string)file_get_contents($this->projectRoot() . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'pwa-sw.js');
        foreach (['/', '/manifest.webmanifest', '/pwa-offline.html', '/pwa-icon.svg', '/pwa-maskable.svg'] as $asset) {
            if (strpos($serviceWorker, "'{$asset}'") === false && strpos($serviceWorker, "\"{$asset}\"") === false) {
                $this->fail("Service worker install cache missing {$asset}.");
                return;
            }
        }

        $this->ok('Manifest install fields, icon references, shortcuts, and service-worker install cache are ready.');
    }

    private function checkManifestIconReferences(string $label, array $icons, bool $requirePurposes): bool
    {
        if (empty($icons)) {
            $this->fail("{$label} are missing.");
            return false;
        }

        $hasAny = false;
        $hasMaskable = false;
        foreach ($icons as $icon) {
            if (!is_array($icon)) {
                $this->fail("{$label} entry must be an object.");
                return false;
            }
            $src = (string)($icon['src'] ?? '');
            $sizes = (string)($icon['sizes'] ?? '');
            $type = (string)($icon['type'] ?? '');
            $purpose = strtolower((string)($icon['purpose'] ?? 'any'));
            if (!$this->isSameOriginManifestPath($src)) {
                $this->fail("{$label} src must be same-origin absolute path: {$src}");
                return false;
            }
            if ($sizes === '' || ($sizes !== 'any' && strpos($sizes, '192x192') === false && strpos($sizes, '512x512') === false)) {
                $this->fail("{$label} src {$src} must declare sizes any, 192x192, or 512x512.");
                return false;
            }
            if ($type !== 'image/svg+xml' && $type !== 'image/png') {
                $this->fail("{$label} src {$src} must use svg or png type.");
                return false;
            }

            $filePath = $this->webPathFromManifestUrl($src);
            if ($filePath === '' || !is_file($filePath)) {
                $this->fail("{$label} src {$src} does not map to an existing web asset.");
                return false;
            }
            if ($type === 'image/svg+xml') {
                $contents = (string)file_get_contents($filePath);
                if (stripos($contents, '<svg') === false || stripos($contents, 'viewBox="0 0 512 512"') === false) {
                    $this->fail("{$label} src {$src} must be a 512x512 SVG asset.");
                    return false;
                }
            }

            $hasAny = $hasAny || strpos($purpose, 'any') !== false;
            $hasMaskable = $hasMaskable || strpos($purpose, 'maskable') !== false;
        }

        if ($requirePurposes && (!$hasAny || !$hasMaskable)) {
            $this->fail("{$label} must include both any and maskable purposes.");
            return false;
        }

        return true;
    }

    private function isSameOriginManifestPath(string $path): bool
    {
        return $path !== '' && $path[0] === '/' && substr($path, 0, 2) !== '//';
    }

    private function webPathFromManifestUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !$this->isSameOriginManifestPath($path)) {
            return '';
        }

        return $this->projectRoot()
            . DIRECTORY_SEPARATOR . 'web'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    }

    private function checkServiceWorker(): void
    {
        $this->section('Service worker');
        $this->requireFileContains('web/pwa-sw.js', [
            'MONGOYIA_PWA_CACHE',
            'install',
            'activate',
            'fetch',
            '/pwa-offline.html',
            'request.mode === \'navigate\'',
        ]);
    }

    private function checkOfflinePage(): void
    {
        $this->section('Offline page');
        $this->requireFileContains('web/pwa-offline.html', [
            'Mongoyia is offline',
            'theme-color',
            'Try again',
        ]);
    }

    private function checkLayoutMarkers(): void
    {
        $this->section('Layout markers');
        $response = $this->get($this->baseUrl . '/');
        if ($response['status'] < 200 || $response['status'] >= 400) {
            $this->fail("Home page expected HTTP 2xx/3xx, got {$response['status']}.");
            return;
        }

        foreach ([
            'rel="manifest"',
            '/manifest.webmanifest',
            'name="theme-color"',
            'apple-mobile-web-app-capable',
            'navigator.serviceWorker.register',
            '/pwa-sw.js',
            'mongoyia-pwa-shell',
            'data-mongoyia-pwa',
        ] as $needle) {
            if (stripos($response['body'], $needle) === false) {
                $this->fail("Home page missing PWA marker '{$needle}'.");
                return;
            }
        }

        $this->ok('Home page contains PWA markers.');
    }

    private function checkHttpAssets(): void
    {
        $this->section('HTTP assets');
        foreach ([
            '/manifest.webmanifest' => ['Mongoyia', 'standalone'],
            '/pwa-sw.js' => ['MONGOYIA_PWA_CACHE', 'fetch'],
            '/pwa-offline.html' => ['Mongoyia is offline', 'Try again'],
            '/pwa-icon.svg' => ['<svg', '#0f766e'],
            '/pwa-maskable.svg' => ['<svg', '#0f766e'],
        ] as $path => $needles) {
            $response = $this->get($this->baseUrl . $path);
            if ($response['status'] < 200 || $response['status'] >= 400) {
                $this->fail("{$path} expected HTTP 2xx/3xx, got {$response['status']}.");
                return;
            }
            foreach ($needles as $needle) {
                if (stripos($response['body'], $needle) === false) {
                    $this->fail("{$path} missing '{$needle}'.");
                    return;
                }
            }
            $this->ok("HTTP {$path} is available.");
        }
    }

    private function checkManifestShortcutRoutes(): void
    {
        $this->section('Manifest shortcut routes');
        $path = $this->projectRoot() . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'manifest.webmanifest';
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest) || empty($manifest['shortcuts']) || !is_array($manifest['shortcuts'])) {
            $this->fail('Manifest shortcuts are not available for route checks.');
            return;
        }

        foreach ($manifest['shortcuts'] as $shortcut) {
            if (!is_array($shortcut)) {
                continue;
            }
            $name = (string)($shortcut['name'] ?? 'shortcut');
            $url = (string)($shortcut['url'] ?? '');
            if ($url === '' || $url[0] !== '/') {
                $this->fail("Manifest {$name} shortcut has invalid same-origin URL '{$url}'.");
                continue;
            }

            $response = $this->get($this->baseUrl . $url, $this->mobileUserAgent());
            if ($response['status'] < 200 || $response['status'] >= 400) {
                $this->fail("Manifest {$name} shortcut expected HTTP 2xx/3xx, got {$response['status']} from {$url}.");
                continue;
            }

            if ($response['status'] < 300 && !$this->checkNoFatalMarkers('manifest ' . $name . ' shortcut', $url, $response['body'])) {
                continue;
            }

            $this->ok("Manifest {$name} shortcut is reachable: HTTP {$response['status']} {$url}");
        }
    }

    private function checkMobilePages(): void
    {
        $this->section('Mobile pages');
        foreach ($this->mobilePageCases() as $case) {
            $path = $case['path'];
            $response = $this->get($this->baseUrl . $path, $this->mobileUserAgent());
            if ($response['status'] < 200 || $response['status'] >= 400) {
                $this->fail("{$case['label']} expected HTTP 2xx/3xx, got {$response['status']} from {$path}.");
                continue;
            }

            if (!$this->checkNoFatalMarkers($case['label'], $path, $response['body'])) {
                continue;
            }

            if (!empty($case['requirePwaMarkers']) && !$this->checkPagePwaMarkers($case['label'], $path, $response['body'])) {
                continue;
            }

            if (!empty($case['authGate']) && !$this->checkAuthGate($case['label'], $path, $response)) {
                continue;
            }

            $this->ok("Mobile {$case['label']} is reachable: HTTP {$response['status']} {$path}");
        }
    }

    private function mobilePageCases(): array
    {
        $categoryPath = $this->categoryId !== ''
            ? '/category/' . (int)$this->categoryId
            : '/mall/category/view?keyword=';

        return [
            ['label' => 'home', 'path' => '/', 'requirePwaMarkers' => true],
            ['label' => 'category/search', 'path' => $categoryPath, 'requirePwaMarkers' => true],
            ['label' => 'product', 'path' => '/product/' . (int)$this->productId, 'requirePwaMarkers' => true],
            ['label' => 'cart', 'path' => '/mall/cart/index', 'requirePwaMarkers' => true],
            ['label' => 'login', 'path' => '/mall/default/login', 'requirePwaMarkers' => true],
            ['label' => 'customer-service chat', 'path' => '/mall/chat/index?gid=' . (int)$this->productId, 'requirePwaMarkers' => true],
            ['label' => 'user orders auth gate', 'path' => '/mall/user/order', 'authGate' => true],
            ['label' => 'distribution auth gate', 'path' => '/mall/user/distribution', 'authGate' => true],
        ];
    }

    private function checkAuthenticatedMobilePages(): void
    {
        $this->section('Authenticated mobile pages');
        $client = new PwaSmokeHttpClient($this->baseUrl, (int)$this->timeout, $this->mobileUserAgent());

        if (!$this->frontendLogin($client)) {
            return;
        }

        foreach ($this->authenticatedMobilePageCases() as $case) {
            $path = $case['path'];
            $response = $client->get($path);
            if ($response['status'] !== 200) {
                $this->fail("Authenticated mobile {$case['label']} expected HTTP 200, got {$response['status']} from {$path}.");
                continue;
            }

            if (!$this->checkNoFatalMarkers($case['label'], $path, $response['body'])) {
                continue;
            }

            if ($this->isLoginPage($response['body'])) {
                $this->fail("Authenticated mobile {$case['label']} fell back to login page from {$path}.");
                continue;
            }

            if (!$this->checkPagePwaMarkers($case['label'], $path, $response['body'])) {
                continue;
            }

            foreach ($case['needles'] ?? [] as $needle) {
                if (stripos($response['body'], $needle) === false) {
                    $this->fail("Authenticated mobile {$case['label']} missing expected marker '{$needle}' from {$path}.");
                    continue 2;
                }
            }

            $this->ok("Authenticated mobile {$case['label']} is reachable: HTTP {$response['status']} {$path}");
        }
    }

    private function frontendLogin(PwaSmokeHttpClient $client): bool
    {
        $login = $client->get('/mall/default/login');
        if ($login['status'] !== 200) {
            $this->fail("Frontend login page expected HTTP 200, got {$login['status']}.");
            return false;
        }

        $csrf = $this->extractCsrf($login['body']);
        if ($csrf === '') {
            $this->fail('Frontend login page missing CSRF token.');
            return false;
        }

        $response = $client->post('/mall/default/login', [
            '_csrf' => $csrf,
            'LoginEmailForm[email]' => (string)$this->frontendEmail,
            'LoginEmailForm[password]' => (string)$this->frontendPassword,
            'LoginEmailForm[rememberMe]' => '1',
        ]);
        if (!in_array($response['status'], [200, 302], true)) {
            $this->fail("Frontend login submit expected HTTP 200/302, got {$response['status']}.");
            return false;
        }

        $probe = $client->get('/mall/user/order');
        if ($probe['status'] !== 200 || $this->isLoginPage($probe['body'])) {
            $this->fail("Frontend login did not reach authenticated user page for {$this->frontendEmail}.");
            return false;
        }

        $this->ok("Frontend mobile login succeeded: {$this->frontendEmail}");
        return true;
    }

    private function authenticatedMobilePageCases(): array
    {
        return [
            ['label' => 'user orders', 'path' => '/mall/user/order', 'needles' => ['user-card-header-tabs']],
            ['label' => 'user history', 'path' => '/mall/user/history', 'needles' => ['data-mongoyia-mobile-ui="user-history"', 'user-card-header-tabs']],
            ['label' => 'user coupons', 'path' => '/mall/user/coupon', 'needles' => ['data-mongoyia-mobile-ui="user-coupon"', 'user-card-header-tabs']],
            ['label' => 'user favorites', 'path' => '/mall/user/favorite', 'needles' => ['data-mongoyia-mobile-ui="user-favorite"', 'user-card-header-tabs']],
            ['label' => 'user address', 'path' => '/mall/user/address', 'needles' => ['data-mongoyia-mobile-ui="user-address"', 'user-card-header-tabs']],
            ['label' => 'user setting', 'path' => '/mall/user/setting', 'needles' => ['data-mongoyia-mobile-ui="user-setting"', 'user-card-header-tabs']],
            ['label' => 'user distribution', 'path' => '/mall/user/distribution', 'needles' => ['Distribution Center', 'Promotion Link']],
        ];
    }

    private function checkMerchantBackendMobilePages(): void
    {
        $this->section('Merchant backend mobile pages');
        $client = new PwaSmokeHttpClient($this->baseUrl, (int)$this->timeout, $this->mobileUserAgent());

        if (!$this->backendLogin($client)) {
            return;
        }

        foreach ($this->merchantBackendMobilePageCases() as $case) {
            $path = $case['path'];
            $response = $client->get($path);
            if ($response['status'] !== 200) {
                $this->fail("Merchant mobile {$case['label']} expected HTTP 200, got {$response['status']} from {$path}.");
                continue;
            }

            if (!$this->checkNoFatalMarkers($case['label'], $path, $response['body'])) {
                continue;
            }

            if ($this->isLoginPage($response['body'])) {
                $this->fail("Merchant mobile {$case['label']} fell back to login page from {$path}.");
                continue;
            }

            foreach (['mongoyia-merchant-pwa-shell', 'data-mongoyia-merchant-pwa'] as $needle) {
                if (stripos($response['body'], $needle) === false) {
                    $this->fail("Merchant mobile {$case['label']} missing shell marker '{$needle}' from {$path}.");
                    continue 2;
                }
            }

            foreach ($case['needles'] ?? [] as $needle) {
                if (stripos($response['body'], $needle) === false) {
                    $this->fail("Merchant mobile {$case['label']} missing expected marker '{$needle}' from {$path}.");
                    continue 2;
                }
            }

            $this->ok("Merchant mobile {$case['label']} is reachable: HTTP {$response['status']} {$path}");
        }

        $this->checkMerchantOrderOperationSmoke($client);
        $this->checkMerchantProductOperationSmoke($client);
    }

    private function backendLogin(PwaSmokeHttpClient $client): bool
    {
        $login = $client->get('/backend/site/login');
        if ($login['status'] !== 200) {
            $this->fail("Backend login page expected HTTP 200, got {$login['status']}.");
            return false;
        }

        $csrf = $this->extractCsrf($login['body']);
        if ($csrf === '') {
            $this->fail('Backend login page missing CSRF token.');
            return false;
        }

        $response = $client->post('/backend/site/login', [
            '_csrf-backend' => $csrf,
            'LoginForm[username]' => (string)$this->sellerUsername,
            'LoginForm[password]' => (string)$this->sellerPassword,
            'LoginForm[rememberMe]' => '1',
        ]);
        if (!in_array($response['status'], [200, 302], true)) {
            $this->fail("Backend login submit expected HTTP 200/302, got {$response['status']}.");
            return false;
        }

        $probe = $client->get('/backend/site/info');
        if ($probe['status'] !== 200 || $this->isLoginPage($probe['body'])) {
            $this->fail("Backend login did not reach authenticated merchant page for {$this->sellerUsername}.");
            return false;
        }

        $this->ok("Merchant backend mobile login succeeded: {$this->sellerUsername}");
        return true;
    }

    private function merchantBackendMobilePageCases(): array
    {
        return [
            ['label' => 'dashboard', 'path' => '/backend/site/info', 'needles' => ['Order Amount']],
            ['label' => 'products', 'path' => '/backend/mall/product/index'],
            ['label' => 'orders', 'path' => '/backend/mall/order/index'],
            ['label' => 'store profile', 'path' => '/backend/mall/store-profile/edit'],
            ['label' => 'merchant statistics', 'path' => '/backend/mall/merchant-stat/index', 'needles' => ['商家统计', '商品销量排行']],
            ['label' => 'merchant coupons', 'path' => '/backend/mall/merchant-coupon/index', 'needles' => ['商家优惠券', '平台券参与']],
            ['label' => 'logistics methods', 'path' => '/backend/mall/logistics-method/index', 'needles' => ['物流方式', '店铺选择']],
            ['label' => 'merchant deposit', 'path' => '/backend/mall/merchant-deposit/index', 'needles' => ['商家预存金', '预存金流水']],
        ];
    }

    private function checkMobileUiReadiness(): void
    {
        $this->section('Mobile UI readiness');

        foreach ($this->frontendMobileUiCases() as $case) {
            $path = $case['path'];
            $response = $this->get($this->baseUrl . $path, $this->mobileUserAgent());
            $this->checkMobileUiResponse(
                'frontend ' . $case['label'],
                $path,
                $response,
                'frontend',
                $case['all'] ?? [],
                $case['any'] ?? []
            );
        }

        $frontendClient = new PwaSmokeHttpClient($this->baseUrl, (int)$this->timeout, $this->mobileUserAgent());
        if ($this->frontendLogin($frontendClient)) {
            foreach ($this->frontendAuthenticatedMobileUiCases() as $case) {
                $this->checkMobileUiResponse(
                    'frontend authenticated ' . $case['label'],
                    $case['path'],
                    $frontendClient->get($case['path']),
                    'frontend',
                    $case['all'] ?? [],
                    $case['any'] ?? []
                );
            }
        }

        $merchantClient = new PwaSmokeHttpClient($this->baseUrl, (int)$this->timeout, $this->mobileUserAgent());
        if ($this->backendLogin($merchantClient)) {
            foreach ($this->merchantMobileUiReadinessCases() as $case) {
                $this->checkMobileUiResponse(
                    'merchant ' . $case['label'],
                    $case['path'],
                    $merchantClient->get($case['path']),
                    'merchant',
                    $case['all'] ?? [],
                    $case['any'] ?? []
                );
            }
        }
    }

    private function frontendMobileUiCases(): array
    {
        $categoryPath = $this->categoryId !== ''
            ? '/category/' . (int)$this->categoryId
            : '/mall/category/view?keyword=';

        return [
            [
                'label' => 'home',
                'path' => '/',
                'all' => ['data-mongoyia-mobile-ui="home"', 'hero-slider', 'property-gallery'],
                'any' => ['product-item', 'services-item'],
            ],
            [
                'label' => 'category/search',
                'path' => $categoryPath,
                'all' => ['data-mongoyia-mobile-ui="category"', 'product-top-bar', 'product-sort', 'product-page-size'],
                'any' => ['product-item', 'category-view-pagination', 'shop-sidebar'],
            ],
            [
                'label' => 'product',
                'path' => '/product/' . (int)$this->productId,
                'all' => ['data-mongoyia-mobile-ui="product"', 'product-details-text', 'product-details-price', 'addToCart', '/mall/chat/index?gid='],
                'any' => ['product-details-pic', 'product-details-tab'],
            ],
            [
                'label' => 'cart',
                'path' => '/mall/cart/index',
                'all' => ['data-mongoyia-mobile-ui="cart"', 'shop-cart'],
                'any' => ['shop-cart-table', 'cart-total-procced', 'site-btn'],
            ],
            [
                'label' => 'login',
                'path' => '/mall/default/login',
                'all' => ['data-mongoyia-mobile-ui="login"', 'login-form', 'LoginEmailForm[email]'],
                'any' => ['lost_pass', 'btn-3'],
            ],
            [
                'label' => 'customer-service chat',
                'path' => '/mall/chat/index?gid=' . (int)$this->productId,
                'all' => ['data-mongoyia-mobile-ui="chat"', 'chat-container', 'messagesContainer', 'messageInput', 'sendBtn'],
                'any' => ['tokenUrl', 'uploadUrl', 'CONFIG.productId', 'CONFIG.storeId'],
            ],
        ];
    }

    private function frontendAuthenticatedMobileUiCases(): array
    {
        return [
            [
                'label' => 'user order',
                'path' => '/mall/user/order',
                'all' => ['data-mongoyia-mobile-ui="user-order"', 'user-card-header-tabs', 'list-group'],
                'any' => ['card-body py-5', 'list-group-item', 'user-pagination'],
            ],
            [
                'label' => 'user history',
                'path' => '/mall/user/history',
                'all' => ['data-mongoyia-mobile-ui="user-history"', 'user-card-header-tabs', 'message-send-view'],
                'any' => ['row', 'product-item', 'card-body py-5', 'user-pagination'],
            ],
            [
                'label' => 'user coupons',
                'path' => '/mall/user/coupon',
                'all' => ['data-mongoyia-mobile-ui="user-coupon"', 'user-card-header-tabs', 'message-send-view'],
                'any' => ['list-group', 'card-body py-5', 'user-pagination'],
            ],
            [
                'label' => 'user favorites',
                'path' => '/mall/user/favorite',
                'all' => ['data-mongoyia-mobile-ui="user-favorite"', 'user-card-header-tabs', 'message-send-view'],
                'any' => ['row', 'card-body py-5', 'user-pagination'],
            ],
            [
                'label' => 'user address',
                'path' => '/mall/user/address',
                'all' => ['data-mongoyia-mobile-ui="user-address"', 'user-card-header-tabs', 'message-send-view'],
                'any' => ['list-group', 'control-full', 'user-pagination'],
            ],
            [
                'label' => 'user setting',
                'path' => '/mall/user/setting',
                'all' => ['data-mongoyia-mobile-ui="user-setting"', 'user-card-header-tabs', 'message-send-view'],
                'any' => ['Change Password', 'form-label-group', 'control-full'],
            ],
            [
                'label' => 'distribution',
                'path' => '/mall/user/distribution',
                'all' => ['data-mongoyia-mobile-ui="distribution"', 'Distribution Center', 'Promotion Link', 'input-group'],
                'any' => ['table-responsive', 'Request Withdrawal'],
            ],
        ];
    }

    private function merchantMobileUiReadinessCases(): array
    {
        $cases = [];
        foreach ($this->merchantBackendMobilePageCases() as $case) {
            $case['all'] = array_merge($case['needles'] ?? [], ['content-wrapper']);
            $case['any'] = ['card', 'grid-view', 'small-box', 'table', 'form'];
            $cases[] = $case;
        }

        return $cases;
    }

    private function checkMobileUiResponse(string $label, string $path, array $response, string $scope, array $allNeedles, array $anyNeedles): bool
    {
        $issues = [];
        if ($response['status'] !== 200) {
            $issues[] = "expected HTTP 200, got {$response['status']}";
            $this->fail("Mobile UI {$label} {$issues[0]} from {$path}.");
            $this->recordMobileUiEvidence($scope, $label, $path, (int)$response['status'], false, $allNeedles, $anyNeedles, $issues);
            return false;
        }

        if ($scope === 'frontend' && $this->isLoginPage($response['body']) && strpos($label, 'login') === false) {
            $issues[] = 'fell back to login page';
            $this->fail("Mobile UI {$label} fell back to login page from {$path}.");
            $this->recordMobileUiEvidence($scope, $label, $path, (int)$response['status'], false, $allNeedles, $anyNeedles, $issues);
            return false;
        }
        if ($scope === 'merchant' && $this->isLoginPage($response['body'])) {
            $issues[] = 'fell back to login page';
            $this->fail("Mobile UI {$label} fell back to login page from {$path}.");
            $this->recordMobileUiEvidence($scope, $label, $path, (int)$response['status'], false, $allNeedles, $anyNeedles, $issues);
            return false;
        }

        if (!$this->checkMobileHtmlBasics($label, $path, $response['body'], $scope)) {
            $issues[] = 'missing mobile shell or fatal-marker check failed';
            $this->recordMobileUiEvidence($scope, $label, $path, (int)$response['status'], false, $allNeedles, $anyNeedles, $issues);
            return false;
        }
        if ($allNeedles && !$this->requireAllNeedles($label, $path, $response['body'], $allNeedles)) {
            $issues[] = 'missing one or more required markers';
            $this->recordMobileUiEvidence($scope, $label, $path, (int)$response['status'], false, $allNeedles, $anyNeedles, $issues);
            return false;
        }
        if ($anyNeedles && !$this->requireAnyNeedle($label, $path, $response['body'], $anyNeedles)) {
            $issues[] = 'missing all one-of markers';
            $this->recordMobileUiEvidence($scope, $label, $path, (int)$response['status'], false, $allNeedles, $anyNeedles, $issues);
            return false;
        }

        $this->recordMobileUiEvidence($scope, $label, $path, (int)$response['status'], true, $allNeedles, $anyNeedles, []);
        $this->ok("Mobile UI {$label} readiness markers pass: HTTP {$response['status']} {$path}");
        return true;
    }

    private function recordMobileUiEvidence(string $scope, string $label, string $path, int $status, bool $passed, array $allNeedles, array $anyNeedles, array $issues): void
    {
        $this->mobileUiEvidenceRows[] = [
            'scope' => $scope,
            'label' => $label,
            'path' => $path,
            'status' => $status,
            'passed' => $passed,
            'required' => $allNeedles,
            'oneOf' => $anyNeedles,
            'issues' => $issues,
        ];
    }

    private function writeMobileUiEvidenceReport(bool $passed): void
    {
        if (!$this->writeEvidence || !$this->checkMobileUiReadiness || !$this->mobileUiEvidenceRows) {
            return;
        }

        $path = $this->evidencePath !== '' ? (string)$this->evidencePath : $this->defaultMobileUiEvidencePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia PWA Mobile UI Evidence',
            '',
            '- Result: ' . ($passed ? 'PASS' : 'FAIL'),
            '- Base URL: ' . $this->baseUrl,
            '- Started at: ' . date('Y-m-d H:i:s', $this->startedAt),
            '- Finished at: ' . date('Y-m-d H:i:s'),
            '- Frontend user: ' . $this->frontendEmail,
            '- Seller backend user: ' . $this->sellerUsername,
            '- Evidence type: HTML marker readiness, no screenshot capture, no external payment/WSS calls.',
            '',
            '## Summary',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Pages checked | ' . count($this->mobileUiEvidenceRows) . ' |',
            '| Passed | ' . $this->mobileUiEvidenceCount(true) . ' |',
            '| Failed | ' . $this->mobileUiEvidenceCount(false) . ' |',
            '',
            '## Page Checks',
            '',
            '| Scope | Page | Path | HTTP | Result | Required markers | One-of markers | Issues |',
            '|---|---|---|---:|---|---|---|---|',
        ];

        foreach ($this->mobileUiEvidenceRows as $row) {
            $lines[] = '| '
                . $this->mdCell($row['scope']) . ' | '
                . $this->mdCell($row['label']) . ' | `'
                . $this->mdCell($row['path']) . '` | '
                . (int)$row['status'] . ' | '
                . ($row['passed'] ? 'PASS' : 'FAIL') . ' | `'
                . $this->mdCell(implode(', ', $row['required'])) . '` | `'
                . $this->mdCell(implode(', ', $row['oneOf'])) . '` | '
                . $this->mdCell($row['issues'] ? implode('; ', $row['issues']) : '-') . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Signoff Notes',
            '',
            '- This evidence verifies mobile HTML readiness and stable PWA markers only.',
            '- It does not replace manual visual QA or real-device/browser screenshot review.',
            '- It does not invoke payment providers, IM WSS, or production credentials.',
            '',
        ]);

        if (file_put_contents($path, implode("\n", $lines)) === false) {
            $this->fail("Could not write PWA mobile UI evidence report to {$path}.");
            return;
        }

        $this->stdout("\nPWA mobile UI evidence written to {$path}\n");
    }

    private function mobileUiEvidenceCount(bool $passed): int
    {
        $count = 0;
        foreach ($this->mobileUiEvidenceRows as $row) {
            if ((bool)$row['passed'] === $passed) {
                $count++;
            }
        }

        return $count;
    }

    private function defaultMobileUiEvidencePath(): string
    {
        return dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'handover'
            . DIRECTORY_SEPARATOR . 'mongoyia-pwa-mobile-ui-evidence-' . date('Ymd-His') . '.md';
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    private function checkMerchantOrderOperationSmoke(PwaSmokeHttpClient $client): void
    {
        $label = 'merchant order operation smoke';
        $seller = $this->sellerUserRow();
        if (!$seller || (int)($seller['store_id'] ?? 0) <= 0) {
            $this->fail("Merchant {$label} needs seller account {$this->sellerUsername} with a store_id.");
            return;
        }

        $storeId = (int)$seller['store_id'];
        $userId = $this->frontendUserId();
        $product = $this->cartSmokeProduct();
        if ($storeId <= 0 || $userId <= 0 || !$product) {
            $this->fail("Merchant {$label} needs seller store, frontend user, and active product fixture.");
            return;
        }

        $marker = 'PWAMER-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        try {
            $this->cleanupMerchantOrderFixture($marker);

            $unshipped = $this->createMerchantOrderFixture($marker, $storeId, $userId, $product, \common\models\mall\Order::SHIPMENT_STATUS_UNSHIPPED);
            $shipping = $this->createMerchantOrderFixture($marker, $storeId, $userId, $product, \common\models\mall\Order::SHIPMENT_STATUS_SHIPPING);

            $index = $client->get('/backend/mall/order/index?page_size=50');
            if ($index['status'] !== 200 || $this->isLoginPage($index['body'])) {
                $this->fail("Merchant {$label} order index expected authenticated HTTP 200, got {$index['status']}.");
                return;
            }
            foreach ([$unshipped['sn'], $shipping['sn'], 'fh-ajax', 'logistics-status-batch', 'data-mongoyia-order-logistics-post-guard'] as $needle) {
                if (stripos($index['body'], (string)$needle) === false) {
                    $this->fail("Merchant {$label} order index missing '{$needle}'.");
                    return;
                }
            }
            if (!$this->checkNoFatalMarkers($label . ' index', '/backend/mall/order/index', $index['body'])) {
                return;
            }

            $view = $client->get('/backend/mall/order/view?id=' . (int)$unshipped['child_id']);
            if ($view['status'] !== 200 || stripos($view['body'], $unshipped['sn']) === false || stripos($view['body'], 'Order Products') === false) {
                $this->fail("Merchant {$label} order view expected fixture detail markers, got HTTP {$view['status']}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' view', '/backend/mall/order/view', $view['body'])) {
                return;
            }

            $shipForm = $client->get('/backend/mall/order/fh-ajax?id=' . (int)$unshipped['child_id']);
            if ($shipForm['status'] !== 200) {
                $this->fail("Merchant {$label} shipment form expected HTTP 200, got {$shipForm['status']}.");
                return;
            }
            foreach (['shipment_id', 'shipment_name', 'shipment_fee'] as $needle) {
                if (stripos($shipForm['body'], $needle) === false) {
                    $this->fail("Merchant {$label} shipment form missing '{$needle}'.");
                    return;
                }
            }

            $prepare = $client->post('/backend/mall/order/logistics-status-batch', [
                'ids' => (int)$unshipped['child_id'],
                'target_status' => \common\models\mall\Order::SHIPMENT_STATUS_PREPARING,
                'apply' => 0,
            ]);
            if (!in_array($prepare['status'], [200, 302], true)) {
                $this->fail("Merchant {$label} prepare dry-run expected HTTP 200/302, got {$prepare['status']}.");
                return;
            }
            if ((int)$this->orderColumn((int)$unshipped['child_id'], 'shipment_status') !== \common\models\mall\Order::SHIPMENT_STATUS_UNSHIPPED) {
                $this->fail("Merchant {$label} prepare dry-run mutated unshipped fixture order.");
                return;
            }

            $receive = $client->post('/backend/mall/order/logistics-status-batch', [
                'ids' => (int)$shipping['child_id'],
                'target_status' => \common\models\mall\Order::SHIPMENT_STATUS_RECEIVED,
                'apply' => 0,
            ]);
            if (!in_array($receive['status'], [200, 302], true)) {
                $this->fail("Merchant {$label} receive dry-run expected HTTP 200/302, got {$receive['status']}.");
                return;
            }
            if ((int)$this->orderColumn((int)$shipping['child_id'], 'shipment_status') !== \common\models\mall\Order::SHIPMENT_STATUS_SHIPPING) {
                $this->fail("Merchant {$label} receive dry-run mutated shipping fixture order.");
                return;
            }

            if (!$this->checkMerchantShipmentSubmitSmoke($client, (int)$unshipped['child_id'], $storeId, $marker)) {
                return;
            }

            $this->ok("Merchant mobile order operation smoke sees order list/detail/shipment form/logistics dry-run/submit entries, then cleanup removes marker {$marker}.");
        } finally {
            $this->cleanupMerchantOrderFixture($marker);
        }
    }

    private function checkMerchantShipmentSubmitSmoke(PwaSmokeHttpClient $client, int $childOrderId, int $storeId, string $marker): bool
    {
        $label = 'merchant shipment submit smoke';
        $beforeStore = $this->storeFinancialSnapshot($storeId);
        if (!$beforeStore) {
            $this->fail("Merchant {$label} cannot snapshot store {$storeId} fund fields.");
            return false;
        }

        $shipmentId = 880001;
        $shipmentName = 'PWA Submit Express';
        $shipmentFee = 1.23;
        $sn = (string)$this->orderColumn($childOrderId, 'sn');

        try {
            Yii::$app->db->createCommand()->update('{{%store}}', [
                'fund' => max((float)$beforeStore['fund'], 100),
            ], ['id' => $storeId])->execute();

            $form = $client->get('/backend/mall/order/fh-ajax?id=' . $childOrderId);
            if ($form['status'] !== 200 || $this->isLoginPage($form['body'])) {
                $this->fail("Merchant {$label} form expected authenticated HTTP 200, got {$form['status']}.");
                return false;
            }
            $csrf = $this->extractCsrf($form['body']);
            if ($csrf === '') {
                $this->fail("Merchant {$label} form missing backend CSRF token.");
                return false;
            }
            if (stripos($form['body'], 'data-mongoyia-order-shipment-post-id-guard') === false || stripos($form['body'], 'name="id"') === false) {
                $this->fail("Merchant {$label} form missing POST id guard marker.");
                return false;
            }

            $submit = $client->post('/backend/mall/order/fh-ajax', [
                '_csrf-backend' => $csrf,
                'id' => $childOrderId,
                'Order[shipment_id]' => (string)$shipmentId,
                'Order[shipment_name]' => $shipmentName,
                'Order[shipment_fee]' => (string)$shipmentFee,
            ]);
            if (!in_array($submit['status'], [200, 302], true)) {
                $this->fail("Merchant {$label} submit expected HTTP 200/302, got {$submit['status']}.");
                return false;
            }

            $row = $this->orderRow($childOrderId);
            if (!$row
                || (int)$row['shipment_status'] !== \common\models\mall\Order::SHIPMENT_STATUS_SHIPPING
                || (int)$row['shipment_id'] !== $shipmentId
                || (string)$row['shipment_name'] !== $shipmentName
                || abs((float)$row['shipment_fee'] - $shipmentFee) >= 0.01
                || (int)$row['shipment_fee_deducted_at'] <= 0
            ) {
                $this->fail("Merchant {$label} did not persist shipment fields and fee deduction marker.");
                return false;
            }

            if ($this->shipmentFeeDeductionLogCount($sn) !== 1) {
                $this->fail("Merchant {$label} expected exactly one shipment fee deduction log for {$sn}.");
                return false;
            }

            $fundAfterFirstSubmit = (float)$this->storeColumn($storeId, 'fund');
            $repeat = $client->post('/backend/mall/order/fh-ajax', [
                '_csrf-backend' => $csrf,
                'id' => $childOrderId,
                'Order[shipment_id]' => (string)$shipmentId,
                'Order[shipment_name]' => $shipmentName,
                'Order[shipment_fee]' => (string)$shipmentFee,
            ]);
            if (!in_array($repeat['status'], [200, 302], true)) {
                $this->fail("Merchant {$label} repeat submit expected HTTP 200/302, got {$repeat['status']}.");
                return false;
            }
            if ($this->shipmentFeeDeductionLogCount($sn) !== 1) {
                $this->fail("Merchant {$label} repeat submit created duplicate shipment fee deduction log for {$sn}.");
                return false;
            }
            if (abs((float)$this->storeColumn($storeId, 'fund') - $fundAfterFirstSubmit) >= 0.01) {
                $this->fail("Merchant {$label} repeat submit deducted store fund again.");
                return false;
            }

            return true;
        } finally {
            $this->restoreStoreFinancialSnapshot($storeId, $beforeStore);
            $this->cleanupShipmentFeeLogs($sn);
        }
    }

    private function checkMerchantProductOperationSmoke(PwaSmokeHttpClient $client): void
    {
        $label = 'merchant product operation smoke';
        $seller = $this->sellerUserRow();
        if (!$seller || (int)($seller['store_id'] ?? 0) <= 0) {
            $this->fail("Merchant {$label} needs seller account {$this->sellerUsername} with a store_id.");
            return;
        }

        $storeId = (int)$seller['store_id'];
        $categoryId = $this->activeCategoryId();
        if ($categoryId <= 0) {
            $this->fail("Merchant {$label} needs one active product category.");
            return;
        }

        $marker = 'PWAPROD-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        try {
            $this->cleanupMerchantProductFixture($marker);
            $productId = $this->createMerchantProductFixture($marker, $storeId, $categoryId);
            $otherProductId = $this->createCrossStoreProductFixture($marker, $storeId, $categoryId);

            $index = $client->get('/backend/mall/product/index?page_size=100');
            if ($index['status'] !== 200 || $this->isLoginPage($index['body'])) {
                $this->fail("Merchant {$label} product index expected authenticated HTTP 200, got {$index['status']}.");
                return;
            }
            foreach ([$marker, 'audit_status', 'Actions'] as $needle) {
                if (stripos($index['body'], (string)$needle) === false) {
                    $this->fail("Merchant {$label} product index missing '{$needle}'.");
                    return;
                }
            }
            foreach ([
                '/backend/mall/product/approve?id=' . $productId,
                '/backend/mall/product/reject?id=' . $productId,
                'product/approve?id=' . $productId,
                'product/reject?id=' . $productId,
            ] as $needle) {
                if (stripos($index['body'], (string)$needle) !== false) {
                    $this->fail("Merchant {$label} seller product index should not expose platform audit action '{$needle}'.");
                    return;
                }
            }
            if (!$this->checkNoFatalMarkers($label . ' index', '/backend/mall/product/index', $index['body'])) {
                return;
            }

            $view = $client->get('/backend/mall/product/view?id=' . $productId);
            if ($view['status'] !== 200 || stripos($view['body'], $marker) === false || stripos($view['body'], 'sku') === false) {
                $this->fail("Merchant {$label} product view expected fixture markers, got HTTP {$view['status']}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' view', '/backend/mall/product/view', $view['body'])) {
                return;
            }

            $edit = $client->get('/backend/mall/product/edit?id=' . $productId);
            if ($edit['status'] !== 200 || $this->isLoginPage($edit['body'])) {
                $this->fail("Merchant {$label} product edit expected authenticated HTTP 200, got {$edit['status']}.");
                return;
            }
            foreach (['Product[name]', 'Product[sku]', 'Product[category_id]'] as $needle) {
                if (stripos($edit['body'], $needle) === false) {
                    $this->fail("Merchant {$label} product edit missing '{$needle}'.");
                    return;
                }
            }
            if (stripos($edit['body'], 'Product[status]') !== false) {
                $this->fail("Merchant {$label} seller product edit should not expose direct status selection.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' edit', '/backend/mall/product/edit', $edit['body'])) {
                return;
            }

            $activate = $client->post('/backend/mall/product/edit-ajax-status?id=' . $productId, ['status' => \common\models\mall\Product::STATUS_ACTIVE]);
            if (!in_array($activate['status'], [200, 302, 403, 422], true)) {
                $this->fail("Merchant {$label} seller direct activate guard returned unexpected HTTP {$activate['status']}.");
                return;
            }
            if ((int)$this->productColumn($productId, 'status') === \common\models\mall\Product::STATUS_ACTIVE) {
                $this->fail("Merchant {$label} seller direct activate guard mutated fixture product to active.");
                return;
            }

            $crossStore = $client->get('/backend/mall/product/view?id=' . $otherProductId);
            if ($crossStore['status'] === 200 && stripos($crossStore['body'], $marker . '-OTHER') !== false) {
                $this->fail("Merchant {$label} cross-store product view exposed another store fixture.");
                return;
            }

            $this->ok("Merchant mobile product operation smoke sees list/view/edit, blocks direct seller activation, enforces cross-store isolation, then cleanup removes marker {$marker}.");
        } finally {
            $this->cleanupMerchantProductFixture($marker);
        }
    }

    private function checkMobileFormSubmits(): void
    {
        $this->section('Mobile form submits');
        $client = new PwaSmokeHttpClient($this->baseUrl, (int)$this->timeout, $this->mobileUserAgent());

        if (!$this->frontendLogin($client)) {
            return;
        }

        $userId = $this->frontendUserId();
        if ($userId <= 0) {
            $this->fail("Cannot find frontend fixture user {$this->frontendEmail}.");
            return;
        }

        $this->checkDistributionProfileSubmit($client, $userId);
        $this->checkCartCheckoutPrelude($client, $userId);
        $this->checkMobileOrderSubmitFixture($client, $userId);
    }

    private function checkDistributionProfileSubmit(PwaSmokeHttpClient $client, int $userId): void
    {
        $label = 'distribution profile submit';
        $before = $this->distributionProfileRow($userId);
        $marker = 'PWA Fixture ' . date('YmdHis');

        try {
            $page = $client->get('/mall/user/distribution');
            if ($page['status'] !== 200 || $this->isLoginPage($page['body'])) {
                $this->fail("Mobile {$label} could not open authenticated distribution page.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label, '/mall/user/distribution', $page['body'])) {
                return;
            }

            $csrf = $this->extractCsrf($page['body']);
            if ($csrf === '') {
                $this->fail("Mobile {$label} missing CSRF token.");
                return;
            }

            $response = $client->post('/mall/user/distribution-profile', [
                '_csrf' => $csrf,
                'display_name' => $marker,
                'contact_mobile' => '13800000000',
                'contact_email' => 'pwa-fixture@example.test',
                'channel' => 'PWA smoke',
                'bio' => 'Rollback-clean PWA profile submit fixture',
            ]);
            if (!in_array($response['status'], [200, 302], true)) {
                $this->fail("Mobile {$label} expected HTTP 200/302, got {$response['status']}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label, '/mall/user/distribution-profile', $response['body'])) {
                return;
            }

            $row = $this->distributionProfileRow($userId);
            if (!$row || (string)$row['display_name'] !== $marker || (string)$row['profile_status'] !== 'pending') {
                $this->fail("Mobile {$label} did not persist pending fixture profile for user {$userId}.");
                return;
            }

            $verify = $client->get('/mall/user/distribution');
            if ($verify['status'] !== 200 || stripos($verify['body'], $marker) === false) {
                $this->fail("Mobile {$label} did not render submitted fixture profile.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' verify', '/mall/user/distribution', $verify['body'])) {
                return;
            }

            $this->ok('Mobile distribution profile submit persists and renders, then cleanup restores original row.');
        } finally {
            $this->restoreDistributionProfile($userId, $before);
        }
    }

    private function frontendUserId(): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['email' => (string)$this->frontendEmail])
            ->andWhere(['>', 'status', 0])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
    }

    private function sellerUserRow(): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%user}}')
            ->where(['username' => (string)$this->sellerUsername])
            ->andWhere(['>', 'status', 0])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function distributionProfileRow(int $userId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_distribution_profile}}')
            ->where(['distributor_user_id' => $userId])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function restoreDistributionProfile(int $userId, ?array $before): void
    {
        if ($before === null) {
            Yii::$app->db->createCommand()->delete('{{%mall_distribution_profile}}', [
                'distributor_user_id' => $userId,
            ])->execute();
            return;
        }

        $existing = $this->distributionProfileRow($userId);
        $row = $before;
        $id = (int)$row['id'];
        unset($row['id']);

        if ($existing) {
            Yii::$app->db->createCommand()->update('{{%mall_distribution_profile}}', $row, ['id' => $id])->execute();
            if ((int)$existing['id'] !== $id) {
                Yii::$app->db->createCommand()->delete('{{%mall_distribution_profile}}', ['id' => (int)$existing['id']])->execute();
            }
            return;
        }

        Yii::$app->db->createCommand()->insert('{{%mall_distribution_profile}}', $before)->execute();
    }

    private function checkCartCheckoutPrelude(PwaSmokeHttpClient $client, int $userId): void
    {
        $label = 'cart checkout prelude';
        $product = $this->cartSmokeProduct();
        if (!$product) {
            $this->fail('Mobile cart checkout prelude needs one active positive-price in-stock product without required SKU attributes.');
            return;
        }

        $before = $this->cartRows($userId);
        try {
            $productPath = '/product/' . (int)$product['id'];
            $page = $client->get($productPath);
            if ($page['status'] !== 200 || $this->isLoginPage($page['body'])) {
                $this->fail("Mobile {$label} could not open product page {$productPath}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' product', $productPath, $page['body'])) {
                return;
            }

            $csrf = $this->extractCsrf($page['body']);
            if ($csrf === '') {
                $this->fail("Mobile {$label} product page missing CSRF token.");
                return;
            }

            $response = $client->post('/mall/cart/edit-ajax', [
                '_csrf' => $csrf,
                'product_id' => (int)$product['id'],
                'number' => 1,
                'product_attribute_value' => '',
            ]);
            if ($response['status'] !== 200) {
                $this->fail("Mobile {$label} add-cart expected HTTP 200, got {$response['status']}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' add-cart', '/mall/cart/edit-ajax', $response['body'])) {
                return;
            }

            $json = json_decode($response['body'], true);
            if ((int)($json['code'] ?? 0) !== 200) {
                $this->fail("Mobile {$label} add-cart expected JSON code 200, got {$response['body']}.");
                return;
            }

            $row = $this->cartRow($userId, (int)$product['id']);
            if (!$row || (int)$row['number'] < 1 || (float)$row['price'] <= 0) {
                $this->fail("Mobile {$label} did not persist positive cart row for product {$product['id']}.");
                return;
            }

            $cart = $client->get('/mall/cart/index');
            if ($cart['status'] !== 200) {
                $this->fail("Mobile {$label} cart page expected HTTP 200, got {$cart['status']}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' cart', '/mall/cart/index', $cart['body'])) {
                return;
            }
            foreach (['Cart Total', 'Proceed to checkout'] as $needle) {
                if (stripos($cart['body'], $needle) === false) {
                    $this->fail("Mobile {$label} cart page missing '{$needle}' after adding product {$product['id']}.");
                    return;
                }
            }

            $checkout = $client->get('/mall/cart/checkout');
            if ($checkout['status'] !== 200 || $this->isLoginPage($checkout['body'])) {
                $this->fail("Mobile {$label} checkout page expected authenticated HTTP 200, got {$checkout['status']}.");
                return;
            }
            foreach (['Checkout', 'Billing', 'Place Order'] as $needle) {
                if (stripos($checkout['body'], $needle) === false) {
                    $this->fail("Mobile {$label} checkout page missing '{$needle}'.");
                    return;
                }
            }
            if (!$this->checkNoFatalMarkers($label . ' checkout', '/mall/cart/checkout', $checkout['body'])) {
                return;
            }

            $this->ok("Mobile cart add, cart render, and checkout prelude pass for product {$product['id']}; cleanup restores cart rows.");
        } finally {
            $this->restoreCartRows($userId, $before);
        }
    }

    private function cartSmokeProduct(): ?array
    {
        $product = $this->productRowForCartSmoke((int)$this->productId);
        if ($product) {
            return $product;
        }

        $row = (new \yii\db\Query())
            ->from('{{%mall_product}}')
            ->where(['status' => 1, 'attribute_set_id' => 0])
            ->andWhere(['>', 'price', 0])
            ->andWhere(['>', 'stock', 0])
            ->orderBy(['stock' => SORT_DESC, 'id' => SORT_ASC])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function productRowForCartSmoke(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $row = (new \yii\db\Query())
            ->from('{{%mall_product}}')
            ->where(['id' => $productId, 'status' => 1, 'attribute_set_id' => 0])
            ->andWhere(['>', 'price', 0])
            ->andWhere(['>', 'stock', 0])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function cartRow(int $userId, int $productId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_cart}}')
            ->where(['user_id' => $userId, 'product_id' => $productId, 'product_attribute_value' => ''])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function cartRows(int $userId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_cart}}')
            ->where(['user_id' => $userId])
            ->all(Yii::$app->db);
    }

    private function restoreCartRows(int $userId, array $before): void
    {
        Yii::$app->db->createCommand()->delete('{{%mall_cart}}', ['user_id' => $userId])->execute();
        foreach ($before as $row) {
            Yii::$app->db->createCommand()->insert('{{%mall_cart}}', $row)->execute();
        }
    }

    private function checkMobileOrderSubmitFixture(PwaSmokeHttpClient $client, int $userId): void
    {
        $label = 'order submit fixture';
        $product = $this->cartSmokeProduct();
        if (!$product) {
            $this->fail('Mobile order submit fixture needs one active positive-price in-stock product without required SKU attributes.');
            return;
        }

        $marker = 'PWAORD-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $beforeCartRows = $this->cartRows($userId);

        try {
            $this->cleanupMobileOrderFixture($userId, $marker);

            $productPath = '/product/' . (int)$product['id'];
            $page = $client->get($productPath);
            if ($page['status'] !== 200 || $this->isLoginPage($page['body'])) {
                $this->fail("Mobile {$label} could not open product page {$productPath}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' product', $productPath, $page['body'])) {
                return;
            }

            $csrf = $this->extractCsrf($page['body']);
            if ($csrf === '') {
                $this->fail("Mobile {$label} product page missing CSRF token.");
                return;
            }

            $addCart = $client->post('/mall/cart/edit-ajax', [
                '_csrf' => $csrf,
                'product_id' => (int)$product['id'],
                'number' => 1,
                'product_attribute_value' => '',
            ]);
            if ($addCart['status'] !== 200) {
                $this->fail("Mobile {$label} add-cart expected HTTP 200, got {$addCart['status']}.");
                return;
            }
            $json = json_decode($addCart['body'], true);
            if ((int)($json['code'] ?? 0) !== 200) {
                $this->fail("Mobile {$label} add-cart expected JSON code 200, got {$addCart['body']}.");
                return;
            }

            $checkout = $client->get('/mall/cart/checkout');
            if ($checkout['status'] !== 200 || $this->isLoginPage($checkout['body'])) {
                $this->fail("Mobile {$label} checkout form expected authenticated HTTP 200, got {$checkout['status']}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' checkout form', '/mall/cart/checkout', $checkout['body'])) {
                return;
            }

            $checkoutCsrf = $this->extractCsrf($checkout['body']);
            if ($checkoutCsrf === '') {
                $this->fail("Mobile {$label} checkout form missing CSRF token.");
                return;
            }

            $submit = $client->post('/mall/cart/checkout', [
                '_csrf' => $checkoutCsrf,
                'Address[first_name]' => 'PWA',
                'Address[last_name]' => 'Smoke',
                'Address[address]' => $marker . ' address',
                'Address[district]' => 'Fixture District',
                'Address[city]' => 'Ulaanbaatar',
                'Address[province]' => 'Ulaanbaatar',
                'Address[country]' => 'Mongolia',
                'Address[mobile]' => '13800000000',
                'Address[postcode]' => '000000',
                'Address[email]' => 'pwa-order@example.test',
                'Order[payment_method]' => \common\models\mall\Order::PAYMENT_METHOD_PAY,
                'Order[remark]' => $marker,
            ]);
            if (!in_array($submit['status'], [200, 302], true)) {
                $this->fail("Mobile {$label} submit expected HTTP 200/302, got {$submit['status']}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' submit', '/mall/cart/checkout', $submit['body'])) {
                return;
            }

            $order = $this->mobileOrderFixtureParent($userId, $marker);
            if (!$order) {
                $this->fail("Mobile {$label} did not create parent order marker {$marker}.");
                return;
            }
            if ((int)$order['payment_method'] !== \common\models\mall\Order::PAYMENT_METHOD_PAY
                || (int)$order['payment_status'] !== \common\models\mall\Order::PAYMENT_STATUS_UNPAID
                || (float)$order['amount'] <= 0
            ) {
                $this->fail("Mobile {$label} created unexpected payment state or amount for order {$order['id']}.");
                return;
            }

            $children = $this->mobileOrderFixtureChildren((int)$order['id']);
            if (!$children) {
                $this->fail("Mobile {$label} did not create child seller order rows for parent {$order['id']}.");
                return;
            }

            $orderProducts = $this->mobileOrderFixtureProductCount((int)$order['id']);
            $orderProduct = $this->mobileOrderFixtureProduct((int)$order['id']);
            if ($orderProducts < 1 || !$orderProduct) {
                $this->fail("Mobile {$label} did not create order product rows for parent {$order['id']}.");
                return;
            }

            $paymentPath = '/mall/payment/index?id=' . (int)$order['id'];
            $payment = $client->get($paymentPath);
            if (!$this->checkMobileUiResponse(
                'payment page',
                $paymentPath,
                $payment,
                'frontend',
                ['data-mongoyia-mobile-ui="payment"', 'payment-order-summary', (string)$order['sn'], 'data-mongoyia-phase11-payment-channel-list'],
                ['data-mongoyia-phase11-payment-channel=', 'data-mongoyia-phase11-payment-no-channel']
            )) {
                return;
            }

            $orders = $client->get('/mall/user/order');
            if ($orders['status'] !== 200 || $this->isLoginPage($orders['body'])) {
                $this->fail("Mobile {$label} order list expected authenticated HTTP 200, got {$orders['status']}.");
                return;
            }
            if (stripos($orders['body'], (string)$order['sn']) === false) {
                $this->fail("Mobile {$label} order list missing order sn '{$order['sn']}'.");
                return;
            }
            $paymentNeedles = [
                '/mall/payment/index?id=' . (int)$order['id'],
                '/mall/payment/index/' . (int)$order['id'],
            ];
            if (!$this->bodyContainsAny($orders['body'], $paymentNeedles)) {
                $this->fail("Mobile {$label} order list missing continue-payment link for order {$order['id']}.");
                return;
            }
            if (!$this->checkNoFatalMarkers($label . ' order list', '/mall/user/order', $orders['body'])) {
                return;
            }

            $orderDetailPath = '/mall/order/view?id=' . (int)$orderProduct['id'];
            $orderDetail = $client->get($orderDetailPath);
            if (!$this->checkMobileUiResponse(
                'order detail page',
                $orderDetailPath,
                $orderDetail,
                'frontend',
                ['data-mongoyia-mobile-ui="order-detail"', 'order-info', 'order-goods', (string)$order['sn']],
                ['物流单号', 'Awaiting shipment', 'Write a review', 'Review after receiving']
            )) {
                return;
            }

            $cancelledPath = '/mall/payment/cancelled?id=' . (int)$order['id'];
            $cancelled = $client->get($cancelledPath);
            if (!$this->checkMobileUiResponse(
                'payment cancelled page',
                $cancelledPath,
                $cancelled,
                'frontend',
                ['data-mongoyia-mobile-ui="payment-cancelled"', 'message-send-view', 'fa-close'],
                ['Payment has been cancelled', 'control-full', 'Go Home']
            )) {
                return;
            }

            $unpaidReturn = $client->get('/mall/payment/succeeded?id=' . (int)$order['id']);
            $unpaidReturnPath = $this->paymentPathFromResponse($unpaidReturn);
            if ($unpaidReturn['status'] !== 302 || stripos($unpaidReturnPath, '/mall/payment/index') === false) {
                $this->fail("Mobile {$label} unpaid payment return should redirect back to payment page, got HTTP {$unpaidReturn['status']} {$unpaidReturnPath}.");
                return;
            }

            $this->markMobileOrderFixturePaid((int)$order['id']);
            $successPath = '/mall/payment/succeeded?id=' . (int)$order['id'];
            $success = $client->get($successPath);
            if (!$this->checkMobileUiResponse(
                'payment success page',
                $successPath,
                $success,
                'frontend',
                ['data-mongoyia-mobile-ui="payment-succeeded"', 'message-send-view', 'fa-check', (string)$order['sn']],
                ['Order has been paid successfully', 'Order has been confirmed', 'control-full']
            )) {
                return;
            }

            $this->ok("Mobile order submit fixture creates parent/child/order-product rows, reaches payment and order-detail pages, verifies payment return/cancel/success pages, then cleanup removes marker {$marker}.");
        } finally {
            $this->cleanupMobileOrderFixture($userId, $marker);
            $this->restoreCartRows($userId, $beforeCartRows);
        }
    }

    private function mobileOrderFixtureParent(int $userId, string $marker): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_order}}')
            ->where(['user_id' => $userId, 'parent_id' => 0, 'remark' => $marker])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function mobileOrderFixtureChildren(int $parentId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_order}}')
            ->where(['parent_id' => $parentId])
            ->all(Yii::$app->db);
    }

    private function mobileOrderFixtureProductCount(int $parentId): int
    {
        return (int)(new \yii\db\Query())
            ->from('{{%mall_order_product}}')
            ->where(['parent_id' => $parentId])
            ->count('*', Yii::$app->db);
    }

    private function mobileOrderFixtureProduct(int $parentId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_order_product}}')
            ->where(['parent_id' => $parentId])
            ->orderBy(['id' => SORT_ASC])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function markMobileOrderFixturePaid(int $parentId): void
    {
        $orderIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where(['or', ['id' => $parentId], ['parent_id' => $parentId]])
            ->column(Yii::$app->db);
        if (!$orderIds) {
            return;
        }

        Yii::$app->db->createCommand()->update('{{%mall_order}}', [
            'payment_status' => \common\models\mall\Order::PAYMENT_STATUS_PAID,
            'status' => \common\models\mall\Order::PAYMENT_STATUS_PAID,
            'paid_at' => time(),
        ], ['id' => $orderIds])->execute();
    }

    private function paymentPathFromResponse(array $response): string
    {
        foreach ($response['headers'] ?? [] as $header) {
            if (stripos($header, 'Location:') !== 0) {
                continue;
            }
            $location = trim(substr($header, strlen('Location:')));
            $parts = parse_url($location);
            if (!is_array($parts)) {
                return $location;
            }
            $path = (string)($parts['path'] ?? '');
            $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
            return $path . $query;
        }

        return '';
    }

    private function bodyContainsAny(string $body, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($body, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function cleanupMobileOrderFixture(int $userId, string $marker): void
    {
        $parentIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where(['user_id' => $userId, 'parent_id' => 0, 'remark' => $marker])
            ->column(Yii::$app->db);
        if (!$parentIds) {
            Yii::$app->db->createCommand()->delete('{{%mall_address}}', [
                'user_id' => $userId,
                'address' => $marker . ' address',
            ])->execute();
            return;
        }

        $orderIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where(['or', ['id' => $parentIds], ['parent_id' => $parentIds]])
            ->column(Yii::$app->db);

        Yii::$app->db->createCommand()->delete('{{%mall_order_log}}', ['order_id' => $orderIds])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_order_product}}', ['parent_id' => $parentIds])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_payment_attempt}}', ['order_id' => $orderIds])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_order}}', ['id' => $orderIds])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_address}}', [
            'user_id' => $userId,
            'address' => $marker . ' address',
        ])->execute();
    }

    private function createMerchantOrderFixture(string $marker, int $storeId, int $userId, array $product, int $shipmentStatus): array
    {
        $now = time();
        $suffix = ($shipmentStatus === \common\models\mall\Order::SHIPMENT_STATUS_SHIPPING ? 'SHIP' : 'UNSHIP') . '-' . mt_rand(1000, 9999);
        $parentId = $this->insertMerchantOrderRow($marker, $storeId, $userId, 0, $marker . '-P-' . $suffix, $shipmentStatus, $now);
        $childId = $this->insertMerchantOrderRow($marker, $storeId, $userId, $parentId, $marker . '-C-' . $suffix, $shipmentStatus, $now);

        Yii::$app->db->createCommand()->insert('{{%mall_order_product}}', [
            'store_id' => $storeId,
            'parent_id' => $parentId,
            'user_id' => $userId,
            'order_id' => $childId,
            'product_id' => (int)$product['id'],
            'product_attribute_value' => '',
            'name' => (string)$product['name'],
            'sku' => (string)$product['sku'],
            'number' => 1,
            'price' => 1,
            'market_price' => 1,
            'cost_price' => 0,
            'wholesale_price' => 0,
            'thumb' => (string)$product['thumb'],
            'cart_id' => 0,
            'type' => \common\models\mall\OrderProduct::TYPE_DEFAULT,
            'sort' => \common\models\mall\OrderProduct::SORT_DEFAULT,
            'status' => \common\models\mall\OrderProduct::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 0,
            'updated_by' => 0,
        ])->execute();

        return [
            'parent_id' => $parentId,
            'child_id' => $childId,
            'sn' => $marker . '-C-' . $suffix,
        ];
    }

    private function insertMerchantOrderRow(string $marker, int $storeId, int $userId, int $parentId, string $sn, int $shipmentStatus, int $now): int
    {
        Yii::$app->db->createCommand()->insert('{{%mall_order}}', [
            'store_id' => $storeId,
            'parent_id' => $parentId,
            'user_id' => $userId,
            'address_id' => 0,
            'name' => 'PWA merchant order fixture',
            'sn' => $sn,
            'first_name' => 'PWA',
            'last_name' => 'Merchant',
            'country_id' => 0,
            'country' => 'Mongolia',
            'province_id' => 0,
            'province' => 'Ulaanbaatar',
            'city_id' => 0,
            'city' => 'Ulaanbaatar',
            'district_id' => 0,
            'district' => 'Fixture District',
            'address' => $marker . ' merchant address',
            'address2' => '',
            'postcode' => '000000',
            'mobile' => '13800000000',
            'email' => 'pwa-merchant@example.test',
            'distance' => 0,
            'remark' => $marker,
            'payment_method' => \common\models\mall\Order::PAYMENT_METHOD_PAY,
            'payment_fee' => 0,
            'payment_status' => \common\models\mall\Order::PAYMENT_STATUS_PAID,
            'paid_at' => $now - 3600,
            'stock_deducted_at' => $now - 3600,
            'stock_refunded_at' => 0,
            'shipment_id' => $shipmentStatus >= \common\models\mall\Order::SHIPMENT_STATUS_SHIPPING ? 9009 : 0,
            'shipment_name' => $shipmentStatus >= \common\models\mall\Order::SHIPMENT_STATUS_SHIPPING ? 'PWA Merchant Express' : '',
            'shipment_fee' => 0,
            'shipment_fee_deducted_at' => 0,
            'shipment_status' => $shipmentStatus,
            'shipped_at' => $shipmentStatus >= \common\models\mall\Order::SHIPMENT_STATUS_SHIPPING ? $now - 1800 : 0,
            'logistics_review_status' => \common\models\mall\Order::LOGISTICS_REVIEW_PENDING,
            'logistics_review_remark' => '',
            'logistics_reviewed_at' => 0,
            'logistics_reviewed_by' => 0,
            'product_amount' => 1,
            'amount' => 1,
            'number' => 1,
            'extra_fee' => 0,
            'discount' => 0,
            'tax' => 0,
            'invoice' => '',
            'type' => \common\models\mall\Order::TYPE_DEFAULT,
            'sort' => \common\models\mall\Order::SORT_DEFAULT,
            'status' => \common\models\mall\Order::PAYMENT_STATUS_PAID,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 0,
            'updated_by' => 0,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function cleanupMerchantOrderFixture(string $marker): void
    {
        $parentIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where(['parent_id' => 0, 'remark' => $marker])
            ->column(Yii::$app->db);
        $orderIds = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where(['remark' => $marker])
            ->column(Yii::$app->db);

        if ($parentIds) {
            Yii::$app->db->createCommand()->delete('{{%mall_order_product}}', ['parent_id' => $parentIds])->execute();
        }
        if ($orderIds) {
            Yii::$app->db->createCommand()->delete('{{%mall_order_log}}', ['order_id' => $orderIds])->execute();
            Yii::$app->db->createCommand()->delete('{{%mall_payment_attempt}}', ['order_id' => $orderIds])->execute();
            Yii::$app->db->createCommand()->delete('{{%mall_order}}', ['id' => $orderIds])->execute();
        }
    }

    private function activeCategoryId(): int
    {
        $preferred = (int)$this->categoryId;
        if ($preferred > 0) {
            $exists = (new \yii\db\Query())
                ->from('{{%mall_category}}')
                ->where(['id' => $preferred])
                ->andWhere(['>', 'status', 0])
                ->exists(Yii::$app->db);
            if ($exists) {
                return $preferred;
            }
        }

        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_category}}')
            ->where(['>', 'status', 0])
            ->orderBy(['sort' => SORT_DESC, 'id' => SORT_ASC])
            ->scalar(Yii::$app->db);
    }

    private function createMerchantProductFixture(string $marker, int $storeId, int $categoryId): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_product}}', [
            'store_id' => $storeId,
            'category_id' => $categoryId,
            'name' => $marker,
            'sku' => $marker,
            'stock_code' => '',
            'stock' => 12,
            'stock_warning' => 1,
            'weight' => 0,
            'volume' => 0,
            'price' => 9.99,
            'market_price' => 9.99,
            'cost_price' => 0,
            'wholesale_price' => 0,
            'thumb' => '',
            'image' => '',
            'images' => '',
            'tags' => '',
            'brief' => 'Rollback-clean PWA product fixture',
            'content' => 'Rollback-clean PWA product fixture',
            'seo_url' => '',
            'seo_title' => '',
            'seo_keywords' => '',
            'seo_description' => '',
            'brand_id' => 0,
            'vendor_id' => 0,
            'attribute_set_id' => 0,
            'param_id' => 0,
            'star' => 5,
            'reviews' => 0,
            'sales' => 0,
            'click' => 0,
            'type' => 0,
            'audit_status' => 'submitted',
            'audit_remark' => $marker,
            'reviewed_at' => 0,
            'reviewer_id' => 0,
            'sort' => \common\models\mall\Product::SORT_DEFAULT,
            'status' => \common\models\mall\Product::STATUS_INACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 0,
            'updated_by' => 0,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function createCrossStoreProductFixture(string $marker, int $sellerStoreId, int $categoryId): int
    {
        $storeId = $this->otherStoreId($sellerStoreId);
        if ($storeId <= 0) {
            $storeId = $sellerStoreId + 100000;
        }

        return $this->createMerchantProductFixture($marker . '-OTHER', $storeId, $categoryId);
    }

    private function otherStoreId(int $sellerStoreId): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['<>', 'id', $sellerStoreId])
            ->andWhere(['>', 'status', 0])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
    }

    private function cleanupMerchantProductFixture(string $marker): void
    {
        $ids = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_product}}')
            ->where(['or', ['name' => $marker], ['name' => $marker . '-OTHER']])
            ->column(Yii::$app->db);

        if (!$ids) {
            return;
        }

        Yii::$app->db->createCommand()->delete('{{%mall_product_sku}}', ['product_id' => $ids])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_product_tag}}', ['product_id' => $ids])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_product_param}}', ['product_id' => $ids])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_product_attribute_item_label}}', ['product_id' => $ids])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_product_visit}}', ['pid' => $ids])->execute();
        Yii::$app->db->createCommand()->delete('{{%mall_product}}', ['id' => $ids])->execute();
    }

    private function orderColumn(int $orderId, string $column)
    {
        return (new \yii\db\Query())
            ->select($column)
            ->from('{{%mall_order}}')
            ->where(['id' => $orderId])
            ->scalar(Yii::$app->db);
    }

    private function orderRow(int $orderId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_order}}')
            ->where(['id' => $orderId])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function storeColumn(int $storeId, string $column)
    {
        return (new \yii\db\Query())
            ->select($column)
            ->from('{{%store}}')
            ->where(['id' => $storeId])
            ->scalar(Yii::$app->db);
    }

    private function storeFinancialSnapshot(int $storeId): ?array
    {
        $row = (new \yii\db\Query())
            ->select(['fund', 'consume_amount', 'consume_count'])
            ->from('{{%store}}')
            ->where(['id' => $storeId])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function restoreStoreFinancialSnapshot(int $storeId, array $snapshot): void
    {
        Yii::$app->db->createCommand()->update('{{%store}}', [
            'fund' => (float)$snapshot['fund'],
            'consume_amount' => (float)$snapshot['consume_amount'],
            'consume_count' => (int)$snapshot['consume_count'],
        ], ['id' => $storeId])->execute();
    }

    private function shipmentFeeDeductionLogCount(string $orderSn): int
    {
        if ($orderSn === '') {
            return 0;
        }

        return (int)(new \yii\db\Query())
            ->from('{{%base_fund_log}}')
            ->where(['like', 'remark', 'shipment_fee_deduction order_sn=' . $orderSn])
            ->count('*', Yii::$app->db);
    }

    private function cleanupShipmentFeeLogs(string $orderSn): void
    {
        if ($orderSn === '') {
            return;
        }

        Yii::$app->db->createCommand()
            ->delete('{{%base_fund_log}}', ['like', 'remark', 'shipment_fee_deduction order_sn=' . $orderSn])
            ->execute();
    }

    private function productColumn(int $productId, string $column)
    {
        return (new \yii\db\Query())
            ->select($column)
            ->from('{{%mall_product}}')
            ->where(['id' => $productId])
            ->scalar(Yii::$app->db);
    }

    private function checkMobileHtmlBasics(string $label, string $path, string $body, string $scope): bool
    {
        if (!$this->checkNoFatalMarkers($label, $path, $body)) {
            return false;
        }

        if (!$this->requireAllNeedles($label, $path, $body, ['name="viewport"'])) {
            return false;
        }

        if ($scope === 'merchant') {
            return $this->requireAllNeedles($label, $path, $body, [
                'mongoyia-merchant-pwa-shell',
                'data-mongoyia-merchant-pwa',
                'mongoyia-merchant-content',
            ]);
        }

        return $this->requireAllNeedles($label, $path, $body, [
            'mongoyia-pwa-shell',
            'data-mongoyia-pwa',
            'rel="manifest"',
            '/manifest.webmanifest',
        ]);
    }

    private function requireAllNeedles(string $label, string $path, string $body, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($body, (string)$needle) === false) {
                $this->fail("Mobile UI {$label} missing marker '{$needle}' from {$path}.");
                return false;
            }
        }

        return true;
    }

    private function requireAnyNeedle(string $label, string $path, string $body, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($body, (string)$needle) !== false) {
                return true;
            }
        }

        $this->fail("Mobile UI {$label} missing any expected marker from {$path}: " . implode(', ', $needles) . '.');
        return false;
    }

    private function checkPagePwaMarkers(string $label, string $path, string $body): bool
    {
        foreach ([
            'name="viewport"',
            'rel="manifest"',
            '/manifest.webmanifest',
            'navigator.serviceWorker.register',
            'mongoyia-pwa-shell',
            'data-mongoyia-pwa',
        ] as $needle) {
            if (stripos($body, $needle) === false) {
                $this->fail("Mobile {$label} missing PWA marker '{$needle}' from {$path}.");
                return false;
            }
        }

        return true;
    }

    private function checkAuthGate(string $label, string $path, array $response): bool
    {
        if ($response['status'] >= 300 && $response['status'] < 400) {
            return true;
        }

        $body = $response['body'];
        foreach ([
            '/mall/default/login',
            'name="LoginForm',
            'login',
            '登录',
            'Sign in',
        ] as $needle) {
            if (stripos($body, $needle) !== false) {
                return true;
            }
        }

        $this->fail("Mobile {$label} should redirect or render login gate for guest from {$path}.");
        return false;
    }

    private function extractCsrf(string $body): string
    {
        if (preg_match('/name=["\'](?:_csrf|_csrf-backend)["\']\s+value=["\']([^"\']+)["\']/i', $body, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function isLoginPage(string $body): bool
    {
        return stripos($body, 'LoginEmailForm[email]') !== false
            || stripos($body, 'LoginForm[username]') !== false
            || stripos($body, 'Please Sign in now') !== false;
    }

    private function requireFile(string $path): void
    {
        if (is_file($this->projectRoot() . DIRECTORY_SEPARATOR . $path)) {
            $this->ok("File exists: {$path}");
            return;
        }
        $this->fail("Missing file {$path}.");
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $fullPath = $this->projectRoot() . DIRECTORY_SEPARATOR . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function get(string $url, ?string $userAgent = null): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => (int)$this->timeout,
                'header' => 'User-Agent: ' . ($userAgent ?: 'MongoyiaPwaSmoke/1.0') . "\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }

        return [
            'status' => $status,
            'body' => $body === false ? '' : $body,
        ];
    }

    private function checkNoFatalMarkers(string $label, string $path, string $body): bool
    {
        foreach ($this->fatalNeedles() as $needle) {
            if (stripos($body, $needle) !== false) {
                $this->fail("Mobile {$label} contains fatal marker '{$needle}' from {$path}.");
                return false;
            }
        }

        return true;
    }

    private function fatalNeedles(): array
    {
        return [
            'yii\base\ErrorException',
            'yii\db\Exception',
            'PHP Warning',
            'PHP Fatal error',
            'Stack trace:',
            'Call to undefined',
            'Trying to get property',
            "render('@backend/views/site/_select'",
        ];
    }

    private function mobileUserAgent(): string
    {
        return 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1 MongoyiaPwaSmoke/1.0';
    }

    private function assertSame(string $expected, string $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            $this->fail($message);
            return;
        }
        $this->ok($message);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}

class PwaSmokeHttpClient
{
    private $baseUrl;
    private $timeout;
    private $userAgent;
    private $cookies = [];

    public function __construct(string $baseUrl, int $timeout, string $userAgent)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->userAgent = $userAgent;
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $data): array
    {
        return $this->request('POST', $path, http_build_query($data));
    }

    private function request(string $method, string $path, string $body = ''): array
    {
        $headers = [
            'User-Agent: ' . $this->userAgent,
        ];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'ignore_errors' => true,
                'timeout' => $this->timeout,
                'follow_location' => 0,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $method === 'POST' ? $body : '',
            ],
        ]);

        $content = @file_get_contents($this->baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
            $status = (int)$matches[1];
        }
        $this->storeCookies($responseHeaders);

        return [
            'status' => $status,
            'body' => $content === false ? '' : $content,
            'headers' => $responseHeaders,
        ];
    }

    private function storeCookies(array $headers): void
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }
            $cookie = trim(substr($header, strlen('Set-Cookie:')));
            $pair = explode(';', $cookie, 2)[0] ?? '';
            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $this->cookies[$name] = $value;
        }
    }
}
