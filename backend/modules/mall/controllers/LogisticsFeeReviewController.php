<?php

namespace backend\modules\mall\controllers;

use common\services\mall\LogisticsFeeAdjustmentService;
use Yii;
use yii\web\ForbiddenHttpException;

class LogisticsFeeReviewController extends BaseController
{
    public function actionIndex()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $storeId = (int)Yii::$app->request->get('store_id', 0);
        $limit = max(1, min(500, (int)Yii::$app->request->get('limit', 100)));
        $result = (new LogisticsFeeAdjustmentService())->run($storeId, $limit, false);

        return $this->render('index', [
            'storeId' => $storeId,
            'limit' => $limit,
            'stores' => $this->getStoresIdName(),
            'result' => $result,
            'applied' => false,
        ]);
    }

    public function actionApply()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $storeId = (int)Yii::$app->request->post('store_id', 0);
        $limit = max(1, min(500, (int)Yii::$app->request->post('limit', 100)));
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $result = (new LogisticsFeeAdjustmentService())->run($storeId, $limit, true);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            return $this->redirectError($e->getMessage());
        }

        $this->clearCache();
        return $this->render('index', [
            'storeId' => $storeId,
            'limit' => $limit,
            'stores' => $this->getStoresIdName(),
            'result' => $result,
            'applied' => true,
        ]);
    }
}
