<?php

namespace console\controllers;

use backend\modules\mall\controllers\ProductController;
use common\models\BaseModel;
use common\models\mall\Product;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaCatalogReadinessController extends Controller
{
    public $limit = 100;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'limit',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia catalog readiness check\n");

        $this->checkProducts();
        $this->checkCategoryTree();
        $this->checkStoreCoverage();
        $this->checkZeroPriceProtections();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkProducts()
    {
        $this->section('Active products');
        $products = (new \yii\db\Query())
            ->select([
                'p.id',
                'p.store_id',
                'p.category_id',
                'p.name',
                'p.sku',
                'p.stock',
                'p.price',
                'p.thumb',
                'p.image',
                's.status AS store_status',
                'c.status AS category_status',
            ])
            ->from('{{%mall_product}} p')
            ->leftJoin('{{%store}} s', 's.id = p.store_id')
            ->leftJoin('{{%mall_category}} c', 'c.id = p.category_id')
            ->where(['p.status' => BaseModel::STATUS_ACTIVE])
            ->orderBy(['p.id' => SORT_ASC])
            ->limit((int)$this->limit)
            ->all(Yii::$app->db);

        foreach ($products as $product) {
            $id = (int)$product['id'];
            if ((int)$product['store_id'] <= 0 || (int)$product['store_status'] !== BaseModel::STATUS_ACTIVE) {
                $this->fail("Product {$id} has missing or inactive store {$product['store_id']}.");
            }
            if ((int)$product['category_id'] <= 0 || (int)$product['category_status'] !== BaseModel::STATUS_ACTIVE) {
                $this->fail("Product {$id} has missing or inactive category {$product['category_id']}.");
            }
            if (trim((string)$product['name']) === '') {
                $this->fail("Product {$id} has empty name.");
            }
            if (trim((string)$product['sku']) === '') {
                $this->fail("Product {$id} has empty sku.");
            }
            if ((int)$product['stock'] < 0) {
                $this->fail("Product {$id} has negative stock {$product['stock']}.");
            }
            if ((float)$product['price'] <= 0) {
                $this->warn("Product {$id} has non-positive price {$product['price']}.");
            }
            if (trim((string)$product['thumb']) === '' && trim((string)$product['image']) === '') {
                $this->warn("Product {$id} has no thumb or image.");
            }
        }

        $this->ok('Checked ' . count($products) . ' active product(s).');
    }

    private function checkCategoryTree()
    {
        $this->section('Active categories');
        $orphans = (new \yii\db\Query())
            ->select(['c.id', 'c.parent_id'])
            ->from('{{%mall_category}} c')
            ->leftJoin('{{%mall_category}} parent', 'parent.id = c.parent_id')
            ->where(['c.status' => BaseModel::STATUS_ACTIVE])
            ->andWhere(['>', 'c.parent_id', 0])
            ->andWhere(['or', ['parent.id' => null], ['<=', 'parent.status', BaseModel::STATUS_DELETED]])
            ->orderBy(['c.id' => SORT_ASC])
            ->limit(10)
            ->all(Yii::$app->db);

        if ($orphans) {
            foreach ($orphans as $row) {
                $this->warn("Category {$row['id']} has missing/inactive parent {$row['parent_id']}.");
            }
            return;
        }

        $this->ok('Active category parents are valid.');
    }

    private function checkStoreCoverage()
    {
        $this->section('Seller coverage');
        $rows = (new \yii\db\Query())
            ->select(['store_id', 'cnt' => 'COUNT(*)'])
            ->from('{{%mall_product}}')
            ->where(['status' => BaseModel::STATUS_ACTIVE])
            ->groupBy('store_id')
            ->orderBy(['cnt' => SORT_DESC])
            ->all(Yii::$app->db);

        $stores = count($rows);
        $products = array_sum(array_map('intval', array_column($rows, 'cnt')));
        if ($stores < 2) {
            $this->fail("Catalog has active products from only {$stores} store(s); multi-seller marketplace coverage is weak.");
            return;
        }

        $this->ok("Catalog has {$products} active product(s) across {$stores} store(s).");
    }

    private function checkZeroPriceProtections()
    {
        $this->section('Zero-price protections');

        if (!class_exists(ProductController::class)) {
            $this->fail('Backend product controller is unavailable; zero-price backend protection cannot be verified.');
            return;
        }

        $reflection = new \ReflectionClass(ProductController::class);
        foreach (['validateActiveProductPrice', 'beforeEditAjaxStatusSave', 'beforeEditStatusSave'] as $methodName) {
            if (!$reflection->hasMethod($methodName)) {
                $this->fail("Backend product controller is missing {$methodName}().");
                return;
            }
        }

        $method = $reflection->getMethod('validateActiveProductPrice');
        if (!$method->isProtected()) {
            $this->fail('Backend active product price validator must stay protected.');
            return;
        }
        $method->setAccessible(true);

        $controller = new ProductController('product', Yii::$app);
        $activeZeroPrice = $this->newProductForPriceCheck(0, Product::STATUS_ACTIVE);
        $inactiveZeroPrice = $this->newProductForPriceCheck(0, Product::STATUS_INACTIVE);
        $activePriced = $this->newProductForPriceCheck(1, Product::STATUS_ACTIVE);

        if ($method->invoke($controller, $activeZeroPrice) !== false) {
            $this->fail('Backend validator allows an active zero-price product.');
            return;
        }
        if ($method->invoke($controller, $inactiveZeroPrice) !== true) {
            $this->fail('Backend validator blocks inactive zero-price product review.');
            return;
        }
        if ($method->invoke($controller, $activePriced) !== true) {
            $this->fail('Backend validator blocks active priced products.');
            return;
        }

        $this->ok('Backend product save/status validators block zero-price active products without changing review access.');
    }

    private function newProductForPriceCheck($price, $status)
    {
        $product = new Product();
        $product->price = $price;
        $product->status = $status;

        return $product;
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
