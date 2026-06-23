<?php

use common\helpers\Html;
use common\models\mall\Category;
use common\models\mall\MerchantApplication as ActiveModel;
use yii\grid\GridView;
use yii\helpers\Inflector;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */

$this->title = '商家入驻审核';
$this->params['breadcrumbs'][] = $this->title;
$auditPostButton = static function (string $route, int $id, string $label, string $class, string $guard): string {
    return '<form method="post" action="' . Html::encode(Url::to([$route])) . '" class="d-inline" data-mongoyia-merchant-application-post-guard="' . Html::encode($guard) . '">'
        . '<input type="hidden" name="' . Html::encode(Yii::$app->request->csrfParam) . '" value="' . Html::encode(Yii::$app->request->csrfToken) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="' . Html::encode($class) . '">' . Html::encode($label) . '</button>'
        . '</form>';
};
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
                        ['attribute' => 'store_id', 'value' => function ($model) { return $model->store->name ?? ($model->store_id ?: '-'); }, 'filter' => Html::activeDropDownList($searchModel, 'store_id', $this->context->getStoresIdName(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')])],
                        ['attribute' => 'user_id', 'value' => function ($model) { return $model->user->username ?? $model->user_id; }],
                        'applicant_name',
                        'mobile',
                        'company_name',
                        ['attribute' => 'requested_category_ids', 'format' => 'raw', 'value' => function ($model) {
                            $names = Category::find()->select('name')->where(['id' => $model->requestedCategoryIds()])->column();
                            return Html::encode($names ? implode(', ', $names) : '-');
                        }],
                        ['attribute' => 'audit_status', 'format' => 'raw', 'value' => function ($model) {
                            $class = $model->audit_status === ActiveModel::AUDIT_APPROVED ? 'success' : ($model->audit_status === ActiveModel::AUDIT_REJECTED ? 'danger' : 'warning');
                            return '<span class="badge badge-' . $class . '">' . Html::encode(ActiveModel::getAuditStatusLabels($model->audit_status)) . '</span>';
                        }, 'filter' => Html::activeDropDownList($searchModel, 'audit_status', ActiveModel::getAuditStatusLabels(), ['class' => 'form-control', 'prompt' => Yii::t('app', 'Please Filter')])],
                        'audit_remark',
                        'submitted_at:datetime',
                        'reviewed_at:datetime',
                        [
                            'header' => Yii::t('app', 'Actions'),
                            'class' => 'yii\grid\ActionColumn',
                            'template' => '{categories} {approve} {reject}',
                            'buttons' => [
                                'categories' => function ($url, $model) {
                                    return Html::view(['categories', 'id' => $model->id], '类目授权', ['class' => 'btn btn-default btn-sm']);
                                },
                                'approve' => function ($url, $model) use ($auditPostButton) {
                                    if ($model->audit_status === ActiveModel::AUDIT_APPROVED) {
                                        return '';
                                    }
                                    return $auditPostButton('approve', (int)$model->id, '通过', 'btn btn-success btn-sm', 'approve');
                                },
                                'reject' => function ($url, $model) use ($auditPostButton) {
                                    if ($model->audit_status === ActiveModel::AUDIT_REJECTED) {
                                        return '';
                                    }
                                    return $auditPostButton('reject', (int)$model->id, '驳回', 'btn btn-warning btn-sm', 'reject');
                                },
                            ],
                            'headerOptions' => ['class' => 'action-column action-column-lg'],
                        ],
                    ],
                ]); ?>
            </div>
        </div>
    </div>
</div>

<?= $this->render('@backend/views/site/_filter', ['model' => $searchModel, 'dataProvider' => $dataProvider]) ?>
