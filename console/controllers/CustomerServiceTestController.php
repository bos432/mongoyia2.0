<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceTestController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $platformUsername = 'codex_platform_backend_test_5';
    public $platformPassword = 'CodexTest123';
    public $sellerUsername = 'zhishichanquan';
    public $sellerPassword = '123456';
    public $productId = 102;
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $strict = false;
    public $timeout = 15;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;
    private $productContext = null;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'platformUsername',
            'platformPassword',
            'sellerUsername',
            'sellerPassword',
            'productId',
            'handoverDir',
            'outputPath',
            'strict',
            'timeout',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->productId = (int)$this->productId;
        $this->stdout("Mongoyia customer-service readiness against {$this->baseUrl}\n");

        $this->checkContractAndSource();
        $this->checkProductContext();
        $this->checkBackendWorkbenches();
        $this->checkFrontendChatPage();
        $this->checkFrontendTokenEndpoint();

        $result = $this->result();
        $path = $this->writeReport($result);
        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0))) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkContractAndSource(): void
    {
        $this->requireFileMarkers('Customer-service contract', 'docs/mongoyia-customer-service-contract.md', [
            '# Mongoyia Customer Service Contract',
            'Contract version: 2026-06-19-customer-service-v1',
            'MONGOYIA_CUSTOMER_SERVICE_CONTRACT_V1',
            'MONGOYIA_CUSTOMER_SERVICE_ORDER_ASSIST_RESERVED_V1',
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_RESERVED_V1',
            'MONGOYIA_CUSTOMER_SERVICE_STAT_RESERVED_V1',
            'Do not change IM token payload rules',
        ]);

        $this->requireFileMarkers('Backend customer-service controller', 'backend/modules/mall/controllers/KfController.php', [
            'class KfController extends BaseController',
            'isMallPlatformOperator',
            "'storeMap' => \$storeMap",
            "'imAuthToken' => \$this->createImAuthToken",
            "'type' => \$isPlatformOperator ? 'platform' : 'merchant'",
            'imAuthSecret',
            'hash_hmac',
            'actionSessionContext',
            'actionTicketCreateFromSession',
            'actionComplaintEvidenceUpload',
            'actionQuickReplies',
            'CustomerServiceStatWidgetReadinessService',
        ]);

        $this->requireFileMarkers('Backend customer-service workbench UI', 'backend/modules/mall/views/kf/index.php', [
            '客服工作台',
            '咨询列表',
            'userType:',
            'userId:',
            'isPlatformOperator:',
            'storeMap:',
            'authToken:',
            'wsAddress:',
            'uploadUrl:',
            'connect();',
            'formatChatContext',
            'product_id',
            'store_id',
            '商品 #',
            '店铺 #',
            'accept="image/*"',
            'data-mongoyia-customer-service-session-context="panel"',
            'data-mongoyia-customer-service-chat-ticket="actions"',
            'quickReplySelect',
        ]);

        $this->requireFileMarkers('Frontend customer-service controller', 'frontend/modules/mall/controllers/ChatController.php', [
            'class ChatController extends BaseController',
            'MONGOYIA_CUSTOMER_SERVICE_CHAT_POST_GUARD_V1',
            'public function actionIndex',
            'public function actionUpload',
            'public function actionToken',
            'chatRequiresPost',
            "post('gid', 0)",
            "post('user_id', '')",
            "'type' => 'user'",
            "'uid' => (int)\$product['user_id']",
            "'product_id' => \$gid",
            "'store_id' => (int)\$product['store_id']",
            'imAuthSecret',
            'getimagesize',
            'actionRatingSubmit',
            'CustomerServiceRatingService',
        ]);

        $this->requireFileMarkers('Frontend customer-service chat UI', 'web/resources/mall/default/views/chat/index.php', [
            'data-mongoyia-mobile-ui="chat"',
            'merchantId:',
            'productId:',
            'storeId:',
            'wsAddress:',
            'tokenUrl:',
            'uploadUrl:',
            'fetchImAuthToken',
            'initChatContext',
            'target_uid',
            'product_id',
            'store_id',
            'accept="image/*"',
            'data-mongoyia-customer-service-rating="frontend"',
            'ratingUrl:',
            'submitRating',
        ]);

        foreach ([
            'backend/modules/mall/views/kf/index.php',
            'web/resources/mall/default/views/chat/index.php',
        ] as $path) {
            $this->requireFileMissingMarkers('Reserved customer-service widgets stay hidden in ' . $path, $path, [
                'data-mongoyia-customer-service-order-assist',
                'data-mongoyia-customer-service-complaint',
                'data-mongoyia-customer-service-stat',
                'MONGOYIA_CUSTOMER_SERVICE_ORDER_ASSIST_WIDGET',
                'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_WIDGET',
                'MONGOYIA_CUSTOMER_SERVICE_STAT_WIDGET',
            ]);
        }
    }

    private function checkProductContext(): void
    {
        if ($this->productId <= 0) {
            $this->addCheck('Customer-service product context', 'FAIL', 'productId=' . $this->productId, 'Product id must be positive.');
            return;
        }

        $product = (new \yii\db\Query())
            ->select(['p.id', 'p.store_id', 's.user_id'])
            ->from(['p' => '{{%mall_product}}'])
            ->leftJoin(['s' => '{{%store}}'], 's.id = p.store_id')
            ->where(['p.id' => $this->productId])
            ->one(Yii::$app->db);

        if (!$product || (int)($product['store_id'] ?? 0) <= 0 || (int)($product['user_id'] ?? 0) <= 0) {
            $this->addCheck('Customer-service product context', 'FAIL', 'productId=' . $this->productId, 'Product must exist and map to a seller store/user.');
            return;
        }

        $this->productContext = [
            'productId' => (int)$product['id'],
            'storeId' => (int)$product['store_id'],
            'merchantUid' => (int)$product['user_id'],
        ];

        $this->addCheck(
            'Customer-service product context',
            'PASS',
            'product=' . $this->productContext['productId'] . ', store=' . $this->productContext['storeId'] . ', merchant=' . $this->productContext['merchantUid'],
            'Product chat context is available for frontend and backend checks.'
        );
    }

    private function checkBackendWorkbenches(): void
    {
        $platform = new CustomerServiceHttpClient($this->baseUrl, (int)$this->timeout);
        if ($this->loginBackend($platform, 'platform', $this->platformUsername, $this->platformPassword)) {
            $this->checkBackendWorkbench($platform, 'platform', $this->platformUsername, true);
        }

        $seller = new CustomerServiceHttpClient($this->baseUrl, (int)$this->timeout);
        if ($this->loginBackend($seller, 'seller', $this->sellerUsername, $this->sellerPassword)) {
            $this->checkBackendWorkbench($seller, 'seller', $this->sellerUsername, false);
        }
    }

    private function loginBackend(CustomerServiceHttpClient $client, string $role, string $username, string $password): bool
    {
        $login = $client->get('/backend/site/login');
        if ($login['status'] !== 200) {
            $this->addCheck("{$role} backend login page", 'FAIL', 'HTTP ' . $login['status'], 'Login page did not return HTTP 200.');
            return false;
        }

        $csrf = $this->extractCsrf($login['body']);
        if ($csrf === '') {
            $this->addCheck("{$role} backend login csrf", 'FAIL', '/backend/site/login', 'Missing _csrf-backend token.');
            return false;
        }

        $response = $client->post('/backend/site/login', [
            '_csrf-backend' => $csrf,
            'LoginForm[username]' => $username,
            'LoginForm[password]' => $password,
            'LoginForm[rememberMe]' => '1',
        ]);

        if (!in_array($response['status'], [200, 302], true)) {
            $this->addCheck("{$role} backend login submit", 'FAIL', 'HTTP ' . $response['status'], 'Login submit did not return HTTP 200/302.');
            return false;
        }

        $probe = $client->get('/backend/site/info');
        if ($probe['status'] !== 200 || stripos($probe['body'], 'login-form') !== false) {
            $this->addCheck("{$role} backend login authenticated", 'FAIL', 'HTTP ' . $probe['status'], 'Authenticated backend page was not reached.');
            return false;
        }

        $this->addCheck("{$role} backend login authenticated", 'PASS', $username, 'Backend account can reach authenticated pages.');
        return true;
    }

    private function checkBackendWorkbench(CustomerServiceHttpClient $client, string $role, string $username, bool $platform): void
    {
        $path = '/backend/mall/kf/index';
        $response = $client->get($path);
        if ($response['status'] !== 200 || stripos($response['body'], 'login-form') !== false) {
            $this->addCheck("{$role} customer-service workbench reachable", 'FAIL', 'HTTP ' . $response['status'], 'Workbench did not return authenticated HTTP 200.');
            return;
        }

        if (!$this->checkNoFatalMarkers("{$role} customer-service workbench", $response['body'], $path)) {
            return;
        }

        $expectedUserId = $this->userIdByUsername($username);
        if ($expectedUserId <= 0) {
            return;
        }

        $expectedType = $platform ? 'platform' : 'merchant';
        $actualType = $this->extractConfigString($response['body'], 'userType');
        $actualUserId = $this->extractConfigInt($response['body'], 'userId');
        $actualPlatformFlag = $this->extractConfigBool($response['body'], 'isPlatformOperator');

        if ($actualType !== $expectedType || $actualUserId !== $expectedUserId || $actualPlatformFlag !== $platform) {
            $this->addCheck(
                "{$role} customer-service identity",
                'FAIL',
                "type={$actualType}, user={$actualUserId}, platform=" . var_export($actualPlatformFlag, true),
                "Expected {$expectedType} user {$expectedUserId}."
            );
            return;
        }

        $authToken = $this->extractConfigString($response['body'], 'authToken');
        if (substr_count($authToken, '.') !== 1) {
            $this->addCheck("{$role} customer-service signed token", 'FAIL', $path, 'Expected signed IM authToken with payload.signature shape.');
            return;
        }

        foreach (['wsAddress', 'uploadUrl'] as $key) {
            if ($this->extractConfigString($response['body'], $key) === '') {
                $this->addCheck("{$role} customer-service transport config", 'FAIL', $key, "{$key} is missing from workbench CONFIG.");
                return;
            }
        }

        foreach (['connect();', 'type: \'chat_list\'', 'type: \'mark_read\'', 'formatChatContext', 'msg_type: 2'] as $marker) {
            if (strpos($response['body'], $marker) === false) {
                $this->addCheck("{$role} customer-service workbench behavior", 'FAIL', $marker, 'Expected chat-list/read-state/context/image marker is missing.');
                return;
            }
        }

        if ($platform && !$this->platformStoreMapIncludesSeller($response['body'])) {
            return;
        }

        if (!$platform && strpos($response['body'], 'storeMap: []') === false) {
            $this->addCheck('seller customer-service store scope', 'FAIL', $path, 'Seller workbench should not render platform storeMap context.');
            return;
        }

        $this->addCheck("{$role} customer-service workbench", 'PASS', $path, "Workbench renders {$expectedType} identity, signed token, transport config, context, and image controls.");
    }

    private function checkFrontendChatPage(): void
    {
        if (!$this->productContext) {
            return;
        }

        $client = new CustomerServiceHttpClient($this->baseUrl, (int)$this->timeout);
        $path = '/mall/chat/index?gid=' . (int)$this->productContext['productId'];
        $response = $client->get($path);
        if ($response['status'] !== 200) {
            $this->addCheck('Frontend customer-service chat page reachable', 'FAIL', 'HTTP ' . $response['status'], $path . ' did not return HTTP 200.');
            return;
        }

        if (!$this->checkNoFatalMarkers('Frontend customer-service chat page', $response['body'], $path)) {
            return;
        }

        $markers = [
            'data-mongoyia-mobile-ui="chat"',
            'merchantId:',
            'productId:',
            'storeId:',
            'wsAddress:',
            'tokenUrl:',
            'uploadUrl:',
            'fetchImAuthToken',
            'initChatContext',
            'connect();',
            'target_uid',
            'msg_type: 2',
            'accept="image/*"',
        ];
        foreach ($markers as $marker) {
            if (strpos($response['body'], $marker) === false) {
                $this->addCheck('Frontend customer-service chat markers', 'FAIL', $marker, 'Expected frontend chat marker is missing.');
                return;
            }
        }

        $merchantId = $this->extractConfigInt($response['body'], 'merchantId');
        $productId = $this->extractConfigInt($response['body'], 'productId');
        $storeId = $this->extractConfigInt($response['body'], 'storeId');
        if (
            $merchantId !== (int)$this->productContext['merchantUid']
            || $productId !== (int)$this->productContext['productId']
            || $storeId !== (int)$this->productContext['storeId']
        ) {
            $this->addCheck(
                'Frontend customer-service context',
                'FAIL',
                "merchant={$merchantId}, product={$productId}, store={$storeId}",
                'Frontend chat CONFIG does not match product seller/store context.'
            );
            return;
        }

        foreach ($this->reservedUiMarkers() as $marker) {
            if (strpos($response['body'], $marker) !== false) {
                $this->addCheck('Frontend reserved customer-service widgets hidden', 'FAIL', $marker, 'Reserved future widget marker is exposed before implementation.');
                return;
            }
        }

        $this->addCheck('Frontend customer-service chat page', 'PASS', $path, 'Chat page renders mobile marker, token/upload config, seller/product/store context, and image controls.');
    }

    private function checkFrontendTokenEndpoint(): void
    {
        if (!$this->productContext) {
            return;
        }

        $client = new CustomerServiceHttpClient($this->baseUrl, (int)$this->timeout);
        $path = '/mall/chat/token?lang=en';
        $response = $client->post($path, [
            'gid' => (int)$this->productContext['productId'],
            'user_id' => 'customer_service_readiness_' . date('YmdHis'),
        ]);
        if ($response['status'] !== 200) {
            $this->addCheck('Frontend customer-service token endpoint', 'FAIL', 'HTTP ' . $response['status'], 'Token endpoint did not return HTTP 200.');
            return;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || (int)($data['code'] ?? 0) !== 200) {
            $this->addCheck('Frontend customer-service token endpoint', 'FAIL', substr($response['body'], 0, 160), 'Token endpoint did not return code=200 JSON.');
            return;
        }

        $token = (string)($data['data']['token'] ?? '');
        if (substr_count($token, '.') !== 1) {
            $this->addCheck('Frontend customer-service signed token', 'FAIL', 'token shape', 'Expected signed user IM token with payload.signature shape.');
            return;
        }

        $this->addCheck('Frontend customer-service token endpoint', 'PASS', '/mall/chat/token', 'User chat endpoint returns a signed IM token without opening WSS.');
    }

    private function platformStoreMapIncludesSeller(string $body): bool
    {
        $sellerId = $this->userIdByUsername($this->sellerUsername);
        if ($sellerId <= 0) {
            return false;
        }

        $store = (new \yii\db\Query())
            ->select(['id', 'name'])
            ->from('{{%store}}')
            ->where(['user_id' => $sellerId])
            ->andWhere(['>', 'id', 0])
            ->one(Yii::$app->db);

        if (!$store) {
            $this->addCheck('platform customer-service storeMap', 'FAIL', 'seller=' . $this->sellerUsername, 'Seller store could not be found for storeMap validation.');
            return false;
        }

        $needleUser = '"' . (int)$sellerId . '"';
        $needleStore = '"id":' . (int)$store['id'];
        if (strpos($body, $needleUser) === false || strpos($body, $needleStore) === false) {
            $this->addCheck('platform customer-service storeMap', 'FAIL', "seller={$sellerId}, store={$store['id']}", 'Platform workbench storeMap does not include seller store context.');
            return false;
        }

        return true;
    }

    private function userIdByUsername(string $username): int
    {
        $id = (new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['username' => $username])
            ->scalar(Yii::$app->db);

        if (!$id) {
            $this->addCheck('Backend user lookup', 'FAIL', $username, 'Required backend test user is missing.');
            return 0;
        }

        return (int)$id;
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

        $this->addCheck($label, 'PASS', $path, 'Required customer-service markers are present.');
    }

    private function requireFileMissingMarkers(string $label, string $path, array $markers): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($markers as $marker) {
            if (strpos($content, $marker) !== false) {
                $this->addCheck($label, 'FAIL', $path, "Reserved customer-service marker `{$marker}` is exposed early.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Reserved order-assist/complaint/stat widgets are not exposed.');
    }

    private function checkNoFatalMarkers(string $label, string $body, string $path): bool
    {
        foreach ($this->fatalNeedles() as $needle) {
            if (stripos($body, $needle) !== false) {
                $this->addCheck($label, 'FAIL', $path, "Page contains fatal marker `{$needle}`.");
                return false;
            }
        }

        return true;
    }

    private function extractCsrf(string $body): string
    {
        if (preg_match('/name="_csrf-backend"\s+value="([^"]+)"/', $body, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $body, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function extractConfigString(string $body, string $key): string
    {
        if (!preg_match('/\b' . preg_quote($key, '/') . '\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $body, $matches)) {
            return '';
        }

        $decoded = json_decode('"' . $matches[1] . '"', true);
        return is_string($decoded) ? $decoded : '';
    }

    private function extractConfigInt(string $body, string $key): int
    {
        if (!preg_match('/\b' . preg_quote($key, '/') . '\s*:\s*(\d+)/', $body, $matches)) {
            return 0;
        }

        return (int)$matches[1];
    }

    private function extractConfigBool(string $body, string $key)
    {
        if (!preg_match('/\b' . preg_quote($key, '/') . '\s*:\s*(true|false)/', $body, $matches)) {
            return null;
        }

        return $matches[1] === 'true';
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
            '<?php',
            '<?=',
        ];
    }

    private function reservedUiMarkers(): array
    {
        return [
            'data-mongoyia-customer-service-order-assist',
            'data-mongoyia-customer-service-complaint',
            'data-mongoyia-customer-service-stat',
            'MONGOYIA_CUSTOMER_SERVICE_ORDER_ASSIST_WIDGET',
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_WIDGET',
            'MONGOYIA_CUSTOMER_SERVICE_STAT_WIDGET',
        ];
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

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Customer Service Readiness',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Product ID: ' . (int)$this->productId,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Evidence type: read-only customer-service workbench readiness; no WSS connection, no chat mutation, no file upload, no order mutation.',
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
            '## Capability Boundary',
            '',
            '| Capability | State | Gate |',
            '|---|---|---|',
            '| Platform/seller chat workbench | Current | Backend `/backend/mall/kf/index`, signed IM auth token, WSS/upload config, product/store context rendering. |',
            '| User product chat page | Current | Frontend `/mall/chat/index?gid=<product_id>`, signed token endpoint, product/store context, image-only controls. |',
            '| Order assistance | Reserved | `MONGOYIA_CUSTOMER_SERVICE_ORDER_ASSIST_RESERVED_V1`; requires schema, permissions, audit trail, UI, regression, cleanup. |',
            '| Complaint handling | Reserved | `MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_RESERVED_V1`; requires schema, permissions, workflow, evidence rules, cleanup. |',
            '| Service statistics | Reserved | `MONGOYIA_CUSTOMER_SERVICE_STAT_RESERVED_V1`; requires aggregation rules, backend UI, export, and regression. |',
            '',
            '## Boundaries',
            '',
            '- This command does not open a WebSocket connection; public-domain WSS evidence still needs `im-healthcheck.py` and `im-regression.py` on the test domain.',
            '- This command does not change IM token rules, create chat messages, upload files, create orders, or enable reserved customer-service widgets.',
            '- Future order-assist, complaint, and statistics widgets must land with schema, permissions, audit records, regression, and cleanup in the same gate.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-readiness-' . date('Ymd-His') . '.md';
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

class CustomerServiceHttpClient
{
    private $baseUrl;
    private $timeout;
    private $cookies = [];

    public function __construct(string $baseUrl, int $timeout)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
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
            'User-Agent: MongoyiaCustomerServiceReadiness/1.0',
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
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $method === 'POST' ? $body : '',
                'ignore_errors' => true,
                'timeout' => $this->timeout,
                'follow_location' => 0,
            ],
        ]);

        $content = @file_get_contents($this->baseUrl . $path, false, $context);
        $status = 0;
        $responseHeaders = $http_response_header ?? [];
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
            $pair = explode(';', $cookie, 2)[0];
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $this->cookies[$name] = $value;
        }
    }
}
