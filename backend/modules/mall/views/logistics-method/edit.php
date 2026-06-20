<?php

use common\helpers\Html;
use yii\widgets\ActiveForm;

/* @var $this yii\web\View */
/* @var $model common\models\mall\LogisticsMethod */

$this->title = '编辑物流方式';
$this->params['breadcrumbs'][] = ['label' => '物流方式', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<?php $form = ActiveForm::begin([
    'fieldConfig' => [
        'template' => "<div class='col-sm-2 text-sm-right'>{label}</div><div class='col-sm-10'>{input}\n{hint}\n{error}</div>",
        'options' => ['class' => 'form-group row'],
    ],
]); ?>
<div class="row">
    <div class="col-md-10 offset-md-1 col-sm-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
            </div>
            <div class="card-body">
                <?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'code')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'provider')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'base_fee')->textInput(['type' => 'number', 'step' => '0.01']) ?>
                <?= $form->field($model, 'fee_per_kg')->textInput(['type' => 'number', 'step' => '0.01']) ?>
                <?= $form->field($model, 'fee_per_volume')->textInput(['type' => 'number', 'step' => '0.01']) ?>
                <?= $form->field($model, 'tracking_url')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'remark')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'sort')->textInput(['type' => 'number']) ?>
                <?= $form->field($model, 'status')->radioList($model::getStatusLabels()) ?>
            </div>
            <div class="card-footer">
                <?= Html::submitButton(Yii::t('app', 'Submit'), ['class' => 'btn btn-primary']) ?>
                <span class="btn btn-default" onclick="history.go(-1)"><?= Yii::t('app', 'Back') ?></span>
            </div>
        </div>
    </div>
</div>
<?php ActiveForm::end(); ?>
