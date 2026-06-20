<?php

use yii\grid\GridView;
use common\helpers\Html;
use common\components\enums\YesNo;
use common\models\mall\Product as ActiveModel;
use yii\helpers\Inflector;
use common\helpers\ArrayHelper;
use common\helpers\ImageHelper;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */

$this->title = Yii::t('app', 'Products');
$this->params['breadcrumbs'][] = $this->title;
//echo '<pre/>';
//foreach ($searchModel as $k=>$v){
//    echo $v.'<br/>';
//}
//var_dump($this->getStoreId());
//exit();
$info = [
    'dataProvider' => $dataProvider,
    'filterModel' => $searchModel,
    'tableOptions' => ['class' => 'table table-hover'],
    'columns' => [
        ['class' => 'yii\grid\CheckboxColumn','visible' => true,],
        'id',
        ['attribute' => 'store_id', 'visible' => $this->context->isMallPlatformOperator(), 'value' => function ($model) { return $model->store->name; }, 'filter' => Html::activeDropDownList($searchModel, 'store_id', $this->context->getStoresIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
        // 'category_id',
        ['attribute' => 'category_id', 'format' => 'raw', 'value' => function ($model) { return $model->category->name; }, 'filter' => Html::activeDropDownList($searchModel, 'category_id', \common\models\mall\Category::getTreeIdLabel(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
        'name',
        // ['attribute' => 'name', 'format' => 'raw', 'value' => function ($model) { return Html::field('name', $model->name); }, 'filter' => true,],
        'sku',
        // 'stock_code',
        'stock',
        // 'stock_warning',
        // 'weight',
        // 'volume',
        'price',
        'market_price',
        // 'cost_price',
        // 'wholesale_price',
        ['attribute' => 'thumb', 'filter' => false, 'format' => 'raw', 'value' => function ($model) { return ImageHelper::fancyBox($model->thumb); },],
        // 'image',
        // 'images:json',
        // 'brief:ntext',
        // 'content:ntext',
        'seo_url',
        // 'seo_title',
        // 'seo_keywords',
        // 'seo_description:ntext',
        // 'brand_id',
        // 'vendor_id',
        // 'attribute_set_id',
        ['attribute' => 'attribute_set_id', 'format' => 'raw', 'value' => function ($model) { return $model->attributeSet->name ?? '-'; }, 'filter' => Html::activeDropDownList($searchModel, 'attribute_set_id', \common\models\mall\AttributeSet::getIdLabel(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
        // 'star',
        // 'sales',
        // 'click',
        ['attribute' => 'type', 'value' => function ($model) { return ActiveModel::getTypesLabels($model->type); }, 'filter' => Html::activeDropDownList($searchModel, 'type', ActiveModel::getTypesLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
        ['attribute' => 'audit_status', 'format' => 'raw', 'value' => function ($model) {
            $labels = ['draft' => '草稿', 'submitted' => '待审核', 'approved' => '已通过', 'rejected' => '已驳回'];
            $class = $model->audit_status === 'approved' ? 'success' : ($model->audit_status === 'rejected' ? 'danger' : 'warning');
            return '<span class="badge badge-' . $class . '">' . Html::encode($labels[$model->audit_status] ?? $model->audit_status) . '</span>';
        }, 'filter' => Html::activeDropDownList($searchModel, 'audit_status', ['draft' => '草稿', 'submitted' => '待审核', 'approved' => '已通过', 'rejected' => '已驳回'], ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
        // ['attribute' => 'sort', 'format' => 'raw', 'value' => function ($model) { return Html::sort($model->sort); }, 'filter' => false,],
        //['attribute' => 'status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::isStatusActiveInactive($model->status) ? Html::status($model->status) : ActiveModel::getStatusLabels($model->status, true); }, 'filter' => Html::activeDropDownList($searchModel, 'status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),],
        //['attribute' => 'created_at', 'format' => 'datetime', 'filter' => false],
        // ['attribute' => 'updated_at', 'format' => 'datetime', 'filter' => false],
        // ['attribute' => 'created_by', 'value' => function ($model) { return $model->createdBy->nameAdmin ?? '-'; }, ],
        // ['attribute' => 'updated_by', 'value' => function ($model) { return $model->updatedBy->nameAdmin ?? '-'; }, ],

//        Html::actionsRedirect(),
    ]
];
if($sa){
    $info['columns'][] = ['attribute' => 'status', 'format' => 'raw', 'value' => function ($model) { return ActiveModel::isStatusActiveInactive($model->status) ? Html::status($model->status) : ActiveModel::getStatusLabels($model->status, true); }, 'filter' => Html::activeDropDownList($searchModel, 'status', ActiveModel::getStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')]),];
}
$info['columns'][] = [
    'header' => Yii::t('app', 'Actions'),
    'class' => 'yii\grid\ActionColumn',
    'template' => '{view} {edit} {approve} {reject} {delete}',
    'buttons' => [
        'view' => function ($url, $model, $key) {
            return Html::view(['view', 'id' => $model->id]);
        },
        'edit' => function ($url, $model, $key) {
            return Html::edit(['edit', 'id' => $model->id]);
        },
        'approve' => function ($url, $model, $key) use ($sa) {
            if (!$sa || $model->audit_status === 'approved') {
                return '';
            }
            return Html::edit(['approve', 'id' => $model->id], '通过', ['class' => 'btn btn-success btn-sm']);
        },
        'reject' => function ($url, $model, $key) use ($sa) {
            if (!$sa || $model->audit_status === 'rejected') {
                return '';
            }
            return Html::edit(['reject', 'id' => $model->id], '驳回', ['class' => 'btn btn-warning btn-sm']);
        },
        'delete' => function ($url, $model, $key){
            return Html::delete(['delete', 'id' => $model->id, 'soft' => true, 'tree' => false]);
        },
    ],
    'headerOptions' => ['class' => 'action-column'],
];
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= !is_null($this->title) ? Html::encode($this->title) : Inflector::camelize($this->context->id);?> <?= Html::aHelp(Yii::$app->params['helpUrl'][Yii::$app->language][$this->context->module->id . '_' . $this->context->id] ?? null) ?>
                    <a href="/backend/mall/product/index?ModelSearch%5Bstatus%5D=0">审核中商品</a>
                    <a href="/backend/mall/product/index">已上架商品</a>
                </h2>
                <div class="card-tools">
                    <?= Html::filterModal() ?>
                    <?= Html::create() ?>
                    <?= Html::export() ?>
                    <?= Html::import() ?>
                </div>
            </div>
<!--            <script>document.onload = function (){document.getElementById('modelsearch-status').value = '1'}</script>-->
            <div class="card-body">
                <?= $this->render('@backend/views/site/_select', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>

                <?= GridView::widget($info); ?>
            </div>
        </div>
    </div>
</div>

<?= $this->render('@backend/views/site/_filter', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
