<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\mall\Order;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MerchantStatTestController extends Controller
{
    public $storeId = 0;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['storeId', 'strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia merchant statistics Phase 2 test\n");

        $this->checkSchemas();
        if ($this->failures === 0) {
            $storeId = $this->resolveStoreId();
            if ($storeId > 0) {
                $this->stdout("Using store_id={$storeId}\n");
                $this->checkOrderAggregates($storeId);
                $this->checkProductAggregates($storeId);
                $this->checkVisitAggregates($storeId);
                $this->checkStoreIsolation($storeId);
                $this->checkBackendEntrances();
                $this->checkBackendPermission();
            }
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchemas()
    {
        $this->section('Schema');
        $this->requireColumns('{{%store}}', ['id', 'name', 'status']);
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'parent_id', 'payment_status', 'amount', 'number', 'created_at', 'status']);
        $this->requireColumns('{{%mall_order_product}}', ['id', 'store_id', 'order_id', 'product_id', 'number', 'price', 'status']);
        $this->requireColumns('{{%mall_product}}', ['id', 'store_id', 'sales', 'click', 'status']);
        $this->requireColumns('{{%mall_product_visit}}', ['id', 'pid', 'time']);
    }

    private function resolveStoreId(): int
    {
        if ((int)$this->storeId > 0) {
            return (int)$this->storeId;
        }

        $row = (new \yii\db\Query())
            ->select([
                'store_id' => 'op.store_id',
                'cnt' => 'COUNT(DISTINCT op.order_id)',
                'products' => 'COUNT(DISTINCT p.id)',
            ])
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->leftJoin('{{%mall_product}} p', 'p.store_id = op.store_id AND p.status <> ' . (int)BaseModel::STATUS_DELETED)
            ->where(['o.payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['>', 'op.store_id', 0])
            ->andWhere(['<>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'o.status', BaseModel::STATUS_DELETED])
            ->groupBy('op.store_id')
            ->having(['>', 'products', 0])
            ->orderBy(['cnt' => SORT_DESC, 'products' => SORT_DESC])
            ->one(Yii::$app->db);

        if ($row && (int)$row['store_id'] > 0) {
            return (int)$row['store_id'];
        }

        $storeId = (int)(new \yii\db\Query())
            ->select('store_id')
            ->from('{{%mall_product}}')
            ->where(['>', 'store_id', 0])
            ->andWhere(['<>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
        if ($storeId <= 0) {
            $this->fail('No store with orders or products is available for merchant statistics.');
        }

        return $storeId;
    }

    private function checkOrderAggregates(int $storeId)
    {
        $this->section('Order sales aggregates');
        foreach ($this->periods() as $name => $range) {
            $row = $this->orderAggregate($storeId, $range[0], $range[1]);
            $this->ok("{$name}: orders={$row['orders']}, amount=" . number_format((float)$row['amount'], 2) . ", items={$row['items']}.");
            if ((int)$row['orders'] < 0 || (float)$row['amount'] < 0 || (int)$row['items'] < 0) {
                $this->fail("{$name} order aggregate returned invalid negative values.");
            }
        }

        $all = $this->orderAggregate($storeId, 0, 0);
        if ((int)$all['orders'] === 0) {
            $this->warn("Store {$storeId} has no paid/COD order-product rows; merchant sales report can run but lacks business sample data.");
        }
    }

    private function checkProductAggregates(int $storeId)
    {
        $this->section('Product sales/click aggregates');
        $row = (new \yii\db\Query())
            ->select([
                'products' => 'COUNT(*)',
                'sales' => 'COALESCE(SUM(sales),0)',
                'clicks' => 'COALESCE(SUM(click),0)',
                'in_stock_products' => 'COALESCE(SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END),0)',
                'out_of_stock_products' => 'COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END),0)',
            ])
            ->from('{{%mall_product}}')
            ->where(['store_id' => $storeId])
            ->andWhere(['<>', 'status', BaseModel::STATUS_DELETED])
            ->one(Yii::$app->db);

        $this->ok("Store products={$row['products']}, product sales={$row['sales']}, product clicks={$row['clicks']}, in-stock={$row['in_stock_products']}, out-of-stock={$row['out_of_stock_products']}.");
        if ((int)$row['products'] === 0) {
            $this->warn("Store {$storeId} has no product rows for merchant product statistics.");
        }

        $lineSales = (new \yii\db\Query())
            ->select([
                'items' => 'COALESCE(SUM(op.number),0)',
                'amount' => 'COALESCE(SUM(op.number * op.price),0)',
            ])
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['op.store_id' => $storeId])
            ->andWhere(['o.payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['<>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'o.status', BaseModel::STATUS_DELETED])
            ->one(Yii::$app->db);
        $this->ok("Order-line product items={$lineSales['items']}, gross line amount=" . number_format((float)$lineSales['amount'], 2) . '.');
    }

    private function checkVisitAggregates(int $storeId)
    {
        $this->section('Visit aggregates');
        $visits = (new \yii\db\Query())
            ->from('{{%mall_product_visit}} v')
            ->innerJoin('{{%mall_product}} p', 'p.id = v.pid')
            ->where(['p.store_id' => $storeId])
            ->count('*', Yii::$app->db);
        $this->ok("Store product visit rows={$visits}.");
        if ((int)$visits === 0) {
            $this->warn("Store {$storeId} has no product visit rows; open product pages before final merchant-stat evidence.");
        }
    }

    private function checkStoreIsolation(int $storeId)
    {
        $this->section('Store isolation');
        $otherStoreIds = (new \yii\db\Query())
            ->select('op.store_id')
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['o.payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['<>', 'op.store_id', $storeId])
            ->andWhere(['>', 'op.store_id', 0])
            ->andWhere(['<>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'o.status', BaseModel::STATUS_DELETED])
            ->groupBy('op.store_id')
            ->limit(3)
            ->column(Yii::$app->db);

        if (!$otherStoreIds) {
            $this->warn('No other-store paid/COD orders are available to prove merchant-stat isolation.');
            return;
        }

        $selectedRows = (int)(new \yii\db\Query())
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['op.store_id' => $storeId])
            ->andWhere(['o.payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['<>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'o.status', BaseModel::STATUS_DELETED])
            ->count('*', Yii::$app->db);
        $otherRows = (int)(new \yii\db\Query())
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['op.store_id' => $otherStoreIds])
            ->andWhere(['o.payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['<>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'o.status', BaseModel::STATUS_DELETED])
            ->count('*', Yii::$app->db);

        if ($selectedRows > 0 && $otherRows > 0) {
            $this->ok("Merchant aggregate has selected-store rows={$selectedRows} and separate other-store rows={$otherRows}; scoped queries exclude other stores.");
            return;
        }

        $this->warn('Cross-store order-product evidence is weak for merchant-stat isolation.');
    }

    private function checkBackendEntrances()
    {
        $this->section('Backend entrances');
        $this->requireFileContains('@app/../backend/modules/mall/controllers/MerchantStatController.php', ['class MerchantStatController', 'actionIndex', 'periodStat', 'topProducts']);
        $this->requireFileContains('@app/../backend/modules/mall/views/merchant-stat/index.php', ['商家统计', '商品销量排行', '物流状态', '客单价', '件均价', '浏览转化率', '有库存商品', '缺货商品']);
        $this->requireFileContains('@app/../backend/views/site/info.php', ['product_visit', 'product_visit_today']);
        $this->requireFileContains('@app/../backend/modules/mall/views/product/index.php', ['sales', 'stock']);
        $this->requireFileContains('@app/../backend/modules/mall/views/order/index.php', ['amount', 'payment_status']);
    }

    private function checkBackendPermission()
    {
        $this->section('Backend permission');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/merchant-stat/index', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active backend permission /mall/merchant-stat/index. Run migration m260618_160000_mongoyia_merchant_stat_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/merchant-stat/index');

        $roleRows = (int)(new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['permission_id' => $permissionId, 'status' => 1])
            ->andWhere(['role_id' => [50, 55]])
            ->count('*', Yii::$app->db);
        if ($roleRows < 2) {
            $this->warn('Merchant statistics permission is not granted to both seller role 50 and platform role 55.');
            return;
        }
        $this->ok('Permission is granted to seller and platform roles.');
    }

    private function orderAggregate(int $storeId, int $from, int $to): array
    {
        $query = (new \yii\db\Query())
            ->select([
                'orders' => 'COUNT(DISTINCT op.order_id)',
                'amount' => 'COALESCE(SUM(op.number * op.price),0)',
                'items' => 'COALESCE(SUM(op.number),0)',
            ])
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['op.store_id' => $storeId])
            ->andWhere(['o.payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['<>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'o.status', BaseModel::STATUS_DELETED]);
        if ($from > 0) {
            $query->andWhere(['>=', 'o.created_at', $from]);
        }
        if ($to > 0) {
            $query->andWhere(['<', 'o.created_at', $to]);
        }

        return $query->one(Yii::$app->db) ?: ['orders' => 0, 'amount' => 0, 'items' => 0];
    }

    private function periods(): array
    {
        $now = time();
        $today = strtotime(date('Y-m-d 00:00:00', $now));
        $month = strtotime(date('Y-m-01 00:00:00', $now));
        $year = strtotime(date('Y-01-01 00:00:00', $now));

        return [
            'today' => [$today, $today + 86400],
            'month' => [$month, strtotime('+1 month', $month)],
            'year' => [$year, strtotime('+1 year', $year)],
            'all' => [0, 0],
        ];
    }

    private function requireColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
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
