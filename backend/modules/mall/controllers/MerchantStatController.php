<?php

namespace backend\modules\mall\controllers;

use common\models\BaseModel;
use common\models\mall\Order;
use common\models\Store;
use Yii;
use yii\db\Expression;
use yii\db\Query;
use yii\web\NotFoundHttpException;

class MerchantStatController extends BaseController
{
    public function actionIndex()
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->resolveStoreId($isPlatformOperator);
        $store = Store::findOne($storeId);
        if (!$store) {
            throw new NotFoundHttpException(Yii::t('app', 'Invalid id'), 500);
        }

        $periodStats = [];
        foreach ($this->periods() as $key => $period) {
            $periodStats[$key] = $this->periodStat($storeId, $period['from'], $period['to']) + [
                'label' => $period['label'],
            ];
        }

        return $this->render('index', [
            'store' => $store,
            'storeId' => $storeId,
            'stores' => $isPlatformOperator ? $this->getStoresIdName() : [],
            'isPlatformOperator' => $isPlatformOperator,
            'periodStats' => $periodStats,
            'topProducts' => $this->topProducts($storeId),
            'shipmentStats' => $this->shipmentStats($storeId),
            'overallProducts' => $this->overallProducts($storeId),
        ]);
    }

    private function resolveStoreId(bool $isPlatformOperator): int
    {
        if ($isPlatformOperator) {
            $requested = (int)Yii::$app->request->get('store_id', 0);
            if ($requested > 0) {
                return $requested;
            }
        }

        return (int)$this->getStoreId();
    }

    private function periodStat(int $storeId, int $from, int $to): array
    {
        $orderQuery = (new Query())
            ->select([
                'orders' => 'COUNT(DISTINCT op.order_id)',
                'items' => 'COALESCE(SUM(op.number),0)',
                'amount' => 'COALESCE(SUM(op.number * op.price),0)',
            ])
            ->from('{{%mall_order_product}} op')
            ->innerJoin('{{%mall_order}} o', 'o.id = op.order_id')
            ->where(['op.store_id' => $storeId])
            ->andWhere(['o.payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['<>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['<>', 'o.status', BaseModel::STATUS_DELETED]);

        $visitQuery = (new Query())
            ->from('{{%mall_product_visit}} v')
            ->innerJoin('{{%mall_product}} p', 'p.id = v.pid')
            ->where(['p.store_id' => $storeId])
            ->andWhere(['<>', 'p.status', BaseModel::STATUS_DELETED]);

        if ($from > 0) {
            $orderQuery->andWhere(['>=', 'o.created_at', $from]);
            $visitQuery->andWhere(['>=', 'v.time', $from]);
        }
        if ($to > 0) {
            $orderQuery->andWhere(['<', 'o.created_at', $to]);
            $visitQuery->andWhere(['<', 'v.time', $to]);
        }

        $orders = $orderQuery->one(Yii::$app->db) ?: [];
        $orderCount = (int)($orders['orders'] ?? 0);
        $itemCount = (int)($orders['items'] ?? 0);
        $amount = (float)($orders['amount'] ?? 0);
        $visits = (int)$visitQuery->count('*', Yii::$app->db);

        return [
            'orders' => $orderCount,
            'items' => $itemCount,
            'amount' => $amount,
            'visits' => $visits,
            'avg_order_amount' => $orderCount > 0 ? $amount / $orderCount : 0,
            'avg_item_amount' => $itemCount > 0 ? $amount / $itemCount : 0,
            'visit_order_rate' => $visits > 0 ? $orderCount / $visits : 0,
        ];
    }

    private function overallProducts(int $storeId): array
    {
        $row = (new Query())
            ->select([
                'products' => 'COUNT(*)',
                'sales' => 'COALESCE(SUM(sales),0)',
                'clicks' => 'COALESCE(SUM(click),0)',
                'stock' => 'COALESCE(SUM(stock),0)',
                'in_stock_products' => 'COALESCE(SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END),0)',
                'out_of_stock_products' => 'COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END),0)',
            ])
            ->from('{{%mall_product}}')
            ->where(['store_id' => $storeId])
            ->andWhere(['<>', 'status', BaseModel::STATUS_DELETED])
            ->one(Yii::$app->db) ?: [];

        return [
            'products' => (int)($row['products'] ?? 0),
            'sales' => (int)($row['sales'] ?? 0),
            'clicks' => (int)($row['clicks'] ?? 0),
            'stock' => (int)($row['stock'] ?? 0),
            'in_stock_products' => (int)($row['in_stock_products'] ?? 0),
            'out_of_stock_products' => (int)($row['out_of_stock_products'] ?? 0),
        ];
    }

    private function topProducts(int $storeId): array
    {
        return (new Query())
            ->select([
                'id' => 'p.id',
                'name' => 'p.name',
                'stock' => 'p.stock',
                'sales' => 'p.sales',
                'click' => 'p.click',
                'ordered_items' => new Expression('COALESCE(SUM(CASE WHEN o.id IS NULL THEN 0 ELSE op.number END),0)'),
                'ordered_amount' => new Expression('COALESCE(SUM(CASE WHEN o.id IS NULL THEN 0 ELSE op.number * op.price END),0)'),
            ])
            ->from('{{%mall_product}} p')
            ->leftJoin('{{%mall_order_product}} op', 'op.product_id = p.id AND op.store_id = p.store_id AND op.status <> ' . (int)BaseModel::STATUS_DELETED)
            ->leftJoin('{{%mall_order}} o', 'o.id = op.order_id AND o.payment_status IN (' . Order::PAYMENT_STATUS_PAID . ',' . Order::PAYMENT_STATUS_COD . ') AND o.status <> ' . (int)BaseModel::STATUS_DELETED)
            ->where(['p.store_id' => $storeId])
            ->andWhere(['<>', 'p.status', BaseModel::STATUS_DELETED])
            ->groupBy(['p.id', 'p.name', 'p.stock', 'p.sales', 'p.click'])
            ->orderBy(['ordered_items' => SORT_DESC, 'p.sales' => SORT_DESC, 'p.click' => SORT_DESC, 'p.id' => SORT_ASC])
            ->limit(10)
            ->all(Yii::$app->db);
    }

    private function shipmentStats(int $storeId): array
    {
        $rows = (new Query())
            ->select([
                'shipment_status',
                'orders' => 'COUNT(*)',
            ])
            ->from('{{%mall_order}}')
            ->where(['store_id' => $storeId])
            ->andWhere(['payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['<>', 'status', BaseModel::STATUS_DELETED])
            ->groupBy('shipment_status')
            ->orderBy(['shipment_status' => SORT_ASC])
            ->all(Yii::$app->db);

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int)$row['shipment_status']] = (int)$row['orders'];
        }

        return $stats;
    }

    private function periods(): array
    {
        $now = time();
        $today = strtotime(date('Y-m-d 00:00:00', $now));
        $month = strtotime(date('Y-m-01 00:00:00', $now));
        $year = strtotime(date('Y-01-01 00:00:00', $now));

        return [
            'today' => ['label' => '今日', 'from' => $today, 'to' => $today + 86400],
            'month' => ['label' => '本月', 'from' => $month, 'to' => strtotime('+1 month', $month)],
            'year' => ['label' => '本年', 'from' => $year, 'to' => strtotime('+1 year', $year)],
            'all' => ['label' => '累计', 'from' => 0, 'to' => 0],
        ];
    }
}
