<?php

namespace backend\modules\mall\controllers;

use common\services\mall\PaymentStatisticsService;
use Yii;
use yii\web\ForbiddenHttpException;

class PaymentStatController extends BaseController
{
    public function actionIndex()
    {
        $filter = [
            'start_date' => (string)Yii::$app->request->get('start_date', ''),
            'end_date' => (string)Yii::$app->request->get('end_date', ''),
            'store_id' => $this->requestedStoreId(),
        ];

        return $this->render('index', [
            'snapshot' => (new PaymentStatisticsService())->snapshot($filter),
            'isPlatformOperator' => $this->isMallPlatformOperator(),
        ]);
    }

    private function requestedStoreId(): int
    {
        if ($this->isMallPlatformOperator()) {
            return max(0, (int)Yii::$app->request->get('store_id', 0));
        }

        $ownStoreId = (int)$this->getStoreId();
        $requested = (int)Yii::$app->request->get('store_id', $ownStoreId);
        if ($requested > 0 && $requested !== $ownStoreId) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return $ownStoreId;
    }
}
