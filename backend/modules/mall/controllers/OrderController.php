<?php

namespace backend\modules\mall\controllers;

use Yii;
use common\models\mall\Order;
use common\models\ModelSearch;
use yii\web\ForbiddenHttpException;

/**
 * Order
 *
 * Class OrderController
 * @package backend\modules\mall\controllers
 */
class OrderController extends BaseController
{
    /**
      * @var Order
      */
    public $modelClass = Order::class;
    

    /**
      * 模糊查询字段
      * @var string[]
      */
    public $likeAttributes = ['name'];

    /**
     * 可编辑字段
     *
     * @var int
     */
    protected $editAjaxFields = ['name', 'sort'];

    /**
     * 导入导出字段
     *
     * @var int
     */
    protected $exportFields = [
        'id' => 'text',
        'name' => 'text',
        'type' => 'select',
    ];

    public function actionIndex()
    {
        $params = Yii::$app->request->queryParams;
        if (!$this->isMallPlatformOperator()) {
            $params['ModelSearch']['store_id'] = $this->getStoreId();
            $params['ModelSearch']['parent_id'] = '>0';
        } elseif ($this->isAgent()) {
            $params['ModelSearch']['store_id'] = $this->getAgentStoreIds();
        }
        if (!isset($params['ModelSearch']['status']) || is_null($params['ModelSearch']['status'])) {
            $params['ModelSearch']['status'] = '>' . Order::STATUS_DELETED;
        }

        $searchModel = new ModelSearch([
            'model' => $this->modelClass,
            'scenario' => 'default',
            'likeAttributes' => $this->likeAttributes,
            'defaultOrder' => $this->defaultOrder,
            'pageSize' => Yii::$app->request->get('page_size', $this->pageSize),
        ]);
        $dataProvider = $searchModel->search($params);

        return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
            'sa' => $this->isSuperAdmin(),
        ]);
    }

    public function actionEditStatus()
    {
        $id = Yii::$app->request->get('id');
        $status = Yii::$app->request->get('status');
        if (!$id || $status === null) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $status = (int)$status;
        if ($status !== Order::PAYMENT_STATUS_REFUND) {
            return parent::actionEditStatus();
        }

        $model = $this->findModel($id);
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }
        $this->assertCanManageOrder($model);
        try {
            $model->markRefunded();
            $this->clearCache();

            return $this->redirectSuccess();
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }
    }

    public function actionFhAjax()
    {
        $id = Yii::$app->request->get('id');
        $model = $this->findModel($id);
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $this->assertCanShipOrder($model);

        // ajax 校验
        $this->activeFormValidate($model);
        if (Yii::$app->request->isPost && $model->load(Yii::$app->request->post())) {
            try {
                if ($this->beforeEditSave($id, $model)) {
                    $model->markShipped($model->shipment_id, $model->shipment_name, null, $model->shipment_fee);
                } else {
                    throw new \RuntimeException(Yii::t('app', 'Something wrong'));
                }

                $this->afterEdit($id, $model);
                $this->clearCache();
                return $this->redirectSuccess();
            } catch (\Throwable $e) {
                return $this->redirectError($e->getMessage());
            }
        }

        $this->beforeEditRender($id, $model);
        return $this->renderAjax(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'model' => $model,
        ]);
    }

    public function actionLogisticsStatusBatch()
    {
        $ids = Yii::$app->request->get('ids', Yii::$app->request->post('ids', ''));
        $targetStatus = (int)Yii::$app->request->get('target_status', Yii::$app->request->post('target_status', 0));
        $apply = (bool)Yii::$app->request->get('apply', Yii::$app->request->post('apply', 1));
        $orderIds = is_array($ids) ? $ids : preg_split('/[,\s]+/', (string)$ids, -1, PREG_SPLIT_NO_EMPTY);
        if (!$orderIds || !$targetStatus) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        try {
            $storeId = $this->isMallPlatformOperator() ? null : (int)$this->getStoreId();
            $result = Order::batchSetLogisticsStatus($orderIds, $targetStatus, $apply, $storeId);
            if ($result['eligible'] <= 0) {
                return $this->redirectError(Yii::t('app', 'No eligible records'));
            }

            $this->clearCache();
            return $this->redirectSuccess();
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }
    }

    public function actionLogisticsReviewBatch()
    {
        $this->assertCanManageOrders();
        $ids = Yii::$app->request->get('ids', Yii::$app->request->post('ids', ''));
        $reviewStatus = (int)Yii::$app->request->get('review_status', Yii::$app->request->post('review_status', 0));
        $remark = (string)Yii::$app->request->get('remark', Yii::$app->request->post('remark', ''));
        $apply = (bool)Yii::$app->request->get('apply', Yii::$app->request->post('apply', 1));
        $orderIds = is_array($ids) ? $ids : preg_split('/[,\s]+/', (string)$ids, -1, PREG_SPLIT_NO_EMPTY);
        if (!$orderIds || !$reviewStatus) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        try {
            $result = Order::batchReviewLogistics($orderIds, $reviewStatus, $remark, $apply);
            if ($result['eligible'] <= 0) {
                return $this->redirectError(Yii::t('app', 'No eligible records'));
            }

            $this->clearCache();
            return $this->redirectSuccess();
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }
    }

    public function actionExport()
    {
        $this->assertCanManageOrders();
        return parent::actionExport();
    }

    public function actionImportAjax()
    {
        $this->assertCanManageOrders();
        return parent::actionImportAjax();
    }

    protected function beforeView($id, $model)
    {
        $this->assertCanAccessOrder($model);
        return true;
    }

    protected function beforeEdit($id = null, $model = null)
    {
        $this->assertCanManageOrder($model);
        return true;
    }

    protected function beforeEditStatus($id = null, $model = null)
    {
        $this->assertCanManageOrder($model);
        return true;
    }

    protected function beforeEditAjaxField($id = null, $model = null, $field = null, $value = null)
    {
        $this->assertCanManageOrder($model);
        return true;
    }

    protected function beforeEditAjaxStatus($id = null, $model = null)
    {
        $this->assertCanManageOrder($model);
        return true;
    }

    protected function beforeDeleteModel($id = null, $model = null, $soft = false, $tree = false)
    {
        $this->assertCanManageOrder($model);
        return true;
    }

    protected function beforeDeleteAll()
    {
        $this->assertCanManageOrders();
        return true;
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
            ->andWhere(['>', 'parent_id', 0])
            ->one();
    }

    protected function assertCanAccessOrder($model, $write = false)
    {
        if (!$model) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        if ($this->isMallPlatformOperator()) {
            return true;
        }

        if ((int)$model->store_id !== (int)$this->getStoreId()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        if ((int)$model->parent_id === 0) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }

    protected function assertCanShipOrder($model)
    {
        $this->assertCanAccessOrder($model);
        return true;
    }

    protected function assertCanManageOrder($model)
    {
        $this->assertCanAccessOrder($model);
        $this->assertCanManageOrders();

        return true;
    }

    protected function assertCanManageOrders()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }
}
