<?php

namespace frontend\modules\mall\controllers;

use common\models\BaseModel;
use common\models\mall\Order;
use common\models\mall\OrderLog;
use common\models\mall\PaymentAttempt;
use common\services\mall\OperationalPaymentConfigService;
use lianlianpay\v3sdk\model\Address;
use lianlianpay\v3sdk\model\Card;
use lianlianpay\v3sdk\model\Customer;
use lianlianpay\v3sdk\model\MerchantOrder;
use lianlianpay\v3sdk\model\PayRequest;
use lianlianpay\v3sdk\model\Product;
use lianlianpay\v3sdk\model\RequestPaymentData;
use lianlianpay\v3sdk\model\Shipping;
use lianlianpay\v3sdk\service\Payment;
use yii\db\ActiveRecord;
use yii\filters\AccessControl;
use Yii;
use \lianlianpay\v3sdk\core\PaySDK;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Class PaymentController
 * @package frontend\modules\mall\controllers
 * @author funson86 <funson86@gmail.com>
 */
class PaymentController extends BaseController
{
    public const MONGOYIA_PAYPAL_CREATE_ROUTE_V1 = 'MONGOYIA_PAYPAL_CREATE_ROUTE_V1';
    public const MONGOYIA_PAYPAL_RETURN_ROUTE_V1 = 'MONGOYIA_PAYPAL_RETURN_ROUTE_V1';
    public const MONGOYIA_PAYPAL_CANCEL_ROUTE_V1 = 'MONGOYIA_PAYPAL_CANCEL_ROUTE_V1';
    public const MONGOYIA_PAYPAL_WEBHOOK_ROUTE_V1 = 'MONGOYIA_PAYPAL_WEBHOOK_ROUTE_V1';

    public $modelClass = Order::class;

    protected function findPaymentOrder($id, $userScoped = true)
    {
        $query = $this->modelClass::find()->where(['id' => $id, 'parent_id' => 0]);
        if ($userScoped) {
            $query->andWhere(['user_id' => Yii::$app->user->id]);
        }

        return $query->one();
    }

