<?php

namespace backend\modules\mall\controllers;

use Yii;

/**
 * Default controller for the `mall` module
 */
class DefaultController extends BaseController
{
    public const BACKEND_MALL_DEFAULT_INTERNAL_FORWARD_VERSION = 'MONGOYIA_BACKEND_MALL_DEFAULT_INTERNAL_FORWARD_V1';

    /**
     * Renders the index view for the module
     * @return string
     */
    public function actionIndex()
    {
        return Yii::$app->runAction('/site/info');
    }
}
