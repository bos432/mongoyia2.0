<?php

namespace common\services\mall;

use common\models\BaseModel;
use common\models\mall\Address;
use common\models\mall\Cart;
use common\models\mall\Category;
use common\models\mall\CouponType;
use common\models\mall\Favorite;
use common\models\mall\Order;
use common\models\mall\OrderLog;
use common\models\mall\OrderProduct;
use common\models\mall\Product;
use common\models\mall\ProductSku;
use common\models\mall\Review;
use common\models\mall\StoreFavorite;
use common\models\mall\UserCoupon;
use Yii;

class AppBuyerApiService
{
    public const VERSION = 'MONGOYIA_APP_BUYER_API_V1';
    public const CHECKOUT_WRITE_VERSION = 'MONGOYIA_APP_BUYER_CHECKOUT_WRITE_V1';

    private $searchVideoService;

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
            ->orderBy($this->searchVideoService()->sortOrder((string)($params['sort'] ?? '')))
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
                'sort' => (string)($params['sort'] ?? ''),
                'search_video_version' => ProductSearchVideoPhase14Service::VERSION,
            ],
        ];
    }

    public function suggestions(array $params, int $storeId = 0): array
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        $limit = max(1, min(20, (int)($params['limit'] ?? 8)));
        $query = $this->publicProductQuery($storeId);
        if ($keyword !== '') {
            $query->andWhere(['or',
                ['like', 'name', $keyword],
                ['like', 'sku', $keyword],
                ['like', 'brief', $keyword],
            ]);
        }

        $products = $query
            ->orderBy(['sales' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(50)
            ->all();

        $rows = array_map(function (Product $product): array {
            return [
                'id' => (int)$product->id,
                'name' => (string)$product->name,
                'sku' => (string)$product->sku,
            ];
        }, $products);

        return [
            'version' => self::VERSION,
            'items' => $this->searchVideoService()->buildSuggestions($rows, $keyword, $limit),
            'summary' => [
                'keyword' => $keyword,
                'limit' => $limit,
                'search_video_version' => ProductSearchVideoPhase14Service::VERSION,
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
        $storeFavorite = false;
        if ($userId > 0) {
            $favorite = Favorite::find()
                ->where(['user_id' => $userId, 'product_id' => $id])
                ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                ->exists();
            if ($this->hasTable(StoreFavorite::tableName())) {
                $storeFavorite = StoreFavorite::find()
                    ->where(['user_id' => $userId, 'store_id' => (int)$product->store_id])
                    ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                    ->exists();
            }
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
            'store_favorite' => $storeFavorite,
            'store' => [
                'id' => (int)$product->store_id,
                'name' => (string)($product->store->name ?? ''),
            ],
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
                'checkout_write_version' => self::CHECKOUT_WRITE_VERSION,
            ],
        ];
    }

    public function submitOrder(int $userId, array $input, int $storeId = 0): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }

        $carts = Cart::find()
            ->where(['user_id' => $userId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        if (!$carts) {
            throw new \RuntimeException('Cart is empty.');
        }

        $cartProducts = [];
        $groups = [];
        $productAmount = 0.0;
        $number = 0;
        foreach ($carts as $cart) {
            $product = $this->publicProductQuery(0)->andWhere(['id' => (int)$cart->product_id])->one();
            if (!$product) {
                throw new \RuntimeException('Product not found.');
            }

            $sku = null;
            if ((string)$cart->product_attribute_value !== '') {
                $sku = ProductSku::find()
                    ->where(['product_id' => (int)$cart->product_id, 'attribute_value' => (string)$cart->product_attribute_value])
                    ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                    ->one();
                if (!$sku) {
                    throw new \RuntimeException('Product SKU not found.');
                }
            }

            $stock = $sku ? (int)$sku->stock : (int)$product->stock;
            if ((int)$cart->number <= 0 || (int)$cart->number > $stock) {
                throw new \RuntimeException('Stock is less than required.');
            }
            if ((float)$cart->price <= 0) {
                throw new \RuntimeException('Product price is not available.');
            }

            $sellerStoreId = (int)$product->store_id;
            if ($sellerStoreId <= 0) {
                throw new \RuntimeException('Product store is not available.');
            }

            $cartProducts[(int)$cart->id] = $product;
            if (!isset($groups[$sellerStoreId])) {
                $groups[$sellerStoreId] = [
                    'product_amount' => 0.0,
                    'number' => 0,
                    'carts' => [],
                ];
            }
            $lineAmount = (float)$cart->price * (int)$cart->number;
            $groups[$sellerStoreId]['product_amount'] += $lineAmount;
            $groups[$sellerStoreId]['number'] += (int)$cart->number;
            $groups[$sellerStoreId]['carts'][] = $cart;
            $productAmount += $lineAmount;
            $number += (int)$cart->number;
        }

        $parentStoreId = $storeId > 0 ? $storeId : (int)array_key_first($groups);
        $paymentMethod = (int)($input['payment_method'] ?? Order::PAYMENT_METHOD_PAY);
        if (!in_array($paymentMethod, [Order::PAYMENT_METHOD_PAY, Order::PAYMENT_METHOD_COD], true)) {
            $paymentMethod = Order::PAYMENT_METHOD_PAY;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $address = $this->saveCheckoutAddress($userId, $parentStoreId, (array)($input['address'] ?? []));
            $remark = mb_substr(trim((string)($input['remark'] ?? '')), 0, 255, 'UTF-8');
            $discount = 0.0;
            $sn = 'APP' . date('YmdHis') . random_int(1000, 9999);

            $parent = new Order();
            $this->fillOrderAddress($parent, $address);
            $parent->user_id = $userId;
            $parent->store_id = $parentStoreId;
            $parent->parent_id = 0;
            $parent->sn = $sn;
            $parent->remark = $remark;
            $parent->payment_method = $paymentMethod;
            $parent->payment_status = $paymentMethod === Order::PAYMENT_METHOD_COD
                ? Order::PAYMENT_STATUS_COD
                : Order::PAYMENT_STATUS_UNPAID;
            $parent->status = $parent->payment_status;
            $parent->shipment_status = Order::SHIPMENT_STATUS_UNSHIPPED;
            $parent->product_amount = round($productAmount, 2);
            $parent->discount = $discount;
            $parent->amount = round($productAmount + $discount, 2);
            $parent->number = $number;
            $parent->created_by = $userId;
            $parent->updated_by = $userId;
            if (!$parent->save()) {
                throw new \RuntimeException('Order save failed: ' . json_encode($parent->errors, JSON_UNESCAPED_UNICODE));
            }

            $allocatedDiscount = 0.0;
            $groupIndex = 0;
            $groupCount = count($groups);
            $childOrders = [];
            foreach ($groups as $sellerStoreId => $group) {
                $groupIndex++;
                if ($productAmount > 0 && $groupIndex < $groupCount) {
                    $childDiscount = round($discount * ((float)$group['product_amount'] / $productAmount), 2);
                    $allocatedDiscount += $childDiscount;
                } else {
                    $childDiscount = round($discount - $allocatedDiscount, 2);
                }

                $child = new Order();
                $this->fillOrderAddress($child, $address);
                $child->user_id = $userId;
                $child->store_id = (int)$sellerStoreId;
                $child->parent_id = (int)$parent->id;
                $child->sn = $sn . '-' . $groupIndex;
                $child->remark = $remark;
                $child->payment_method = $parent->payment_method;
                $child->payment_status = $parent->payment_status;
                $child->status = $parent->status;
                $child->shipment_status = Order::SHIPMENT_STATUS_UNSHIPPED;
                $child->product_amount = round((float)$group['product_amount'], 2);
                $child->discount = $childDiscount;
                $child->amount = round((float)$group['product_amount'] + $childDiscount, 2);
                $child->number = (int)$group['number'];
                $child->created_by = $userId;
                $child->updated_by = $userId;
                if (!$child->save()) {
                    throw new \RuntimeException('Child order save failed: ' . json_encode($child->errors, JSON_UNESCAPED_UNICODE));
                }

                foreach ($group['carts'] as $cart) {
                    $product = $cartProducts[(int)$cart->id] ?? null;
                    if (!$product) {
                        throw new \RuntimeException('Product not found.');
                    }

                    $orderProduct = new OrderProduct();
                    $orderProduct->store_id = (int)$sellerStoreId;
                    $orderProduct->parent_id = (int)$parent->id;
                    $orderProduct->user_id = $userId;
                    $orderProduct->order_id = (int)$child->id;
                    $orderProduct->product_id = (int)$cart->product_id;
                    $orderProduct->product_attribute_value = (string)$cart->product_attribute_value;
                    $orderProduct->sku = (string)$cart->sku;
                    $orderProduct->name = (string)$cart->name;
                    $orderProduct->number = (int)$cart->number;
                    $orderProduct->market_price = (float)$cart->market_price;
                    $orderProduct->price = (float)$cart->price;
                    $orderProduct->thumb = (string)$cart->thumb;
                    $orderProduct->type = (int)$cart->type;
                    $orderProduct->cart_id = (int)$cart->id;
                    $orderProduct->created_by = $userId;
                    $orderProduct->updated_by = $userId;
                    if (!$orderProduct->save()) {
                        throw new \RuntimeException('Order product save failed: ' . json_encode($orderProduct->errors, JSON_UNESCAPED_UNICODE));
                    }
                }

                OrderLog::create((int)$child->id, (int)$child->status, 'APP checkout', null, $userId);
                $childOrders[] = $child;
            }

            if ($paymentMethod === Order::PAYMENT_METHOD_COD) {
                $parent->deductStockIfNeeded();
            }

            Cart::deleteAll(['id' => array_map(static function (Cart $cart): int {
                return (int)$cart->id;
            }, $carts)]);
            OrderLog::create((int)$parent->id, (int)$parent->status, 'APP checkout', null, $userId);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return [
            'version' => self::VERSION,
            'checkout_write_version' => self::CHECKOUT_WRITE_VERSION,
            'order' => $this->orderSummary($parent),
            'children' => array_map([$this, 'orderSummary'], $childOrders),
            'payment' => [
                'method' => $paymentMethod,
                'payment_url' => '/mall/payment/index?id=' . (int)$parent->id,
                'requires_online_payment' => $paymentMethod === Order::PAYMENT_METHOD_PAY,
            ],
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

    public function storeFavorites(int $userId): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }
        if (!$this->hasTable(StoreFavorite::tableName())) {
            return [
                'version' => self::VERSION,
                'items' => [],
                'summary' => [
                    'table_ready' => false,
                    'favorite_review_version' => FavoriteReviewPhase14Service::VERSION,
                ],
            ];
        }

        $favorites = StoreFavorite::find()
            ->where(['user_id' => $userId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit(100)
            ->all();

        return [
            'version' => self::VERSION,
            'items' => array_map(function (StoreFavorite $favorite): array {
                return [
                    'id' => (int)$favorite->id,
                    'store_id' => (int)$favorite->store_id,
                    'name' => (string)$favorite->name,
                    'created_at' => (int)$favorite->created_at,
                ];
            }, $favorites),
            'summary' => [
                'favorite_review_version' => FavoriteReviewPhase14Service::VERSION,
            ],
        ];
    }

    public function toggleStoreFavorite(int $userId, int $storeId): array
    {
        if ($userId <= 0) {
            return $this->authRequiredPayload();
        }
        if ($storeId <= 0) {
            throw new \RuntimeException('Store not found.');
        }
        if (!$this->hasTable(StoreFavorite::tableName())) {
            throw new \RuntimeException('Store favorite table is not ready.');
        }

        $store = \common\models\Store::findOne(['id' => $storeId]);
        if (!$store) {
            throw new \RuntimeException('Store not found.');
        }

        $favorite = StoreFavorite::find()
            ->where(['user_id' => $userId, 'store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->one();
        if ($favorite) {
            $favorite->status = BaseModel::STATUS_DELETED;
            $favorite->save(false);
            return ['version' => self::VERSION, 'store_favorite' => false];
        }

        $favorite = new StoreFavorite();
        $favorite->user_id = $userId;
        $favorite->store_id = $storeId;
        $favorite->name = (string)$store->name;
        $favorite->status = BaseModel::STATUS_ACTIVE;
        $favorite->created_at = time();
        $favorite->updated_at = time();
        $favorite->created_by = $userId;
        $favorite->updated_by = $userId;
        if (!$favorite->save()) {
            throw new \RuntimeException('Store favorite save failed: ' . json_encode($favorite->errors, JSON_UNESCAPED_UNICODE));
        }

        return ['version' => self::VERSION, 'store_favorite' => true];
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
        if ($this->hasColumn(Review::tableName(), 'moderation_status')) {
            $query->andWhere(['moderation_status' => Review::MODERATION_APPROVED]);
        }
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
            'brand_id' => (int)$product->brand_id,
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
            'has_video' => $this->productVideoUrl($product) !== '',
        ];
    }

    private function productDetail(Product $product): array
    {
        return array_merge($this->productSummary($product), [
            'brief' => (string)$product->brief,
            'description' => strip_tags((string)$product->content),
            'images' => $this->decodeImages($product->images),
            'video_url' => $this->productVideoUrl($product),
            'video' => $this->searchVideoService()->videoPayload($this->productVideoUrl($product)),
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
            'payment_url' => (int)$order->parent_id === 0 && (int)$order->payment_status === Order::PAYMENT_STATUS_UNPAID
                ? '/mall/payment/index?id=' . (int)$order->id
                : '',
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

    private function saveCheckoutAddress(int $userId, int $storeId, array $payload): Address
    {
        $addressId = (int)($payload['id'] ?? $payload['address_id'] ?? 0);
        $address = null;
        if ($addressId > 0) {
            $address = Address::find()
                ->where(['id' => $addressId, 'user_id' => $userId])
                ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
                ->one();
        }
        if (!$address) {
            $address = new Address();
        }

        $name = trim((string)($payload['name'] ?? ''));
        $firstName = trim((string)($payload['first_name'] ?? ''));
        $lastName = trim((string)($payload['last_name'] ?? ''));
        if ($firstName === '' && $name !== '') {
            $parts = preg_split('/\s+/u', $name, 2);
            $firstName = (string)($parts[0] ?? $name);
            $lastName = (string)($parts[1] ?? '');
        }

        $address->store_id = $storeId;
        $address->user_id = $userId;
        $address->name = $name !== '' ? $name : trim($firstName . ' ' . $lastName);
        $address->first_name = $firstName;
        $address->last_name = $lastName;
        $address->country = mb_substr(trim((string)($payload['country'] ?? '')), 0, 255, 'UTF-8');
        $address->province = mb_substr(trim((string)($payload['province'] ?? '')), 0, 255, 'UTF-8');
        $address->city = mb_substr(trim((string)($payload['city'] ?? '')), 0, 255, 'UTF-8');
        $address->district = mb_substr(trim((string)($payload['district'] ?? '')), 0, 255, 'UTF-8');
        $address->address = mb_substr(trim((string)($payload['address'] ?? '')), 0, 255, 'UTF-8');
        $address->address2 = mb_substr(trim((string)($payload['address2'] ?? '')), 0, 255, 'UTF-8');
        $address->postcode = mb_substr(trim((string)($payload['postcode'] ?? '')), 0, 255, 'UTF-8');
        $address->mobile = mb_substr(trim((string)($payload['mobile'] ?? '')), 0, 255, 'UTF-8');
        $address->email = mb_substr(trim((string)($payload['email'] ?? '')), 0, 255, 'UTF-8');
        $address->status = BaseModel::STATUS_ACTIVE;
        $address->created_by = $address->created_by ?: $userId;
        $address->updated_by = $userId;

        if ($address->first_name === '' || $address->address === '' || ($address->mobile === '' && $address->email === '')) {
            throw new \RuntimeException('Receiver name, address, and mobile or email are required.');
        }
        if (!$address->save()) {
            throw new \RuntimeException('Address save failed: ' . json_encode($address->errors, JSON_UNESCAPED_UNICODE));
        }

        return $address;
    }

    private function fillOrderAddress(Order $order, Address $address): void
    {
        $order->address_id = (int)$address->id;
        $order->first_name = (string)$address->first_name;
        $order->last_name = (string)$address->last_name;
        $order->country = (string)$address->country;
        $order->province = (string)$address->province;
        $order->city = (string)$address->city;
        $order->district = (string)$address->district;
        $order->address = (string)$address->address;
        $order->address2 = (string)$address->address2;
        $order->postcode = (string)$address->postcode;
        $order->mobile = (string)$address->mobile;
        $order->email = (string)$address->email;
        $order->name = trim((string)$address->first_name . ' ' . (string)$address->last_name);
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

    private function hasTable(string $table): bool
    {
        try {
            return Yii::$app->db->schema->getTableSchema($table, true) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function productVideoUrl(Product $product): string
    {
        if (!$this->hasColumn(Product::tableName(), 'video_url') ||
            (method_exists($product, 'hasAttribute') && !$product->hasAttribute('video_url'))) {
            return '';
        }

        return $this->searchVideoService()->normalizeVideoUrl((string)$product->getAttribute('video_url'));
    }

    private function searchVideoService(): ProductSearchVideoPhase14Service
    {
        if (!$this->searchVideoService) {
            $this->searchVideoService = new ProductSearchVideoPhase14Service();
        }

        return $this->searchVideoService;
    }
}
