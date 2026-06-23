<?php

namespace common\services\mall;

use common\models\BaseModel;
use common\models\base\FundLog;
use common\models\mall\Coupon;
use common\models\mall\CouponType;
use common\models\mall\LogisticsMethod;
use common\models\mall\Order;
use common\models\mall\OrderProduct;
use common\models\mall\Product;
use common\models\mall\StoreCouponParticipation;
use common\models\mall\StoreLogisticsMethod;
use common\models\Store;
use Yii;
use yii\db\Query;

class AppSellerApiService
{
    public const VERSION = 'MONGOYIA_APP_SELLER_API_V1';
    public const SHIPMENT_WRITE_VERSION = 'MONGOYIA_APP_SELLER_SHIPMENT_WRITE_V1';
    public const PRODUCT_WRITE_GATE = 'seller_product_write_requires_audit_browser_acceptance';

    public function dashboard(int $storeId): array
    {
        $store = $this->store($storeId);
        $today = $this->periodStat($storeId, strtotime(date('Y-m-d 00:00:00')), strtotime(date('Y-m-d 00:00:00')) + 86400);
        $all = $this->periodStat($storeId, 0, 0);

        return [
            'version' => self::VERSION,
            'store' => $this->storeSummary($store),
            'summary' => [
                'orders' => (int)$all['orders'],
                'products' => (int)$this->productQuery($storeId)->count(),
                'amount' => number_format((float)$all['amount'], 2, '.', ''),
                'today_orders' => (int)$today['orders'],
                'today_amount' => number_format((float)$today['amount'], 2, '.', ''),
                'pending_shipment' => (int)$this->orderQuery($storeId)
                    ->andWhere(['shipment_status' => Order::SHIPMENT_STATUS_UNSHIPPED])
                    ->count(),
                'fund' => number_format((float)$store->fund, 2, '.', ''),
            ],
            'gates' => [
                'shipment_write_version' => self::SHIPMENT_WRITE_VERSION,
                'product_write' => self::PRODUCT_WRITE_GATE,
            ],
        ];
    }

