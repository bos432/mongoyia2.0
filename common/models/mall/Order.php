<?php

namespace common\models\mall;

use Yii;
use common\models\User;
use common\models\Store;
use common\models\base\FundLog;

/**
 * This is the model class for table "{{%mall_order}}".
 *
 * @property int $id
 * @property int $store_id 商家
 * @property int $parent_id 父订单
 * @property int $user_id 用户
 * @property int $address_id 地址ID
 * @property string $name 名称
 * @property string $sn 编号
 * @property string $first_name 名字
 * @property string $last_name 姓氏
 * @property int $country_id 国家
 * @property string $country 国家
 * @property int $province_id 省
 * @property string $province 省
 * @property int $city_id 市
 * @property string $city 市
 * @property int $district_id 区
 * @property string $district 区
 * @property string $address 地址
 * @property string $address2 地址2
 * @property string $postcode 邮编
 * @property string $mobile 手机
 * @property string $email 邮箱
 * @property float $distance 距离
 * @property string $remark 备注
 * @property int $payment_method 支付方式
 * @property float $payment_fee 支付手续费
 * @property int $payment_status 支付状态
 * @property int $paid_at 支付时间
 * @property int $stock_deducted_at 库存扣减时间
 * @property int $stock_refunded_at 库存返还时间
 * @property int $shipment_id 配送公司
 * @property string $shipment_name 配送名称
 * @property float $shipment_fee 配送费
 * @property int $shipment_fee_deducted_at 物流费扣费时间
 * @property int $shipment_status 配送状态
 * @property int $logistics_review_status 平台/口岸物流复核状态
 * @property int $logistics_reviewed_at 平台/口岸物流复核时间
 * @property int $logistics_reviewed_by 平台/口岸物流复核人
 * @property string $logistics_review_remark 平台/口岸物流复核备注
 * @property int $shipped_at 配送时间
 * @property float $product_amount 商品总价
 * @property float $amount 支付金额
 * @property int $number 数量
 * @property float $extra_fee 额外费用
 * @property float $discount 优惠金额
 * @property float $tax 税费
 * @property string $invoice 发票
 * @property int $type 类型
 * @property int $sort 排序
 * @property int $status 状态
 * @property int $created_at 创建时间
 * @property int $updated_at 更新时间
 * @property int $created_by 创建用户
 * @property int $updated_by 更新用户
 * @property int $fx_id 更新用户
 */
