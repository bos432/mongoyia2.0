<?php

use yii\helpers\Html;
use yii\widgets\DetailView;
use common\components\enums\YesNo;
use common\models\mall\Order as ActiveModel;
use common\models\mall\PaymentAttempt;

/* @var $this yii\web\View */
/* @var $model common\models\mall\Order */

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Orders'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
$isPlatformOperator = $this->context->isMallPlatformOperator();
$paymentOrderId = (int)($model->parent_id ?: $model->id);
$paymentAttempts = $isPlatformOperator ? PaymentAttempt::find()
    ->where(['order_id' => $paymentOrderId])
    ->orderBy(['id' => SORT_DESC])
    ->limit(5)
    ->all() : [];
$parentOrder = $isPlatformOperator && (int)$model->parent_id > 0 ? ActiveModel::findOne($model->parent_id) : null;
$childOrders = $isPlatformOperator && (int)$model->parent_id === 0 ? ActiveModel::find()->where(['parent_id' => $model->id])->orderBy(['id' => SORT_ASC])->all() : [];
?>
<div class="card order-view">
    <div class="card-header">
        <?php if ($isPlatformOperator): ?>
            <?= Html::a(Yii::t('app', 'Payment Attempts'), ['/mall/payment-attempt/index', 'ModelSearch' => ['order_id' => $paymentOrderId]], ['class' => 'btn btn-info']) ?>
        <?php endif; ?>
<!--        --><?php //= Html::a(Yii::t('app', 'Update'), ['edit', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
<!--        --><?php //= Html::a(Yii::t('app', 'Delete'), ['delete', 'id' => $model->id], [
//            'class' => 'btn btn-danger',
//            'data' => [
//                'confirm' => Yii::t('app', 'Are you sure you want to delete this item?'),
//                'method' => 'post',
//            ],
//        ]) ?>
    </div>

    <div class="card-body">

        <?= DetailView::widget([
            'model' => $model,
            'options' => ['class' => 'table table-bordered table-hover box'],
            'attributes' => [
                'id',
                ['attribute' => 'store_id', 'visible' => $isPlatformOperator, 'value' => function ($model) { return $model->store->name ?? '-'; }, ],
                ['attribute' => 'user_id', 'value' => function ($model) { return $model->user->username ?? '-'; }, ],
                'name',
                'sn',
                'consignee',
                'country_id',
                'province_id',
                'city_id',
                'district_id',
                'state',
                'address',
                'address1',
                'address2',
                'zipcode',
                'mobile',
                'email:email',
                'distance',
                'remark',
                'payment_method',
                'payment_fee',
                'payment_status',
                'paid_at:datetime',
                'shipment_id',
                'shipment_name',
                'shipment_fee',
                'shipment_status',
                'shipped_at:datetime',
                'product_amount',
                'amount',
                'extra_fee',
                'discount',
                'tax',
                'invoice',
                ['attribute' => 'type', 'value' => function ($model) { return ActiveModel::getTypeLabels($model->type); }, ],
                'sort',
                ['attribute' => 'status', 'value' => function ($model) { return ActiveModel::getStatusLabels($model->status, true); }, ],
                'created_at:datetime',
                'updated_at:datetime',
                ['attribute' => 'created_by', 'value' => function ($model) { return $model->createdBy->nameAdmin ?? '-'; }, ],
                ['attribute' => 'updated_by', 'value' => function ($model) { return $model->updatedBy->nameAdmin ?? '-'; }, ],
            ],
        ]) ?>

        <h5 class="mt-4"><?= Html::encode(Yii::t('app', 'Order Relationship')) ?></h5>
        <table class="table table-bordered table-hover">
            <tbody>
            <tr>
                <th style="width: 180px;"><?= Html::encode(Yii::t('app', 'Order Type')) ?></th>
                <td>
                    <?php if ((int)$model->parent_id === 0): ?>
                        <span class="badge badge-info"><?= Html::encode(Yii::t('app', 'Parent Order')) ?></span>
                    <?php else: ?>
                        <span class="badge badge-secondary"><?= Html::encode(Yii::t('app', 'Seller Order')) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?= Html::encode(Yii::t('app', 'Payment Order')) ?></th>
                <td>
                    <?php if ($isPlatformOperator): ?>
                        <?= Html::a('#' . $paymentOrderId, ['view', 'id' => $paymentOrderId]) ?>
                    <?php else: ?>
                        <span class="text-muted">#<?= (int)$paymentOrderId ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($parentOrder): ?>
                <tr>
                    <th><?= Html::encode(Yii::t('app', 'Parent Order')) ?></th>
                    <td>
                        <?= Html::a('#' . $parentOrder->id, ['view', 'id' => $parentOrder->id]) ?>
                        <span class="text-muted ml-2"><?= Html::encode($parentOrder->sn) ?></span>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ((int)$model->parent_id === 0): ?>
                <tr>
                    <th><?= Html::encode(Yii::t('app', 'Seller Orders')) ?></th>
                    <td><?= Html::encode(Yii::t('app', '{count} seller orders', ['count' => count($childOrders)])) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ($childOrders): ?>
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th><?= Html::encode(Yii::t('app', 'ID')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Store ID')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Amount')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Payment Status')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Shipment Status')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($childOrders as $childOrder): ?>
                    <tr>
                        <td><?= Html::a('#' . $childOrder->id, ['view', 'id' => $childOrder->id]) ?></td>
                        <td><?= Html::encode($childOrder->store->name ?? $childOrder->store_id) ?></td>
                        <td><?= Html::encode($childOrder->amount) ?></td>
                        <td><?= Html::encode(ActiveModel::getPaymentStatusLabels($childOrder->payment_status)) ?></td>
                        <td><?= Html::encode(ActiveModel::getShipmentStatusLabels($childOrder->shipment_status)) ?></td>
                        <td><?= Html::a(Yii::t('app', 'View'), ['view', 'id' => $childOrder->id], ['class' => 'btn btn-default btn-sm']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?= $this->render('_order_products', ['model' => $model]) ?>

        <?php if ($isPlatformOperator): ?>
            <h5 class="mt-4"><?= Html::encode(Yii::t('app', 'Recent Payment Attempts')) ?></h5>
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th><?= Html::encode(Yii::t('app', 'Provider')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Event')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Amount')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Result')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Error Message')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Created At')) ?></th>
                    <th><?= Html::encode(Yii::t('app', 'Actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($paymentAttempts): ?>
                    <?php foreach ($paymentAttempts as $attempt): ?>
                        <?php $badgeClass = $attempt->result === PaymentAttempt::RESULT_SUCCESS ? 'success' : ($attempt->result === PaymentAttempt::RESULT_FAILED ? 'danger' : 'secondary'); ?>
                        <tr>
                            <td><?= Html::encode($attempt->provider) ?></td>
                            <td><?= Html::encode($attempt->event) ?></td>
                            <td><?= Html::encode($attempt->amount . ' ' . $attempt->currency) ?></td>
                            <td><span class="badge badge-<?= $badgeClass ?>"><?= Html::encode($attempt->result) ?></span></td>
                            <td><?= Html::encode($attempt->error_message ?: '-') ?></td>
                            <td><?= Yii::$app->formatter->asDatetime($attempt->created_at) ?></td>
                            <td><?= Html::a(Yii::t('app', 'View'), ['/mall/payment-attempt/view', 'id' => $attempt->id], ['class' => 'btn btn-default btn-sm']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center text-muted"><?= Html::encode(Yii::t('app', 'No payment attempts yet')) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</div>
