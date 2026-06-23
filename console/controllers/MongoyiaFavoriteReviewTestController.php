<?php

namespace console\controllers;

use common\models\mall\Favorite;
use common\models\mall\Order;
use common\models\mall\Review;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaFavoriteReviewTestController extends Controller
{
    public $baseUrl = '';
    public $productId = 102;
    public $timeout = 15;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['baseUrl', 'productId', 'timeout', 'strict']);
    }

    public function actionRun()
    {
        $this->baseUrl = $this->resolveBaseUrl();
        $this->stdout("Mongoyia favorite/review closure test\n");

        $this->checkSchemas();
        $this->checkModels();
        $this->checkEntrances();
        $this->checkProductPageAjaxEntrances();
        $this->checkDataReadiness();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchemas()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_favorite}}', ['id', 'user_id', 'product_id', 'name', 'status']);
        $this->requireColumns('{{%mall_review}}', ['id', 'store_id', 'product_id', 'user_id', 'order_id', 'star', 'content', 'status']);
    }

    private function checkModels()
    {
        $this->section('Models');
        foreach ([Favorite::class, Review::class] as $class) {
            if (!class_exists($class)) {
                $this->fail("Missing model {$class}.");
                continue;
            }
            $this->ok("Model exists: {$class}");
        }
    }

    private function checkEntrances()
    {
        $this->section('Entrances');
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/ProductController.php', ['function actionFavorite', 'function actionReview']);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/OrderController.php', [
            'function actionView',
            'Review submitted',
            'markReceived',
            'MONGOYIA_BUYER_ORDER_RECEIVED_POST_ID_GUARD_V1',
            "post('id', 0)",
        ]);
        $this->requireFileContains('@app/../web/resources/mall/default/views/user/favorite_.php', ['Favorite']);
        $this->requireFileContains('@app/../web/resources/mall/default/views/order/view.php', [
            'Write a review',
            'star',
            'data-mongoyia-buyer-received-post-guard',
            "hiddenInput('id'",
        ]);
        $this->requireFileContains('@app/../web/resources/mall/default/views/user/order_.php', [
            'data-mongoyia-buyer-received-post-guard',
            "hiddenInput('id'",
        ]);
    }

    private function checkProductPageAjaxEntrances()
    {
        $this->section('Product page');
        $response = $this->get($this->baseUrl . '/product/' . (int)$this->productId);
        if ($response['status'] < 200 || $response['status'] >= 400) {
            $this->fail("Product page expected HTTP 2xx/3xx, got {$response['status']}.");
            return;
        }
        foreach (['/mall/product/favorite', '/mall/product/review', 'review-list'] as $needle) {
            if (strpos($response['body'], $needle) === false) {
                $this->fail("Product page missing favorite/review marker '{$needle}'.");
                return;
            }
        }
        $this->ok('Product page exposes favorite and review AJAX entrances.');
    }

    private function checkDataReadiness()
    {
        $this->section('Data readiness');
        $favoriteCount = (int)(new \yii\db\Query())->from('{{%mall_favorite}}')->count('*', Yii::$app->db);
        $reviewCount = (int)(new \yii\db\Query())->from('{{%mall_review}}')->count('*', Yii::$app->db);
        $reviewableOrders = (int)(new \yii\db\Query())
            ->from('{{%mall_order}}')
            ->where(['shipment_status' => Order::SHIPMENT_STATUS_RECEIVED])
            ->andWhere(['payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->count('*', Yii::$app->db);

        $this->ok("Favorite rows: {$favoriteCount}.");
        if ($reviewCount === 0) {
            $this->warn('No review rows found; UI exists but review display needs business sample data.');
        } else {
            $this->ok("Review rows: {$reviewCount}.");
        }
        if ($reviewableOrders === 0) {
            $this->warn('No received paid/COD order found for manual review test.');
        } else {
            $this->ok("Reviewable order rows: {$reviewableOrders}.");
        }
    }

    private function requireColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema) {
            $this->fail("Missing table {$table}.");
            return;
        }
        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                $this->fail("Table {$table} missing column {$column}.");
                return;
            }
        }
        $this->ok("Table {$table} has required columns.");
    }

    private function requireFileContains(string $alias, array $needles)
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
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
                'header' => "User-Agent: MongoyiaFavoriteReviewTest/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }
        return ['status' => $status, 'body' => $body === false ? '' : $body];
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
