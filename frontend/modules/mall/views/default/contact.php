<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/* @var $this yii\web\View */
/* @var $model frontend\models\ContactForm */

$this->title = Yii::t('app', 'Contact');
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="container py-4">
    <h1 class="h4 mb-3"><?= Html::encode($this->title) ?></h1>
    <div class="alert alert-info">
        <?= Html::encode(Yii::t('app', 'Contact form is read-only until SMTP provider evidence is configured.')) ?>
    </div>

    <?php $form = ActiveForm::begin(['id' => 'mall-contact-form']); ?>
    <div class="row">
        <div class="col-md-6">
            <?= $form->field($model, 'name')->textInput(['readonly' => true]) ?>
            <?= $form->field($model, 'email')->textInput(['readonly' => true]) ?>
            <?= $form->field($model, 'subject')->textInput(['readonly' => true]) ?>
            <?= $form->field($model, 'body')->textarea(['rows' => 6, 'readonly' => true]) ?>
        </div>
    </div>
    <div class="form-group">
        <?= Html::submitButton(Yii::t('app', 'Submit'), ['class' => 'btn btn-secondary', 'disabled' => true]) ?>
    </div>
    <?php ActiveForm::end(); ?>
</div>
