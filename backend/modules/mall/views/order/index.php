<?php

use yii\grid\GridView;
use common\helpers\Html;
//use common\components\enums\YesNo;
use common\models\mall\Order as ActiveModel;
use common\models\mall\PaymentAttempt;
use yii\db\Expression;
use yii\helpers\Inflector;
use yii\helpers\Url;
//use common\helpers\ArrayHelper;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */

$this->title = Yii::t('app', 'Orders');
$this->params['breadcrumbs'][] = $this->title;
$isPlatformOperator = $this->context->isMallPlatformOperator();
//echo '<pre/>';
//var_dump($searchModel);exit();
$visibleOrderIds = [];
$paymentOrderIds = [];
foreach ($dataProvider->getModels() as $order) {
    $visibleOrderIds[] = (int)$order->id;
    $paymentOrderIds[] = (int)($order->parent_id ?: $order->id);
}
$visibleOrderIds = array_unique($visibleOrderIds);
$paymentOrderIds = array_unique($paymentOrderIds);
$childOrderCounts = $visibleOrderIds ? ActiveModel::find()
    ->select([
        'parent_id',
        'total' => new Expression('COUNT(*)'),
    ])
    ->where(['parent_id' => $visibleOrderIds])
    ->groupBy('parent_id')
    ->indexBy('parent_id')
    ->asArray()
    ->all() : [];
$paymentAttemptStats = $paymentOrderIds ? PaymentAttempt::find()
    ->select([
        'order_id',
        'total' => new Expression('COUNT(*)'),
        'failed' => new Expression('SUM(result = :failed)', [':failed' => PaymentAttempt::RESULT_FAILED]),
    ])
    ->where(['order_id' => $paymentOrderIds])
    ->groupBy('order_id')
    ->indexBy('order_id')
    ->asArray()
    ->all() : [];