    protected function requireUserPaymentOrder($id)
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['/mall/default/login', 'returnUrl' => Yii::$app->request->getUrl()]);
        }

        $model = $this->findPaymentOrder($id, true);
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'), ['/mall/user/order']);
        }

        return $model;
    }

    protected function canShowPaymentSuccess(Order $model)
    {
        return (int)$model->payment_method === (int)Order::PAYMENT_METHOD_COD
            || (int)$model->payment_status === (int)Order::PAYMENT_STATUS_PAID;
    }

    protected function isPaypalEnabled()
    {
        return $this->paymentProviderEnabled($this->paymentProviderConfig('paypal'));
    }

    protected function paymentProviderConfig($provider)
    {
        static $cache = [];
        $provider = strtolower((string)$provider);
        if (isset($cache[$provider])) {
            return $cache[$provider];
        }

        if ($provider === 'paypal') {
            $fallbacks = $this->paypalEnvFallbacks();
        } elseif ($provider === 'lianlian') {
            $fallbacks = $this->lianlianEnvFallbacks();
        } else {
            $fallbacks = $this->qpayEnvFallbacks();
        }
        try {
            $cache[$provider] = (new OperationalPaymentConfigService())->runtimeConfig($provider, $fallbacks);
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment.operational_config_fallback');
            $fallbacks['provider'] = $provider;
            $fallbacks['environment'] = 'test';
            $cache[$provider] = $fallbacks;
        }

        return $cache[$provider];
    }

    protected function qpayEnvFallbacks()
    {
        $authBasic = env('QPAY_AUTH_BASIC', '');
        $invoiceCode = env('QPAY_INVOICE_CODE', '');
        return [
            'enabled' => $authBasic !== '' && $invoiceCode !== '' ? '1' : '0',
            'auth_basic' => $authBasic,
            'invoice_code' => $invoiceCode,
            'auth_url' => env('QPAY_AUTH_URL', 'https://merchant.qpay.mn/v2/auth/token'),
            'invoice_url' => env('QPAY_INVOICE_URL', 'https://merchant.qpay.mn/v2/invoice'),
            'callback_base' => env('QPAY_CALLBACK_BASE', 'https://www.mongoyia.com'),
            'callback_secret' => env('QPAY_CALLBACK_SECRET', ''),
            'callback_hmac_secret' => env('QPAY_CALLBACK_HMAC_SECRET', ''),
            'callback_allowed_ips' => env('QPAY_CALLBACK_ALLOWED_IPS', ''),
            'callback_max_age_seconds' => env('QPAY_CALLBACK_MAX_AGE_SECONDS', 0),
        ];
    }

    protected function lianlianEnvFallbacks()
    {
        $merchantId = env('LIANLIAN_MERCHANT_ID', '');
        $publicKey = env('LIANLIAN_PUBLIC_KEY', '');
        $privateKey = env('LIANLIAN_PRIVATE_KEY', '');
        return [
            'enabled' => $merchantId !== '' && $publicKey !== '' && $privateKey !== '' ? '1' : '0',
            'merchant_id' => $merchantId,
            'sub_merchant_id' => env('LIANLIAN_SUB_MERCHANT_ID', ''),
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'callback_base' => env('LIANLIAN_CALLBACK_BASE', 'https://www.mongoyia.com'),
            'callback_secret' => env('LIANLIAN_CALLBACK_SECRET', ''),
            'callback_hmac_secret' => env('LIANLIAN_CALLBACK_HMAC_SECRET', ''),
            'callback_allowed_ips' => env('LIANLIAN_CALLBACK_ALLOWED_IPS', ''),
            'callback_max_age_seconds' => env('LIANLIAN_CALLBACK_MAX_AGE_SECONDS', 0),
        ];
    }

    protected function paypalEnvFallbacks()
    {
        $clientId = env('PAYPAL_CLIENT_ID', '');
        $clientSecret = env('PAYPAL_CLIENT_SECRET', '');
        return [
            'enabled' => env_bool('PAYPAL_ENABLED', false) && $clientId !== '' && $clientSecret !== '' ? '1' : '0',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'webhook_id' => env('PAYPAL_WEBHOOK_ID', ''),
            'callback_base' => env('PAYPAL_CALLBACK_BASE', 'https://www.mongoyia.com'),
            'return_path' => env('PAYPAL_RETURN_PATH', '/mall/payment/paypal-return'),
            'cancel_path' => env('PAYPAL_CANCEL_PATH', '/mall/payment/paypal-cancel'),
            'webhook_path' => env('PAYPAL_WEBHOOK_PATH', '/mall/payment/paypal-webhook'),
            'webhook_hmac_secret' => env('PAYPAL_WEBHOOK_HMAC_SECRET', ''),
            'currency' => env('PAYPAL_CURRENCY', 'USD'),
        ];
    }

    protected function paymentProviderEnabled(array $config)
    {
        return !empty($config['enabled']) && !in_array(strtolower((string)$config['enabled']), ['0', 'false', 'off', 'no'], true);
    }

    protected function paypalDisabledRoute($route)
    {
        if (!$this->isPaypalEnabled()) {
            Yii::warning(['route' => $route], 'mall.payment.paypal_disabled');
            Yii::$app->response->format = Response::FORMAT_JSON;
            Yii::$app->response->statusCode = 404;
            return [
                'success' => false,
                'code' => 'PAYPAL_DISABLED',
                'message' => 'PayPal payment is disabled',
            ];
        }

        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->statusCode = 503;
        return [
            'success' => false,
            'code' => 'PAYPAL_NOT_READY',
            'message' => 'PayPal payment is not ready for live traffic',
        ];
    }

    protected function extractPaidAmount()
    {
        $request = Yii::$app->request;
        $payload = array_merge($request->get(), $request->post());
        $amountKeys = ['amount', 'order_amount', 'paid_amount', 'total_amount', 'payment_amount'];
        $containerKeys = ['data', 'order', 'merchant_order', 'payment', 'transaction'];
        $amount = $this->findPaymentPayloadValue($payload, $amountKeys, $containerKeys);
        if ($amount !== '' && is_numeric($amount)) {
            return (float)$amount;
        }

        $rawBody = $request->rawBody;
        if ($rawBody) {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                $amount = $this->findPaymentPayloadValue($json, $amountKeys, $containerKeys);
                if ($amount !== '' && is_numeric($amount)) {
                    return (float)$amount;
                }
            }
        }

        return null;
    }

    protected function extractPaymentStatus()
    {
        $request = Yii::$app->request;
        $payload = array_merge($request->get(), $request->post());
        $statusKeys = ['payment_status', 'status', 'trade_status', 'order_status', 'pay_status', 'result_code'];
        $containerKeys = ['data', 'order', 'merchant_order', 'payment', 'transaction'];
        $status = $this->findPaymentPayloadValue($payload, $statusKeys, $containerKeys);
        if ($status !== '') {
            return strtoupper((string)$status);
        }

        $rawBody = $request->rawBody;
        if ($rawBody) {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                $status = $this->findPaymentPayloadValue($json, $statusKeys, $containerKeys);
                if ($status !== '') {
                    return strtoupper((string)$status);
                }
            }
        }

        return '';
    }

    protected function extractMerchantTransactionId()
    {
        $request = Yii::$app->request;
        $payload = array_merge($request->get(), $request->post());
        $transactionKeys = ['merchant_transaction_id', 'merchant_order_id', 'sender_invoice_no', 'out_trade_no', 'order_no'];
        $containerKeys = ['data', 'order', 'merchant_order', 'payment', 'transaction'];
        $transactionId = $this->findPaymentPayloadValue($payload, $transactionKeys, $containerKeys);
        if ($transactionId !== '') {
            return $transactionId;
        }

        $rawBody = $request->rawBody;
        if ($rawBody) {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                return $this->findPaymentPayloadValue($json, $transactionKeys, $containerKeys);
            }
        }

        return '';
    }

    protected function extractGatewayTransactionId()
    {
        $request = Yii::$app->request;
        $payload = array_merge($request->get(), $request->post());
        $transactionKeys = ['gateway_transaction_id', 'transaction_id', 'payment_id', 'invoice_id', 'qpay_payment_id', 'acquirer_reference_no'];
        $containerKeys = ['data', 'order', 'merchant_order', 'payment', 'transaction'];
        $transactionId = $this->findPaymentPayloadValue($payload, $transactionKeys, $containerKeys);
        if ($transactionId !== '') {
            return $transactionId;
        }

        $rawBody = $request->rawBody;
        if ($rawBody) {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                return $this->findPaymentPayloadValue($json, $transactionKeys, $containerKeys);
            }
        }

        return '';
    }

    protected function findPaymentPayloadValue(array $payload, array $keys, array $containerKeys = [])
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                return (string)$payload[$key];
            }
        }

        foreach ($containerKeys as $containerKey) {
            if (isset($payload[$containerKey]) && is_array($payload[$containerKey])) {
                $value = $this->findPaymentPayloadValue($payload[$containerKey], $keys, $containerKeys);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }
            $nestedValue = $this->findPaymentPayloadValue($value, $keys, $containerKeys);
            if ($nestedValue !== '') {
                return $nestedValue;
            }
        }

        return '';
    }

    protected function assertMerchantTransactionId($expected)
    {
        $actual = $this->extractMerchantTransactionId();
        if ($actual === '') {
            Yii::warning([
                'expected' => $expected,
            ], 'mall.payment.merchant_transaction_missing');
            throw new BadRequestHttpException('Merchant transaction id is required');
        }

        if ($actual !== (string)$expected) {
            Yii::warning([
                'expected' => $expected,
                'actual' => $actual,
            ], 'mall.payment.merchant_transaction_mismatch');
            throw new BadRequestHttpException('Merchant transaction id mismatch');
        }

        return true;
    }

    protected function assertSuccessfulPaymentStatus($provider)
    {
        $status = $this->extractPaymentStatus();
        $successStatuses = ['SUCCESS', 'PAID', 'PAY_SUCCESS', 'PS', 'COMPLETED'];

        if (!in_array($status, $successStatuses, true)) {
            Yii::warning([
                'provider' => $provider,
                'status' => $status,
            ], 'mall.payment.status_not_success');
            throw new BadRequestHttpException('Payment status is not successful');
        }

        return true;
    }

    protected function assertCallbackSecret($envKey)
    {
        return $this->assertCallbackSecretValue(env($envKey, ''));
    }

    protected function assertCallbackSecretValue($secret)
    {
        $secret = (string)$secret;
        if ($secret === '') {
            return true;
        }

        $request = Yii::$app->request;
        $provided = $request->headers->get('X-Mongoyia-Payment-Secret')
            ?: $request->post('callback_secret')
            ?: $request->get('callback_secret')
            ?: $request->post('token')
            ?: $request->get('token');

        if (!is_string($provided) || !hash_equals($secret, $provided)) {
            throw new BadRequestHttpException('Invalid payment callback secret');
        }

        return true;
    }

    protected function assertCallbackSource($envKey)
    {
        return $this->assertCallbackSourceValue(env($envKey, ''));
    }

    protected function assertCallbackSourceValue($allowed)
    {
        $allowed = trim((string)$allowed);
        if ($allowed === '') {
            return true;
        }

        $clientIp = Yii::$app->request->userIP;
        foreach (array_filter(array_map('trim', explode(',', $allowed))) as $rule) {
            if ($this->ipMatchesRule($clientIp, $rule)) {
                return true;
            }
        }

        Yii::warning([
            'client_ip' => $clientIp,
            'allowed' => $allowed,
        ], 'mall.payment.callback_ip_denied');
        throw new BadRequestHttpException('Payment callback IP is not allowed');
    }

    protected function ipMatchesRule($ip, $rule)
    {
        if ($ip === $rule) {
            return true;
        }

        if (strpos($rule, '/') === false) {
            return false;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $bits = (int)$bits;
        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    protected function assertCallbackTimestamp($envKey)
    {
        return $this->assertCallbackTimestampValue(env($envKey, 0));
    }

    protected function assertCallbackTimestampValue($maxAge)
    {
        $maxAge = (int)$maxAge;
        if ($maxAge <= 0) {
            return true;
        }

        $timestamp = $this->extractCallbackTimestamp();
        if ($timestamp <= 0) {
            throw new BadRequestHttpException('Payment callback timestamp is required');
        }

        if ($timestamp > 1000000000000) {
            $timestamp = (int)floor($timestamp / 1000);
        }

        $age = abs(time() - $timestamp);
        if ($age > $maxAge) {
            Yii::warning([
                'timestamp' => $timestamp,
                'age' => $age,
                'max_age' => $maxAge,
            ], 'mall.payment.callback_timestamp_expired');
            throw new BadRequestHttpException('Payment callback timestamp expired');
        }

        return true;
    }

    protected function extractCallbackTimestamp()
    {
        $request = Yii::$app->request;
        $timestamp = $request->headers->get('X-Mongoyia-Payment-Timestamp')
            ?: $request->headers->get('X-Payment-Timestamp')
            ?: $request->post('timestamp')
            ?: $request->get('timestamp')
            ?: $request->post('time')
            ?: $request->get('time');

        return is_numeric($timestamp) ? (int)$timestamp : 0;
    }

    protected function assertCallbackSignature($envKey)
    {
        return $this->assertCallbackSignatureValue(env($envKey, ''));
    }

    protected function assertCallbackSignatureValue($secret)
    {
        $secret = (string)$secret;
        if ($secret === '') {
            return true;
        }

        $signature = $this->extractCallbackSignature();
        if ($signature === '') {
            throw new BadRequestHttpException('Payment callback signature is required');
        }

        $signature = preg_replace('/^sha256=/i', '', $signature);
        $expected = hash_hmac('sha256', $this->buildCallbackSignaturePayload(), $secret);
        if (!is_string($signature) || !hash_equals($expected, $signature)) {
            throw new BadRequestHttpException('Invalid payment callback signature');
        }

        return true;
    }

    protected function extractCallbackSignature()
    {
        $request = Yii::$app->request;
        $signature = $request->headers->get('X-Mongoyia-Payment-Signature')
            ?: $request->headers->get('X-Payment-Signature')
            ?: $request->headers->get('X-Signature')
            ?: $request->post('signature')
            ?: $request->get('signature')
            ?: $request->post('sign')
            ?: $request->get('sign');

        return is_string($signature) ? trim($signature) : '';
    }

    protected function buildCallbackSignaturePayload()
    {
        $request = Yii::$app->request;
        $payload = [
            'get' => $request->get(),
            'post' => $request->post(),
        ];
        $timestamp = $request->headers->get('X-Mongoyia-Payment-Timestamp')
            ?: $request->headers->get('X-Payment-Timestamp');
        if ($timestamp !== null && $timestamp !== '') {
            $payload['headers'] = ['timestamp' => (string)$timestamp];
        }
        if ($request->rawBody !== '') {
            $json = json_decode($request->rawBody, true);
            if (is_array($json)) {
                $payload['raw'] = $json;
            }
        }

        $this->removePaymentSignatureFields($payload);
        $this->ksortRecursive($payload);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function removePaymentSignatureFields(array &$payload)
    {
        foreach (['signature', 'sign', 'hmac', 'x_signature', 'callback_secret', 'token'] as $key) {
            unset($payload[$key]);
        }

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->removePaymentSignatureFields($value);
            }
        }
        unset($value);
    }

    protected function ksortRecursive(array &$payload)
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        unset($value);
    }

    protected function buildCallbackUrl($baseEnvKey, $path, $id, $secretEnvKey = null)
    {
        $base = rtrim(env($baseEnvKey, 'https://www.mongoyia.com'), '/');
        $params = ['id' => $id];
        if ($secretEnvKey) {
            $secret = env($secretEnvKey, '');
            if ($secret !== '') {
                $params['callback_secret'] = $secret;
            }
        }

        return $base . $path . '?' . http_build_query($params);
    }

    protected function buildOperationalCallbackUrl(array $config, $baseEnvKey, $path, $id, $secretCode = null, $secretEnvKey = null)
    {
        // Compatibility marker: buildCallbackUrl('QPAY_CALLBACK_BASE' and buildCallbackUrl('LIANLIAN_CALLBACK_BASE' fallbacks remain supported.
        $base = rtrim((string)($config['callback_base'] ?? ''), '/');
        if ($base === '') {
            return $this->buildCallbackUrl($baseEnvKey, $path, $id, $secretEnvKey);
        }

        $params = ['id' => $id];
        if ($secretCode) {
            $secret = (string)($config[$secretCode] ?? '');
            if ($secret === '' && $secretEnvKey) {
                $secret = env($secretEnvKey, '');
            }
            if ($secret !== '') {
                $params['callback_secret'] = $secret;
            }
        }

        return $base . $path . '?' . http_build_query($params);
    }

    protected function paymentRequestPayload()
    {
        $request = Yii::$app->request;
        $payload = [
            'get' => $request->get(),
            'post' => $request->post(),
        ];
        if ($request->rawBody !== '') {
            $payload['raw'] = $request->rawBody;
        }

        return $payload;
    }

    protected function logPaymentAttempt(Order $model, $provider, $event, array $data = [])
    {
        $request = Yii::$app->request;
        $merchantTransactionId = (string)($data['merchant_transaction_id'] ?? '');
        if ($event === 'callback') {
            $merchantTransactionId = $this->extractMerchantTransactionId();
            $data['merchant_transaction_id'] = $merchantTransactionId;
            $data['gateway_transaction_id'] = $this->extractGatewayTransactionId();
        }

        $data += [
            'business_key' => $provider . ':' . $event . ':' . ($merchantTransactionId ?: ('order-' . $model->id)),
            'amount' => $this->extractPaidAmount() ?? (float)$model->amount,
            'request_method' => $request->method,
            'request_ip' => $request->userIP,
            'payload' => $this->paymentRequestPayload(),
            'processed_at' => time(),
        ];

        if ($event === 'create' && ($data['result'] ?? '') === PaymentAttempt::RESULT_FAILED) {
            $recentAttempt = PaymentAttempt::find()
                ->where([
                    'order_id' => $model->id,
                    'provider' => $provider,
                    'event' => $event,
                    'business_key' => $data['business_key'],
                    'result' => PaymentAttempt::RESULT_FAILED,
                    'error_message' => (string)($data['error_message'] ?? ''),
                ])
                ->andWhere(['>=', 'created_at', time() - 300])
                ->orderBy(['id' => SORT_DESC])
                ->one();
            if ($recentAttempt) {
                return $recentAttempt;
            }
        }

        return PaymentAttempt::createForOrder($model, $provider, $event, $data);
    }

    protected function updatePaymentAttemptResult($attempt, $result, $errorMessage = '')
    {
        if (!$attempt instanceof PaymentAttempt) {
            return false;
        }

        try {
            $attempt->result = $result;
            $attempt->error_message = mb_substr((string)$errorMessage, 0, 255, 'UTF-8');
            $attempt->processed_at = time();
            if (!$attempt->save()) {
                Yii::warning($attempt->errors, 'mall.payment_attempt.update_failed');
                return false;
            }
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment_attempt.update_failed');
            return false;
        }

        return true;
    }

    protected function paymentGatewayResponse($content, $statusCode = 200)
    {
        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->statusCode = $statusCode;
        Yii::$app->response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $content;
    }

    protected function paymentCallbackLockName($provider, Order $model, $merchantTransactionId = '')
    {
        $key = $merchantTransactionId ?: (string)$model->id;
        return 'mongoyia:payment:' . $provider . ':' . preg_replace('/[^a-zA-Z0-9:_-]/', '_', $key);
    }

    protected function acquirePaymentCallbackLock($lockName, $timeout = 5)
    {
        try {
            return (int)Yii::$app->db->createCommand('SELECT GET_LOCK(:name, :timeout)', [
                ':name' => $lockName,
                ':timeout' => (int)$timeout,
            ])->queryScalar() === 1;
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment.lock_acquire_failed');
            return true;
        }
    }

    protected function releasePaymentCallbackLock($lockName)
    {
        try {
            Yii::$app->db->createCommand('SELECT RELEASE_LOCK(:name)', [
                ':name' => $lockName,
            ])->queryScalar();
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment.lock_release_failed');
        }
    }

    protected function assertOrderCanBeMarkedPaid(Order $model)
    {
        $allowedStatuses = [
            Order::PAYMENT_STATUS_UNPAID,
            Order::PAYMENT_STATUS_PAYING,
            Order::PAYMENT_STATUS_PAID,
        ];
        if (!in_array((int)$model->payment_status, $allowedStatuses, true)) {
            Yii::warning([
                'order_id' => $model->id,
                'payment_status' => $model->payment_status,
            ], 'mall.payment.invalid_payment_transition');
            throw new BadRequestHttpException('Order payment status cannot be marked paid');
        }

        return true;
    }

    protected function assertOrderCanStartPayment(Order $model)
    {
        if ((int)$model->parent_id !== 0) {
            throw new BadRequestHttpException('Only parent orders can be paid');
        }

        if ((int)$model->payment_method !== (int)Order::PAYMENT_METHOD_PAY) {
            throw new BadRequestHttpException('Order is not an online payment order');
        }

        if ((int)$model->payment_status === (int)Order::PAYMENT_STATUS_PAID) {
            return true;
        }

        $allowedStatuses = [
            Order::PAYMENT_STATUS_UNPAID,
            Order::PAYMENT_STATUS_PAYING,
        ];
        if (!in_array((int)$model->payment_status, $allowedStatuses, true)) {
            Yii::warning([
                'order_id' => $model->id,
                'payment_status' => $model->payment_status,
            ], 'mall.payment.invalid_payment_start');
            throw new BadRequestHttpException('Order payment status cannot start payment');
        }

        return true;
    }

    protected function markOrderPaying(Order $model)
    {
        if ((int)$model->payment_method === (int)Order::PAYMENT_METHOD_COD || (int)$model->payment_status === (int)Order::PAYMENT_STATUS_PAID) {
            return true;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            if ((int)$model->payment_status === (int)Order::PAYMENT_STATUS_UNPAID) {
                $model->payment_status = Order::PAYMENT_STATUS_PAYING;
                $model->status = Order::PAYMENT_STATUS_PAYING;
                if (!$model->save()) {
                    Yii::$app->logSystem->db($model->errors);
                    throw new BadRequestHttpException($this->getError($model));
                }
                OrderLog::create($model->id, $model->status, '', null, $model->user_id);
            }

            $children = Order::find()->where(['parent_id' => $model->id])->all();
            foreach ($children as $child) {
                if ((int)$child->payment_status !== (int)Order::PAYMENT_STATUS_UNPAID) {
                    continue;
                }
                $child->payment_status = Order::PAYMENT_STATUS_PAYING;
                $child->status = Order::PAYMENT_STATUS_PAYING;
                if (!$child->save()) {
                    Yii::$app->logSystem->db($child->errors);
                    throw new BadRequestHttpException($this->getError($child));
                }
                OrderLog::create($child->id, $child->status, '', null, $child->user_id);
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    protected function assertPaidAmountMatches(Order $model, $paidAmount)
    {
        if ($paidAmount === null) {
            throw new BadRequestHttpException('Payment amount is required');
        }

        if (abs((float)$model->amount - (float)$paidAmount) > 0.01) {
            Yii::warning([
                'order_id' => $model->id,
                'expected' => $model->amount,
                'actual' => $paidAmount,
            ], 'mall.payment.amount_mismatch');
            throw new BadRequestHttpException('Payment amount mismatch');
        }

        return true;
    }

    protected function markOrderPaid(Order $model, $paidAmount = null)
    {
        $this->assertPaidAmountMatches($model, $paidAmount);

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $this->assertOrderCanBeMarkedPaid($model);

            $paidAt = $model->paid_at ?: time();
            $parentChanged = false;
            if ((int)$model->payment_status !== (int)Order::PAYMENT_STATUS_PAID) {
                $model->payment_status = Order::PAYMENT_STATUS_PAID;
                $model->status = Order::PAYMENT_STATUS_PAID;
                $model->paid_at = $paidAt;
                if (!$model->save()) {
                    Yii::$app->logSystem->db($model->errors);
                    throw new BadRequestHttpException($this->getError($model));
                }
                $parentChanged = true;
            }

            $children = Order::find()->where(['parent_id' => $model->id])->all();
            $changedChildren = [];
            foreach ($children as $child) {
                $this->assertOrderCanBeMarkedPaid($child);

                if ((int)$child->payment_status === (int)Order::PAYMENT_STATUS_PAID) {
                    continue;
                }
                $child->payment_status = Order::PAYMENT_STATUS_PAID;
                $child->status = Order::PAYMENT_STATUS_PAID;
                $child->paid_at = $paidAt;
                if (!$child->save()) {
                    Yii::$app->logSystem->db($child->errors);
                    throw new BadRequestHttpException($this->getError($child));
                }
                $changedChildren[] = $child;
            }

            $model->deductStockIfNeeded($paidAt);

            if ($parentChanged) {
                OrderLog::create($model->id, $model->status, '', null, $model->user_id);
            }
            foreach ($changedChildren as $child) {
                OrderLog::create($child->id, $child->status, '', null, $child->user_id);
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        $behaviors = [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'actions' => ['succeeded','qpayres', 'cancelled', 'paypal', 'paypal-return', 'paypal-cancel', 'paypal-webhook'],  // 新增：允许匿名访问
                        'allow' => true,
                    ],
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],

            ]
        ];
        return $behaviors;
    }
    public function beforeAction($action)
    {

        $result = parent::beforeAction($action);
        return $result;
    }

    function curlRequest($url, $method = 'GET', $data = false, $headers = false) {
        $curl = curl_init();

        switch ($method) {
            case "POST":
                curl_setopt($curl, CURLOPT_POST, 1);
                if ($data) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                }
                break;
            case "PUT":
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
                if ($data) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                }
                break;
            default:
                if ($data) {
                    $url = sprintf("%s?%s", $url, http_build_query($data));
                }
        }

        // 设置 cURL 选项
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);
        if ($headers) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 根据需要设置证书验证

        // 执行 cURL 请求
        $result = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($errno) {
            throw new \RuntimeException('HTTP request failed: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new \RuntimeException('HTTP request returned status ' . $httpCode);
        }

        return $result;
    }

    protected function isPaypalConfigReady(array $config)
    {
        return $this->paymentProviderEnabled($config)
            && (string)($config['client_id'] ?? '') !== ''
            && (string)($config['client_secret'] ?? '') !== ''
            && (string)($config['callback_base'] ?? '') !== '';
    }

    protected function paypalCurrency(array $config)
    {
        $currency = strtoupper(trim((string)($config['currency'] ?? 'USD')));
        return $currency !== '' ? $currency : 'USD';
    }

    protected function paypalApiBase(array $config)
    {
        return (string)($config['environment'] ?? 'test') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    protected function paypalRouteUrl(array $config, $pathCode, $defaultPath, array $params = [])
    {
        $base = rtrim((string)($config['callback_base'] ?? ''), '/');
        $path = (string)($config[$pathCode] ?? $defaultPath);
        if ($path === '') {
            $path = $defaultPath;
        }
        if ($base === '') {
            $base = rtrim(env('PAYPAL_CALLBACK_BASE', 'https://www.mongoyia.com'), '/');
        }

        return $base . $path . ($params ? '?' . http_build_query($params) : '');
    }

    protected function paypalAccessToken(array $config)
    {
        $raw = $this->curlRequest($this->paypalApiBase($config) . '/v1/oauth2/token', 'POST', 'grant_type=client_credentials', [
            'Authorization: Basic ' . base64_encode((string)$config['client_id'] . ':' . (string)$config['client_secret']),
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $response = json_decode($raw, true);
        if (!is_array($response) || empty($response['access_token'])) {
            throw new \RuntimeException('PayPal access token missing');
        }

        return (string)$response['access_token'];
    }

    protected function paypalRequest(array $config, $method, $path, array $data = [])
    {
        $body = $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
        $raw = $this->curlRequest($this->paypalApiBase($config) . $path, $method, $body, [
            'Authorization: Bearer ' . $this->paypalAccessToken($config),
            'Content-Type: application/json',
            'Prefer: return=representation',
        ]);
        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new \RuntimeException('PayPal response is not JSON');
        }

        return $response;
    }

    protected function verifyPaypalWebhook(array $config, array $payload)
    {
        $webhookId = (string)($config['webhook_id'] ?? '');
        if ($webhookId === '') {
            throw new BadRequestHttpException('PayPal webhook id missing');
        }

        $request = Yii::$app->request;
        $verify = $this->paypalRequest($config, 'POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => (string)$request->headers->get('PAYPAL-AUTH-ALGO', ''),
            'cert_url' => (string)$request->headers->get('PAYPAL-CERT-URL', ''),
            'transmission_id' => (string)$request->headers->get('PAYPAL-TRANSMISSION-ID', ''),
            'transmission_sig' => (string)$request->headers->get('PAYPAL-TRANSMISSION-SIG', ''),
            'transmission_time' => (string)$request->headers->get('PAYPAL-TRANSMISSION-TIME', ''),
            'webhook_id' => $webhookId,
            'webhook_event' => $payload,
        ]);

        return strtoupper((string)($verify['verification_status'] ?? '')) === 'SUCCESS';
    }

    protected function paypalApprovalUrl(array $response)
    {
        foreach (($response['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
                return (string)$link['href'];
            }
        }

        return '';
    }

    protected function paypalCaptureAmount(array $payload)
    {
        $candidates = [
            $payload['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? null,
            $payload['resource']['amount']['value'] ?? null,
            $payload['amount']['value'] ?? null,
        ];
        foreach ($candidates as $value) {
            if ($value !== null && is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }

    protected function paypalMerchantTransactionId(array $payload)
    {
        $candidates = [
            $payload['purchase_units'][0]['invoice_id'] ?? null,
            $payload['resource']['invoice_id'] ?? null,
            $payload['invoice_id'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function paypalOrderIdFromPayload(array $payload)
    {
        $merchantTransactionId = $this->paypalMerchantTransactionId($payload);
        if (preg_match('/^PAYPAL-(\d+)-/', $merchantTransactionId, $matches)) {
            return (int)$matches[1];
        }
        $customId = $payload['purchase_units'][0]['custom_id'] ?? $payload['resource']['custom_id'] ?? null;
        return is_numeric($customId) ? (int)$customId : 0;
    }

    protected function paypalWebhookIsCompleted(array $payload)
    {
        $eventType = strtoupper((string)($payload['event_type'] ?? ''));
        $status = strtoupper((string)($payload['resource']['status'] ?? $payload['status'] ?? ''));
        return $eventType === 'PAYMENT.CAPTURE.COMPLETED' || $status === 'COMPLETED';
    }

    protected function paypalSafePayload(array $payload)
    {
        foreach (['client_secret', 'access_token'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = 'REDACTED';
            }
        }

        return $payload;
    }

    public function actionPaypal()
    {
        $id = Yii::$app->request->get('id');
        $model = $this->findPaymentOrder($id);
        if (!$model) {
            return $this->goBack();
        }
        try {
            $this->assertOrderCanStartPayment($model);
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['/mall/payment/index', 'id' => $id]);
        }
        if ($this->canShowPaymentSuccess($model)) {
            return $this->redirect(['/mall/payment/succeeded', 'id' => $id]);
        }

        $config = $this->paymentProviderConfig('paypal');
        if (!$this->isPaypalConfigReady($config)) {
            $this->logPaymentAttempt($model, 'paypal', 'create', [
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'PayPal config missing',
            ]);
            return $this->redirectError('PayPal config missing', ['/mall/payment/index', 'id' => $id]);
        }

        $merchantTransactionId = 'PAYPAL-' . $id . '-' . time();
        try {
            $response = $this->paypalRequest($config, 'POST', '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string)$id,
                    'custom_id' => (string)$id,
                    'invoice_id' => $merchantTransactionId,
                    'amount' => [
                        'currency_code' => $this->paypalCurrency($config),
                        'value' => number_format((float)$model->amount, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url' => $this->paypalRouteUrl($config, 'return_path', '/mall/payment/paypal-return', ['id' => $id]),
                    'cancel_url' => $this->paypalRouteUrl($config, 'cancel_path', '/mall/payment/paypal-cancel', ['id' => $id]),
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                ],
            ]);
            $approvalUrl = $this->paypalApprovalUrl($response);
            if ($approvalUrl === '') {
                throw new \RuntimeException('PayPal approval link missing');
            }
            $this->logPaymentAttempt($model, 'paypal', 'create', [
                'merchant_transaction_id' => $merchantTransactionId,
                'gateway_transaction_id' => (string)($response['id'] ?? ''),
                'amount' => (float)$model->amount,
                'currency' => $this->paypalCurrency($config),
                'payload' => $this->paypalSafePayload($response),
                'result' => PaymentAttempt::RESULT_PENDING,
            ]);
            $this->markOrderPaying($model);
            return $this->redirect($approvalUrl);
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment.paypal_create');
            $this->logPaymentAttempt($model, 'paypal', 'create', [
                'merchant_transaction_id' => $merchantTransactionId,
                'amount' => (float)$model->amount,
                'currency' => $this->paypalCurrency($config),
                'payload' => ['exception' => get_class($e), 'message' => $e->getMessage()],
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'PayPal create order failed',
            ]);
            return $this->redirectError('PayPal create order failed', ['/mall/payment/index', 'id' => $id]);
        }
    }

    public function actionPaypalReturn()
    {
        $id = Yii::$app->request->get('id');
        $token = (string)Yii::$app->request->get('token', '');
        $model = $this->requireUserPaymentOrder($id);
        if ($model instanceof \yii\web\Response) {
            return $model;
        }
        if ($this->canShowPaymentSuccess($model)) {
            return $this->redirect(['/mall/payment/succeeded', 'id' => $id]);
        }
        if ($token === '') {
            return $this->redirectError('PayPal return token missing', ['/mall/payment/index', 'id' => $id]);
        }

        $config = $this->paymentProviderConfig('paypal');
        try {
            $response = $this->paypalRequest($config, 'POST', '/v2/checkout/orders/' . rawurlencode($token) . '/capture', []);
            $paidAmount = $this->paypalCaptureAmount($response);
            $this->assertPaidAmountMatches($model, $paidAmount);
            $this->markOrderPaid($model, $paidAmount);
            $this->logPaymentAttempt($model, 'paypal', 'return', [
                'merchant_transaction_id' => $this->paypalMerchantTransactionId($response),
                'gateway_transaction_id' => $token,
                'amount' => $paidAmount,
                'currency' => $this->paypalCurrency($config),
                'payload' => $this->paypalSafePayload($response),
                'result' => PaymentAttempt::RESULT_SUCCESS,
            ]);
            return $this->redirect(['/mall/payment/succeeded', 'id' => $id]);
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment.paypal_return');
            $this->logPaymentAttempt($model, 'paypal', 'return', [
                'gateway_transaction_id' => $token,
                'payload' => ['exception' => get_class($e), 'message' => $e->getMessage()],
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'PayPal capture failed',
            ]);
            return $this->redirectError('PayPal capture failed', ['/mall/payment/index', 'id' => $id]);
        }
    }

    public function actionPaypalCancel()
    {
        $id = Yii::$app->request->get('id');
        $model = $this->requireUserPaymentOrder($id);
        if ($model instanceof \yii\web\Response) {
            return $model;
        }
        $this->logPaymentAttempt($model, 'paypal', 'cancel', [
            'gateway_transaction_id' => (string)Yii::$app->request->get('token', ''),
            'result' => PaymentAttempt::RESULT_DISPLAY,
        ]);

        return $this->redirect(['/mall/payment/cancelled', 'id' => $id]);
    }

    public function actionPaypalWebhook()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $config = $this->paymentProviderConfig('paypal');
        $payload = json_decode(Yii::$app->request->rawBody, true);
        if (!is_array($payload)) {
            Yii::$app->response->statusCode = 400;
            return ['success' => false, 'message' => 'Invalid PayPal webhook payload'];
        }

        $eventId = (string)($payload['id'] ?? '');
        if ($eventId !== '' && PaymentAttempt::find()->where([
            'provider' => 'paypal',
            'event' => 'webhook',
            'gateway_transaction_id' => $eventId,
            'result' => PaymentAttempt::RESULT_SUCCESS,
        ])->exists()) {
            return ['success' => true, 'message' => 'Duplicate PayPal webhook ignored'];
        }

        try {
            if (!$this->verifyPaypalWebhook($config, $payload)) {
                throw new BadRequestHttpException('Invalid PayPal webhook signature');
            }
            $orderId = $this->paypalOrderIdFromPayload($payload);
            $model = $orderId ? $this->findPaymentOrder($orderId, false) : null;
            if (!$model) {
                throw new BadRequestHttpException('PayPal webhook order not found');
            }
            $attempt = $this->logPaymentAttempt($model, 'paypal', 'webhook', [
                'merchant_transaction_id' => $this->paypalMerchantTransactionId($payload),
                'gateway_transaction_id' => $eventId,
                'amount' => $this->paypalCaptureAmount($payload),
                'currency' => $this->paypalCurrency($config),
                'payload' => $this->paypalSafePayload($payload),
                'result' => PaymentAttempt::RESULT_PENDING,
            ]);
            $lockName = $this->paymentCallbackLockName('paypal', $model, $eventId ?: $this->paypalMerchantTransactionId($payload));
            if (!$this->acquirePaymentCallbackLock($lockName)) {
                $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_IGNORED, 'Duplicate webhook is being processed');
                return ['success' => true, 'message' => 'Duplicate PayPal webhook ignored'];
            }
            try {
                if (!$this->paypalWebhookIsCompleted($payload)) {
                    $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_IGNORED, 'PayPal webhook event is not a completed capture');
                    return ['success' => true, 'message' => 'PayPal webhook ignored'];
                }
                $paidAmount = $this->paypalCaptureAmount($payload);
                if ((int)$model->payment_status === (int)Order::PAYMENT_STATUS_PAID) {
                    $this->assertPaidAmountMatches($model, $paidAmount);
                    $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_IGNORED, 'Duplicate paid PayPal webhook ignored');
                    return ['success' => true, 'message' => 'Duplicate PayPal webhook ignored'];
                }
                $this->markOrderPaid($model, $paidAmount);
                $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_SUCCESS);
            } finally {
                $this->releasePaymentCallbackLock($lockName);
            }

            return ['success' => true, 'message' => 'PayPal webhook processed'];
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment.paypal_webhook');
            Yii::$app->response->statusCode = $e instanceof BadRequestHttpException ? 400 : 500;
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function actionQpay(){
        $id = Yii::$app->request->get('id');
        $model = $this->findPaymentOrder($id);
        if (!$model) {
            return $this->goBack();
        }
        try {
            $this->assertOrderCanStartPayment($model);
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['/mall/payment/index', 'id' => $id]);
        }
        if ($this->canShowPaymentSuccess($model)) {
            return $this->redirect(['/mall/payment/succeeded', 'id' => $id]);
        }

        $merchantTransactionId = '1234567' . $id;
        $qpayConfig = $this->paymentProviderConfig('qpay');
        $authBasic = (string)($qpayConfig['auth_basic'] ?? '');
        $invoiceCode = (string)($qpayConfig['invoice_code'] ?? '');
        if (!$this->paymentProviderEnabled($qpayConfig) || $authBasic === '' || $invoiceCode === '') {
            $this->logPaymentAttempt($model, 'qpay', 'create', [
                'merchant_transaction_id' => $merchantTransactionId,
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'QPay test config missing',
            ]);
            return $this->redirectError('QPay test config missing');
        }
        try {
            $tokenRawResponse = $this->curlRequest((string)($qpayConfig['auth_url'] ?? 'https://merchant.qpay.mn/v2/auth/token'),'POST',[],[
                "Authorization: Basic " . $authBasic
            ]);
            $tokenResponse = json_decode($tokenRawResponse);
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment.qpay_token');
            $this->logPaymentAttempt($model, 'qpay', 'create', [
                'merchant_transaction_id' => $merchantTransactionId,
                'payload' => ['exception' => get_class($e), 'message' => $e->getMessage()],
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'QPay token error',
            ]);
            return $this->redirectError('QPay token error');
        }
        if (!isset($tokenResponse->access_token)) {
            Yii::warning($tokenResponse, 'mall.payment.qpay_token');
            $this->logPaymentAttempt($model, 'qpay', 'create', [
                'merchant_transaction_id' => $merchantTransactionId,
                'payload' => $tokenResponse,
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'QPay token error',
            ]);
            return $this->redirectError('QPay token error');
        }
        $token = $tokenResponse->access_token;
        try {
            $invoiceRawResponse = $this->curlRequest((string)($qpayConfig['invoice_url'] ?? 'https://merchant.qpay.mn/v2/invoice'),'POST',json_encode([
                'invoice_code'=>$invoiceCode,
                'sender_invoice_no'=>$merchantTransactionId,
                'invoice_receiver_code'=>'terminal',
                'invoice_description'=>'test',
                'amount'=>(float)$model->amount,
                'callback_url'=>$this->buildOperationalCallbackUrl($qpayConfig, 'QPAY_CALLBACK_BASE', '/mall/payment/qpayres', $id, 'callback_secret', 'QPAY_CALLBACK_SECRET')
            ]),[
                "Authorization: Bearer ".$token,
                "Content-Type: application/json"
            ]);
            $order = json_decode($invoiceRawResponse);
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.payment.qpay_invoice');
            $this->logPaymentAttempt($model, 'qpay', 'create', [
                'merchant_transaction_id' => $merchantTransactionId,
                'payload' => ['exception' => get_class($e), 'message' => $e->getMessage()],
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'QPay invoice error',
            ]);
            return $this->redirectError('QPay invoice error');
        }
        if(isset($order->qPay_shortUrl)){
            $this->logPaymentAttempt($model, 'qpay', 'create', [
                'merchant_transaction_id' => $merchantTransactionId,
                'payload' => $order,
                'result' => PaymentAttempt::RESULT_PENDING,
            ]);
            $this->markOrderPaying($model);
            return $this->redirect($order->qPay_shortUrl);
        }else{
            $this->logPaymentAttempt($model, 'qpay', 'create', [
                'merchant_transaction_id' => $merchantTransactionId,
                'payload' => $order,
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'QPay invoice error',
            ]);
            return $this->redirectError('QPay invoice error');
        }
    }

    public function actionQpayres(){
        $id = Yii::$app->request->get('id', Yii::$app->request->post('id'));
        if (!$id) {
            return $this->goBack();
        }
        $model = Yii::$app->request->isPost ? $this->findPaymentOrder($id, false) : $this->requireUserPaymentOrder($id);
        if ($model instanceof \yii\web\Response) {
            return $model;
        }
        if (!$model) {
            return $this->goBack();
        }

        $attempt = $this->logPaymentAttempt($model, 'qpay', Yii::$app->request->isPost ? 'callback' : 'return', [
            'result' => Yii::$app->request->isPost ? PaymentAttempt::RESULT_PENDING : PaymentAttempt::RESULT_DISPLAY,
        ]);
        if (Yii::$app->request->isPost) {
            $qpayConfig = $this->paymentProviderConfig('qpay');
            $callbackMerchantTransactionId = $attempt instanceof PaymentAttempt && $attempt->merchant_transaction_id ? $attempt->merchant_transaction_id : ('1234567' . $id);
            $lockName = $this->paymentCallbackLockName('qpay', $model, $callbackMerchantTransactionId);
            if (!$this->acquirePaymentCallbackLock($lockName)) {
                $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_IGNORED, 'Duplicate callback is being processed');
                return $this->paymentGatewayResponse('SUCCESS');
            }
            try {
                $this->assertCallbackSourceValue($qpayConfig['callback_allowed_ips'] ?? '');
                $this->assertCallbackSecretValue($qpayConfig['callback_secret'] ?? '');
                $this->assertCallbackTimestampValue($qpayConfig['callback_max_age_seconds'] ?? 0);
                $this->assertCallbackSignatureValue($qpayConfig['callback_hmac_secret'] ?? '');
                $this->assertSuccessfulPaymentStatus('qpay');
                $this->assertMerchantTransactionId('1234567' . $id);
                $paidAmount = $this->extractPaidAmount();
                if ((int)$model->payment_status === (int)Order::PAYMENT_STATUS_PAID) {
                    $this->assertPaidAmountMatches($model, $paidAmount);
                    $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_IGNORED, 'Duplicate paid callback ignored');
                    return $this->paymentGatewayResponse('SUCCESS');
                }
                $this->markOrderPaid($model, $paidAmount);
                $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_SUCCESS);
            } catch (\Throwable $e) {
                $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_FAILED, $e->getMessage());
                Yii::warning($e->getMessage(), __METHOD__);
                return $this->paymentGatewayResponse('FAIL', 400);
            } finally {
                $this->releasePaymentCallbackLock($lockName);
            }
            return $this->paymentGatewayResponse('SUCCESS');
        }

        if (!$this->canShowPaymentSuccess($model)) {
            return $this->redirect(['/mall/payment/index', 'id' => $id]);
        }

        return $this->render('succeeded', [
            'model' => $model,
        ]);
    }

    public function actionLianlian(){
//        $amount = Yii::$app->request->get('amount');
        $id = Yii::$app->request->get('id');
        $model = $this->findPaymentOrder($id);
        if (!$model) {
            return $this->goBack();
        }
        try {
            $this->assertOrderCanStartPayment($model);
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['/mall/payment/index', 'id' => $id]);
        }
        if ($this->canShowPaymentSuccess($model)) {
            return $this->redirect(['/mall/payment/succeeded', 'id' => $id]);
        }

        $merchant_transaction_id = 'Test-pay'.$id;
        $lianlianConfig = $this->paymentProviderConfig('lianlian');
        if (!$this->paymentProviderEnabled($lianlianConfig) || !PayConstant::isConfigured($lianlianConfig)) {
            $this->logPaymentAttempt($model, 'lianlian', 'create', [
                'merchant_transaction_id' => $merchant_transaction_id,
                'amount' => (float)$model->amount,
                'currency' => 'USD',
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'LianLian test config missing',
            ]);
            return $this->redirectError('LianLian test config missing');
        }
        $pay_sdk = PaySDK::getInstance();
        $pay_sdk->init(PayConstant::$sandbox);
        $pay_request = new PayRequest();
        $pay_request->merchant_id = PayConstant::$merchant_id;
        //二级商户号,若有二级商户号必填
        $pay_request->sub_merchant_id= PayConstant::$sub_merchant_id;
        $pay_request->country = 'MN';
        //支付成功后跳转地址，商户支付成功地址，这里模拟商户的支付成功页面
        //支付成功后，用户页面回跳URL地址;收银台方式接入必填，Iframe必填，api国际信用卡、本地必填。
        $redirect_url=$this->buildOperationalCallbackUrl($lianlianConfig, 'LIANLIAN_CALLBACK_BASE', '/mall/payment/succeeded/', $id);
        $pay_request->redirect_url = $redirect_url;
        //支付成功后异步通知地址，这里模拟商户的接收异常通知请求  请看PayController#paymentSuccess
        $notification_url=$this->buildOperationalCallbackUrl($lianlianConfig, 'LIANLIAN_CALLBACK_BASE', '/mall/payment/succeeded/', $id, 'callback_secret', 'LIANLIAN_CALLBACK_SECRET');
        $pay_request->notification_url = $notification_url;

        $time = date('YmdHis', time());
        //商户发起支付交易的单号，保证唯一
        $pay_request->merchant_transaction_id = $merchant_transaction_id;
        //国际信用iframe 创单时需要手动制定支付方式inter_credit_card
