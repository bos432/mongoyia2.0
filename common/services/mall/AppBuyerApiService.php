<?php

namespace common\services\mall;

use common\models\BaseModel;
use common\models\mall\Cart;
use common\models\mall\Category;
use common\models\mall\CouponType;
use common\models\mall\Favorite;
use common\models\mall\Order;
use common\models\mall\OrderProduct;
use common\models\mall\Product;
use common\models\mall\ProductSku;
use common\models\mall\Review;
use common\models\mall\UserCoupon;
use Yii;

class AppBuyerApiService
{
    public const VERSION = 'MONGOYIA_APP_BUYER_API_V1';
    public const CHECKOUT_WRITE_GATE = 'checkout_write_requires_payment_address_stock_safety_acceptance';

    public function home(int $storeId = 0): array
    {
        $products = $this->publicProductQuery($storeId)
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_DESC])
            ->limit(12)
            ->all();

        return [
            'version' => self::VERSION,
            'items' => array_map([$this, 'productSummary'], $products),
            'summary' => [
                'product_count' => $this->publicProductQuery($storeId)->count(),
                'category_count' => Category::find()->where(['status' => BaseModel::STATUS_ACTIVE])->count(),
            ],
        ];
    }

    public function categories(int $storeId = 0): array
    {
        $query = Category::find()
            ->where(['status' => BaseModel::STATUS_ACTIVE])
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC]);
        if ($storeId > 0) {
            $query->andFilterWhere(['store_id' => $storeId]);
        }

        $items = [];
        foreach ($query->limit(200)->all() as $category) {
            $items[] = [
                'id' => (int)$category->id,
                'parent_id' => (int)($category->parent_id ?? 0),
                'name' => (string)$category->name,
                'brief' => (string)($category->brief ?? ''),
                'banner' => (string)($category->banner ?? ''),
                'product_count' => (int)$this->publicProductQuery(0)->andWhere(['category_id' => (int)$category->id])->count(),
                'sort' => (int)$category->sort,
            ];
        }

        return [
            'version' => self::VERSION,
            'items' => $items,
        ];
    }

    public function search(array $params, int $storeId = 0): array
    {
        $query = $this->publicProductQuery($storeId);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->andWhere(['or',
                ['like', 'name', $keyword],
                ['like', 'sku', $keyword],
                ['like', 'brief', $keyword],
            ]);
        }
        $categoryId = (int)($params['category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->andWhere(['category_id' => $categoryId]);
        }
        $brandId = (int)($params['brand_id'] ?? ($params['brand'] ?? 0));
        if ($brandId > 0) {
            $query->andWhere(['brand_id' => $brandId]);
        }
        $minPrice = $this->optionalFloat($params['min_price'] ?? null);
        if ($minPrice !== null) {
            $query->andWhere(['>=', 'price', $minPrice]);
        }
        $maxPrice = $this->optionalFloat($params['max_price'] ?? null);
        if ($maxPrice !== null) {
            $query->andWhere(['<=', 'price', $maxPrice]);
        }

        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(50, (int)($params['page_size'] ?? 20)));
        $total = (int)$query->count();
        $products = $query
            ->orderBy(['sort' => SORT_ASC, 'id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'version' => self::VERSION,
            'items' => array_map([$this, 'productSummary'], $products),
            'summary' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
            ],
        ];
    }

    public function product(int $id, int $userId = 0): array
    {
        $product = $this->publicProductQuery(0)->andWhere(['id' => $id])->one();
        if (!$product) {
            throw new \RuntimeException('Product not found.');
        }

        $favorite = false;
        if ($userId > 0) {
            $favorite = Favorite::find()
                ->where(['user_id' => $userId, 'product_id' => $id])
                ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                ->exists();
        }

        return [
            'version' => self::VERSION,
            'product' => $this->productDetail($product),
            'skus' => array_map([$this, 'skuSummary'], ProductSku::find()
                ->where(['product_id' => $id])
                ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                ->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])
                ->all()),
            'reviews' => $this->reviews($id, 1, 5)['items'],
            'favorite' => $favorite,
            'customer_service' => [
                'route' => '/pages/chat/index',
                'gid' => $id,
            ],
        ];
    }

    public function cart(int $userId): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }

        $rows = Cart::find()
            ->where(['user_id' => $userId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $items = array_map([$this, 'cartSummary'], $rows);
        $total = 0.0;
        $number = 0;
        foreach ($items as $item) {
            $total += (float)$item['price'] * (int)$item['number'];
            $number += (int)$item['number'];
        }

        return [
            'version' => self::VERSION,
            'items' => $items,
            'summary' => [
                'total' => number_format($total, 2, '.', ''),
                'number' => $number,
            ],
        ];
    }

    public function addCart(int $userId, array $input): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }

        $productId = (int)($input['product_id'] ?? 0);
        $number = max(1, min(999, (int)($input['number'] ?? 1)));
        $product = $this->publicProductQuery(0)->andWhere(['id' => $productId])->one();
        if (!$product) {
            throw new \RuntimeException('Product not found.');
        }

        $sku = null;
        $skuId = (int)($input['sku_id'] ?? 0);
        $attributeValue = trim((string)($input['attribute_value'] ?? ''));
        if ($skuId > 0) {
            $sku = ProductSku::find()
                ->where(['id' => $skuId, 'product_id' => $productId])
                ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                ->one();
        } elseif ($attributeValue !== '') {
            $sku = ProductSku::find()
                ->where(['product_id' => $productId, 'attribute_value' => $attributeValue])
                ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                ->one();
        }

        $stock = $sku ? (int)$sku->stock : (int)$product->stock;
        if ($stock < $number) {
            throw new \RuntimeException('Stock is less than required.');
        }

        $attributeValue = $sku ? (string)$sku->attribute_value : $attributeValue;
        $cart = Cart::find()
            ->where([
                'user_id' => $userId,
                'product_id' => $productId,
                'product_attribute_value' => $attributeValue,
            ])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->one();
        if (!$cart) {
            $cart = new Cart();
            $cart->user_id = $userId;
            $cart->product_id = $productId;
            $cart->product_attribute_value = $attributeValue;
            $cart->number = 0;
            $cart->status = BaseModel::STATUS_ACTIVE;
        }

        if ((int)$cart->number + $number > $stock) {
            throw new \RuntimeException('Stock is less than required.');
        }

        $cart->store_id = (int)$product->store_id;
        $cart->session_id = '';
        $cart->number = (int)$cart->number + $number;
        $cart->name = (string)$product->name;
        $cart->sku = (string)($sku->sku ?? $product->sku);
        $cart->market_price = (float)($sku->market_price ?? $product->market_price);
        $cart->price = (float)($sku->price ?? $product->price);
        $cart->thumb = (string)($sku->thumb ?? $product->thumb);
        $cart->type = (int)$product->type;

        if (!$cart->save()) {
            throw new \RuntimeException('Cart save failed: ' . json_encode($cart->errors, JSON_UNESCAPED_UNICODE));
        }

        return $this->cart($userId);
    }

    public function orders(int $userId): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }

        $orders = Order::find()
            ->where(['user_id' => $userId, 'parent_id' => 0])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit(50)
            ->all();

        return [
            'version' => self::VERSION,
            'items' => array_map([$this, 'orderSummary'], $orders),
            'summary' => [
                'checkout_write_gate' => self::CHECKOUT_WRITE_GATE,
            ],
        ];
    }

    public function checkoutReserved(): array
    {
        return [
            'version' => self::VERSION,
            'checkout_reserved' => true,
            'message' => 'Checkout write API is reserved until Phase 13 payment/address/stock safety acceptance is complete.',
            'gate' => self::CHECKOUT_WRITE_GATE,
        ];
    }

    public function coupons(int $userId): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }

        $rows = (new \yii\db\Query())
            ->select([
                'user_coupon_id' => 'uc.id',
                'coupon_id' => 'uc.cid',
                'user_coupon_status' => 'uc.status',
                'name' => 'ct.name',
                'money' => 'ct.money',
                'min_amount' => 'ct.min_amount',
            ])
            ->from(UserCoupon::tableName() . ' uc')
            ->leftJoin(CouponType::tableName() . ' ct', 'ct.id = uc.cid')
            ->where(['uc.uid' => $userId])
            ->andWhere(['>', 'uc.status', BaseModel::STATUS_DELETED])
            ->orderBy(['uc.id' => SORT_DESC])
            ->limit(100)
            ->all(Yii::$app->db);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int)($row['user_coupon_id'] ?? 0),
                'coupon_id' => (int)($row['coupon_id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'money' => (string)($row['money'] ?? ''),
                'min_amount' => (float)($row['min_amount'] ?? 0),
                'status' => (int)($row['user_coupon_status'] ?? 0),
            ];
        }

        return [
            'version' => self::VERSION,
            'items' => $items,
        ];
    }

    public function favorites(int $userId): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }

        $favorites = Favorite::find()
            ->where(['user_id' => $userId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit(100)
            ->all();

        return [
            'version' => self::VERSION,
            'items' => array_map(function (Favorite $favorite): array {
                return [
                    'id' => (int)$favorite->id,
                    'product_id' => (int)$favorite->product_id,
                    'name' => (string)$favorite->name,
                ];
            }, $favorites),
        ];
    }

    public function toggleFavorite(int $userId, int $productId): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }

        $product = $this->publicProductQuery(0)->andWhere(['id' => $productId])->one();
        if (!$product) {
            throw new \RuntimeException('Product not found.');
        }

        $favorite = Favorite::find()
            ->where(['user_id' => $userId, 'product_id' => $productId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->one();
        if ($favorite) {
            $favorite->status = BaseModel::STATUS_DELETED;
            $favorite->save(false);
            return ['version' => self::VERSION, 'favorite' => false];
        }

        $favorite = new Favorite();
        $favorite->user_id = $userId;
        $favorite->product_id = $productId;
        $favorite->store_id = (int)$product->store_id;
        $favorite->name = (string)$product->name;
        $favorite->status = BaseModel::STATUS_ACTIVE;
        if (!$favorite->save()) {
            throw new \RuntimeException('Favorite save failed: ' . json_encode($favorite->errors, JSON_UNESCAPED_UNICODE));
        }

        return ['version' => self::VERSION, 'favorite' => true];
    }

    public function reviews(int $productId, int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        $query = Review::find()
            ->where(['product_id' => $productId, 'status' => BaseModel::STATUS_ACTIVE])
            ->orderBy(['id' => SORT_DESC]);
        $total = (int)$query->count();

        $items = [];
        foreach ($query->offset(($page - 1) * $pageSize)->limit($pageSize)->all() as $review) {
            $items[] = [
                'id' => (int)$review->id,
                'product_id' => (int)$review->product_id,
                'name' => (string)$review->name,
                'star' => (int)$review->star,
                'content' => (string)$review->content,
                'created_at' => (int)$review->created_at,
            ];
        }

        return [
            'version' => self::VERSION,
            'items' => $items,
            'summary' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
            ],
        ];
    }

    private function publicProductQuery(int $storeId = 0)
    {
        $query = Product::find()
            ->where(['status' => BaseModel::STATUS_ACTIVE]);
        if ($storeId > 0) {
            $query->andFilterWhere(['store_id' => $storeId]);
        }
        if ($this->hasColumn(Product::tableName(), 'audit_status')) {
            $query->andWhere(['or',
                ['audit_status' => 'approved'],
                ['audit_status' => ''],
                ['audit_status' => null],
            ]);
        }

        return $query;
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
            'reviews' => (int)$product->reviews,
            'star' => (float)$product->star,
        ];
    }

    private function productDetail(Product $product): array
    {
        return array_merge($this->productSummary($product), [
            'brief' => (string)$product->brief,
            'description' => strip_tags((string)$product->content),
            'images' => $this->decodeImages($product->images),
            'seo_url' => (string)$product->seo_url,
            'brand_id' => (int)$product->brand_id,
        ]);
    }

    private function skuSummary(ProductSku $sku): array
    {
        return [
            'id' => (int)$sku->id,
            'product_id' => (int)$sku->product_id,
            'name' => (string)$sku->name,
            'attribute_value' => (string)$sku->attribute_value,
            'thumb' => (string)$sku->thumb,
            'price' => number_format((float)$sku->price, 2, '.', ''),
            'market_price' => number_format((float)$sku->market_price, 2, '.', ''),
            'sku' => (string)$sku->sku,
            'stock' => (int)$sku->stock,
        ];
    }

    private function cartSummary(Cart $cart): array
    {
        return [
            'id' => (int)$cart->id,
            'product_id' => (int)$cart->product_id,
            'name' => (string)$cart->name,
            'thumb' => (string)$cart->thumb,
            'sku' => (string)$cart->sku,
            'product_attribute_value' => (string)$cart->product_attribute_value,
            'number' => (int)$cart->number,
            'price' => number_format((float)$cart->price, 2, '.', ''),
            'amount' => number_format((float)$cart->price * (int)$cart->number, 2, '.', ''),
        ];
    }

    private function orderSummary(Order $order): array
    {
        $products = OrderProduct::find()
            ->where(['order_id' => (int)$order->id])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->limit(20)
            ->all();

        return [
            'id' => (int)$order->id,
            'sn' => (string)$order->sn,
            'order_sn' => (string)$order->sn,
            'amount' => number_format((float)$order->amount, 2, '.', ''),
            'product_amount' => number_format((float)$order->product_amount, 2, '.', ''),
            'number' => (int)$order->number,
            'payment_status' => (int)$order->payment_status,
            'shipment_status' => (int)$order->shipment_status,
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
            }, $products),
        ];
    }

    private function authRequiredPayload(): array
    {
        return [
            'version' => self::VERSION,
            'auth_required' => true,
            'items' => [],
            'summary' => [],
        ];
    }

    private function decodeImages($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value));
        }
        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded));
        }

        return array_values(array_filter(array_map('trim', explode(',', $text))));
    }

    private function optionalFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float)$value : null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $schema = Yii::$app->db->schema->getTableSchema($table, true);
            return $schema && isset($schema->columns[$column]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
