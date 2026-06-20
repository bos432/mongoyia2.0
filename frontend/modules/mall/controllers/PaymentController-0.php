<?php

namespace frontend\modules\mall\controllers;

use common\models\BaseModel;
use common\models\mall\Order;
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
use yii\helpers\Url;

/**
 * Class PaymentController
 * @package frontend\modules\mall\controllers
 * @author funson86 <funson86@gmail.com>
 */
class PaymentController extends BaseController
{
    public $modelClass = Order::class;

    protected function buildAbsoluteMallUrl($route, array $params = [])
    {
        return Url::to(array_merge([$route], $params), true);
    }

    protected function getMerchantTransactionId($id)
    {
        return 'Test-pay' . $id;
    }

    protected function syncLianlianOrderStatus(Order $model)
    {
        $pay_sdk = PaySDK::getInstance();
        $pay_sdk->init(PayConstant::$sandbox);
        $payment = new Payment();
        $response = $payment->pay_query(
            PayConstant::$merchant_id,
            $this->getMerchantTransactionId($model->id),
            PayConstant::$private_key,
            PayConstant::$public_key
        );

        $paymentStatus = strtoupper((string)($response['payment_status'] ?? $response['status'] ?? ''));
        $tradeStatus = strtoupper((string)($response['trade_status'] ?? $response['order_status'] ?? ''));
        $isPaid = in_array($paymentStatus, ['SUCCESS', 'PAID', 'PAY_SUCCESS', 'PS'], true)
            || in_array($tradeStatus, ['SUCCESS', 'PAID', 'PAY_SUCCESS', 'PS'], true);

        if ($isPaid && (int)$model->payment_status !== (int)Order::PAYMENT_STATUS_PAID) {
            $model->payment_status = Order::PAYMENT_STATUS_PAID;
            $model->status = Order::PAYMENT_STATUS_PAID;
            $model->paid_at = time();
            if (!$model->save()) {
                Yii::$app->logSystem->db($model->errors);
            }
        }

        return $response;
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
                        'actions' => ['succeeded','qpayres'],  // 新增：允许匿名访问
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
        if ($headers) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 根据需要设置证书验证

        // 执行 cURL 请求
        $result = curl_exec($curl);
        curl_close($curl);

        return $result;
    }

    public function actionQpay(){
        $amount = Yii::$app->request->get('amount');
        $id = Yii::$app->request->get('id');
        $token = json_decode($this->curlRequest('https://merchant.qpay.mn/v2/auth/token','POST',[],[
            "Authorization: Basic TU9UT19FWFBSRVNTOlZTQnpWY2xW"
        ]))->access_token;
//        var_dump($token);exit();
        $order = json_decode($this->curlRequest('https://merchant.qpay.mn/v2/invoice','POST',json_encode([
            'invoice_code'=>'MOTO_EXPRESS_INVOICE',
            'sender_invoice_no'=>'1234567'.$id,
            'invoice_receiver_code'=>'terminal',
            'invoice_description'=>'test',
            'amount'=>$amount,
            'callback_url'=>'https://www.mongoyia.com/mall/payment/qpayres?id='.$id
        ]),[
            "Authorization: Bearer ".$token,
            "Content-Type: application/json"
        ]));
        if(isset($order->qPay_shortUrl)){
            header('Location: '.$order->qPay_shortUrl);
        }else{
            echo '<pre/>';
            var_dump($order);exit();
        }
    }

    public function actionQpayres(){
        $id = Yii::$app->request->get('id', Yii::$app->request->post('id'));
        if (!$id) {
            return $this->goBack();
        }
        $model = $this->modelClass::findOne(['id' => $id]);
        if (!$model) {
            return $this->goBack();
        }

        $model->payment_status = $model->status = Order::PAYMENT_STATUS_PAID;
        $model->paid_at = time();
        if (!$model->save()) {
            Yii::$app->logSystem->db($model->errors);
            return $this->error();
        }
        if (Yii::$app->request->isPost) {
            echo 'SUCCESS';exit();
        }

        return $this->render($this->action->id, [
            'model' => $model,
        ]);
    }

