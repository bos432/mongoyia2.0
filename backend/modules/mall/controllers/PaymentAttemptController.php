<?php

namespace backend\modules\mall\controllers;

use common\models\mall\PaymentAttempt;
use common\models\ModelSearch;
use Yii;

/**
 * Payment attempt and callback audit log.
 */
class PaymentAttemptController extends BaseController
{
    public $modelClass = PaymentAttempt::class;

    public $likeAttributes = [
        'provider',
        'event',
        'business_key',
        'merchant_transaction_id',
        'gateway_transaction_id',
        'payload_hash',
        'result',
        'error_message',
    ];

    public $defaultOrder = [
        'id' => SORT_DESC,
    ];

    public function actionIndex()
    {
        $searchModel = new ModelSearch([
            'model' => $this->modelClass,
            'scenario' => 'default',
            'likeAttributes' => $this->likeAttributes,
            'defaultOrder' => $this->defaultOrder,
            'pageSize' => Yii::$app->request->get('page_size', $this->pageSize),
        ]);

        $params = Yii::$app->request->queryParams;
        if (!$this->isMallPlatformOperator()) {
            $params['ModelSearch']['store_id'] = $this->getStoreId();
            (!isset($params['ModelSearch']['status']) || is_null($params['ModelSearch']['status'])) && $params['ModelSearch']['status'] = '>' . PaymentAttempt::STATUS_DELETED;
        } elseif ($this->isAgent()) {
            $params['ModelSearch']['store_id'] = $this->getAgentStoreIds();
        }

        $dataProvider = $searchModel->search($params);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
        ]);
    }

    protected function findModel($id = null)
    {
        if (!$id) {
            return parent::findModel($id);
        }

        if ($this->isMallPlatformOperator()) {
            return $this->modelClass::find()->where(['id' => $id])->one();
        }

        return $this->modelClass::find()
            ->where(['id' => $id, 'store_id' => $this->getStoreId()])
            ->one();
    }
}