class Order extends OrderBase
{
    const LOGISTICS_REVIEW_PENDING = 0;
    const LOGISTICS_REVIEW_PASSED = 1;
    const LOGISTICS_REVIEW_REJECTED = 2;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%mall_order}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return array_merge(parent::rules(), [
            [['store_id', 'parent_id', 'user_id', 'address_id', 'country_id', 'province_id', 'city_id', 'district_id', 'payment_method', 'payment_status', 'paid_at', 'stock_deducted_at', 'stock_refunded_at', 'shipment_id', 'shipment_fee_deducted_at', 'shipment_status', 'logistics_review_status', 'logistics_reviewed_at', 'logistics_reviewed_by', 'shipped_at', 'number', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by', 'fx_id'], 'integer'],
            [['user_id', 'sn'], 'required'],
            [['distance', 'payment_fee', 'shipment_fee', 'product_amount', 'amount', 'extra_fee', 'discount', 'tax'], 'number'],
            [['name', 'sn', 'first_name', 'last_name', 'country', 'province', 'city', 'district', 'address', 'address2', 'postcode', 'mobile', 'email', 'remark', 'shipment_name', 'logistics_review_remark', 'invoice','wlgs','wldh'], 'string', 'max' => 255],
        ]);
    }

    public static function getLogisticsReviewStatusLabels($id = null, $all = false, $flip = false)
    {
        $data = [
            self::LOGISTICS_REVIEW_PENDING => Yii::t('app', 'Pending Review'),
            self::LOGISTICS_REVIEW_PASSED => Yii::t('app', 'Review Passed'),
            self::LOGISTICS_REVIEW_REJECTED => Yii::t('app', 'Review Rejected'),
        ];

        $flip && $data = array_flip($data);

        return !is_null($id) ? ($data[$id] ?? $id) : $data;
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        if (Yii::$app->language == Yii::$app->params['sqlCommentLanguage']) {
            return array_merge(parent::attributeLabels(), [
                'id' => Yii::t('app', 'ID'),
                'store_id' => '商家',
                'parent_id' => '父订单',
                'user_id' => '用户',
                'address_id' => '地址ID',
                'name' => '名称',
                'sn' => '编号',
                'first_name' => '名字',
                'last_name' => '姓氏',
                'country_id' => '国家',
                'country' => '国家',
                'province_id' => '省',
                'province' => '省',
                'city_id' => '市',
                'city' => '市',
                'district_id' => '区',
                'district' => '区',
                'address' => '地址',
                'address2' => '地址2',
                'postcode' => '邮编',
                'mobile' => '手机',
                'email' => '邮箱',
                'distance' => '距离',
                'remark' => '备注',
                'payment_method' => '支付方式',
                'payment_fee' => '支付手续费',
                'payment_status' => '支付状态',
                'paid_at' => '支付时间',
                'stock_deducted_at' => '库存扣减时间',
                'stock_refunded_at' => '库存返还时间',
                'shipment_id' => '配送单号',
                'shipment_name' => '配送公司名称',
                'shipment_fee' => '配送费',
                'shipment_fee_deducted_at' => '物流费扣费时间',
                'shipment_status' => '配送状态',
                'logistics_review_status' => '平台/口岸物流复核状态',
                'logistics_reviewed_at' => '平台/口岸物流复核时间',
                'logistics_reviewed_by' => '平台/口岸物流复核人',
                'logistics_review_remark' => '平台/口岸物流复核备注',
                'shipped_at' => '配送时间',
                'product_amount' => '商品总价',
                'amount' => '支付金额',
                'number' => '数量',
                'extra_fee' => '额外费用',
                'discount' => '优惠金额',
                'tax' => '税费',
                'invoice' => '发票',
                'type' => '类型',
                'sort' => '排序',
                'status' => '状态',
                'yj' => '佣金',
                'fx_id' => '分销用户id',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'created_by' => '创建用户',
                'updated_by' => '更新用户',
                'wlgs' => '物流公司',
                'wldh' => '物流单号',
            ]);
        } else {
            return array_merge(parent::attributeLabels(), [
                'id' => Yii::t('app', 'ID'),
                'store_id' => Yii::t('app', 'Store ID'),
                'parent_id' => Yii::t('app', 'Parent ID'),
                'user_id' => Yii::t('app', 'User ID'),
                'address_id' => Yii::t('app', 'Address ID'),
                'name' => Yii::t('app', 'Name'),
                'sn' => Yii::t('app', 'Sn'),
                'first_name' => Yii::t('app', 'First Name'),
                'last_name' => Yii::t('app', 'Last Name'),
                'country_id' => Yii::t('app', 'Country ID'),
                'country' => Yii::t('app', 'Country'),
                'province_id' => Yii::t('app', 'Province ID'),
                'province' => Yii::t('app', 'Province'),
                'city_id' => Yii::t('app', 'City ID'),
                'city' => Yii::t('app', 'City'),
                'district_id' => Yii::t('app', 'District ID'),
                'district' => Yii::t('app', 'District'),
                'address' => Yii::t('app', 'Address'),
                'address2' => Yii::t('app', 'Address2'),
                'postcode' => Yii::t('app', 'Postcode'),
                'mobile' => Yii::t('app', 'Mobile'),
                'email' => Yii::t('app', 'Email'),
                'distance' => Yii::t('app', 'Distance'),
                'remark' => Yii::t('app', 'Remark'),
                'payment_method' => Yii::t('app', 'Payment Method'),
                'payment_fee' => Yii::t('app', 'Payment Fee'),
                'payment_status' => Yii::t('app', 'Payment Status'),
                'paid_at' => Yii::t('app', 'Paid At'),
                'stock_deducted_at' => Yii::t('app', 'Stock Deducted At'),
                'stock_refunded_at' => Yii::t('app', 'Stock Refunded At'),
                'shipment_id' => Yii::t('app', 'Shipment ID'),
                'shipment_name' => Yii::t('app', 'Shipment Name'),
                'shipment_fee' => Yii::t('app', 'Shipment Fee'),
                'shipment_fee_deducted_at' => Yii::t('app', 'Shipment Fee Deducted At'),
                'shipment_status' => Yii::t('app', 'Shipment Status'),
                'logistics_review_status' => Yii::t('app', 'Logistics Review Status'),
                'logistics_reviewed_at' => Yii::t('app', 'Logistics Reviewed At'),
                'logistics_reviewed_by' => Yii::t('app', 'Logistics Reviewed By'),
                'logistics_review_remark' => Yii::t('app', 'Logistics Review Remark'),
                'shipped_at' => Yii::t('app', 'Shipped At'),
                'product_amount' => Yii::t('app', 'Product Amount'),
                'amount' => Yii::t('app', 'Amount'),
                'number' => Yii::t('app', 'Number'),
                'extra_fee' => Yii::t('app', 'Extra Fee'),
                'discount' => Yii::t('app', 'Discount'),
                'tax' => Yii::t('app', 'Tax'),
                'invoice' => Yii::t('app', 'Invoice'),
                'type' => Yii::t('app', 'Type'),
                'sort' => Yii::t('app', 'Sort'),
                'status' => Yii::t('app', 'Status'),
                'created_at' => Yii::t('app', 'Created At'),
                'updated_at' => Yii::t('app', 'Updated At'),
                'created_by' => Yii::t('app', 'Created By'),
                'updated_by' => Yii::t('app', 'Updated By'),
            ]);
        }
    }

    public function deductStockIfNeeded($deductedAt = null)
    {
        $deductedAt = $deductedAt ?: time();
        $orders = (int)$this->parent_id === 0 ? self::find()->where(['parent_id' => $this->id])->all() : [$this];
        $hasChildOrders = count($orders) > 0;
        if (!$hasChildOrders) {
            $orders = [$this];
        }

        foreach ($orders as $order) {
            $claimed = self::updateAll(['stock_deducted_at' => $deductedAt], ['id' => $order->id, 'stock_deducted_at' => 0]);
            if (!$claimed) {
                continue;
            }

            $orderProducts = OrderProduct::find()->where(['order_id' => $order->id])->all();
            foreach ($orderProducts as $orderProduct) {
                $number = (int)$orderProduct->number;
                if ($number <= 0) {
                    continue;
                }

                if (strlen((string)$orderProduct->product_attribute_value) > 0) {
                    $updated = ProductSku::updateAllCounters(
                        ['stock' => -$number],
                        ['and', ['product_id' => $orderProduct->product_id, 'attribute_value' => $orderProduct->product_attribute_value], ['>=', 'stock', $number]]
                    );
                } else {
                    $updated = Product::updateAllCounters(
                        ['stock' => -$number],
                        ['and', ['id' => $orderProduct->product_id], ['>=', 'stock', $number]]
                    );
                }

                if (!$updated) {
                    throw new \RuntimeException(Yii::t('mall', 'Stock is less than required'));
                }
            }
        }

        if ((int)$this->parent_id === 0) {
            self::updateAll(['stock_deducted_at' => $deductedAt], ['id' => $this->id, 'stock_deducted_at' => 0]);
            $this->stock_deducted_at = $deductedAt;
        }

        return true;
    }

    public function refundStockIfNeeded($refundedAt = null)
    {
        $refundedAt = $refundedAt ?: time();
        $orders = (int)$this->parent_id === 0 ? self::find()->where(['parent_id' => $this->id])->all() : [$this];
        if (!count($orders)) {
            $orders = [$this];
        }

        foreach ($orders as $order) {
            $claimed = self::updateAll(
                ['stock_refunded_at' => $refundedAt],
                ['and', ['id' => $order->id, 'stock_refunded_at' => 0], ['>', 'stock_deducted_at', 0]]
            );
            if (!$claimed) {
                continue;
            }

            $orderProducts = OrderProduct::find()->where(['order_id' => $order->id])->all();
            foreach ($orderProducts as $orderProduct) {
                $number = (int)$orderProduct->number;
                if ($number <= 0) {
                    continue;
                }

                if (strlen((string)$orderProduct->product_attribute_value) > 0) {
                    ProductSku::updateAllCounters(
                        ['stock' => $number],
                        ['product_id' => $orderProduct->product_id, 'attribute_value' => $orderProduct->product_attribute_value]
                    );
                } else {
                    Product::updateAllCounters(['stock' => $number], ['id' => $orderProduct->product_id]);
                }
            }
        }

        if ((int)$this->parent_id === 0) {
            self::updateAll(
                ['stock_refunded_at' => $refundedAt],
                ['and', ['id' => $this->id, 'stock_refunded_at' => 0], ['>', 'stock_deducted_at', 0]]
            );
            $this->stock_refunded_at = $refundedAt;
        }

        return true;
    }

    public function markRefunded($refundedAt = null)
    {
        $refundedAt = $refundedAt ?: time();
        if ((int)$this->parent_id !== 0) {
            throw new \RuntimeException('Please refund the parent order');
        }
        if ((int)$this->payment_method !== (int)self::PAYMENT_METHOD_PAY) {
            throw new \RuntimeException('Only online payment orders can be refunded');
        }
        if (!in_array((int)$this->payment_status, [self::PAYMENT_STATUS_PAID, self::PAYMENT_STATUS_REFUND], true)) {
            throw new \RuntimeException('Only paid orders can be refunded');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            if ((int)$this->payment_status !== (int)self::PAYMENT_STATUS_REFUND) {
                $this->payment_status = self::PAYMENT_STATUS_REFUND;
                $this->status = self::PAYMENT_STATUS_REFUND;
                if (!$this->save()) {
                    Yii::$app->logSystem->db($this->errors);
                    throw new \RuntimeException(json_encode($this->errors, JSON_UNESCAPED_UNICODE));
                }
                OrderLog::create($this->id, $this->status, '', null, $this->user_id);
            }

            $children = self::find()->where(['parent_id' => $this->id])->all();
            foreach ($children as $child) {
                if ((int)$child->payment_status === (int)self::PAYMENT_STATUS_REFUND) {
                    continue;
                }
                if ((int)$child->payment_status !== (int)self::PAYMENT_STATUS_PAID) {
                    throw new \RuntimeException('Child order payment status cannot be refunded');
                }

                $child->payment_status = self::PAYMENT_STATUS_REFUND;
                $child->status = self::PAYMENT_STATUS_REFUND;
                if (!$child->save()) {
                    Yii::$app->logSystem->db($child->errors);
                    throw new \RuntimeException(json_encode($child->errors, JSON_UNESCAPED_UNICODE));
                }
                OrderLog::create($child->id, $child->status, '', null, $child->user_id);
            }

            $this->refundStockIfNeeded($refundedAt);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    public function markShipped($shipmentId = null, $shipmentName = null, $shippedAt = null, $shipmentFee = null)
    {
        $shippedAt = $shippedAt ?: time();
        if ((int)$this->payment_status === (int)self::PAYMENT_STATUS_REFUND) {
            throw new \RuntimeException('Refunded orders cannot be shipped');
        }
        if (!in_array((int)$this->payment_status, [self::PAYMENT_STATUS_COD, self::PAYMENT_STATUS_PAID], true)) {
            throw new \RuntimeException('Only paid orders can be shipped');
        }

        $orders = (int)$this->parent_id === 0 ? self::find()->where(['parent_id' => $this->id])->all() : [$this];
        $hasChildOrders = count($orders) > 0;
        if (!$hasChildOrders) {
            $orders = [$this];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($orders as $order) {
                if ((int)$order->payment_status === (int)self::PAYMENT_STATUS_REFUND) {
                    throw new \RuntimeException('Refunded orders cannot be shipped');
                }
                if (!in_array((int)$order->payment_status, [self::PAYMENT_STATUS_COD, self::PAYMENT_STATUS_PAID], true)) {
                    throw new \RuntimeException('Only paid orders can be shipped');
                }

                $wasShipped = (int)$order->shipment_status >= self::SHIPMENT_STATUS_SHIPPING;
                $order->shipment_status = self::SHIPMENT_STATUS_SHIPPING;
                $order->status = self::SHIPMENT_STATUS_SHIPPING;
                $order->shipped_at = $order->shipped_at ?: $shippedAt;
                if ($shipmentId !== null) {
                    $order->shipment_id = $shipmentId;
                }
                if ($shipmentName !== null) {
                    $order->shipment_name = $shipmentName;
                }
                if ($shipmentFee !== null && ((int)$this->parent_id !== 0 || !$hasChildOrders)) {
                    $order->shipment_fee = round((float)$shipmentFee, 2);
                }
                if (!$order->save()) {
                    Yii::$app->logSystem->db($order->errors);
                    throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
                }
                $order->deductShipmentFeeIfNeeded($shippedAt);
                if (!$wasShipped) {
                    OrderLog::create($order->id, self::SHIPMENT_STATUS_SHIPPING, '', null, $order->user_id);
                }
            }

            if ((int)$this->parent_id === 0) {
                $parentWasShipped = (int)$this->shipment_status >= self::SHIPMENT_STATUS_SHIPPING;
                $this->shipment_status = self::SHIPMENT_STATUS_SHIPPING;
                $this->status = self::SHIPMENT_STATUS_SHIPPING;
                $this->shipped_at = $this->shipped_at ?: $shippedAt;
                if ($shipmentId !== null) {
                    $this->shipment_id = $shipmentId;
                }
                if ($shipmentName !== null) {
                    $this->shipment_name = $shipmentName;
                }
                if (!$this->save()) {
                    Yii::$app->logSystem->db($this->errors);
                    throw new \RuntimeException(json_encode($this->errors, JSON_UNESCAPED_UNICODE));
                }
                if (!$hasChildOrders) {
                    $this->deductShipmentFeeIfNeeded($shippedAt);
                }
                if (!$parentWasShipped) {
                    OrderLog::create($this->id, self::SHIPMENT_STATUS_SHIPPING, '', null, $this->user_id);
                }
            } else {
                $this->syncParentShipmentStatus();
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    public function deductShipmentFeeIfNeeded($deductedAt = null)
    {
        $deductedAt = $deductedAt ?: time();
        $fee = round((float)$this->shipment_fee, 2);
        if ($fee <= 0) {
            return true;
        }
        if ((int)$this->shipment_fee_deducted_at > 0) {
            return true;
        }
        if ((int)$this->store_id <= 0) {
            throw new \RuntimeException('Order store is required for shipment fee deduction');
        }

        $store = Store::findOne((int)$this->store_id);
        if (!$store) {
            throw new \RuntimeException('Store not found for shipment fee deduction');
        }
        $original = round((float)$store->fund, 2);
        if ($original < $fee) {
            throw new \RuntimeException('Merchant deposit balance is insufficient for shipment fee');
        }

        $claimed = self::updateAll(
            ['shipment_fee_deducted_at' => $deductedAt],
            ['and', ['id' => $this->id, 'shipment_fee_deducted_at' => 0], ['>', 'shipment_fee', 0]]
        );
        if (!$claimed) {
            return true;
        }

        $updated = Store::updateAllCounters([
            'fund' => -$fee,
            'consume_amount' => $fee,
            'consume_count' => 1,
        ], ['and', ['id' => (int)$this->store_id], ['>=', 'fund', $fee]]);
        if (!$updated) {
            throw new \RuntimeException('Merchant deposit balance is insufficient for shipment fee');
        }

        $log = new FundLog();
        $log->store_id = (int)$this->store_id;
        $log->user_id = $this->currentOperatorId();
        $log->name = '物流费扣费：订单 #' . $this->id;
        $log->change = -$fee;
        $log->original = $original;
        $log->balance = $original - $fee;
        $log->remark = 'shipment_fee_deduction order_sn=' . $this->sn;
        $log->type = FundLog::TYPE_CONSUME;
        if (!$log->save()) {
            Yii::$app->logSystem->db($log->errors);
            throw new \RuntimeException(json_encode($log->errors, JSON_UNESCAPED_UNICODE));
        }

        $this->shipment_fee_deducted_at = $deductedAt;
        return true;
    }

    private function currentOperatorId()
    {
        if (isset(Yii::$app->user) && !Yii::$app->user->getIsGuest()) {
            return Yii::$app->user->id;
        }

        return $this->updated_by ?: ($this->created_by ?: 1);
    }

    public function markReceived($receivedAt = null)
    {
        $receivedAt = $receivedAt ?: time();
        if ((int)$this->payment_status === (int)self::PAYMENT_STATUS_REFUND) {
            throw new \RuntimeException(Yii::t('app', 'Invalid id'));
        }
        if ((int)$this->shipment_status === (int)self::SHIPMENT_STATUS_RECEIVED) {
            return true;
        }
        if ((int)$this->shipment_status !== (int)self::SHIPMENT_STATUS_SHIPPING) {
            throw new \RuntimeException(Yii::t('app', 'Only shipped orders can be received'));
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $orders = (int)$this->parent_id === 0 ? self::find()->where(['parent_id' => $this->id])->all() : [$this];
            if (!count($orders)) {
                $orders = [$this];
            }

            foreach ($orders as $order) {
                if ((int)$order->payment_status === (int)self::PAYMENT_STATUS_REFUND) {
                    throw new \RuntimeException(Yii::t('app', 'Invalid id'));
                }
                if ((int)$order->shipment_status === (int)self::SHIPMENT_STATUS_RECEIVED) {
                    continue;
                }
                if ((int)$order->shipment_status !== (int)self::SHIPMENT_STATUS_SHIPPING) {
                    throw new \RuntimeException(Yii::t('app', 'Only shipped orders can be received'));
                }

                $order->shipment_status = self::SHIPMENT_STATUS_RECEIVED;
                $order->status = self::SHIPMENT_STATUS_RECEIVED;
                if (!$order->shipped_at) {
                    $order->shipped_at = $receivedAt;
                }
                if (!$order->save()) {
                    Yii::$app->logSystem->db($order->errors);
                    throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
                }
                OrderLog::create($order->id, self::SHIPMENT_STATUS_RECEIVED, '', null, $order->user_id);
            }

            if ((int)$this->parent_id === 0) {
                if ((int)$this->shipment_status !== (int)self::SHIPMENT_STATUS_RECEIVED) {
                    $this->shipment_status = self::SHIPMENT_STATUS_RECEIVED;
                    $this->status = self::SHIPMENT_STATUS_RECEIVED;
                    if (!$this->shipped_at) {
                        $this->shipped_at = $receivedAt;
                    }
                    if (!$this->save()) {
                        Yii::$app->logSystem->db($this->errors);
                        throw new \RuntimeException(json_encode($this->errors, JSON_UNESCAPED_UNICODE));
                    }
                    OrderLog::create($this->id, self::SHIPMENT_STATUS_RECEIVED, '', null, $this->user_id);
                }
            } else {
                $parentBeforeStatus = self::find()->select('shipment_status')->where(['id' => $this->parent_id])->scalar();
                $this->syncParentShipmentStatus();
                if ((int)$parentBeforeStatus !== self::SHIPMENT_STATUS_RECEIVED) {
                    $parent = self::findOne($this->parent_id);
                    if ($parent && (int)$parent->shipment_status === self::SHIPMENT_STATUS_RECEIVED) {
                        OrderLog::create($parent->id, $parent->status, '', null, $parent->user_id);
                    }
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    public function setLogisticsStatus(int $targetStatus, $changedAt = null)
    {
        $changedAt = $changedAt ?: time();
        if (!in_array($targetStatus, [self::SHIPMENT_STATUS_PREPARING, self::SHIPMENT_STATUS_SHIPPING, self::SHIPMENT_STATUS_RECEIVED], true)) {
            throw new \RuntimeException('Unsupported logistics status');
        }
        if ((int)$this->payment_status === (int)self::PAYMENT_STATUS_REFUND) {
            throw new \RuntimeException('Refunded orders cannot change logistics status');
        }
        if (!in_array((int)$this->payment_status, [self::PAYMENT_STATUS_COD, self::PAYMENT_STATUS_PAID], true)) {
            throw new \RuntimeException('Only paid orders can change logistics status');
        }
        if ($targetStatus === self::SHIPMENT_STATUS_RECEIVED && (int)$this->shipment_status !== self::SHIPMENT_STATUS_SHIPPING) {
            throw new \RuntimeException('Only shipped orders can be received');
        }
        if ($targetStatus === self::SHIPMENT_STATUS_SHIPPING && (int)$this->shipment_status < self::SHIPMENT_STATUS_PREPARING) {
            throw new \RuntimeException('Preparing status is required before shipping status batch update');
        }
        if ((int)$this->shipment_status === $targetStatus) {
            return false;
        }

        $this->shipment_status = $targetStatus;
        $this->status = $targetStatus;
        if ($targetStatus >= self::SHIPMENT_STATUS_SHIPPING && !$this->shipped_at) {
            $this->shipped_at = $changedAt;
        }
        if (!$this->save()) {
            Yii::$app->logSystem->db($this->errors);
            throw new \RuntimeException(json_encode($this->errors, JSON_UNESCAPED_UNICODE));
        }
        OrderLog::create($this->id, $targetStatus, '', null, $this->user_id);
        if ((int)$this->parent_id > 0) {
            $this->syncParentShipmentStatus();
        }

        return true;
    }

    public static function batchSetLogisticsStatus(array $orderIds, int $targetStatus, bool $apply = false, ?int $storeId = null): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $result = [
            'targetStatus' => $targetStatus,
            'apply' => $apply,
            'scanned' => 0,
            'eligible' => 0,
            'updated' => 0,
            'skipped' => [],
            'updatedIds' => [],
            'dryRunIds' => [],
        ];
        if (!$orderIds) {
            return $result;
        }

        $query = self::find()
            ->where(['id' => $orderIds])
            ->andWhere(['>', 'status', self::STATUS_DELETED])
            ->orderBy(['id' => SORT_ASC]);
        if ($storeId !== null) {
            $query->andWhere(['store_id' => $storeId])->andWhere(['>', 'parent_id', 0]);
        }

        $orders = $query->all();
        $result['scanned'] = count($orders);
        $found = [];
        foreach ($orders as $order) {
            $found[] = (int)$order->id;
            $reason = self::logisticsStatusBatchSkipReason($order, $targetStatus);
            if ($reason !== '') {
                $result['skipped'][] = [
                    'id' => (int)$order->id,
                    'reason' => $reason,
                ];
                continue;
            }

            $result['eligible']++;
            if (!$apply) {
                $result['dryRunIds'][] = (int)$order->id;
                continue;
            }

            if ($order->setLogisticsStatus($targetStatus)) {
                $result['updated']++;
                $result['updatedIds'][] = (int)$order->id;
            }
        }

        foreach (array_diff($orderIds, $found) as $missingId) {
            $result['skipped'][] = [
                'id' => (int)$missingId,
                'reason' => 'not found or out of scope',
            ];
        }

        return $result;
    }

    private static function logisticsStatusBatchSkipReason(Order $order, int $targetStatus): string
    {
        if ((int)$order->payment_status === self::PAYMENT_STATUS_REFUND) {
            return 'refunded order';
        }
        if (!in_array((int)$order->payment_status, [self::PAYMENT_STATUS_COD, self::PAYMENT_STATUS_PAID], true)) {
            return 'not paid/COD';
        }
        if ((int)$order->shipment_status === $targetStatus) {
            return 'already target status';
        }
        if ($targetStatus === self::SHIPMENT_STATUS_PREPARING && (int)$order->shipment_status >= self::SHIPMENT_STATUS_SHIPPING) {
            return 'already shipped or received';
        }
        if ($targetStatus === self::SHIPMENT_STATUS_SHIPPING && (int)$order->shipment_status >= self::SHIPMENT_STATUS_SHIPPING) {
            return 'already shipped or received';
        }
        if ($targetStatus === self::SHIPMENT_STATUS_SHIPPING && (int)$order->shipment_status < self::SHIPMENT_STATUS_PREPARING) {
            return 'not preparing';
        }
        if ($targetStatus === self::SHIPMENT_STATUS_RECEIVED && (int)$order->shipment_status !== self::SHIPMENT_STATUS_SHIPPING) {
            return 'not shipping';
        }

        return '';
    }

    public function reviewLogistics(int $reviewStatus, string $remark = '', $reviewedAt = null, $reviewedBy = null)
    {
        $reviewedAt = $reviewedAt ?: time();
        $reviewedBy = $reviewedBy ?: $this->currentOperatorId();
        if (!in_array($reviewStatus, [self::LOGISTICS_REVIEW_PASSED, self::LOGISTICS_REVIEW_REJECTED], true)) {
            throw new \RuntimeException('Unsupported logistics review status');
        }
        if ((int)$this->payment_status === self::PAYMENT_STATUS_REFUND) {
            throw new \RuntimeException('Refunded orders cannot be logistics reviewed');
        }
        if ((int)$this->shipment_status < self::SHIPMENT_STATUS_SHIPPING) {
            throw new \RuntimeException('Only shipped orders can be logistics reviewed');
        }
        if ((int)$this->logistics_review_status === $reviewStatus && (string)$this->logistics_review_remark === $remark) {
            return false;
        }

        $this->logistics_review_status = $reviewStatus;
        $this->logistics_reviewed_at = $reviewedAt;
        $this->logistics_reviewed_by = (int)$reviewedBy;
        $this->logistics_review_remark = mb_substr($remark, 0, 255, 'UTF-8');
        if (!$this->save()) {
            Yii::$app->logSystem->db($this->errors);
            throw new \RuntimeException(json_encode($this->errors, JSON_UNESCAPED_UNICODE));
        }

        return true;
    }

    public static function batchReviewLogistics(array $orderIds, int $reviewStatus, string $remark = '', bool $apply = false, ?int $storeId = null): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $result = [
            'reviewStatus' => $reviewStatus,
            'apply' => $apply,
            'scanned' => 0,
            'eligible' => 0,
            'updated' => 0,
            'skipped' => [],
            'updatedIds' => [],
            'dryRunIds' => [],
        ];
        if (!$orderIds) {
            return $result;
        }

        $query = self::find()
            ->where(['id' => $orderIds])
            ->andWhere(['>', 'status', self::STATUS_DELETED])
            ->orderBy(['id' => SORT_ASC]);
        if ($storeId !== null) {
            $query->andWhere(['store_id' => $storeId])->andWhere(['>', 'parent_id', 0]);
        }

        $orders = $query->all();
        $result['scanned'] = count($orders);
        $found = [];
        foreach ($orders as $order) {
            $found[] = (int)$order->id;
            $reason = self::logisticsReviewBatchSkipReason($order, $reviewStatus, $remark);
            if ($reason !== '') {
                $result['skipped'][] = [
                    'id' => (int)$order->id,
                    'reason' => $reason,
                ];
                continue;
            }

            $result['eligible']++;
            if (!$apply) {
                $result['dryRunIds'][] = (int)$order->id;
                continue;
            }

            if ($order->reviewLogistics($reviewStatus, $remark)) {
                $result['updated']++;
                $result['updatedIds'][] = (int)$order->id;
            }
        }

        foreach (array_diff($orderIds, $found) as $missingId) {
            $result['skipped'][] = [
                'id' => (int)$missingId,
                'reason' => 'not found or out of scope',
            ];
        }

        return $result;
    }

    private static function logisticsReviewBatchSkipReason(Order $order, int $reviewStatus, string $remark): string
    {
        if (!in_array($reviewStatus, [self::LOGISTICS_REVIEW_PASSED, self::LOGISTICS_REVIEW_REJECTED], true)) {
            return 'unsupported review status';
        }
        if ((int)$order->payment_status === self::PAYMENT_STATUS_REFUND) {
            return 'refunded order';
        }
        if ((int)$order->shipment_status < self::SHIPMENT_STATUS_SHIPPING) {
            return 'not shipped';
        }
        if ((int)$order->logistics_review_status === $reviewStatus && (string)$order->logistics_review_remark === $remark) {
            return 'already target review status';
        }

        return '';
    }

    public function syncParentShipmentStatus()
    {
        if ((int)$this->parent_id === 0) {
            return true;
        }

        $parent = self::findOne($this->parent_id);
        if (!$parent) {
            return true;
        }

        $children = self::find()->where(['parent_id' => $parent->id])->all();
        if (!count($children)) {
            return true;
        }

        $allShipped = true;
        $allReceived = true;
        foreach ($children as $child) {
            if ((int)$child->shipment_status < self::SHIPMENT_STATUS_SHIPPING) {
                $allShipped = false;
            }
            if ((int)$child->shipment_status < self::SHIPMENT_STATUS_RECEIVED) {
                $allReceived = false;
            }
        }

        if ($allReceived) {
            $parent->shipment_status = self::SHIPMENT_STATUS_RECEIVED;
            $parent->status = self::SHIPMENT_STATUS_RECEIVED;
        } elseif ($allShipped) {
            $parent->shipment_status = self::SHIPMENT_STATUS_SHIPPING;
            $parent->status = self::SHIPMENT_STATUS_SHIPPING;
        } else {
            return true;
        }

        if (!$parent->shipped_at && $parent->shipment_status >= self::SHIPMENT_STATUS_SHIPPING) {
            $parent->shipped_at = time();
        }
        if (!$parent->save()) {
            Yii::$app->logSystem->db($parent->errors);
            throw new \RuntimeException(json_encode($parent->errors, JSON_UNESCAPED_UNICODE));
        }

        return true;
    }
}
