<?php

use yii\helpers\Html;
use yii\widgets\DetailView;

/* @var $this yii\web\View */
/* @var $model common\models\mall\PaymentAttempt */

$this->title = Yii::t('app', 'Payment Attempt') . ' #' . $model->id;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Payment Attempts'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

$payload = $model->payload;
$decoded = json_decode($payload, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $payload = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
?>

<div class="card payment-attempt-view">
    <div class="card-header">
        <?= Html::a(Yii::t('app', 'Back'), ['index'], ['class' => 'btn btn-default']) ?>
    </div>
    <div class="card-body">
        <?= DetailView::widget([
            'model' => $model,
            'options' => ['class' => 'table table-bordered table-hover box'],
            'attributes' => [
                'id',
                ['attribute' => 'store_id', 'value' => function ($model) { return $model->store->name ?? $model->store_id; }],
                'order_id',
                ['attribute' => 'user_id', 'value' => function ($model) { return $model->user->email ?? $model->user->username ?? $model->user_id; }],
                'provider',
                'event',
                'business_key',
                'merchant_transaction_id',
                'gateway_transaction_id',
                'payload_hash',
                'amount',
                'currency',
                'request_method',
                'request_ip',
                'result',
                'error_message',
                'processed_at:datetime',
                'created_at:datetime',
                'updated_at:datetime',
            ],
        ]) ?>

        <h5><?= Html::encode(Yii::t('app', 'Payload')) ?></h5>
        <pre style="white-space: pre-wrap; word-break: break-word; max-height: 520px; overflow: auto;"><?= Html::encode($payload) ?></pre>
    </div>
</div>
