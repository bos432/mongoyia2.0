<?php

use common\helpers\Html;
use common\helpers\Url;
use yii\widgets\ActiveForm;
use common\components\enums\YesNo;
use common\models\mall\Order as ActiveModel;


/* @var $this yii\web\View */
/* @var $model common\models\mall\Order */
/* @var $form yii\widgets\ActiveForm */

$form = ActiveForm::begin([
    'id' => $model->formName(),
    'enableAjaxValidation' => true,
    'action' => Url::to(['fh-ajax']),
    'validationUrl' => Url::to(['fh-ajax']),
    'options' => [
        'data-mongoyia-order-product-shipment-post-id-guard' => '1',
    ],
    'fieldConfig' => [
        'template' => "<div class='col-sm-2 text-sm-right'>{label}</div><div class='col-sm-10'>{input}\n{hint}\n{error}</div>",
    ],
]);
?>
    <div class="modal-header">
        <h4 class="modal-title">物流信息</h4>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <?= Html::hiddenInput('id', (int)$model['id']) ?>
        <?= $form->field($model, 'shipment_id')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'shipment_name')->textInput(['maxlength' => true]) ?>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal"><?= Yii::t('app', 'Close') ?></button>
        <button class="btn btn-primary" type="submit"><?= Yii::t('app', 'Submit') ?></button>
    </div>
<?php ActiveForm::end(); ?>
