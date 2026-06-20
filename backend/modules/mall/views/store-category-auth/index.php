<?php

use common\helpers\Html;
use common\models\mall\StoreCategoryAuth as ActiveModel;
use yii\grid\GridView;
use yii\helpers\Inflector;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */

$this->title = '店铺类目授权';
$this->params['breadcrumbs'][] = $this->title;
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
                        ['attribute' => 'store_id', 'value' => function ($model) { return $model->store->name ?? $model->store_id; }, 'filter' => Html::activeDropDownList($searchModel, 'store_id', $this->context->getStoresIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')])],
                        ['attribute' => 'category_id', 'value' => function ($model) { return $model->category->name ?? $model->category_id; }],
                        'source_application_id',
                        ['attribute' => 'audit_status', 'format' => 'raw', 'value' => function ($model) {
                            $class = $model->audit_status === ActiveModel::AUDIT_APPROVED ? 'success' : 'danger';
                            return '<span class="badge badge-' . $class . '">' . Html::encode(ActiveModel::getAuditStatusLabels($model->audit_status)) . '</span>';
                        }, 'filter' => Html::activeDropDownList($searchModel, 'audit_status', ActiveModel::getAuditStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')])],
                        'audit_remark',
                        'authorized_at:datetime',
                        'expires_at:datetime',
                        ['attribute' => 'status', 'format' => 'raw', 'value' => function ($model) { return Html::status($model->status); }, 'filter' => Html::activeDropDownList($searchModel, 'status', ActiveModel::getStatusLabels(null, true), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')])],
                        [
                            'header' => Yii::t('app', 'Actions'),
                            'class' => 'yii\grid\ActionColumn',
                            'template' => '{approve} {reject}',
                            'buttons' => [
                                'approve' => function ($url, $model) {
                                    if ($model->audit_status === ActiveModel::AUDIT_APPROVED && (int)$model->status === ActiveModel::STATUS_ACTIVE) {
                                        return '';
                                    }
                                    return Html::edit(['approve', 'id' => $model->id], '授权', ['class' => 'btn btn-success btn-sm']);
                                },
                                'reject' => function ($url, $model) {
                                    if ($model->audit_status === ActiveModel::AUDIT_REJECTED) {
                                        return '';
                                    }
                                    return Html::edit(['reject', 'id' => $model->id], '驳回', ['class' => 'btn btn-warning btn-sm']);
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
