<?php

namespace backend\modules\mall\controllers;

use common\services\mall\SettlementReportService;
use Yii;
use yii\web\ForbiddenHttpException;

class SettlementReportController extends BaseController
{
    public function actionIndex()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $storeId = (int)Yii::$app->request->get('store_id', 0);
        $dateFrom = (string)Yii::$app->request->get('date_from', '');
        $dateTo = (string)Yii::$app->request->get('date_to', '');
        $limit = max(1, min(1000, (int)Yii::$app->request->get('limit', 500)));

        return $this->render('index', [
            'storeId' => $storeId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'stores' => $this->getStoresIdName(),
            'report' => (new SettlementReportService())->run($storeId, $dateFrom, $dateTo, $limit),
        ]);
    }
}
