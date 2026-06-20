<?php
use yii\helpers\Html;

/* @var $model \common\models\mall\Order */

$this->title = Yii::t('app', 'Payment');
$amount = $this->context->getNumberByCurrency($model->amount);
?>

<section class="page-section payment-index" data-mongoyia-mobile-ui="payment">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 col-lg-offset-3 col-md-6 col-md-offset-3 col-sm-12">
                <div class="card message-send-view">
                    <div class="card-header">
                        <?= Html::encode($this->title) ?>
                    </div>

                    <div class="card-body p-5">
                        <div class="payment-order-summary">
                            <p><strong><?= Yii::t('mall', 'Order Number') ?>:</strong> <?= Html::encode($model->sn) ?></p>
                            <p><strong><?= Yii::t('mall', 'Amount Payable') ?>:</strong> <?= Html::encode($amount) ?></p>
                        </div>
                        <?= Html::a(Yii::t('mall', 'Pay with LianLian'), ['/mall/payment/lianlian', 'id' => $model->id], ['class' => 'btn btn-success control-full']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
