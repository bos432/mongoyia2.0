<?php
use frontend\helpers\Url;
use yii\helpers\Html;
use common\models\mall\Order;

$isPaid = (int)$model->payment_status === (int)Order::PAYMENT_STATUS_PAID;
$this->title = $model->payment_method == Order::PAYMENT_METHOD_COD
    ? Yii::t('mall', 'Order has been confirmed')
    : ($isPaid ? Yii::t('mall', 'Order has been paid successfully') : Yii::t('mall', 'Order created, waiting for payment confirmation'));
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row page-section">
    <div class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-xs-12 px-4">
        <div class="card message-send-view">
            <div class="card-header text-center">
                <?= Html::encode($this->title) ?>
            </div>

            <div class="card-body">
                <p class="attention-icon"><i class="fa <?= $isPaid || $model->payment_method == Order::PAYMENT_METHOD_COD ? 'fa-check text-success' : 'fa-clock-o text-warning' ?>"></i></p>
                <div class="form-group text-center">
                    <?php if ($model->payment_method == Order::PAYMENT_METHOD_COD || $isPaid) { ?>
                        <p><?= Yii::t('mall', 'Thank you! We will dispatch order ') ?><?= $model->sn ?> <?= Yii::t('mall', ' as soon as possible') ?></p>
                    <?php } else { ?>
                        <p><?= Yii::t('mall', 'Order ') ?><?= $model->sn ?> <?= Yii::t('mall', ' has been created. Payment confirmation has not been received yet.') ?></p>
                    <?php } ?>
                </div>
                <div class="form-group text-center pt-3">
                    <?= Html::a(Yii::t('mall', 'Go home to order more'), ['/'], ['class' => 'btn btn-success control-full']) ?>
                </div>

            </div>
        </div>
    </div>
</div>
