<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/* @var $this yii\web\View */
/* @var $model common\models\forms\LoginEmailForm */

$this->title = Yii::t('app', 'Login');
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="container py-4" data-mongoyia-mobile-ui="login">
    <h1 class="h4 mb-3"><?= Html::encode($this->title) ?></h1>

    <?php $form = ActiveForm::begin(['id' => 'mall-login-form']); ?>
    <div class="row">
        <div class="col-md-5">
            <?= $form->field($model, 'email')->textInput(['autofocus' => true, 'autocomplete' => 'username']) ?>
            <?= $form->field($model, 'password')->passwordInput(['autocomplete' => 'current-password']) ?>
            <?= $form->field($model, 'rememberMe')->checkbox() ?>
            <div class="form-group">
                <?= Html::submitButton(Yii::t('app', 'Login'), ['class' => 'btn btn-primary', 'name' => 'login-button']) ?>
                <?= Html::a(Yii::t('app', 'Forgot Password?'), ['/mall/default/request-password-reset'], ['class' => 'ml-3']) ?>
            </div>
        </div>
    </div>
    <?php ActiveForm::end(); ?>
</div>
