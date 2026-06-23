<?php
use yii\helpers\Html;

/* @var $model \common\models\mall\Order */
/* @var $paymentChannels array */

$this->title = Yii::t('app', 'Payment');
$amount = $this->context->getNumberByCurrency($model->amount);
$paymentChannels = $paymentChannels ?? [];
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
                        <div class="payment-channel-list" data-mongoyia-phase11-payment-channel-list>
                            <?php if ($paymentChannels): ?>
                                <?php foreach ($paymentChannels as $channel): ?>
                                    <?= Html::a(
                                        Yii::t('mall', 'Pay with {provider}', ['provider' => $channel['label']]),
                                        [$channel['route'], 'id' => $model->id],
                                        [
                                            'class' => $channel['class'],
                                            'data-mongoyia-phase11-payment-channel' => $channel['provider'],
                                            'data-mongoyia-phase11-payment-store-id' => (int)($channel['store_id'] ?? 0),
                                        ]
                                    ) ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="alert alert-warning" data-mongoyia-phase11-payment-no-channel>
                                    <?= Yii::t('mall', 'No online payment channel is currently available. Please contact customer service.') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
