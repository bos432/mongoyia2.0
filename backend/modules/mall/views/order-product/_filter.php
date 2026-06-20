<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;
use kartik\daterange\DateRangePicker;

/* @var $this yii\web\View */
/* @var $model common\models\ModelSearch */
?>

<div class="modal fade" id="ajaxModalFilter" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <?php $form = ActiveForm::begin([
            'action' => [''],
            'method' => 'get',
            'enableClientScript' => false,
            'fieldConfig' => [
                'template' => "<div class='col-sm-4 text-sm-right'>{label}</div><div class='col-sm-8'>{input}\n{hint}\n{error}</div>",
            ],
        ]); ?>
        <div class="modal-content">

            <div class="modal-header">
                <h4 class="modal-title"><?= Yii::t('app', 'Filter') ?></h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
            </div>

            <div class="modal-body">
                <?php if(yii::$app->user->id == 1){?>
                <div class="row">
                    <div class="col-sm-6">
                        <?= $form->field($model, 'store_id')->textInput(['value' => '']) ?>
                    </div>
                </div>
                <?php }?>
                <div class="row">
                    <div class="col-sm-2 text-sm-right"><?= Yii::t('app', 'Created At') ?></div>
                    <div class="col-sm-10 input-group drp-container">
                        <?= DateRangePicker::widget([
                            'name' => 'rangeCreatedAt',
                            'value' => '',
                            'useWithAddon' => true,
                            'convertFormat' => true,
                            'attribute' => 'created_at',
                            'options' => [
                                'class' => 'form-control',
                                'placeholder' => Yii::t('app', 'Start Time - End Time')
                            ],
                            'pluginOptions' => [
                                'locale' => ['format' => 'Y-m-d H:i:s'],
                            ],
                        ]);?>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= Yii::t('app', 'Close') ?></button>
                <?= Html::resetButton(Yii::t('app', 'Reset'), ['class' => 'btn btn-default']) ?>
                <?= Html::submitButton('<i class="fa fa-search"></i> ' . Yii::t('app', 'Search'), ['class' => 'btn btn-primary px-3']) ?>
            </div>

        </div>
        <?php ActiveForm::end() ?>
    </div>
</div>
