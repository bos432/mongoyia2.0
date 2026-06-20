<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaWebClosureTestController extends Controller
{
    public $baseUrl = '';
    public $productIds = '90,102';
    public $timeout = 15;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'productIds',
            'timeout',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = $this->resolveBaseUrl();
        $this->stdout("Mongoyia Web/PC/H5 closure smoke against {$this->baseUrl}\n");

        $this->checkCorePages();
        $this->checkCategoryAndSearchPages();
        $this->checkUserClosureEntrances();
        $this->checkPaymentEntrance();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkCorePages()
    {
        $this->section('Core storefront pages');
        $this->checkPage('home', '/', ['Mongoyia']);
        $this->checkPage('cart', '/mall/cart/index');

        foreach ($this->productIds() as $productId) {
            $this->checkPage('product ' . $productId, '/product/' . $productId, ['addToCart', 'favorite', 'review']);
        }

        $chatProductId = $this->productIds()[1] ?? ($this->productIds()[0] ?? 102);
        $this->checkPage('IM entry', '/mall/chat/index?gid=' . $chatProductId, ['wsAddress', 'tokenUrl', 'uploadUrl']);
    }

    private function checkCategoryAndSearchPages()
    {
        $this->section('Category/search pages');
        $categoryId = (int)(new \yii\db\Query())
            ->select('category_id')
            ->from('{{%mall_product}}')
            ->where(['id' => $this->productIds()])
            ->andWhere(['>', 'category_id', 0])
            ->scalar(Yii::$app->db);

        if ($categoryId > 0) {
            $this->checkPage('category ' . $categoryId, '/mall/category/view?id=' . $categoryId);
        } else {
            $this->warn('No category id found from configured products; category detail smoke skipped.');
        }

        $this->checkPage('empty search', '/mall/category/view?keyword=');
        $this->checkPage('sorted by price', '/mall/category/view?keyword=&sort=price');
        $this->checkPage('sorted by sales', '/mall/category/view?keyword=&sort=-sales');
    }

    private function checkUserClosureEntrances()
    {
        $this->section('User closure entrances');
        foreach ([
            'login' => '/mall/default/login',
            'favorites' => '/mall/user/favorite',
            'coupons' => '/mall/user/coupon',
            'orders' => '/mall/user/order',
        ] as $label => $path) {
            $this->checkPage($label, $path);
        }
    }

    private function checkPaymentEntrance()
    {
        $this->section('Payment entrance');
        $orderId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%mall_order}}')
            ->where(['parent_id' => 0])
            ->orderBy(['id' => SORT_DESC])
            ->scalar(Yii::$app->db);

        if ($orderId <= 0) {
            $this->warn('No parent order found; payment page smoke skipped.');
            return;
        }

        $this->checkPage('payment ' . $orderId, '/mall/payment/index/' . $orderId);
    }

    private function checkPage(string $label, string $path, array $needles = [])
    {
        $url = $this->baseUrl . $path;
        $response = $this->get($url);
        if ($response['status'] < 200 || $response['status'] >= 400) {
            $this->fail("{$label} expected HTTP 2xx/3xx, got {$response['status']} from {$url}");
            return;
        }

        foreach ($this->fatalNeedles() as $needle) {
            if (stripos($response['body'], $needle) !== false) {
                $this->fail("{$label} contains fatal marker '{$needle}' from {$url}");
                return;
            }
        }

        foreach ($needles as $needle) {
            if (stripos($response['body'], $needle) === false) {
                $this->fail("{$label} missing expected text '{$needle}' from {$url}");
                return;
            }
        }

        $this->ok("{$label}: HTTP {$response['status']} {$path}");
    }

    private function productIds()
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)$this->productIds))));
    }

    private function resolveBaseUrl()
    {
        $baseUrl = trim((string)$this->baseUrl);
        if ($baseUrl === '') {
            $baseUrl = (string)(Yii::$app->params['webBaseUrl'] ?? '');
        }
        if ($baseUrl === '') {
            $domain = trim((string)(Yii::$app->params['storePlatformDomain'] ?? ''));
            if ($domain !== '') {
                $baseUrl = (strpos($domain, 'http://') === 0 || strpos($domain, 'https://') === 0) ? $domain : 'https://' . $domain;
            }
        }
        if ($baseUrl === '') {
            $baseUrl = 'http://' . '127.0.0.1:8089';
        }

        return rtrim($baseUrl, '/');
    }

    private function get(string $url)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => (int)$this->timeout,
                'header' => "User-Agent: MongoyiaWebClosureTest/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }

        return ['status' => $status, 'body' => $body === false ? '' : $body];
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

    private function section(string $name)
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function warn(string $message)
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
