<?php

namespace backend\modules\mall\controllers;

use common\services\mall\OperationalNotificationService;
use Yii;
use yii\web\ForbiddenHttpException;

class NotificationLogController extends BaseController
{
    public function actionIndex()
    {
        $filter = [
            'start_date' => (string)Yii::$app->request->get('start_date', ''),
            'end_date' => (string)Yii::$app->request->get('end_date', ''),
            'store_id' => $this->requestedStoreId(),
            'event_key' => (string)Yii::$app->request->get('event_key', ''),
            'channel' => (string)Yii::$app->request->get('channel', ''),
            'delivery_status' => (string)Yii::$app->request->get('delivery_status', ''),
        ];

        return $this->render('index', [
            'snapshot' => (new OperationalNotificationService())->snapshot($filter),
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
