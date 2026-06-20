<?php

namespace backend\modules\mall\controllers;

use common\services\mall\SettlementPayoutPlanService;
use Yii;
use yii\web\ForbiddenHttpException;

class SettlementPayoutPlanController extends BaseController
{
    public function actionIndex()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $storeId = (int)Yii::$app->request->get('store_id', 0);
        $limit = max(1, min(500, (int)Yii::$app->request->get('limit', 100)));

        return $this->render('index', [
            'storeId' => $storeId,
            'limit' => $limit,
            'stores' => $this->getStoresIdName(),
            'result' => (new SettlementPayoutPlanService())->run($storeId, $limit),
        ]);
    }
}
