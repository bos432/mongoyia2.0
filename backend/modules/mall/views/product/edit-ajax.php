<?php

use common\helpers\Html;
use common\helpers\Url;
use yii\widgets\ActiveForm;
use common\components\enums\YesNo;
use common\models\mall\Product as ActiveModel;


/* @var $this yii\web\View */
/* @var $model common\models\mall\Product */
/* @var $form yii\widgets\ActiveForm */

$form = ActiveForm::begin([
    'id' => $model->formName(),
    'enableAjaxValidation' => true,
    'action' => Url::to(['edit-ajax']),
    'validationUrl' => Url::to(['edit-ajax']),
    'options' => [
        'data-mongoyia-product-edit-ajax-post-id-guard' => '1',
    ],
    'fieldConfig' => [
        'template' => "<div class='col-sm-2 text-sm-right'>{label}</div><div class='col-sm-10'>{input}\n{hint}\n{error}</div>",
    ],
]);
$hasProductVideoField = method_exists($model, 'hasAttribute') && $model->hasAttribute('video_url');
?>
    <div class="modal-header">
        <h4 class="modal-title"><?= $model->name ?: Yii::t('app', 'Basic info') ?></h4>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <?= Html::hiddenInput('id', (int)$model['id']) ?>
        <?= $form->field($model, 'category_id')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'sku')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'stock_code')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'stock')->textInput() ?>
        <?= $form->field($model, 'stock_warning')->textInput() ?>
        <?= $form->field($model, 'weight')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'volume')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'price')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'market_price')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'cost_price')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'wholesale_price')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'thumb')->widget(\common\components\uploader\FileWidget::class, [
            'uploadType' => \common\models\base\Attachment::UPLOAD_TYPE_IMAGE,
            'theme' => 'default',
            'themeConfig' => [],
            'config' => [
                // 可设置自己的上传地址, 不设置则默认地址
                // 'server' => '',
                'pick' => [
                    'multiple' => false,
                ],
            ]
        ]); ?>
        <?= $form->field($model, 'images')->widget(\common\components\uploader\FileWidget::class, [
            'uploadType' => \common\models\base\Attachment::UPLOAD_TYPE_IMAGE,
            'theme' => 'default',
            'themeConfig' => [],
            'config' => [
                // 可设置自己的上传地址, 不设置则默认地址
                // 'server' => '',
                'pick' => [
                    'multiple' => true,
                ],
            ]
        ]); ?>
        <?php if ($hasProductVideoField) { ?>
            <?= $form->field($model, 'video_url')->textInput([
                'maxlength' => true,
                'placeholder' => 'https://example.com/product-video.mp4',
                'data-mongoyia-phase14-product-video-admin' => 'MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_V1',
            ])->hint(Yii::t('app', 'Use an HTTPS or site-relative video URL.')) ?>
        <?php } ?>
        <?= $form->field($model, 'brief')->textarea(['rows' => 6]) ?>
        <?= $form->field($model, 'content')->textarea(['rows' => 6]) ?>
        <?= $form->field($model, 'seo_url')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'seo_title')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'seo_keywords')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'seo_description')->textarea(['rows' => 6]) ?>
        <?= $form->field($model, 'brand_id')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'vendor_id')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'attribute_set_id')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'star')->textInput(['maxlength' => true]) ?>
        <?= $form->field($model, 'sales')->textInput() ?>
        <?= $form->field($model, 'click')->textInput() ?>
        <?= $form->field($model, 'type')->dropDownList(ActiveModel::getTypeLabels()) ?>
        <?= $form->field($model, 'sort')->textInput() ?>
        <?= $form->field($model, 'status')->radioList(ActiveModel::getStatusLabels()) ?>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal"><?= Yii::t('app', 'Close') ?></button>
        <button class="btn btn-primary" type="submit"><?= Yii::t('app', 'Submit') ?></button>
    </div>
<?php ActiveForm::end(); ?>
