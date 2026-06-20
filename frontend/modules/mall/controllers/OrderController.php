<?php

namespace frontend\modules\mall\controllers;

use common\models\base\Setting;
use common\models\BaseModel;
use common\models\base\PointLog;
use common\models\mall\Address;
use common\models\mall\Cart;
use common\models\mall\Consultation;
use common\models\mall\CouponType;
use common\models\mall\Order;
use common\models\mall\OrderProduct;
use common\models\mall\Product;
use common\models\mall\ProductSku;
use common\models\mall\Review;
use common\models\User;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\NotFoundHttpException;

/**
 * Class CartController
 * @package frontend\modules\mall\controllers
 * @author funson86 <funson86@gmail.com>
 */
class OrderController extends BaseController
{
    public $modelClass = Order::class;

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['view', 'review'],
                'rules' => [
                    [
                        'actions' => ['view', 'review'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'review' => ['POST'],
                ],
            ],
        ];
    }

    public function actionView(){
        $oid = (int)$this->request->get('id');
        $orderProduct = $this->findUserOrderProduct($oid);
        $order = $orderProduct->order;
        $hasReview = $this->hasReview($orderProduct);
        $canReview = $this->canReview($order, $hasReview);

        if (Yii::$app->request->isPost) {
            if (!$canReview) {
                return $this->htmlFailed(Yii::t('app', 'This order cannot be reviewed now'));
            }

            $star = (int)$this->request->post('star');
            if ($star < 1 || $star > 5) {
                return $this->htmlFailed(Yii::t('app', 'Please select a rating from 1 to 5'));
            }

            $uid = Yii::$app->user->id;
            $name = Yii::$app->user->identity->name;
            $transaction = Yii::$app->db->beginTransaction();
            try {
                if ($this->hasReview($orderProduct)) {
                    $transaction->commit();
                    return $this->redirectSuccess(Yii::t('app', 'Reviewed'));
                }

                $review = new Review();
                $review->store_id = (int)$orderProduct->store_id ?: (int)($orderProduct->product->store_id ?? 0);
                $review->parent_id = (int)$orderProduct->parent_id;
                $review->product_id = (int)$orderProduct->product_id;
                $review->user_id = $uid;
                $review->name = $name ?: Yii::$app->user->identity->username;
                $review->star = $star;
                $review->content = trim((string)$this->request->post('content'));
                $review->status = Review::STATUS_ACTIVE;
                $review->order_id = (int)$orderProduct->order_id;
                $review->created_at = time();
                $review->updated_at = time();
                $review->created_by = $uid;
                $review->updated_by = $uid;

                if (!$review->save()) {
                    Yii::$app->logSystem->db($review->errors);
                    throw new \RuntimeException(Yii::t('app', 'Review failed'));
                }

                $transaction->commit();
                return $this->redirectSuccess(Yii::t('app', 'Review submitted'));
            } catch (\Throwable $e) {
                $transaction->rollBack();
                return $this->htmlFailed($e->getMessage());
            }

        }
        return $this->render($this->action->id, [
            'orderProduct' => $orderProduct,
            'order' => $order,
            'canReview' => $canReview,
            'hasReview' => $hasReview,
        ]);
    }

    public function actionReview(){
        $oid = (int)$this->request->get('id');
        $model = $this->findUserOrder($oid);
        try {
            $model->markReceived();
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }

        $this->clearCache();
        return $this->redirectSuccess();
    }

    protected function findUserOrderProduct($id)
    {
        $model = OrderProduct::find()
            ->alias('op')
            ->joinWith(['order mo'])
            ->where(['op.id' => $id])
            ->andWhere(['or', ['op.user_id' => Yii::$app->user->id], ['mo.user_id' => Yii::$app->user->id]])
            ->one();

        if (!$model || !$model->order) {
            throw new NotFoundHttpException(Yii::t('app', 'Invalid id'), 500);
        }

        return $model;
    }

    protected function findUserOrder($id)
    {
        $model = Order::find()
            ->where(['id' => $id, 'user_id' => Yii::$app->user->id])
            ->andWhere(['>', 'status', Order::STATUS_DELETED])
            ->one();

        if (!$model) {
            throw new NotFoundHttpException(Yii::t('app', 'Invalid id'), 500);
        }

        return $model;
    }

    protected function hasReview(OrderProduct $orderProduct)
    {
        return Review::find()->where([
            'product_id' => $orderProduct->product_id,
            'user_id' => Yii::$app->user->id,
            'order_id' => $orderProduct->order_id,
        ])->exists();
    }

    protected function canReview(Order $order, $hasReview = false)
    {
        if ($hasReview || (int)$order->payment_status === (int)Order::PAYMENT_STATUS_REFUND) {
            return false;
        }

        $isPaid = in_array((int)$order->payment_status, [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD], true);
        $isReceived = (int)$order->shipment_status === (int)Order::SHIPMENT_STATUS_RECEIVED;

        return $isPaid && $isReceived;
    }

    protected function clearCache()
    {
        return Yii::$app->cacheSystemMall->clearMallAllData($this->getStoreId());
    }
}
