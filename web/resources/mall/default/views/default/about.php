<?php

/* @var $this yii\web\View */
/* @var $form yii\bootstrap\ActiveForm */
/* @var $model \common\models\LoginForm */

use yii\helpers\Html;
use yii\bootstrap\ActiveForm;
use common\helpers\MallHelper;
use common\helpers\ArrayHelper;

$store = $this->context->store;

$this->title = Yii::t('app', 'About');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="page-section">
    <div class="container">
        <div class="row">
            <div class="col-md-6 col-md-offset-3 col-sm-12 col-xs-12">
                <div class="card message-send-view">
                    <div class="card-header">
                        <?= Html::encode($this->title) ?>
                    </div>

                    <div class="card-body">
                        <?= $about;?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