//        $pay_request->payment_method = 'inter_credit_card';
//        $pay_request->front_model = 'IFRAME';
        $product = new Product();
        $product->category = 'clothes';
        $product->name = 'female clothes';
        $product->price = (float)$model->amount;
        $product->product_id = '20001029398';
        $product->quantity = 1;
        $product->shipping_provider = 'DHL';
        $product->sku = 'M1120';
        $product->url = 'https://www.taobao.com';
        $products = array();
        $products[] = $product;

        $merchant_order = new MerchantOrder();
        //此为商户系统的订单号，支付订单号和支付交易单号可以传一样
        $merchant_order->merchant_order_id = $merchant_transaction_id;
        $merchant_order->merchant_order_time = $time;
        $merchant_order->order_amount = (float)$model->amount;
        $merchant_order->order_currency_code = 'USD';
        $merchant_order->products = $products;

        $pay_request->merchant_order = $merchant_order;

        try {
            $payment = new Payment();
            $pay_response = $payment->pay($pay_request, PayConstant::$private_key, PayConstant::$public_key);
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), __METHOD__);
            $this->logPaymentAttempt($model, 'lianlian', 'create', [
                'merchant_transaction_id' => $merchant_transaction_id,
                'amount' => (float)$model->amount,
                'currency' => 'USD',
                'payload' => ['exception' => get_class($e), 'message' => $e->getMessage()],
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'LianLian payment create failed',
            ]);
            return $this->redirectError('LianLian payment create failed', ['/mall/payment/index', 'id' => $id]);
        }
        if(isset($pay_response['order'])){
            $this->logPaymentAttempt($model, 'lianlian', 'create', [
                'merchant_transaction_id' => $merchant_transaction_id,
                'amount' => (float)$model->amount,
                'currency' => 'USD',
                'payload' => $pay_response,
                'result' => PaymentAttempt::RESULT_PENDING,
            ]);
            $this->markOrderPaying($model);
            return $this->redirect($pay_response['order']['payment_url']);
        }else{
            $this->logPaymentAttempt($model, 'lianlian', 'create', [
                'merchant_transaction_id' => $merchant_transaction_id,
                'amount' => (float)$model->amount,
                'currency' => 'USD',
                'payload' => $pay_response,
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'LianLian payment create failed',
            ]);
            return $this->redirectError('支付金额不能小于$1，请添加其他商品','/mall/cart');
        }

    }


    public function actionLquery(){
        $id = Yii::$app->request->get('id');
        if (!$id) {
            return $this->goBack();
        }
        $model = $this->findPaymentOrder($id);
        if (!$model) {
            return $this->goBack();
        }

        $merchant_transaction_id = 'Test-pay'.$id;
        $lianlianConfig = $this->paymentProviderConfig('lianlian');
        if (!$this->paymentProviderEnabled($lianlianConfig) || !PayConstant::isConfigured($lianlianConfig)) {
            $this->logPaymentAttempt($model, 'lianlian', 'query', [
                'merchant_transaction_id' => $merchant_transaction_id,
                'amount' => (float)$model->amount,
                'currency' => 'USD',
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'LianLian test config missing',
            ]);
            return $this->redirectError('LianLian test config missing', ['/mall/payment/index', 'id' => $id]);
        }

        $pay_sdk = PaySDK::getInstance();
        $pay_sdk->init(PayConstant::$sandbox);
        try {
            $payment = new Payment();
            $pay_query_response = $payment->pay_query(PayConstant::$merchant_id,$merchant_transaction_id,PayConstant::$private_key,PayConstant::$public_key);
            $this->logPaymentAttempt($model, 'lianlian', 'query', [
                'merchant_transaction_id' => $merchant_transaction_id,
                'amount' => (float)$model->amount,
                'currency' => 'USD',
                'payload' => $pay_query_response,
                'result' => PaymentAttempt::RESULT_DISPLAY,
            ]);
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), __METHOD__);
            $this->logPaymentAttempt($model, 'lianlian', 'query', [
                'merchant_transaction_id' => $merchant_transaction_id,
                'amount' => (float)$model->amount,
                'currency' => 'USD',
                'payload' => ['exception' => get_class($e), 'message' => $e->getMessage()],
                'result' => PaymentAttempt::RESULT_FAILED,
                'error_message' => 'LianLian query failed',
            ]);
            return $this->redirectError('LianLian query failed', ['/mall/payment/index', 'id' => $id]);
        }

        return $this->redirect(['/mall/payment/index', 'id' => $id]);
    }

    public function actionIndex()
    {
        $id = Yii::$app->request->get('id');
        if (!$id) {
            return $this->goBack();
        }
        /** @var Order $model */
        $model = $this->findPaymentOrder($id);
        if (!$model) {
            return $this->goBack();
        }

        if ($this->canShowPaymentSuccess($model)) {
            return $this->redirect(['/mall/payment/succeeded', 'id' => $id]);
        }

        return $this->render($this->action->id, [
            'model' => $model,
        ]);
    }

    public function actionSucceeded()
    {
        $id = Yii::$app->request->get('id', Yii::$app->request->post('id'));
        if (!$id) {
            return $this->goBack();
        }
        /** @var Order $model */
        $model = Yii::$app->request->isPost ? $this->findPaymentOrder($id, false) : $this->requireUserPaymentOrder($id);
        if ($model instanceof \yii\web\Response) {
            return $model;
        }
        if (!$model) {
            return $this->goBack();
        }
        $attempt = $this->logPaymentAttempt($model, 'lianlian', Yii::$app->request->isPost ? 'callback' : 'return', [
            'merchant_transaction_id' => 'Test-pay' . $id,
            'currency' => 'USD',
            'result' => Yii::$app->request->isPost ? PaymentAttempt::RESULT_PENDING : PaymentAttempt::RESULT_DISPLAY,
        ]);
        if (Yii::$app->request->isPost) {
            $lianlianConfig = $this->paymentProviderConfig('lianlian');
            $callbackMerchantTransactionId = $attempt instanceof PaymentAttempt && $attempt->merchant_transaction_id ? $attempt->merchant_transaction_id : ('Test-pay' . $id);
            $lockName = $this->paymentCallbackLockName('lianlian', $model, $callbackMerchantTransactionId);
            if (!$this->acquirePaymentCallbackLock($lockName)) {
                $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_IGNORED, 'Duplicate callback is being processed');
                return $this->paymentGatewayResponse('success');
            }
            try {
                $this->assertCallbackSourceValue($lianlianConfig['callback_allowed_ips'] ?? '');
                $this->assertCallbackSecretValue($lianlianConfig['callback_secret'] ?? '');
                $this->assertCallbackTimestampValue($lianlianConfig['callback_max_age_seconds'] ?? 0);
                $this->assertCallbackSignatureValue($lianlianConfig['callback_hmac_secret'] ?? '');
                $this->assertSuccessfulPaymentStatus('lianlian');
                $this->assertMerchantTransactionId('Test-pay' . $id);
                $paidAmount = $this->extractPaidAmount();
                if ((int)$model->payment_status === (int)Order::PAYMENT_STATUS_PAID) {
                    $this->assertPaidAmountMatches($model, $paidAmount);
                    $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_IGNORED, 'Duplicate paid callback ignored');
                    return $this->paymentGatewayResponse('success');
                }
                $this->markOrderPaid($model, $paidAmount);
                $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_SUCCESS);
            } catch (\Throwable $e) {
                $this->updatePaymentAttemptResult($attempt, PaymentAttempt::RESULT_FAILED, $e->getMessage());
                Yii::warning($e->getMessage(), __METHOD__);
                return $this->paymentGatewayResponse('fail', 400);
            } finally {
                $this->releasePaymentCallbackLock($lockName);
            }
            return $this->paymentGatewayResponse('success');
        }

        if (!$this->canShowPaymentSuccess($model)) {
            return $this->redirect(['/mall/payment/index', 'id' => $id]);
        }

        return $this->render($this->action->id, [
            'model' => $model,
        ]);
    }

    public function actionCancelled()
    {
        $id = Yii::$app->request->get('id');
        if (!$id) {
            return $this->goBack();
        }
        /** @var Order $model */
        $model = $this->findPaymentOrder($id);
        if (!$model) {
            return $this->goBack();
        }

        return $this->render($this->action->id, [
            'model' => $model,
        ]);
    }

    public function actionPay()
    {

    }
}
