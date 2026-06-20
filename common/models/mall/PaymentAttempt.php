<?php

namespace common\models\mall;

use common\models\BaseModel;
use Yii;

/**
 * Payment attempt and callback audit log.
 */
class PaymentAttempt extends BaseModel
{
    const RESULT_PENDING = 'pending';
    const RESULT_SUCCESS = 'success';
    const RESULT_FAILED = 'failed';
    const RESULT_DISPLAY = 'display';
    const RESULT_IGNORED = 'ignored';

    public static function tableName()
    {
        return '{{%mall_payment_attempt}}';
    }

    public function rules()
    {
        return [
            [['store_id', 'order_id', 'user_id', 'processed_at', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['amount'], 'number'],
            [['payload'], 'string'],
            [['order_id'], 'required'],
            [['provider', 'event', 'result'], 'string', 'max' => 32],
            [['merchant_transaction_id', 'request_ip'], 'string', 'max' => 64],
            [['gateway_transaction_id'], 'string', 'max' => 128],
            [['business_key'], 'string', 'max' => 160],
            [['payload_hash'], 'string', 'max' => 64],
            [['currency', 'request_method'], 'string', 'max' => 16],
            [['error_message'], 'string', 'max' => 255],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'order_id' => Yii::t('app', 'Order ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'provider' => Yii::t('app', 'Provider'),
            'event' => Yii::t('app', 'Event'),
            'business_key' => Yii::t('app', 'Business Key'),
            'merchant_transaction_id' => Yii::t('app', 'Merchant Transaction ID'),
            'gateway_transaction_id' => Yii::t('app', 'Gateway Transaction ID'),
            'amount' => Yii::t('app', 'Amount'),
            'currency' => Yii::t('app', 'Currency'),
            'request_method' => Yii::t('app', 'Request Method'),
            'request_ip' => Yii::t('app', 'Request IP'),
            'payload' => Yii::t('app', 'Payload'),
            'payload_hash' => Yii::t('app', 'Payload Hash'),
            'result' => Yii::t('app', 'Result'),
            'error_message' => Yii::t('app', 'Error Message'),
            'processed_at' => Yii::t('app', 'Processed At'),
        ]);
    }

    public static function createForOrder(Order $order, $provider, $event, array $data = [])
    {
        try {
            $model = new self();
            $model->store_id = $order->store_id;
            $model->order_id = $order->id;
            $model->user_id = $order->user_id;
            $model->provider = (string)$provider;
            $model->event = (string)$event;
            $model->merchant_transaction_id = (string)($data['merchant_transaction_id'] ?? '');
            $model->gateway_transaction_id = (string)($data['gateway_transaction_id'] ?? '');
            $model->business_key = (string)($data['business_key'] ?? self::buildBusinessKey($provider, $event, $model->merchant_transaction_id, $order->id));
            $model->amount = (float)($data['amount'] ?? 0);
            $model->currency = (string)($data['currency'] ?? '');
            $model->request_method = (string)($data['request_method'] ?? '');
            $model->request_ip = (string)($data['request_ip'] ?? '');
            $model->payload = self::encodePayload($data['payload'] ?? null);
            $model->payload_hash = (string)($data['payload_hash'] ?? self::hashPayload($model->payload));
            $model->result = (string)($data['result'] ?? self::RESULT_PENDING);
            $model->error_message = mb_substr((string)($data['error_message'] ?? ''), 0, 255, 'UTF-8');
            $model->processed_at = (int)($data['processed_at'] ?? time());

            if (!$model->save()) {
                Yii::warning($model->errors, 'mall.payment_attempt.save_failed');
                return null;
            }

            return $model;
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment_attempt.create_failed');
            return null;
        }
    }

    protected static function encodePayload($payload)
    {
        if ($payload === null || $payload === '') {
            return '';
        }

        if (!is_string($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($payload === false) {
            return '';
        }

        return mb_substr($payload, 0, 6000, 'UTF-8');
    }

    protected static function buildBusinessKey($provider, $event, $merchantTransactionId, $orderId)
    {
        $key = $merchantTransactionId ?: ('order-' . $orderId);
        return mb_substr($provider . ':' . $event . ':' . $key, 0, 160, 'UTF-8');
    }

    protected static function hashPayload($payload)
    {
        return $payload === null || $payload === '' ? '' : hash('sha256', (string)$payload);
    }
}