    public function actionLianlian(){
//        $amount = Yii::$app->request->get('amount');
        $id = Yii::$app->request->get('id');
        $pay_sdk = PaySDK::getInstance();
        $pay_sdk->init(PayConstant::$sandbox);
        $pay_request = new PayRequest();
        $pay_request->merchant_id = PayConstant::$merchant_id;
        //二级商户号,若有二级商户号必填
        $pay_request->sub_merchant_id= PayConstant::$sub_merchant_id;
        $pay_request->country = 'MN';
        //支付成功后跳转地址，商户支付成功地址，这里模拟商户的支付成功页面
        //支付成功后，用户页面回跳URL地址;收银台方式接入必填，Iframe必填，api国际信用卡、本地必填。
        $redirect_url = $this->buildAbsoluteMallUrl('/mall/payment/succeeded', ['id' => $id]);
        $pay_request->redirect_url = $redirect_url;
        //支付成功后异步通知地址，这里模拟商户的接收异常通知请求  请看PayController#paymentSuccess
        $notification_url = $this->buildAbsoluteMallUrl('/mall/payment/succeeded', ['id' => $id]);
        $pay_request->notification_url = $notification_url;

        $time = date('YmdHis', time());
        $merchant_transaction_id = $this->getMerchantTransactionId($id);
        //商户发起支付交易的单号，保证唯一
        $pay_request->merchant_transaction_id = $merchant_transaction_id;
        //国际信用iframe 创单时需要手动制定支付方式inter_credit_card
//        $pay_request->payment_method = 'inter_credit_card';
//        $pay_request->front_model = 'IFRAME';
        $product = new Product();
        $product->category = 'clothes';
        $product->name = 'female clothes';
        $product->price = Yii::$app->request->get('amount');
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
        $merchant_order->order_amount = Yii::$app->request->get('amount');
        $merchant_order->order_currency_code = 'USD';
        $merchant_order->products = $products;

        $pay_request->merchant_order = $merchant_order;

        $payment = new Payment();
        $pay_response = $payment->pay($pay_request, PayConstant::$private_key, PayConstant::$public_key);
//        var_dump($pay_response);exit();
        if(isset($pay_response['order'])){
            header("Location: ".$pay_response['order']['payment_url']);
        }else{
            $this->redirectError('支付金额不能小于$1，请添加其他商品','/mall/cart');
        }

    }


    public function actionLquery(){
        $pay_sdk = PaySDK::getInstance();
        $pay_sdk->init(PayConstant::$sandbox);
        $payment = new Payment();
        $merchant_transaction_id = 'Test-pay12';
        $pay_query_response = $payment->pay_query(PayConstant::$merchant_id,$merchant_transaction_id,PayConstant::$private_key,PayConstant::$public_key);
        echo '<pre/>';
        var_dump($pay_query_response);exit();
    }

    public function actionIndex()
    {
        header('Content-Type: application/json');
        $id = Yii::$app->request->get('id');
//        var_dump($id);exit();
        if (!$id) {
            return $this->goBack();
        }
        /** @var Order $model */
        $model = $this->modelClass::findOne(['store_id' => $this->getStoreId(), 'user_id' => Yii::$app->user->id, 'id' => $id]);
        if (!$model) {
            return $this->goBack();
        }

        if ($model->payment_method == Order::PAYMENT_METHOD_COD) {
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
        $model = $this->modelClass::findOne(['id' => $id]);
//        var_dump($model);exit();
        if (!$model) {
            return $this->goBack();
        }


        if (Yii::$app->request->isPost) {
            $model->payment_status = $model->status = Order::PAYMENT_STATUS_PAID;
            $model->paid_at = time();
            if (!$model->save()) {
                Yii::$app->logSystem->db($model->errors);
                return $this->error();
            }
            echo 'success';exit();
        }

        $returnedPaymentStatus = strtoupper((string)Yii::$app->request->get('payment_status', ''));
        if (
            $returnedPaymentStatus === 'PS'
            && (int)$model->payment_status !== (int)Order::PAYMENT_STATUS_PAID
        ) {
            $model->payment_status = $model->status = Order::PAYMENT_STATUS_PAID;
            $model->paid_at = time();
            if (!$model->save()) {
                Yii::$app->logSystem->db($model->errors);
            }
        }

        if ((int)$model->payment_status !== (int)Order::PAYMENT_STATUS_PAID) {
            try {
                $this->syncLianlianOrderStatus($model);
            } catch (\Throwable $e) {
                Yii::warning($e->getMessage(), __METHOD__);
            }
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
        $model = $this->modelClass::findOne(['store_id' => $this->getStoreId(), 'user_id' => Yii::$app->user->id, 'id' => $id]);
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