    public function products(int $storeId, array $params): array
    {
        $query = $this->productQuery($storeId);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->andWhere(['or',
                ['like', 'name', $keyword],
                ['like', 'sku', $keyword],
                ['like', 'brief', $keyword],
            ]);
        }
        $status = $params['status'] ?? null;
        if ($status !== null && $status !== '') {
            $query->andWhere(['status' => (int)$status]);
        }

        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(50, (int)($params['page_size'] ?? 20)));
        $total = (int)$query->count();
        $items = array_map([$this, 'productSummary'], $query
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all());

        return [
            'version' => self::VERSION,
            'items' => $items,
            'summary' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'product_write_gate' => self::PRODUCT_WRITE_GATE,
            ],
        ];
    }

    public function orders(int $storeId, array $params): array
    {
        $query = $this->orderQuery($storeId);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->andWhere(['or',
                ['like', 'sn', $keyword],
                ['like', 'name', $keyword],
                ['like', 'mobile', $keyword],
                ['like', 'email', $keyword],
            ]);
        }
        $shipmentStatus = (int)($params['shipment_status'] ?? 0);
        if ($shipmentStatus > 0) {
            $query->andWhere(['shipment_status' => $shipmentStatus]);
        }

        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(50, (int)($params['page_size'] ?? 20)));
        $total = (int)$query->count();
        $orders = $query
            ->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'version' => self::VERSION,
            'items' => array_map([$this, 'orderSummary'], $orders),
            'summary' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'shipment_write_version' => self::SHIPMENT_WRITE_VERSION,
            ],
        ];
    }

    public function shipOrder(int $storeId, array $input): array
    {
        $orderId = (int)($input['order_id'] ?? $input['id'] ?? 0);
        $trackingNo = mb_substr(trim((string)($input['tracking_no'] ?? $input['wldh'] ?? '')), 0, 255, 'UTF-8');
        $logisticsCompany = mb_substr(trim((string)($input['logistics_company'] ?? $input['shipment_name'] ?? $input['wlgs'] ?? '')), 0, 255, 'UTF-8');
        $shipmentId = (int)($input['shipment_id'] ?? 0);
        $shipmentFee = $this->optionalMoney($input['shipment_fee'] ?? null);

        if ($orderId <= 0) {
            throw new \RuntimeException('ORDER_ID_REQUIRED');
        }
        if ($trackingNo === '') {
            throw new \RuntimeException('TRACKING_NO_REQUIRED');
        }
        if ($logisticsCompany === '') {
            $logisticsCompany = 'APP Shipment';
        }

        $order = Order::find()
            ->where(['id' => $orderId, 'store_id' => $storeId])
            ->andWhere(['>', 'parent_id', 0])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->one();
        if (!$order) {
            throw new \RuntimeException('ORDER_NOT_FOUND_OR_OUT_OF_SCOPE');
        }

        if (method_exists($order, 'hasAttribute') && $order->hasAttribute('wldh')) {
            $order->wldh = $trackingNo;
        }
        if (method_exists($order, 'hasAttribute') && $order->hasAttribute('wlgs')) {
            $order->wlgs = $logisticsCompany;
        }
        $order->shipment_name = $logisticsCompany;
        if ($shipmentId > 0) {
            $order->shipment_id = $shipmentId;
        }
        if ($shipmentFee !== null) {
            $order->shipment_fee = $shipmentFee;
        }

        $order->markShipped(
            $shipmentId > 0 ? $shipmentId : null,
            $logisticsCompany,
            null,
            $shipmentFee
        );

        $order = Order::findOne((int)$order->id) ?: $order;
        return [
            'version' => self::VERSION,
            'shipment_write_version' => self::SHIPMENT_WRITE_VERSION,
            'order' => $this->orderSummary($order),
            'tracking_no' => $trackingNo,
        ];
    }

    public function logistics(int $storeId): array
    {
        $rows = (new Query())
            ->select([
                'id' => 'lm.id',
                'name' => 'lm.name',
                'code' => 'lm.code',
                'provider' => 'lm.provider',
                'base_fee' => 'lm.base_fee',
                'fee_per_kg' => 'lm.fee_per_kg',
                'fee_per_volume' => 'lm.fee_per_volume',
                'tracking_url' => 'lm.tracking_url',
                'selection_status' => 'slm.selection_status',
                'selected_at' => 'slm.selected_at',
            ])
            ->from(['lm' => LogisticsMethod::tableName()])
            ->leftJoin(['slm' => StoreLogisticsMethod::tableName()], 'slm.logistics_method_id = lm.id AND slm.store_id = :storeId', [':storeId' => $storeId])
            ->where(['>', 'lm.status', BaseModel::STATUS_DELETED])
            ->orderBy(['lm.sort' => SORT_ASC, 'lm.id' => SORT_DESC])
            ->limit(100)
            ->all(Yii::$app->db);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['base_fee'] = number_format((float)$row['base_fee'], 2, '.', '');
            $row['fee_per_kg'] = number_format((float)$row['fee_per_kg'], 2, '.', '');
            $row['fee_per_volume'] = number_format((float)$row['fee_per_volume'], 2, '.', '');
            $row['selection_status'] = $row['selection_status'] ?: StoreLogisticsMethod::SELECTION_DISABLED;
            $row['selected_at'] = (int)($row['selected_at'] ?? 0);
        }
        unset($row);

        return [
            'version' => self::VERSION,
            'items' => $rows,
            'summary' => [
                'selected' => count(array_filter($rows, static function ($row) {
                    return ($row['selection_status'] ?? '') === StoreLogisticsMethod::SELECTION_ENABLED;
                })),
            ],
        ];
    }

    public function deposit(int $storeId): array
    {
        $store = $this->store($storeId);
        $logs = (new Query())
            ->select(['id', 'name', 'change', 'original', 'balance', 'remark', 'type', 'created_at'])
            ->from(FundLog::tableName())
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit(30)
            ->all(Yii::$app->db);

        foreach ($logs as &$log) {
            $log['id'] = (int)$log['id'];
            $log['change'] = number_format((float)$log['change'], 2, '.', '');
            $log['original'] = number_format((float)$log['original'], 2, '.', '');
            $log['balance'] = number_format((float)$log['balance'], 2, '.', '');
            $log['type'] = (int)$log['type'];
            $log['created_at'] = (int)$log['created_at'];
        }
        unset($log);

        return [
            'version' => self::VERSION,
            'summary' => [
                'fund' => number_format((float)$store->fund, 2, '.', ''),
                'fund_amount' => number_format((float)$store->fund_amount, 2, '.', ''),
                'consume_amount' => number_format((float)$store->consume_amount, 2, '.', ''),
                'consume_count' => (int)$store->consume_count,
            ],
            'items' => $logs,
        ];
    }

    public function coupons(int $storeId): array
    {
        $storeCoupons = CouponType::find()
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit(50)
            ->all();

        $usageRows = Coupon::find()
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['used_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(30)
            ->all();

        return [
            'version' => self::VERSION,
            'items' => array_map([$this, 'couponTypeSummary'], $storeCoupons),
            'usage' => array_map([$this, 'couponUsageSummary'], $usageRows),
            'summary' => [
                'store_coupon_count' => count($storeCoupons),
                'usage_count' => count($usageRows),
                'participation_write_gate' => 'seller_coupon_participation_requires_browser_acceptance',
            ],
            'platform_participation' => $this->platformCouponParticipation($storeId),
        ];
    }

    public function statistics(int $storeId): array
    {
        $periods = [];
        foreach ($this->periods() as $key => $period) {
            $periods[$key] = $this->periodStat($storeId, $period['from'], $period['to']) + ['label' => $period['label']];
        }

        return [
            'version' => self::VERSION,
            'periods' => $periods,
            'products' => $this->overallProducts($storeId),
            'shipment' => $this->shipmentStats($storeId),
            'top_products' => $this->topProducts($storeId),
        ];
    }

    public function distribution(int $storeId): array
    {
        if (!$this->tableExists('{{%mall_distribution_commission}}')) {
            return [
                'version' => self::VERSION,
                'items' => [],
                'summary' => [
                    'available' => false,
                    'message' => 'Distribution commission tables are not installed.',
                ],
            ];
        }

        $summary = (new Query())
            ->select([
                'commission_status',
                'rows' => 'COUNT(*)',
                'order_amount' => 'COALESCE(SUM(order_amount),0)',
                'commission_amount' => 'COALESCE(SUM(commission_amount),0)',
            ])
            ->from('{{%mall_distribution_commission}}')
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->groupBy('commission_status')
            ->orderBy(['commission_status' => SORT_ASC])
            ->all(Yii::$app->db);

        $items = (new Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit(20)
            ->all(Yii::$app->db);

        return [
            'version' => self::VERSION,
            'items' => $items,
            'summary' => [
                'available' => true,
                'rows' => $summary,
                'withdraw_write_gate' => 'seller_distribution_withdraw_requires_signoff_acceptance',
            ],
        ];
    }

    private function store(int $storeId): Store
    {
        $store = Store::find()
            ->where(['id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->one();
        if (!$store) {
            throw new \RuntimeException('SELLER_STORE_NOT_FOUND');
        }

        return $store;
    }

    private function productQuery(int $storeId)
    {
        return Product::find()
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED]);
    }

    private function orderQuery(int $storeId)
    {
        return Order::find()
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->andWhere(['>', 'parent_id', 0]);
    }

    private function storeSummary(Store $store): array
    {
        return [
            'id' => (int)$store->id,
            'name' => (string)$store->name,
            'brief' => (string)$store->brief,
            'host_name' => (string)$store->host_name,
            'fund' => number_format((float)$store->fund, 2, '.', ''),
            'status' => (int)$store->status,
        ];
    }

    private function productSummary(Product $product): array
    {
        return [
            'id' => (int)$product->id,
            'product_id' => (int)$product->id,
            'store_id' => (int)$product->store_id,
            'category_id' => (int)$product->category_id,
            'name' => (string)$product->name,
            'sku' => (string)$product->sku,
            'thumb' => (string)$product->thumb,
            'image' => (string)$product->image,
            'price' => number_format((float)$product->price, 2, '.', ''),
            'market_price' => number_format((float)$product->market_price, 2, '.', ''),
            'stock' => (int)$product->stock,
            'sales' => (int)$product->sales,
            'status' => (int)$product->status,
            'audit_status' => $this->attr($product, 'audit_status', ''),
            'product_write_gate' => self::PRODUCT_WRITE_GATE,
        ];
    }

    private function orderSummary(Order $order): array
    {
        $items = OrderProduct::find()
            ->where(['order_id' => (int)$order->id, 'store_id' => (int)$order->store_id])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->limit(20)
            ->all();

        return [
            'id' => (int)$order->id,
            'sn' => (string)$order->sn,
            'order_sn' => (string)$order->sn,
            'receiver' => trim((string)$order->first_name . ' ' . (string)$order->last_name) ?: (string)$order->name,
            'mobile' => (string)$order->mobile,
            'email' => (string)$order->email,
            'amount' => number_format((float)$order->amount, 2, '.', ''),
            'product_amount' => number_format((float)$order->product_amount, 2, '.', ''),
            'number' => (int)$order->number,
            'payment_status' => (int)$order->payment_status,
            'payment_status_label' => Order::getPaymentStatusLabels((int)$order->payment_status, true),
            'shipment_status' => (int)$order->shipment_status,
            'shipment_status_label' => Order::getShipmentStatusLabels((int)$order->shipment_status, true),
            'shipment_name' => (string)$order->shipment_name,
            'shipment_fee' => number_format((float)$order->shipment_fee, 2, '.', ''),
            'tracking_no' => $this->attr($order, 'wldh', ''),
            'logistics_company' => $this->attr($order, 'wlgs', (string)$order->shipment_name),
            'status' => (int)$order->status,
            'status_label' => Order::getStatusLabels((int)$order->status, true),
            'created_at' => (int)$order->created_at,
            'items' => array_map(function (OrderProduct $item): array {
                return [
                    'id' => (int)$item->id,
                    'product_id' => (int)$item->product_id,
                    'name' => (string)$item->name,
                    'sku' => (string)$item->sku,
                    'thumb' => (string)$item->thumb,
                    'number' => (int)$item->number,
                    'price' => number_format((float)$item->price, 2, '.', ''),
                ];
            }, $items),
        ];
    }

    private function couponTypeSummary(CouponType $coupon): array
    {
        return [
            'id' => (int)$coupon->id,
            'name' => (string)$coupon->name,
            'money' => number_format((float)$coupon->money, 2, '.', ''),
            'min_amount' => number_format((float)$coupon->min_amount, 2, '.', ''),
            'started_at' => (int)$coupon->started_at,
            'ended_at' => (int)$coupon->ended_at,
            'status' => (int)$coupon->status,
        ];
    }

    private function couponUsageSummary(Coupon $coupon): array
    {
        return [
            'id' => (int)$coupon->id,
            'coupon_type_id' => (int)$coupon->coupon_type_id,
            'user_id' => (int)$coupon->user_id,
            'name' => (string)$coupon->name,
            'money' => number_format((float)$coupon->money, 2, '.', ''),
            'order_id' => (int)$coupon->order_id,
            'used_at' => (int)$coupon->used_at,
            'status' => (int)$coupon->status,
        ];
    }

    private function platformCouponParticipation(int $storeId): array
    {
        if (!$this->tableExists(StoreCouponParticipation::tableName())) {
            return [];
        }

        $rows = (new Query())
            ->select([
                'id' => 'ct.id',
                'name' => 'ct.name',
                'money' => 'ct.money',
                'min_amount' => 'ct.min_amount',
                'participation_status' => 'scp.participation_status',
                'joined_at' => 'scp.joined_at',
                'left_at' => 'scp.left_at',
            ])
            ->from(['ct' => CouponType::tableName()])
            ->leftJoin(['scp' => StoreCouponParticipation::tableName()], 'scp.coupon_type_id = ct.id AND scp.store_id = :storeId', [':storeId' => $storeId])
            ->where(['ct.store_id' => $this->platformStoreIds()])
            ->andWhere(['>', 'ct.status', BaseModel::STATUS_DELETED])
            ->orderBy(['ct.id' => SORT_DESC])
            ->limit(50)
            ->all(Yii::$app->db);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['money'] = number_format((float)$row['money'], 2, '.', '');
            $row['min_amount'] = number_format((float)$row['min_amount'], 2, '.', '');
            $row['participation_status'] = $row['participation_status'] ?: StoreCouponParticipation::PARTICIPATION_LEFT;
            $row['joined_at'] = (int)($row['joined_at'] ?? 0);
            $row['left_at'] = (int)($row['left_at'] ?? 0);
        }
        unset($row);

        return $rows;
    }

    private function periodStat(int $storeId, int $from, int $to): array
    {
        $query = (new Query())
            ->select([
                'orders' => 'COUNT(DISTINCT op.order_id)',
                'items' => 'COALESCE(SUM(op.number),0)',
                'amount' => 'COALESCE(SUM(op.number * op.price),0)',
            ])
            ->from(['op' => OrderProduct::tableName()])
            ->innerJoin(['o' => Order::tableName()], 'o.id = op.order_id')
            ->where(['op.store_id' => $storeId])
            ->andWhere(['o.payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['>', 'op.status', BaseModel::STATUS_DELETED])
            ->andWhere(['>', 'o.status', BaseModel::STATUS_DELETED]);
        if ($from > 0) {
            $query->andWhere(['>=', 'o.created_at', $from]);
        }
        if ($to > 0) {
            $query->andWhere(['<', 'o.created_at', $to]);
        }

        $row = $query->one(Yii::$app->db) ?: [];
        return [
            'orders' => (int)($row['orders'] ?? 0),
            'items' => (int)($row['items'] ?? 0),
            'amount' => (float)($row['amount'] ?? 0),
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
            ->from(Product::tableName())
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
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

    private function shipmentStats(int $storeId): array
    {
        $rows = (new Query())
            ->select(['shipment_status', 'orders' => 'COUNT(*)'])
            ->from(Order::tableName())
            ->where(['store_id' => $storeId])
            ->andWhere(['payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->groupBy('shipment_status')
            ->orderBy(['shipment_status' => SORT_ASC])
            ->all(Yii::$app->db);

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int)$row['shipment_status']] = (int)$row['orders'];
        }

        return $stats;
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
                'ordered_items' => 'COALESCE(SUM(CASE WHEN o.id IS NULL THEN 0 ELSE op.number END),0)',
                'ordered_amount' => 'COALESCE(SUM(CASE WHEN o.id IS NULL THEN 0 ELSE op.number * op.price END),0)',
            ])
            ->from(['p' => Product::tableName()])
            ->leftJoin(['op' => OrderProduct::tableName()], 'op.product_id = p.id AND op.store_id = p.store_id AND op.status > ' . (int)BaseModel::STATUS_DELETED)
            ->leftJoin(['o' => Order::tableName()], 'o.id = op.order_id AND o.payment_status IN (' . Order::PAYMENT_STATUS_PAID . ',' . Order::PAYMENT_STATUS_COD . ') AND o.status > ' . (int)BaseModel::STATUS_DELETED)
            ->where(['p.store_id' => $storeId])
            ->andWhere(['>', 'p.status', BaseModel::STATUS_DELETED])
            ->groupBy(['p.id', 'p.name', 'p.stock', 'p.sales', 'p.click'])
            ->orderBy(['ordered_items' => SORT_DESC, 'p.sales' => SORT_DESC, 'p.click' => SORT_DESC, 'p.id' => SORT_ASC])
            ->limit(10)
            ->all(Yii::$app->db);
    }

    private function periods(): array
    {
        $now = time();
        $today = strtotime(date('Y-m-d 00:00:00', $now));
        $month = strtotime(date('Y-m-01 00:00:00', $now));
        return [
            'today' => ['label' => 'today', 'from' => $today, 'to' => $today + 86400],
            'month' => ['label' => 'month', 'from' => $month, 'to' => strtotime('+1 month', $month)],
            'all' => ['label' => 'all', 'from' => 0, 'to' => 0],
        ];
    }

    private function platformStoreIds(): array
    {
        $ids = [];
        foreach (Store::find()->select(['id'])->where(['parent_id' => 0])->andWhere(['>', 'status', BaseModel::STATUS_DELETED])->asArray()->all() as $row) {
            $ids[] = (int)$row['id'];
        }

        return $ids ?: [(int)(Yii::$app->params['defaultStoreId'] ?? 0)];
    }

    private function attr($model, string $name, $default = '')
    {
        try {
            return $model->hasAttribute($name) ? $model->{$name} : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function optionalMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float)$value, 2) : null;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Yii::$app->db->schema->getTableSchema($table, true) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
