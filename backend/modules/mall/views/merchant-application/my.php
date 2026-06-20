<?php

use common\helpers\Html;
use common\models\mall\MerchantApplication;
use yii\widgets\ActiveForm;

/* @var $this yii\web\View */
/* @var $model common\models\mall\MerchantApplication */
/* @var $readonly bool */
/* @var $categories array */

$this->title = '我的入驻申请';
$this->params['breadcrumbs'][] = $this->title;
$selectedCategories = $model->requestedCategoryIds();
?>

<div class="row">
    <div class="col-md-10 offset-md-1 col-sm-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
            </div>
            <?php $form = ActiveForm::begin([
                'fieldConfig' => [
                    'template' => "<div class='col-sm-2 text-sm-right'>{label}</div><div class='col-sm-10'>{input}\n{hint}\n{error}</div>",
                    'options' => ['class' => 'form-group row'],
                ],
            ]); ?>
            <div class="card-body">
                <div class="alert alert-info">
                    当前状态：<?= Html::encode(MerchantApplication::getAuditStatusLabels($model->audit_status)) ?>
                    <?php if ($model->audit_remark): ?>
                        <br>审核备注：<?= Html::encode($model->audit_remark) ?>
                    <?php endif; ?>
                </div>

                <?= $form->field($model, 'applicant_name')->textInput(['maxlength' => true, 'readonly' => $readonly]) ?>
                <?= $form->field($model, 'mobile')->textInput(['maxlength' => true, 'readonly' => $readonly]) ?>
                <?= $form->field($model, 'email')->textInput(['maxlength' => true, 'readonly' => $readonly]) ?>
                <?= $form->field($model, 'company_name')->textInput(['maxlength' => true, 'readonly' => $readonly]) ?>
                <?= $form->field($model, 'business_license')->textInput(['maxlength' => true, 'readonly' => $readonly]) ?>
                <?= $form->field($model, 'requested_category_ids')->checkboxList($categories, [
                    'value' => $selectedCategories,
                    'itemOptions' => ['disabled' => $readonly],
                ]) ?>
            </div>
            <div class="card-footer">
                <?php if (!$readonly): ?>
                    <?= Html::submitButton(Yii::t('app', 'Submit'), ['class' => 'btn btn-primary']) ?>
                <?php endif; ?>
                <span class="btn btn-default" onclick="history.go(-1)"><?= Yii::t('app', 'Back') ?></span>
            </div>
            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div>
