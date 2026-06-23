<?php

use yii\grid\GridView;
use common\helpers\Html;
use common\models\mall\StoreFavorite as ActiveModel;
use yii\helpers\Inflector;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */

$this->title = Yii::t('app', 'Store Favorites');
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row" data-mongoyia-phase14-store-favorite-backend="MONGOYIA_FAVORITE_REVIEW_PHASE14_V1">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= !is_null($this->title) ? Html::encode($this->title) : Inflector::camelize($this->context->id);?></h2>
                <div class="card-tools">
                    <?= Html::filterModal() ?>
                    <?= Html::export() ?>
                </div>
            </div>
            <div class="card-body">
                <?= GridView::widget([
                    'dataProvider' => $dataProvider,
                    'filterModel' => $searchModel,
                    'tableOptions' => ['class' => 'table table-hover'],
                    'columns' => [
                        'id',
                        ['attribute' => 'store_id', 'visible' => $this->context->isAdmin(), 'value' => function ($model) { return $model->store->name ?? '-'; }, 'filter' => Html::activeDropDownList($searchModel, 'store_id', $this->context->getStoresIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['attribute' => 'user_id', 'value' => function ($model) { return $model->user->username ?? '-'; }, 'filter' => Html::activeDropDownList($searchModel, 'user_id', $this->context->getUsersIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        'name',
                        ['attribute' => 'status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::getStatusLabels($model->status, true); }, 'filter' => Html::activeDropDownList($searchModel, 'status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
                        ['attribute' => 'created_at', 'format' => 'datetime', 'filter' => false],
                    ],
                ]); ?>
            </div>
        </div>
    </div>
</div>

<?= $this->render('@backend/views/site/_filter', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
