<?php
use common\models\mall\OrderProduct as ActiveModel;
use yii\helpers\Html;
use yii\helpers\Url;
use common\models\mall\Order;
use common\models\mall\Review;

/* @var $this yii\web\View */
/* @var  Order $model */
$order = $model;
$models = $order->orderProducts;
if (!count($models) && (int)$order->parent_id === 0) {
    $models = [];
    foreach ($order->childOrders as $childOrder) {
        foreach ($childOrder->orderProducts as $orderProduct) {
            $models[] = $orderProduct;
        }
    }
}
$isParentUnpaidOnline = (int)$order->parent_id === 0
    && (int)$order->payment_method === Order::PAYMENT_METHOD_PAY
    && (int)$order->payment_status === Order::PAYMENT_STATUS_UNPAID;
?>

<?php foreach ($models as $model) { ?>
<?php
$hasReview = Review::find()->where([
    'product_id' => $model->product_id,
    'user_id' => Yii::$app->user->id,
    'order_id' => $model->order_id,
])->exists();
$isPaid = in_array((int)$model->order->payment_status, [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD], true);
?>
<div class="info-box position-relative shadow-sm">
    <div class="info-box-content">
        <?php if ((int)$order->parent_id === 0) { ?>
            <p class="info-box-text small m-0 text-muted">
                <?= Yii::t('app', 'Order Sn') ?>: <?= Html::encode($order->sn) ?>
            </p>
        <?php } ?>
        <p class="info-box-text m-0">
            <?= Html::a($model->name, ['/mall/order/view', 'id' => $model->id]) ?>
        </p>
        <p class="info-box-text small m-0">
            <?= \common\helpers\Html::color($model->order->payment_status, Order::getStatusLabels($model->order->payment_status), [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD, Order::SHIPMENT_STATUS_SHIPPING, Order::SHIPMENT_STATUS_RECEIVED], [Order::PAYMENT_STATUS_UNPAID]) ?>
            <?= \common\helpers\Html::color($model->order->shipment_status, Order::getStatusLabels($model->order->shipment_status), [Order::SHIPMENT_STATUS_UNSHIPPED, Order::SHIPMENT_STATUS_PREPARING, Order::SHIPMENT_STATUS_SHIPPING, Order::SHIPMENT_STATUS_RECEIVED], [Order::PAYMENT_STATUS_UNPAID]) ?>
            <?= Yii::$app->formatter->asDatetime($model->created_at) ?>
            <i class="pull-right"><?= $this->context->getNumberByCurrency($model->price) ?></i>
        </p>
        <span class="info-box-text py-3 text-right">
            <!--<?= $model->order->payment_status != Order::PAYMENT_STATUS_UNPAID ? Html::a(Yii::t('app', 'Comment'), ['/mall/order/review', 'id' => $model->id], ['class' => 'btn btn-sm btn-info'], false) : '' ?>-->
            <?php if ($isParentUnpaidOnline) {
                echo Html::a(Yii::t('app', 'Continue Payment'), ['/mall/payment/index', 'id' => $order->id], ['class' => 'btn btn-sm btn-theme ml-3']);
            } elseif ((int)$model->order->shipment_status === Order::SHIPMENT_STATUS_SHIPPING) {
                echo Html::beginForm(['/mall/order/review'], 'post', ['class' => 'd-inline', 'data-mongoyia-buyer-received-post-guard' => '1']);
                echo Html::hiddenInput('id', (int)$model->order_id);
                echo Html::submitButton(Yii::t('app', 'Received'), ['class' => 'btn btn-sm btn-theme ml-3']);
                echo Html::endForm();
            } elseif ((int)$model->order->shipment_status === Order::SHIPMENT_STATUS_RECEIVED && $isPaid) {
                if ($hasReview) {
                    echo Html::tag('span', Yii::t('app', 'Reviewed'), ['class' => 'btn btn-sm btn-secondary ml-3 disabled']);
                } else {
                    echo Html::a(Yii::t('app', 'Write a review'), ['/mall/order/view', 'id' => $model->id], ['class' => 'btn btn-sm btn-theme ml-3']);
                }
            } elseif ((int)$model->order->shipment_status < Order::SHIPMENT_STATUS_SHIPPING && $isPaid) {
                echo Html::tag('span', Yii::t('app', 'Awaiting shipment'), ['class' => 'btn btn-sm btn-secondary ml-3 disabled']);
            } ?>
        </span>
    </div>
</div>
<?php } ?>
