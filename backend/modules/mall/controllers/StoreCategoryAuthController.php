<?php

namespace backend\modules\mall\controllers;

use common\models\mall\StoreCategoryAuth;
use common\models\ModelSearch;
use Yii;
use yii\web\ForbiddenHttpException;

class StoreCategoryAuthController extends BaseController
{
    public $modelClass = StoreCategoryAuth::class;

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }

    public function actionIndex()
    {
        $searchModel = new ModelSearch([
            'model' => $this->modelClass,
            'scenario' => 'default',
            'defaultOrder' => ['id' => SORT_DESC],
            'pageSize' => Yii::$app->request->get('page_size', $this->pageSize),
        ]);

        $params = Yii::$app->request->queryParams;
        if (!isset($params['ModelSearch']['status'])) {
            $params['ModelSearch']['status'] = '>' . StoreCategoryAuth::STATUS_DELETED;
        }

        return $this->render('index', [
            'dataProvider' => $searchModel->search($params),
            'searchModel' => $searchModel,
        ]);
    }

    public function actionApprove()
    {
        return $this->setAuditStatus(StoreCategoryAuth::AUDIT_APPROVED, StoreCategoryAuth::STATUS_ACTIVE);
    }

    public function actionReject()
    {
        return $this->setAuditStatus(StoreCategoryAuth::AUDIT_REJECTED, StoreCategoryAuth::STATUS_INACTIVE);
    }

    protected function findModel($id = null)
    {
        if (!$id) {
            return parent::findModel($id);
        }

        return StoreCategoryAuth::findOne((int)$id);
    }

    private function setAuditStatus(string $auditStatus, int $status)
    {
        $model = $this->findModel(Yii::$app->request->get('id'));
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $model->audit_status = $auditStatus;
        $model->audit_remark = Yii::$app->request->get('remark', $auditStatus . ' from backend.');
        $model->status = $status;
        if ($auditStatus === StoreCategoryAuth::AUDIT_APPROVED) {
            $model->authorized_at = time();
        }

        if (!$model->save()) {
            return $this->redirectError($this->getError($model));
        }

        $this->clearCache();
        return $this->redirectSuccess();
    }
}
