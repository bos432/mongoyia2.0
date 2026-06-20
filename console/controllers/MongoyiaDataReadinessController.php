<?php

namespace console\controllers;

use common\models\BaseModel;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDataReadinessController extends Controller
{
    public $platformStoreId = 5;
    public $platformUsername = 'codex_platform_backend_test_5';
    public $sellerUsername = 'zhishichanquan';
    public $paymentUserId = 71;
    public $productIds = '90,102';
    public $imMerchantUid = 37;
    public $imProductId = 102;
    public $imStoreId = 9;
    public $minStock = 10;
    public $requireDifferentStores = true;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'platformStoreId',
            'platformUsername',
            'sellerUsername',
            'paymentUserId',
            'productIds',
            'imMerchantUid',
            'imProductId',
            'imStoreId',
            'minStock',
            'requireDifferentStores',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia data readiness check\n");

        $this->checkPlatformStore();
        $this->checkStoreHosts();
        $this->checkUsers();
        $this->checkProducts();
        $this->checkImContext();
        $this->checkLanguages();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkPlatformStore()
    {
        $this->section('Platform store');
        $store = $this->one('{{%store}}', ['id' => (int)$this->platformStoreId]);
        if (!$store) {
            $this->fail("Platform store {$this->platformStoreId} is missing.");
            return;
        }

        if ((int)$store['status'] !== BaseModel::STATUS_ACTIVE) {
            $this->fail("Platform store {$this->platformStoreId} is not active.");
        } else {
            $this->ok("Platform store {$this->platformStoreId} exists: {$store['name']}.");
        }
    }

    private function checkStoreHosts()
    {
        $this->section('Store host routing');
        $stores = (new \yii\db\Query())
            ->select(['id', 'name', 'host_name', 'route', 'status'])
            ->from('{{%store}}')
            ->where(['>=', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);

        if (!$stores) {
            $this->fail('No store rows found for host routing.');
            return;
        }

        $legacyHosts = array_flip($this->legacyHostDomains());
        $platformHosts = array_flip($this->platformHosts());
        $platformWarnings = [];
        $legacyRows = [];

        foreach ($stores as $store) {
            foreach ($this->hostNamesFromValue($store['host_name'] ?? '') as $host) {
                if (isset($legacyHosts[$host])) {
                    $legacyRows[] = "#{$store['id']} {$host}";
                }

                if (!isset($platformHosts[$host])) {
                    continue;
                }

                if ((int)$store['id'] !== (int)$this->platformStoreId) {
                    $platformWarnings[] = "#{$store['id']} {$host}";
                } elseif ((string)$store['route'] !== 'mall') {
                    $this->fail("Platform store {$store['id']} uses route '{$store['route']}' for {$host}; expected mall.");
                }
            }
        }

        if ($legacyRows) {
            $preview = implode(', ', array_slice($legacyRows, 0, 8));
            $this->warn('Legacy domains remain in fb_store.host_name: ' . $preview . '. Runtime routing filters them, but test-server data cleanup is recommended.');
        }

        if ($platformWarnings) {
            $preview = implode(', ', array_slice($platformWarnings, 0, 8));
            $this->warn('Platform domains remain on non-platform store rows: ' . $preview . '. Runtime routing forces them to the platform mall store, but data cleanup is recommended.');
        } else {
            $this->ok('Platform domains are reserved for the platform mall store in store data.');
        }
    }

    private function checkUsers()
    {
        $this->section('Users');
        $this->requireUserByUsername($this->platformUsername, 'platform backend user');
        $seller = $this->requireUserByUsername($this->sellerUsername, 'seller backend user');
        $paymentUser = $this->one('{{%user}}', ['id' => (int)$this->paymentUserId]);
        if (!$paymentUser) {
            $this->fail("Payment test user {$this->paymentUserId} is missing.");
        } elseif ((int)$paymentUser['status'] !== BaseModel::STATUS_ACTIVE) {
            $this->fail("Payment test user {$this->paymentUserId} is not active.");
        } else {
            $this->ok("Payment test user {$this->paymentUserId} exists: {$paymentUser['username']}.");
        }

        if ($seller && (int)$seller['store_id'] <= 0) {
            $this->warn("Seller user {$this->sellerUsername} has no store_id on user row; backend smoke may still rely on permissions.");
        }
    }

    private function checkProducts()
    {
        $this->section('Products');
        $ids = $this->ids($this->productIds);
        if (!$ids) {
            $this->fail('No productIds configured.');
            return;
        }

        $products = (new \yii\db\Query())
            ->from('{{%mall_product}}')
            ->where(['id' => $ids])
            ->indexBy('id')
            ->all(Yii::$app->db);

        $stores = [];
        foreach ($ids as $id) {
            if (!isset($products[$id])) {
                $this->fail("Product {$id} is missing.");
                continue;
            }

            $product = $products[$id];
            $stores[(int)$product['store_id']] = true;
            if ((int)$product['status'] !== BaseModel::STATUS_ACTIVE) {
                $this->fail("Product {$id} is not active.");
            }
            if ((int)$product['stock'] < (int)$this->minStock) {
                $this->fail("Product {$id} stock {$product['stock']} is below minStock {$this->minStock}.");
            }
            if ((int)$product['store_id'] <= 0) {
                $this->fail("Product {$id} has no seller store_id.");
            }
            if (trim((string)$product['name']) === '') {
                $this->fail("Product {$id} name is empty.");
            }

            if ((int)$product['status'] === BaseModel::STATUS_ACTIVE && (int)$product['stock'] >= (int)$this->minStock) {
                $this->ok("Product {$id} reachable: store={$product['store_id']}, stock={$product['stock']}, price={$product['price']}.");
            }
        }

        if ($this->requireDifferentStores && count($ids) > 1 && count($stores) < 2) {
            $this->fail('Configured products do not cover at least two seller stores; multi-seller payment regression is weaker.');
        }

        $this->checkPaymentProductPool();
    }

    private function checkPaymentProductPool()
    {
        $products = (new \yii\db\Query())
            ->select(['id', 'store_id', 'stock', 'price'])
            ->from('{{%mall_product}}')
            ->where(['status' => BaseModel::STATUS_ACTIVE])
            ->andWhere(['>', 'stock', (int)$this->minStock])
            ->orderBy(['stock' => SORT_DESC, 'id' => SORT_ASC])
            ->limit(50)
            ->all(Yii::$app->db);

        if (count($products) < 2) {
            $this->fail('Need at least two active high-stock products for payment regression.');
            return;
        }

        $storeIds = [];
        foreach ($products as $product) {
            $storeIds[(int)$product['store_id']] = true;
        }

        if ($this->requireDifferentStores && count($storeIds) < 2) {
            $this->fail('Need active high-stock products from at least two seller stores for multi-seller payment regression.');
            return;
        }

        $this->ok('Payment regression product pool has ' . count($products) . ' active high-stock products across ' . count($storeIds) . ' store(s).');
    }

    private function checkImContext()
    {
        $this->section('IM context');
        $merchant = $this->one('{{%user}}', ['id' => (int)$this->imMerchantUid]);
        if (!$merchant) {
            $this->fail("IM merchant user {$this->imMerchantUid} is missing.");
        } elseif ((int)$merchant['status'] !== BaseModel::STATUS_ACTIVE) {
            $this->fail("IM merchant user {$this->imMerchantUid} is not active.");
        } else {
            $this->ok("IM merchant user {$this->imMerchantUid} exists: {$merchant['username']}.");
        }

        $product = $this->one('{{%mall_product}}', ['id' => (int)$this->imProductId]);
        if (!$product) {
            $this->fail("IM product {$this->imProductId} is missing.");
            return;
        }

        if ((int)$product['store_id'] !== (int)$this->imStoreId) {
            $this->fail("IM product {$this->imProductId} store_id {$product['store_id']} does not match imStoreId {$this->imStoreId}.");
        } else {
            $this->ok("IM product {$this->imProductId} belongs to store {$this->imStoreId}.");
        }
    }

    private function checkLanguages()
    {
        $this->section('Languages');
        $rows = (new \yii\db\Query())
            ->select(['target', 'cnt' => 'COUNT(*)'])
            ->from('{{%base_lang}}')
            ->where(['target' => ['en', 'mn']])
            ->andWhere(['status' => BaseModel::STATUS_ACTIVE])
            ->groupBy('target')
            ->indexBy('target')
            ->all(Yii::$app->db);

        foreach (['en', 'mn'] as $target) {
            $count = (int)($rows[$target]['cnt'] ?? 0);
            if ($count <= 0) {
                $this->warn("No active {$target} rows found in fb_base_lang; pages may fall back to source language.");
            } else {
                $this->ok("fb_base_lang has {$count} active {$target} rows.");
            }
        }
    }

    private function requireUserByUsername(string $username, string $label)
    {
        $user = $this->one('{{%user}}', ['username' => $username]);
        if (!$user) {
            $this->fail("{$label} '{$username}' is missing.");
            return null;
        }

        if ((int)$user['status'] !== BaseModel::STATUS_ACTIVE) {
            $this->fail("{$label} '{$username}' is not active.");
        } else {
            $this->ok("{$label} '{$username}' exists with id {$user['id']}.");
        }

        return $user;
    }

    private function one(string $table, array $where)
    {
        return (new \yii\db\Query())->from($table)->where($where)->one(Yii::$app->db);
    }

    private function ids(string $value)
    {
        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }

    private function platformHosts()
    {
        $hosts = [];
        foreach ([Yii::$app->params['storePlatformDomain'] ?? '', env('WEB_BASE_URL', '')] as $value) {
            $host = $this->normalizeHost($value);
            if ($host === '') {
                continue;
            }
            $hosts[] = $host;
            if (str_starts_with($host, 'www.')) {
                $hosts[] = substr($host, 4);
            }
        }

        return array_values(array_unique($hosts));
    }

    private function legacyHostDomains()
    {
        $hosts = [];
        $value = env('LEGACY_HOST_DOMAINS', 'mn.zlck888.com,www.funpay.com,www.funcms.com,www.funbbs.com,test.zlck888.com,test.sc.hanxuys.com');
        foreach (array_filter(array_map('trim', explode(',', $value))) as $domain) {
            $host = $this->normalizeHost($domain);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function hostNamesFromValue($value)
    {
        $hosts = [];
        foreach (array_filter(array_map('trim', explode('|', (string)$value))) as $item) {
            $host = $this->normalizeHost($item);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function normalizeHost($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!$host && !str_contains($value, '://')) {
            $host = parse_url('https://' . $value, PHP_URL_HOST);
        }

        return strtolower((string)$host);
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
