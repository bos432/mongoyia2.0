<?php

use common\helpers\Html;
use yii\widgets\ActiveForm;

/* @var $this yii\web\View */
/* @var $model common\models\Store */
/* @var $isPlatformOperator bool */

$this->title = '店铺资料';
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
                <?php if ($isPlatformOperator): ?>
                    <?= $form->field($model, 'id')->textInput(['readonly' => true]) ?>
                <?php endif; ?>
                <?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'name_en')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'name_mn')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'brief')->textarea(['rows' => 3, 'maxlength' => true]) ?>
                <?= $form->field($model, 'brief_en')->textarea(['rows' => 3, 'maxlength' => true]) ?>
                <?= $form->field($model, 'brief_mn')->textarea(['rows' => 3, 'maxlength' => true]) ?>
                <?= $form->field($model, 'main_products')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'logo')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'contact_name')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'contact_phone')->textInput(['maxlength' => true]) ?>
                <?= $form->field($model, 'business_hours')->textInput(['maxlength' => true]) ?>
            </div>
            <div class="card-footer">
                <?= Html::submitButton(Yii::t('app', 'Submit'), ['class' => 'btn btn-primary']) ?>
                <span class="btn btn-default" onclick="history.go(-1)"><?= Yii::t('app', 'Back') ?></span>
            </div>
        </div>
    </div>
</div>
<?php ActiveForm::end(); ?>
