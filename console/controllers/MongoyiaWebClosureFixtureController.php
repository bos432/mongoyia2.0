<?php

namespace console\controllers;

use common\models\BaseModel;
use common\models\mall\Order;
use common\models\mall\OrderProduct;
use common\models\mall\Product;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaWebClosureFixtureController extends Controller
{
    public $apply = false;
    public $userId = 71;
    public $productId = 102;
    public $amount = '1.00';
    public $failOnPending = false;

    private $failures = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'apply',
            'userId',
            'productId',
            'amount',
            'failOnPending',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia Web closure fixture\n");
        $this->stdout('Mode: ' . ($this->apply ? 'apply' : 'dry-run') . "\n");

        $product = $this->loadProduct();
        $this->ensureUser();
        if ($product) {
            $this->ensureOrder('WEBFIX-COD-SHIPPED', $product, Order::PAYMENT_METHOD_COD, Order::PAYMENT_STATUS_COD, Order::SHIPMENT_STATUS_SHIPPING);
            $this->ensureOrder('WEBFIX-PAID-RECEIVED', $product, Order::PAYMENT_METHOD_PAY, Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_RECEIVED);
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->pending} pending change(s).\n");
        if ($this->failures > 0) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        if (!$this->apply && $this->failOnPending && $this->pending > 0) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function loadProduct()
    {
        $product = Product::find()
            ->where(['id' => (int)$this->productId, 'status' => Product::STATUS_ACTIVE])
            ->andWhere(['>', 'price', 0])
            ->andWhere(['>', 'stock', 0])
            ->one();
        if (!$product) {
            $this->warn("Configured product {$this->productId} is unavailable for fixture; selecting fallback active priced product.");
            $product = Product::find()
                ->where(['status' => Product::STATUS_ACTIVE])
                ->andWhere(['>', 'price', 0])
                ->andWhere(['>', 'stock', 0])
                ->orderBy(['id' => SORT_ASC])
                ->one();
            if (!$product) {
                $this->fail('No active priced product with stock is available for fixture.');
                return null;
            }
        }

        $this->ok("Using product {$product->id} store {$product->store_id}.");
        return $product;
    }

    private function ensureUser()
    {
        $user = (new \yii\db\Query())
            ->from('{{%user}}')
            ->where(['id' => (int)$this->userId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->one(Yii::$app->db);
        if (!$user) {
            $this->fail("Fixture user {$this->userId} is missing. Run mongoyia-acceptance-fixture/run --apply=1 first.");
            return;
        }

        $this->ok("Using user {$this->userId} ({$user['username']}).");
    }

    private function ensureOrder(string $prefix, Product $product, int $paymentMethod, int $paymentStatus, int $shipmentStatus)
    {
        $existing = Order::find()
            ->where(['like', 'sn', $prefix . '-%', false])
            ->andWhere(['<>', 'status', Order::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        if ($existing) {
            $this->ok("Order fixture {$prefix} exists as order {$existing->id}.");
            return;
        }

        $this->pending("Create order fixture {$prefix}.");
        if (!$this->apply) {
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $order = $this->newOrder($prefix, $product, $paymentMethod, $paymentStatus, $shipmentStatus);
            $this->newOrderProduct($order, $product);
            $transaction->commit();
            $this->ok("Created order fixture {$prefix} as order {$order->id}.");
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail("Create order fixture {$prefix} failed: {$e->getMessage()}");
        }
    }

    private function newOrder(string $prefix, Product $product, int $paymentMethod, int $paymentStatus, int $shipmentStatus)
    {
        $now = time();
        $amount = (float)$this->amount;
        $order = new Order();
        $order->store_id = (int)$product->store_id;
        $order->parent_id = 0;
        $order->user_id = (int)$this->userId;
        $order->address_id = 0;
        $order->name = 'Mongoyia web closure fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'Fixture';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local web closure fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_web_fixture@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-web-closure-fixture/run';
        $order->payment_method = $paymentMethod;
        $order->payment_fee = 0;
        $order->payment_status = $paymentStatus;
        $order->paid_at = in_array($paymentStatus, [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD], true) ? $now : 0;
        $order->stock_deducted_at = 0;
        $order->stock_refunded_at = 0;
        $order->shipment_id = $shipmentStatus >= Order::SHIPMENT_STATUS_SHIPPING ? 9002 : 0;
        $order->shipment_name = $shipmentStatus >= Order::SHIPMENT_STATUS_SHIPPING ? 'Codex Web Fixture Express' : '';
        $order->shipment_fee = 0;
        $order->shipment_status = $shipmentStatus;
        $order->shipped_at = $shipmentStatus >= Order::SHIPMENT_STATUS_SHIPPING ? $now : 0;
        $order->product_amount = $amount;
        $order->amount = $amount;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = $shipmentStatus;

        if (!$order->save()) {
            throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
    }

    private function newOrderProduct(Order $order, Product $product)
    {
        $orderProduct = new OrderProduct();
        $orderProduct->store_id = (int)$product->store_id;
        $orderProduct->parent_id = 0;
        $orderProduct->user_id = (int)$this->userId;
        $orderProduct->order_id = (int)$order->id;
        $orderProduct->product_id = (int)$product->id;
        $orderProduct->product_attribute_value = '';
        $orderProduct->name = (string)$product->name;
        $orderProduct->sku = (string)$product->sku;
        $orderProduct->number = 1;
        $orderProduct->price = (float)$order->amount;
        $orderProduct->market_price = (float)$order->amount;
        $orderProduct->cost_price = 0;
        $orderProduct->wholesale_price = 0;
        $orderProduct->thumb = (string)$product->thumb;
        $orderProduct->cart_id = 0;
        $orderProduct->type = OrderProduct::TYPE_DEFAULT;
        $orderProduct->sort = OrderProduct::SORT_DEFAULT;
        $orderProduct->status = OrderProduct::STATUS_ACTIVE;

        if (!$orderProduct->save()) {
            throw new \RuntimeException(json_encode($orderProduct->errors, JSON_UNESCAPED_UNICODE));
        }

        return $orderProduct;
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function pending(string $message)
    {
        $this->pending++;
        $this->stdout(($this->apply ? 'APPLY ' : 'TODO  ') . $message . "\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }

    private function warn(string $message)
    {
        $this->stdout("WARN {$message}\n");
    }
}
