<?php

use common\helpers\Html;
use common\models\mall\PaymentAttempt as ActiveModel;
use yii\db\Expression;
use yii\grid\GridView;
use yii\helpers\Inflector;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */

$this->title = Yii::t('app', 'Payment Attempts');
$this->params['breadcrumbs'][] = $this->title;
$isPlatformOperator = $this->context->isMallPlatformOperator();

$duplicateConditions = ['or'];
foreach ($dataProvider->getModels() as $model) {
    if ($model->business_key && $model->payload_hash) {
        $duplicateConditions[] = [
            'business_key' => $model->business_key,
            'payload_hash' => $model->payload_hash,
        ];
    }
}

$duplicateCounts = [];
if (count($duplicateConditions) > 1) {
    $duplicateRows = ActiveModel::find()
        ->select([
            'business_key',
            'payload_hash',
            'duplicate_count' => new Expression('COUNT(*)'),
        ])
        ->where($duplicateConditions)
        ->groupBy(['business_key', 'payload_hash'])
        ->asArray()
        ->all();

    foreach ($duplicateRows as $row) {
        $duplicateCounts[$row['business_key'] . '|' . $row['payload_hash']] = (int)$row['duplicate_count'];
    }
}

$businessKeyConditions = ['or'];
foreach ($dataProvider->getModels() as $model) {
    if ($model->business_key) {
        $businessKeyConditions[] = ['business_key' => $model->business_key];
    }
}

$businessKeyCounts = [];
if (count($businessKeyConditions) > 1) {
    $businessRows = ActiveModel::find()
        ->select([
            'business_key',
            'event_count' => new Expression('COUNT(*)'),
        ])
        ->where($businessKeyConditions)
        ->groupBy(['business_key'])
        ->asArray()
        ->all();

    foreach ($businessRows as $row) {
        $businessKeyCounts[$row['business_key']] = (int)$row['event_count'];
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title ?: Inflector::camelize($this->context->id)) ?></h2>
                <div class="card-tools">
                    <?= Html::filterModal() ?>
                </div>
            </div>
            <div class="card-body">
                <?= GridView::widget([
                    'dataProvider' => $dataProvider,
                    'filterModel' => $searchModel,
                    'tableOptions' => ['class' => 'table table-hover'],
                    'columns' => [
                        'id',
                        ['attribute' => 'store_id', 'visible' => $isPlatformOperator, 'value' => function ($model) { return $model->store->name ?? $model->store_id; }, 'filter' => Html::activeDropDownList($searchModel, 'store_id', $this->context->getStoresIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        'order_id',
                        ['attribute' => 'user_id', 'value' => function ($model) { return $model->user->email ?? $model->user->username ?? $model->user_id; }, 'filter' => true],
                        ['attribute' => 'provider', 'filter' => true],
                        ['attribute' => 'event', 'filter' => true],
                        ['attribute' => 'business_key', 'contentOptions' => ['style' => 'max-width:240px;word-break:break-all;']],
                        'merchant_transaction_id',
                        ['attribute' => 'payload_hash', 'contentOptions' => ['style' => 'max-width:180px;word-break:break-all;']],
                        ['label' => Yii::t('app', 'Business Events'), 'format' => 'raw', 'value' => function ($model) use ($businessKeyCounts) {
                            $count = $model->business_key ? ($businessKeyCounts[$model->business_key] ?? 1) : 1;
                            $class = $count > 1 ? 'warning' : 'secondary';
                            $label = '<span class="badge badge-' . $class . '">' . Html::encode($count . 'x') . '</span>';
                            if ($count <= 1 || !$model->business_key) {
                                return $label;
                            }

                            return Html::a($label, [
                                'index',
                                'ModelSearch[business_key]' => $model->business_key,
                            ]);
                        }],
                        ['label' => Yii::t('app', 'Duplicates'), 'format' => 'raw', 'value' => function ($model) use ($duplicateCounts) {
                            $count = 1;
                            if ($model->business_key && $model->payload_hash) {
                                $count = $duplicateCounts[$model->business_key . '|' . $model->payload_hash] ?? 1;
                            }

                            $class = $count > 1 ? 'danger' : 'secondary';
                            $label = '<span class="badge badge-' . $class . '">' . Html::encode($count . 'x') . '</span>';
                            if ($count <= 1 || !$model->business_key || !$model->payload_hash) {
                                return $label;
                            }

                            return Html::a($label, [
                                'index',
                                'ModelSearch[business_key]' => $model->business_key,
                                'ModelSearch[payload_hash]' => $model->payload_hash,
                            ]);
                        }],
                        'amount',
                        'currency',
                        ['attribute' => 'result', 'format' => 'raw', 'value' => function ($model) {
                            $class = $model->result === ActiveModel::RESULT_SUCCESS ? 'success' : ($model->result === ActiveModel::RESULT_FAILED ? 'danger' : 'secondary');
                            return '<span class="badge badge-' . $class . '">' . Html::encode($model->result) . '</span>';
                        }, 'filter' => true],
                        ['attribute' => 'error_message', 'contentOptions' => ['style' => 'max-width:220px;white-space:normal;']],
                        'request_method',
                        'request_ip',
                        ['attribute' => 'created_at', 'format' => 'datetime', 'filter' => false],
                        [
                            'header' => Yii::t('app', 'Actions'),
                            'class' => 'yii\grid\ActionColumn',
                            'template' => '{view}',
                            'buttons' => [
                                'view' => function ($url, $model) {
                                    return Html::view(['view', 'id' => $model->id], null, ['class' => 'btn btn-default btn-sm']);
                                },
                            ],
                            'headerOptions' => ['class' => 'action-column'],
                        ],
                    ],
                ]); ?>
            </div>
        </div>
    </div>
</div>

<?= $this->render('@backend/views/site/_filter', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
