<?php

namespace backend\modules\mall\controllers;

use common\services\mall\OperationalConfigService;
use Yii;
use yii\web\ForbiddenHttpException;

class OperationalConfigController extends BaseController
{
    public function actionIndex()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return $this->render('index', [
            'summary' => (new OperationalConfigService())->summary(),
        ]);
    }
}
