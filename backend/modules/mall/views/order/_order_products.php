<?php

use common\models\mall\Order;
use common\models\mall\OrderProduct;
use yii\helpers\Html;

/* @var $model Order */

$isPlatformOperator = $this->context->isMallPlatformOperator();
$orderIds = (int)$model->parent_id === 0
    ? Order::find()->select('id')->where(['parent_id' => $model->id])->column()
    : [$model->id];
if (!$orderIds) {
    $orderIds = [$model->id];
}

$orderProducts = OrderProduct::find()
    ->where(['order_id' => $orderIds])
    ->orderBy(['order_id' => SORT_ASC, 'id' => SORT_ASC])
    ->all();
?>

<h5 class="mt-4"><?= Html::encode(Yii::t('app', 'Order Products')) ?></h5>
<table class="table table-bordered table-hover">
    <thead>
    <tr>
        <th><?= Html::encode(Yii::t('app', 'Order ID')) ?></th>
        <th><?= Html::encode(Yii::t('app', 'Store ID')) ?></th>
        <th><?= Html::encode(Yii::t('app', 'Product ID')) ?></th>
        <th><?= Html::encode(Yii::t('app', 'Name')) ?></th>
        <th><?= Html::encode(Yii::t('app', 'Sku')) ?></th>
        <th><?= Html::encode(Yii::t('app', 'Number')) ?></th>
        <th><?= Html::encode(Yii::t('app', 'Price')) ?></th>
        <th><?= Html::encode(Yii::t('app', 'Amount')) ?></th>
    </tr>
    </thead>
    <tbody>
    <?php if ($orderProducts): ?>
        <?php foreach ($orderProducts as $orderProduct): ?>
            <tr>
                <td>
                    <?php if ($isPlatformOperator || (int)$orderProduct->order_id === (int)$model->id): ?>
                        <?= Html::a('#' . $orderProduct->order_id, ['view', 'id' => $orderProduct->order_id]) ?>
                    <?php else: ?>
                        <span class="text-muted">#<?= (int)$orderProduct->order_id ?></span>
                    <?php endif; ?>
                </td>
                <td><?= Html::encode($orderProduct->store->name ?? $orderProduct->store_id) ?></td>
                <td><?= Html::a('#' . $orderProduct->product_id, ['/mall/product/view', 'id' => $orderProduct->product_id]) ?></td>
                <td>
                    <?= Html::encode($orderProduct->name) ?>
                    <?php if ((string)$orderProduct->product_attribute_value !== ''): ?>
                        <br><small class="text-muted"><?= Html::encode($orderProduct->product_attribute_value) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= Html::encode($orderProduct->sku) ?></td>
                <td><?= Html::encode($orderProduct->number) ?></td>
                <td><?= Html::encode($orderProduct->price) ?></td>
                <td><?= Html::encode(number_format((float)$orderProduct->price * (int)$orderProduct->number, 2, '.', '')) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="8" class="text-center text-muted"><?= Html::encode(Yii::t('app', 'No order products yet')) ?></td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
