<?php

use common\helpers\Html;
use common\models\mall\StoreCategoryAuth;
use yii\grid\GridView;

/* @var $this yii\web\View */
/* @var $application common\models\mall\MerchantApplication */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = '入驻类目授权 #' . $application->id;
$this->params['breadcrumbs'][] = ['label' => '商家入驻审核', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
            </div>
            <div class="card-body">
                <?= GridView::widget([
                    'dataProvider' => $dataProvider,
                    'tableOptions' => ['class' => 'table table-hover'],
                    'columns' => [
                        'id',
                        ['attribute' => 'store_id', 'value' => function ($model) { return $model->store->name ?? $model->store_id; }],
                        ['attribute' => 'category_id', 'value' => function ($model) { return $model->category->name ?? $model->category_id; }],
                        ['attribute' => 'audit_status', 'format' => 'raw', 'value' => function ($model) {
                            $class = $model->audit_status === StoreCategoryAuth::AUDIT_APPROVED ? 'success' : 'danger';
                            return '<span class="badge badge-' . $class . '">' . Html::encode(StoreCategoryAuth::getAuditStatusLabels($model->audit_status)) . '</span>';
                        }],
                        'audit_remark',
                        'authorized_at:datetime',
                        'expires_at:datetime',
                    ],
                ]); ?>
            </div>
        </div>
    </div>
</div>
