<?php

namespace console\controllers;

use common\models\mall\Order;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class BackendSmokeTestController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $platformUsername = 'codex_platform_backend_test_5';
    public $platformPassword = 'CodexTest123';
    public $sellerUsername = 'zhishichanquan';
    public $sellerPassword = '123456';
    public $timeout = 15;

    private $failures = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'platformUsername',
            'platformPassword',
            'sellerUsername',
            'sellerPassword',
            'timeout',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->stdout("Backend smoke test against {$this->baseUrl}\n");
        $logisticsReviewFixtureSn = $this->createLogisticsReviewFixture();

        try {
            $this->runAccount('platform', $this->platformUsername, $this->platformPassword, [
                ['label' => 'platform dashboard', 'path' => '/backend/site/info'],
                ['label' => 'platform products', 'path' => '/backend/mall/product/index'],
                ['label' => 'platform orders', 'path' => '/backend/mall/order/index'],
                ['label' => 'platform order products', 'path' => '/backend/mall/order-product/index'],
                ['label' => 'platform payment attempts', 'path' => '/backend/mall/payment-attempt/index'],
                ['label' => 'platform merchant applications', 'path' => '/backend/mall/merchant-application/index'],
                ['label' => 'platform merchant application self', 'path' => '/backend/mall/merchant-application/my', 'needles' => ['我的入驻申请']],
                ['label' => 'platform store category auth', 'path' => '/backend/mall/store-category-auth/index'],
                ['label' => 'platform store profile', 'path' => '/backend/mall/store-profile/edit'],
                ['label' => 'platform merchant statistics', 'path' => '/backend/mall/merchant-stat/index', 'needles' => ['商家统计', '商品销量排行']],
                ['label' => 'platform merchant coupons', 'path' => '/backend/mall/merchant-coupon/index', 'needles' => ['商家优惠券', '平台券参与']],
                ['label' => 'platform logistics methods', 'path' => '/backend/mall/logistics-method/index', 'needles' => ['物流方式', '店铺选择']],
                ['label' => 'platform merchant deposit', 'path' => '/backend/mall/merchant-deposit/index', 'needles' => ['商家预存金', '充值/扣费']],
                ['label' => 'platform logistics fee review', 'path' => '/backend/mall/logistics-fee-review/index', 'needles' => ['物流费财务复核', '执行调账']],
                ['label' => 'platform settlement readiness', 'path' => '/backend/mall/settlement-readiness/index', 'needles' => ['结算就绪复核', '可结算金额']],
                ['label' => 'platform settlement payout plan', 'path' => '/backend/mall/settlement-payout-plan/index', 'needles' => ['结算打款计划', '计划打款']],
                ['label' => 'platform logistics review orders', 'path' => '/backend/mall/order/index', 'needles' => ['logistics_review_status', 'Review Passed']],
                ['label' => 'platform customer service', 'path' => '/backend/mall/kf/index', 'needles' => ['userType:', '"platform"']],
            ], true);

            $this->runAccount('seller', $this->sellerUsername, $this->sellerPassword, [
                ['label' => 'seller dashboard', 'path' => '/backend/site/info'],
                ['label' => 'seller products', 'path' => '/backend/mall/product/index'],
                ['label' => 'seller orders', 'path' => '/backend/mall/order/index'],
                ['label' => 'seller order products', 'path' => '/backend/mall/order-product/index'],
                ['label' => 'seller merchant application self', 'path' => '/backend/mall/merchant-application/my', 'needles' => ['我的入驻申请']],
                ['label' => 'seller store profile', 'path' => '/backend/mall/store-profile/edit'],
                ['label' => 'seller merchant statistics', 'path' => '/backend/mall/merchant-stat/index', 'needles' => ['商家统计', '商品销量排行']],
                ['label' => 'seller merchant coupons', 'path' => '/backend/mall/merchant-coupon/index', 'needles' => ['商家优惠券', '平台券参与']],
                ['label' => 'seller logistics methods', 'path' => '/backend/mall/logistics-method/index', 'needles' => ['物流方式', '店铺选择']],
                ['label' => 'seller merchant deposit', 'path' => '/backend/mall/merchant-deposit/index', 'needles' => ['商家预存金', '预存金流水']],
                ['label' => 'seller customer service', 'path' => '/backend/mall/kf/index', 'needles' => ['userType:', '"merchant"']],
            ], false);
        } finally {
            $this->cleanupLogisticsReviewFixture($logisticsReviewFixtureSn);
        }

        if ($this->failures) {
            $this->stderr("\nFailed backend smoke checks:\n");
            foreach ($this->failures as $failure) {
                $this->stderr("- {$failure}\n");
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nAll backend smoke checks passed.\n");
        return ExitCode::OK;
    }

    private function createLogisticsReviewFixture(): string
    {
        $seller = (new \yii\db\Query())
            ->select(['store_id'])
            ->from('{{%user}}')
            ->where(['username' => $this->sellerUsername])
            ->one(Yii::$app->db);
        $sellerStoreId = $seller ? (int)$seller['store_id'] : 0;
        $storeId = (int)((new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'status', -10])
            ->andFilterWhere($sellerStoreId > 0 ? ['<>', 'id', $sellerStoreId] : [])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db) ?: 0);
        $userId = (int)((new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', -10])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db) ?: 0);

        if ($storeId <= 0 || $userId <= 0) {
            $this->fail('Cannot create backend smoke logistics review fixture: active store/user missing.');
            return '';
        }

        $now = time();
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 0;
        $order->user_id = $userId;
        $order->address_id = 0;
        $order->name = 'Backend smoke logistics review';
        $order->sn = 'BESMOKE-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Backend';
        $order->last_name = 'Smoke';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Backend smoke fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'backend-smoke@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by backend-smoke-test/run';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = Order::PAYMENT_STATUS_PAID;
        $order->paid_at = $now;
        $order->stock_deducted_at = $now;
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9001;
        $order->shipment_name = 'Backend Smoke Express';
        $order->shipment_fee = 0;
        $order->shipment_fee_deducted_at = 0;
        $order->shipment_status = Order::SHIPMENT_STATUS_SHIPPING;
        $order->logistics_review_status = Order::LOGISTICS_REVIEW_PENDING;
        $order->logistics_reviewed_at = 0;
        $order->logistics_reviewed_by = 0;
        $order->logistics_review_remark = '';
        $order->shipped_at = $now;
        $order->product_amount = 1;
        $order->amount = 1;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = Order::SHIPMENT_STATUS_SHIPPING;
        $order->created_at = $now;
        $order->updated_at = $now;
        $order->created_by = 0;
        $order->updated_by = 0;

        if (!$order->save(false)) {
            $this->fail('Cannot create backend smoke logistics review fixture order.');
            return '';
        }

        return (string)$order->sn;
    }

    private function cleanupLogisticsReviewFixture(string $sn): void
    {
        if ($sn === '') {
            return;
        }

        Order::deleteAll(['sn' => $sn]);
    }

    private function runAccount(string $role, string $username, string $password, array $cases, bool $platform)
    {
        $client = new SmokeHttpClient($this->baseUrl, (int)$this->timeout);
        if (!$this->login($client, $role, $username, $password)) {
            return;
        }

        foreach ($cases as $case) {
            $this->checkPage($client, $case['label'], $case['path'], $case['needles'] ?? []);
        }

        $this->checkTenantIsolation($client, $role, $platform);
    }

    private function login(SmokeHttpClient $client, string $role, string $username, string $password)
    {
        $login = $client->get('/backend/site/login');
        if ($login['status'] !== 200) {
            $this->fail("{$role} login page expected HTTP 200, got {$login['status']}");
            return false;
        }

        $csrf = $this->extractCsrf($login['body']);
        if ($csrf === '') {
            $this->fail("{$role} login page missing _csrf-backend token");
            return false;
        }

        $response = $client->post('/backend/site/login', [
            '_csrf-backend' => $csrf,
            'LoginForm[username]' => $username,
            'LoginForm[password]' => $password,
            'LoginForm[rememberMe]' => '1',
        ]);

        if (!in_array($response['status'], [200, 302], true)) {
            $this->fail("{$role} login submit expected HTTP 200/302, got {$response['status']}");
            return false;
        }

        $probe = $client->get('/backend/site/info');
        if ($probe['status'] !== 200 || stripos($probe['body'], 'login-form') !== false) {
            $this->fail("{$role} login did not reach authenticated backend page");
            return false;
        }

        $this->stdout("PASS {$role} login: {$username}\n");
        return true;
    }

    private function checkPage(SmokeHttpClient $client, string $label, string $path, array $needles = [])
    {
        $response = $client->get($path);
        if ($response['status'] !== 200 || stripos($response['body'], 'login-form') !== false) {
            $this->fail("{$label} expected authenticated HTTP 200, got {$response['status']} from {$path}");
            return;
        }

        foreach ($this->fatalNeedles() as $needle) {
            if (stripos($response['body'], $needle) !== false) {
                $this->fail("{$label} contains fatal marker '{$needle}' from {$path}");
                return;
            }
        }

        foreach ($needles as $needle) {
            if (stripos($response['body'], $needle) === false) {
                $this->fail("{$label} missing expected text '{$needle}' from {$path}");
                return;
            }
        }

        $this->stdout("PASS {$label}: HTTP {$response['status']} {$path}\n");
    }

    private function checkCustomerServiceConfig(SmokeHttpClient $client, string $role, string $username, bool $platform)
    {
        $path = '/backend/mall/kf/index';
        $response = $client->get($path);
        if ($response['status'] !== 200) {
            $this->fail("{$role} customer service config expected HTTP 200, got {$response['status']} from {$path}");
            return;
        }

        foreach ($this->fatalNeedles() as $needle) {
            if (stripos($response['body'], $needle) !== false) {
                $this->fail("{$role} customer service config contains fatal marker '{$needle}' from {$path}");
                return;
            }
        }

        $expectedUserId = $this->userIdByUsername($username);
        if (!$expectedUserId) {
            return;
        }

        $expectedType = $platform ? 'platform' : 'merchant';
        $actualType = $this->extractConfigString($response['body'], 'userType');
        if ($actualType !== $expectedType) {
            $this->fail("{$role} customer service expected userType {$expectedType}, got {$actualType}");
            return;
        }

        $actualUserId = $this->extractConfigInt($response['body'], 'userId');
        if ($actualUserId !== $expectedUserId) {
            $this->fail("{$role} customer service expected userId {$expectedUserId}, got {$actualUserId}");
            return;
        }

        $actualPlatformFlag = $this->extractConfigBool($response['body'], 'isPlatformOperator');
        if ($actualPlatformFlag !== $platform) {
            $this->fail("{$role} customer service expected isPlatformOperator " . ($platform ? 'true' : 'false'));
            return;
        }

        $authToken = $this->extractConfigString($response['body'], 'authToken');
        if (substr_count($authToken, '.') !== 1) {
            $this->fail("{$role} customer service expected signed IM authToken");
            return;
        }

        foreach (['wsAddress', 'uploadUrl'] as $key) {
            if ($this->extractConfigString($response['body'], $key) === '') {
                $this->fail("{$role} customer service missing {$key}");
                return;
            }
        }

        if (stripos($response['body'], 'connect();') === false) {
            $this->fail("{$role} customer service does not auto-connect to IM");
            return;
        }

        if ($platform && !$this->platformStoreMapLooksUsable($response['body'])) {
            return;
        }

        $this->stdout("PASS {$role} customer service config: {$expectedType} user {$expectedUserId}\n");
    }

    private function userIdByUsername(string $username)
    {
        $id = (new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['username' => $username])
            ->scalar(Yii::$app->db);
        if (!$id) {
            $this->fail("Cannot find backend user {$username} for customer service config.");
            return 0;
        }

        return (int)$id;
    }

    private function platformStoreMapLooksUsable(string $body)
    {
        $seller = (new \yii\db\Query())
            ->select(['u.id', 'u.store_id'])
            ->from(['u' => '{{%user}}'])
            ->where(['u.username' => $this->sellerUsername])
            ->one(Yii::$app->db);
        if (!$seller || (int)$seller['store_id'] <= 0) {
            $this->fail("Cannot validate platform customer service storeMap for {$this->sellerUsername}.");
            return false;
        }

        if (strpos($body, '"' . (int)$seller['id'] . '"') === false || strpos($body, '"id":' . (int)$seller['store_id']) === false) {
            $this->fail('platform customer service storeMap does not include seller store context.');
            return false;
        }

        return true;
    }

    private function extractConfigString(string $body, string $key)
    {
        if (!preg_match('/\b' . preg_quote($key, '/') . '\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $body, $matches)) {
            return '';
        }

        $decoded = json_decode('"' . $matches[1] . '"', true);
        return is_string($decoded) ? $decoded : '';
    }

    private function extractConfigInt(string $body, string $key)
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

    private function checkTenantIsolation(SmokeHttpClient $client, string $role, bool $platform)
    {
        $fixtures = $this->tenantIsolationFixtures($this->sellerUsername);
        if (!$fixtures) {
            return;
        }

        $cases = [
            ['label' => "{$role} own product view", 'path' => '/backend/mall/product/view?id=' . $fixtures['ownProductId'], 'allowed' => true],
            ['label' => "{$role} other-store product view", 'path' => '/backend/mall/product/view?id=' . $fixtures['otherProductId'], 'allowed' => $platform],
        ];

        if ($fixtures['parentOrderId']) {
            $cases[] = ['label' => "{$role} parent order view", 'path' => '/backend/mall/order/view?id=' . $fixtures['parentOrderId'], 'allowed' => $platform];
        }
        if ($fixtures['otherChildOrderId']) {
            $cases[] = ['label' => "{$role} other-store child order view", 'path' => '/backend/mall/order/view?id=' . $fixtures['otherChildOrderId'], 'allowed' => $platform];
        }

        foreach ($cases as $case) {
            $this->checkIsolationPage($client, $case['label'], $case['path'], $case['allowed']);
        }
    }

    private function checkIsolationPage(SmokeHttpClient $client, string $label, string $path, bool $allowed)
    {
        $response = $client->get($path);
        $blocked = in_array($response['status'], [302, 403], true)
            || stripos($response['body'], 'No Auth') !== false
            || stripos($response['body'], 'login-form') !== false;

        if ($allowed) {
            if ($response['status'] < 200 || $response['status'] >= 400 || stripos($response['body'], 'login-form') !== false) {
                $this->fail("{$label} expected access, got HTTP {$response['status']} from {$path}");
                return;
            }
            $this->stdout("PASS {$label}: accessible {$path}\n");
            return;
        }

        if (!$blocked) {
            $this->fail("{$label} expected tenant isolation block, got HTTP {$response['status']} from {$path}");
            return;
        }

        $this->stdout("PASS {$label}: blocked {$path}\n");
    }

    private function tenantIsolationFixtures(string $username)
    {
        $seller = (new \yii\db\Query())
            ->from('{{%user}}')
            ->where(['username' => $username])
            ->one(Yii::$app->db);
        if (!$seller || (int)$seller['store_id'] <= 0) {
            $this->fail("Cannot build tenant isolation fixtures: {$username} has no store_id.");
            return null;
        }

        $storeId = (int)$seller['store_id'];
        $ownProductId = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_product}}')
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', -10])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
        $otherProductId = (new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_product}}')
            ->where(['<>', 'store_id', $storeId])
            ->andWhere(['>', 'status', -10])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);

        if (!$ownProductId || !$otherProductId) {
            $this->fail('Cannot build tenant isolation fixtures: need own and other-store products.');
            return null;
        }

        return [
            'ownProductId' => (int)$ownProductId,
            'otherProductId' => (int)$otherProductId,
            'parentOrderId' => (int)((new \yii\db\Query())
                ->select('id')
                ->from('{{%mall_order}}')
                ->where(['parent_id' => 0])
                ->andWhere(['>', 'status', -10])
                ->orderBy(['id' => SORT_DESC])
                ->scalar(Yii::$app->db) ?: 0),
            'otherChildOrderId' => (int)((new \yii\db\Query())
                ->select('id')
                ->from('{{%mall_order}}')
                ->where(['<>', 'store_id', $storeId])
                ->andWhere(['>', 'parent_id', 0])
                ->andWhere(['>', 'status', -10])
                ->orderBy(['id' => SORT_DESC])
                ->scalar(Yii::$app->db) ?: 0),
        ];
    }

    private function extractCsrf(string $body)
    {
        if (preg_match('/name="_csrf-backend"\s+value="([^"]+)"/', $body, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $body, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function fatalNeedles()
    {
        return [
            'yii\base\ErrorException',
            'yii\db\Exception',
            'PHP Warning',
            'PHP Fatal error',
            'Stack trace:',
            'Call to undefined',
            'Trying to get property',
        ];
    }

    private function fail(string $message)
    {
        $this->failures[] = $message;
        $this->stderr("FAIL {$message}\n");
    }
}

class SmokeHttpClient
{
    private $baseUrl;
    private $timeout;
    private $cookies = [];

    public function __construct(string $baseUrl, int $timeout)
    {
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
    }

    public function get(string $path)
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $data)
    {
        return $this->request('POST', $path, http_build_query($data));
    }

    private function request(string $method, string $path, string $body = '')
    {
        $headers = [
            'User-Agent: MongoyiaBackendSmokeTest/1.0',
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

    private function storeCookies(array $headers)
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