$postActionButton = static function (array $url, string $label, array $options = [], string $guard = 'logistics-workflow'): string {
    $route = array_shift($url);
    $class = $options['class'] ?? 'btn btn-default btn-sm';
    $html = '<form method="post" action="' . Html::encode(Url::to([$route])) . '" class="d-inline" data-mongoyia-order-logistics-post-guard="' . Html::encode($guard) . '">';
    $html .= '<input type="hidden" name="' . Html::encode(Yii::$app->request->csrfParam) . '" value="' . Html::encode(Yii::$app->request->csrfToken) . '">';
    foreach ($url as $name => $value) {
        $html .= '<input type="hidden" name="' . Html::encode((string)$name) . '" value="' . Html::encode((string)$value) . '">';
    }
    $html .= '<button type="submit" class="' . Html::encode($class) . '">' . Html::encode($label) . '</button>';
    $html .= '</form>';

    return $html;
};
$refundPostButton = static function (int $orderId): string {
    $html = '<form method="post" action="' . Html::encode(Url::to(['edit-status'])) . '" class="d-inline" data-mongoyia-order-refund-post-guard="1">';
    $html .= '<input type="hidden" name="' . Html::encode(Yii::$app->request->csrfParam) . '" value="' . Html::encode(Yii::$app->request->csrfToken) . '">';
    $html .= '<input type="hidden" name="id" value="' . (int)$orderId . '">';
    $html .= '<input type="hidden" name="status" value="' . (int)ActiveModel::PAYMENT_STATUS_REFUND . '">';
    $html .= '<button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'' . Html::encode(Yii::t('app', 'Are you sure to do this operation?')) . '\')">' . Html::encode(Yii::t('app', 'Refund')) . '</button>';
    $html .= '</form>';

    return $html;
};
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= !is_null($this->title) ? Html::encode($this->title) : Inflector::camelize($this->context->id);?> <?= Html::aHelp(Yii::$app->params['helpUrl'][Yii::$app->language][$this->context->module->id . '_' . $this->context->id] ?? null) ?></h2>
                <div class="card-tools">
                    <?= Html::filterModal() ?>
                    <?php if ($isPlatformOperator): ?>
                        <?= Html::createModal() ?>
                        <?= Html::export() ?>
                        <?= Html::import() ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php // echo $this->render('@backend/views/site/_select', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
                <?php $cz = [
                        'view' => function ($url, $model, $key) {
                            return Html::view(['view', 'id' => $model->id], null, ['class' => 'btn btn-default btn-sm']);
                        },
                        'edit' => function ($url, $model, $key) {
                            return Html::editModal(['edit-ajax', 'id' => $model->id]);
                        },
                        'fh' => function ($url, $model, $key) {
                            return Html::editModal(['fh-ajax', 'id' => $model->id],'发货');
                        },
                        'prepare' => function ($url, $model, $key) use ($postActionButton) {
                            return in_array((int)$model->payment_status, [ActiveModel::PAYMENT_STATUS_PAID, ActiveModel::PAYMENT_STATUS_COD], true)
                                && (int)$model->shipment_status === ActiveModel::SHIPMENT_STATUS_UNSHIPPED
                                ? $postActionButton(['logistics-status-batch', 'ids' => $model->id, 'target_status' => ActiveModel::SHIPMENT_STATUS_PREPARING, 'apply' => 1], Yii::t('app', 'Prepare'), ['class' => 'btn btn-info btn-sm'], 'logistics-status-batch')
                                : '';
                        },
                        'receive' => function ($url, $model, $key) use ($postActionButton) {
                            return in_array((int)$model->payment_status, [ActiveModel::PAYMENT_STATUS_PAID, ActiveModel::PAYMENT_STATUS_COD], true)
                                && (int)$model->shipment_status === ActiveModel::SHIPMENT_STATUS_SHIPPING
                                ? $postActionButton(['logistics-status-batch', 'ids' => $model->id, 'target_status' => ActiveModel::SHIPMENT_STATUS_RECEIVED, 'apply' => 1], Yii::t('app', 'Receive'), ['class' => 'btn btn-success btn-sm'], 'logistics-status-batch')
                                : '';
                        },
                        'review' => function ($url, $model, $key) use ($isPlatformOperator, $postActionButton) {
                            return $isPlatformOperator
                                && (int)$model->shipment_status >= ActiveModel::SHIPMENT_STATUS_SHIPPING
                                && (int)$model->logistics_review_status !== ActiveModel::LOGISTICS_REVIEW_PASSED
                                ? $postActionButton(['logistics-review-batch', 'ids' => $model->id, 'review_status' => ActiveModel::LOGISTICS_REVIEW_PASSED, 'remark' => 'platform_port_review_passed', 'apply' => 1], Yii::t('app', 'Review Passed'), ['class' => 'btn btn-primary btn-sm'], 'logistics-review-batch')
                                : '';
                        },
                        'delete' => function ($url, $model, $key) {
                            return Html::delete(['delete', 'id' => $model->id, 'soft' => true], Yii::t('app', 'Delete'));
                        },
                        'refund' => function ($url, $model, $key) use ($refundPostButton) {
                            return $model->parent_id == 0 && $model->payment_method == ActiveModel::PAYMENT_METHOD_PAY && $model->payment_status == ActiveModel::PAYMENT_STATUS_PAID ? $refundPostButton((int)$model->id) : '';
                        }
                    ];
                    $actionTemplate = '{view} {edit} {prepare} {fh} {receive} {review} {delete} {refund}';
                    if (!$isPlatformOperator) {
                        unset($cz['edit'], $cz['delete'], $cz['refund']);
                        $actionTemplate = '{view} {prepare} {fh} {receive}';
                    }
                ?>
                <?= GridView::widget([
                    'dataProvider' => $dataProvider,
//                    'filterModel' => $searchModel,
                    'tableOptions' => ['class' => 'table table-hover'],
                    'columns' => [
                        [
                            'class' => 'yii\grid\CheckboxColumn',
                            'visible' => false,
                        ],

                        'id',
                        ['label' => Yii::t('app', 'Order Type'), 'format' => 'raw', 'value' => function ($model) use ($childOrderCounts, $isPlatformOperator) {
                            if ((int)$model->parent_id === 0) {
                                $childCount = (int)($childOrderCounts[$model->id]['total'] ?? 0);
                                $badge = '<span class="badge badge-info">' . Html::encode(Yii::t('app', 'Parent Order')) . '</span>';
                                if ($childCount > 0) {
                                    $badge .= '<br><small class="text-muted">' . Html::encode(Yii::t('app', '{count} seller orders', ['count' => $childCount])) . '</small>';
                                }
                                return $badge;
                            }

                            return '<span class="badge badge-secondary">' . Html::encode(Yii::t('app', 'Seller Order')) . '</span><br>'
                                . ($isPlatformOperator ? Html::a('#' . (int)$model->parent_id, ['view', 'id' => $model->parent_id], ['class' => 'small']) : '<span class="small text-muted">#' . (int)$model->parent_id . '</span>');
                        },],
                        ['attribute' => 'store_id', 'visible' => $isPlatformOperator, 'value' => function ($model) { return $model->store->name; }, 'filter' => Html::activeDropDownList($searchModel, 'store_id', $this->context->getStoresIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['attribute' => 'user_id', 'value' => function ($model) { return $model->user->email; }, 'filter' => true],
                        'user_id',
                        // ['attribute' => 'name', 'format' => 'raw', 'value' => function ($model) { return Html::field('name', $model->name); }, 'filter' => true,],
                        'sn',
                        // 'country_id',
                        // 'province_id',
                        // 'city_id',
                        // 'district_id',
                        // 'state',
                        'address',
                        // 'address1',
                        // 'address2',
                        // 'zipcode',
                        'mobile',
                        'email:email',
                        // 'distance',
                        'remark',
                        // 'payment_method',
                        // 'payment_fee',
                        ['attribute' => 'payment_status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::getPaymentStatusLabels($model->payment_status); }, 'filter' => Html::activeDropDownList($searchModel, 'payment_status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['label' => Yii::t('app', 'Payment Attempts'), 'format' => 'raw', 'value' => function ($model) use ($paymentAttemptStats, $isPlatformOperator) {
                            if (!$isPlatformOperator) {
                                return '<span class="text-muted">-</span>';
                            }

                            $paymentOrderId = (int)($model->parent_id ?: $model->id);
                            $stats = $paymentAttemptStats[$paymentOrderId] ?? ['total' => 0, 'failed' => 0];
                            $total = (int)$stats['total'];
                            $failed = (int)$stats['failed'];
                            $class = $failed > 0 ? 'danger' : ($total > 0 ? 'success' : 'secondary');
                            $label = $failed > 0 ? Yii::t('app', '{total} logs, {failed} failed', ['total' => $total, 'failed' => $failed]) : Yii::t('app', '{total} logs', ['total' => $total]);
                            return Html::a('<span class="badge badge-' . $class . '">' . Html::encode($label) . '</span>', ['/mall/payment-attempt/index', 'ModelSearch' => ['order_id' => $paymentOrderId]]);
                        },],
                        // 'paid_at:datetime',
                        // 'shipment_id',
                        // 'shipment_name',
                        // 'shipment_fee',
                        // 'shipment_status',
                        ['attribute' => 'shipment_status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::getShipmentStatusLabels($model->shipment_status); }, 'filter' => Html::activeDropDownList($searchModel, 'shipment_status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['attribute' => 'logistics_review_status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::getLogisticsReviewStatusLabels((int)$model->logistics_review_status); }, 'visible' => $isPlatformOperator, 'filter' => Html::activeDropDownList($searchModel, 'logistics_review_status', ActiveModel::getLogisticsReviewStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        // 'shipped_at:datetime',
                        // 'product_amount',
                        'amount',
                        // 'extra_fee',
                        // 'discount',
                        // 'tax',
                        // 'invoice',
                        // ['attribute' => 'type', 'value' => function ($model) { return ActiveModel::getTypeLabels($model->type); }, 'filter' => Html::activeDropDownList($searchModel, 'type', ActiveModel::getTypeLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        // ['attribute' => 'sort', 'format' => 'raw', 'value' => function ($model) { return Html::sort($model->sort); }, 'filter' => false,],
                        // ['attribute' => 'status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::isStatusActiveInactive($model->status) ? Html::status($model->status) : ActiveModel::getStatusLabels($model->status, true); }, 'filter' => Html::activeDropDownList($searchModel, 'status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        // ['attribute' => 'created_at', 'format' => 'datetime', 'filter' => false],
                        // ['attribute' => 'updated_at', 'format' => 'datetime', 'filter' => false],
                        // ['attribute' => 'created_by', 'value' => function ($model) { return $model->createdBy->nameAdmin ?? '-'; }, ],
                        // ['attribute' => 'updated_by', 'value' => function ($model) { return $model->updatedBy->nameAdmin ?? '-'; }, ],

                        [
                            'header' => Yii::t('app', 'Actions'),
                            'class' => 'yii\grid\ActionColumn',
                            'template' => $actionTemplate,
                            'buttons' => $cz,
                            'headerOptions' => ['class' => 'action-column action-column-lg'],
                        ],
                    ]
                ]); ?>
            </div>
        </div>
    </div>
</div>

<?= $this->render('@backend/views/site/_filter', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
