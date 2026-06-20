<?php

use yii\grid\GridView;
use common\helpers\Html;
//use common\components\enums\YesNo;
use common\models\mall\Order as ActiveModel;
use yii\helpers\Inflector;
//use common\helpers\ArrayHelper;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */

$this->title = Yii::t('app', 'Orders');
$this->params['breadcrumbs'][] = $this->title;
//echo '<pre/>';
//var_dump($searchModel);exit();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= !is_null($this->title) ? Html::encode($this->title) : Inflector::camelize($this->context->id);?> <?= Html::aHelp(Yii::$app->params['helpUrl'][Yii::$app->language][$this->context->module->id . '_' . $this->context->id] ?? null) ?></h2>
                <div class="card-tools">
<!--                    --><?php //= Html::filterModal() ?>
<!--                    --><?php //= Html::createModal() ?>
<!--                    --><?php //= Html::export() ?>
<!--                    --><?php //= Html::import() ?>
                </div>
            </div>
            <div class="card-body">
                <?//= $this->render('@backend/views/site/_select', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
                <?php $cz = [
                        'view' => function ($url, $model, $key) {
                            return Html::view(['view', 'id' => $model->id], null, ['class' => 'btn btn-default btn-sm']);
                        },
                        'delete'=>function($url,$model,$key){
                            return;
                        }
                    ];
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
                        'store_name',
//                        'total_actual_amount',
//                        ['attribute' => 'store_id', 'value' => function ($model) { return $model->store_id; }, 'filter' => true],
////                        'price',
                        ['attribute' => 'total_actual_amount', 'value' => function ($model) {
                            return '$'.number_format($model['total_actual_amount'],2);
                        }],
//                    return $model->product->store_id; }],
//                        ['attribute' => 'store_id', 'visible' => $this->context->isAdmin(), 'value' => function ($model) {
//                    echo '<pre/>';
//                        var_dump($model);exit();
//                    return $model->product->store_id; }],
//                        ['attribute' => 'user_id', 'value' => function ($model) { return $model->user->email; }, 'filter' => true],
//                        'user_id',
                        // ['attribute' => 'name', 'format' => 'raw', 'value' => function ($model) { return Html::field('name', $model->name); }, 'filter' => true,],
//                        'sn',
                        // 'country_id',
                        // 'province_id',
                        // 'city_id',
                        // 'district_id',
                        // 'state',
//                        'address',
                        // 'address1',
                        // 'address2',
                        // 'zipcode',
//                        'mobile',
//                        'email:email',
                        // 'distance',
//                        'remark',
                        // 'payment_method',
                        // 'payment_fee',
//                        ['attribute' => 'payment_status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::getPaymentStatusLabels($model->payment_status); }, 'filter' => Html::activeDropDownList($searchModel, 'payment_status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        // 'paid_at:datetime',
                        // 'shipment_id',
                        // 'shipment_name',
                        // 'shipment_fee',
                        // 'shipment_status',
//                        ['attribute' => 'shipment_status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::getShipmentStatusLabels($model->shipment_status); }, 'filter' => Html::activeDropDownList($searchModel, 'shipment_status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        // 'shipped_at:datetime',
                        // 'product_amount',
//                        'amount',
                        // 'extra_fee',
                        // 'discount',
                        // 'tax',
                        // 'invoice',
                        // ['attribute' => 'type', 'value' => function ($model) { return ActiveModel::getTypeLabels($model->type); }, 'filter' => Html::activeDropDownList($searchModel, 'type', ActiveModel::getTypeLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        // ['attribute' => 'sort', 'format' => 'raw', 'value' => function ($model) { return Html::sort($model->sort); }, 'filter' => false,],
                        // ['attribute' => 'status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::isStatusActiveInactive($model->status) ? Html::status($model->status) : ActiveModel::getStatusLabels($model->status, true); }, 'filter' => Html::activeDropDownList($searchModel, 'status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
//                         ['attribute' => 'created_at', 'format' => 'datetime', 'filter' => false],
                        // ['attribute' => 'updated_at', 'format' => 'datetime', 'filter' => false],
                        // ['attribute' => 'created_by', 'value' => function ($model) { return $model->createdBy->nameAdmin ?? '-'; }, ],
                        // ['attribute' => 'updated_by', 'value' => function ($model) { return $model->updatedBy->nameAdmin ?? '-'; }, ],

//                        [
//                            'header' => Yii::t('app', 'Actions'),
//                            'class' => 'yii\grid\ActionColumn',
//                            'template' => '{view} {edit} {fh} {delete} {refund}',
//                            'buttons' => $cz,
//                            'headerOptions' => ['class' => 'action-column action-column-lg'],
//                        ],
                    ]
                ]); ?>
            </div>
        </div>
    </div>
</div>

<?php //= $this->render('@backend/modules/mall/views/order-product/_filter', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
