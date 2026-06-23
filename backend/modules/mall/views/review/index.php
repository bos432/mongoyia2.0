<?php

use yii\grid\GridView;
use common\helpers\Html;
use common\components\enums\YesNo;
use common\models\mall\Review as ActiveModel;
use yii\helpers\Inflector;
use common\helpers\ArrayHelper;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */

$this->title = Yii::t('app', 'Reviews');
$this->params['breadcrumbs'][] = $this->title;
$hasReviewModeration = (new ActiveModel())->hasAttribute('moderation_status');
?>

<div class="row" data-mongoyia-phase14-review-moderation="MONGOYIA_FAVORITE_REVIEW_PHASE14_V1">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= !is_null($this->title) ? Html::encode($this->title) : Inflector::camelize($this->context->id);?> <?= Html::aHelp(Yii::$app->params['helpUrl'][Yii::$app->language][$this->context->module->id . '_' . $this->context->id] ?? null) ?></h2>
                <div class="card-tools">
                    <?= Html::filterModal() ?>
                    <?= Html::createModal() ?>
                    <?= Html::export() ?>
                    <?= Html::import() ?>
                </div>
            </div>
            <div class="card-body">
                <?//= $this->render('@backend/views/site/_select', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>

                <?= GridView::widget([
                    'dataProvider' => $dataProvider,
                    'filterModel' => $searchModel,
                    'tableOptions' => ['class' => 'table table-hover'],
                    'columns' => [
                        [
                            'class' => 'yii\grid\CheckboxColumn',
                            'visible' => false,
                        ],

                        'id',
                        ['attribute' => 'store_id', 'visible' => $this->context->isAdmin(), 'value' => function ($model) { return $model->store->name; }, 'filter' => Html::activeDropDownList($searchModel, 'store_id', $this->context->getStoresIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        // ['attribute' => 'parent_id', 'value' => function ($model) { return $model->parent->name ?? '-'; }, 'filter' => Html::activeDropDownList($searchModel, 'parent_id', ActiveModel::getTreeIdLabel(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],,
                        'product_id',
                        ['attribute' => 'user_id', 'value' => function ($model) { return $model->user->username ?? '-'; }, 'filter' => Html::activeDropDownList($searchModel, 'user_id', $this->context->getUsersIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['attribute' => 'name', 'format' => 'raw', 'value' => function ($model) { return Html::field('name', $model->name); }, 'filter' => true,],
                        'order_id',
                        'star',
                        'content:ntext',
                        'point',
                        // 'like',
                        // ['attribute' => 'type', 'value' => function ($model) { return ActiveModel::getTypeLabels($model->type); }, 'filter' => Html::activeDropDownList($searchModel, 'type', ActiveModel::getTypeLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['attribute' => 'sort', 'format' => 'raw', 'value' => function ($model) { return Html::sort($model->sort); }, 'filter' => false, 'headerOptions' => ['data-mongoyia-phase14-review-sort' => 'MONGOYIA_FAVORITE_REVIEW_PHASE14_V1']],
                        ['attribute' => 'moderation_status', 'visible' => $hasReviewModeration, 'value' => function ($model) { return ActiveModel::getModerationStatusLabels($model->moderation_status, true); }, 'filter' => Html::activeDropDownList($searchModel, 'moderation_status', ActiveModel::getModerationStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['attribute' => 'moderation_remark', 'visible' => $hasReviewModeration, 'contentOptions' => ['style' => 'max-width:220px;white-space:normal;']],
                        ['attribute' => 'moderated_at', 'visible' => $hasReviewModeration, 'format' => 'datetime', 'filter' => false],
                        ['attribute' => 'status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::isStatusActiveInactive($model->status) ? Html::status($model->status) : ActiveModel::getStatusLabels($model->status, true); }, 'filter' => Html::activeDropDownList($searchModel, 'status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['attribute' => 'created_at', 'format' => 'datetime', 'filter' => false],
                        // ['attribute' => 'updated_at', 'format' => 'datetime', 'filter' => false],
                        // ['attribute' => 'created_by', 'value' => function ($model) { return $model->createdBy->nameAdmin ?? '-'; }, ],
                        // ['attribute' => 'updated_by', 'value' => function ($model) { return $model->updatedBy->nameAdmin ?? '-'; }, ],

                        [
                            'header' => Yii::t('app', 'Moderation'),
                            'format' => 'raw',
                            'visible' => $hasReviewModeration,
                            'value' => function ($model) {
                                $csrf = '<input type="hidden" name="' . Html::encode(Yii::$app->request->csrfParam) . '" value="' . Html::encode(Yii::$app->request->csrfToken) . '">';
                                $button = function (string $route, string $label, string $class) use ($model, $csrf) {
                                    return '<form method="post" action="' . Html::encode(Url::to([$route])) . '" class="d-inline" data-mongoyia-review-moderation-post-guard="1">'
                                        . $csrf
                                        . '<input type="hidden" name="id" value="' . (int)$model->id . '">'
                                        . '<button type="submit" class="' . Html::encode($class) . '" data-confirm="' . Html::encode(Yii::t('app', 'Are you sure?')) . '">' . Html::encode($label) . '</button>'
                                        . '</form>';
                                };

                                return implode(' ', [
                                    $button('approve', Yii::t('app', 'Approve'), 'btn btn-success btn-sm'),
                                    $button('reject', Yii::t('app', 'Reject'), 'btn btn-warning btn-sm'),
                                    $button('mark-violation', Yii::t('app', 'Violation'), 'btn btn-danger btn-sm'),
                                ]);
                            },
                        ],
                        Html::actionsModal(),
                    ]
                ]); ?>
            </div>
        </div>
    </div>
</div>

<?= $this->render('@backend/views/site/_filter', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
