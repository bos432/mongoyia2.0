<?php

namespace backend\modules\mall\controllers;

use common\models\mall\Category;
use common\models\mall\MerchantApplication;
use common\models\mall\StoreCategoryAuth;
use common\models\ModelSearch;
use common\models\Store;
use Yii;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;

class MerchantApplicationController extends BaseController
{
    public $modelClass = MerchantApplication::class;
    public $likeAttributes = ['applicant_name', 'company_name', 'mobile', 'email'];

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($action->id !== 'my' && !$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }

    public function actionMy()
    {
        $model = MerchantApplication::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->andWhere(['>', 'status', MerchantApplication::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        if (!$model) {
            $model = new MerchantApplication();
            $model->user_id = Yii::$app->user->id;
            $model->store_id = (int)$this->getStoreId();
            $model->audit_status = MerchantApplication::AUDIT_SUBMITTED;
        }

        $readonly = $model->audit_status === MerchantApplication::AUDIT_APPROVED;
        if (!$readonly && $model->load(Yii::$app->request->post())) {
            $model->user_id = Yii::$app->user->id;
            $model->store_id = (int)$this->getStoreId();
            $model->audit_status = MerchantApplication::AUDIT_SUBMITTED;
            $model->reviewed_at = 0;
            $model->reviewer_id = 0;
            $model->audit_remark = '';
            $model->status = MerchantApplication::STATUS_ACTIVE;
            if ($model->save()) {
                return $this->redirectSuccess(['my']);
            }

            return $this->redirectError($this->getError($model));
        }

        return $this->render('my', [
            'model' => $model,
            'readonly' => $readonly,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function actionIndex()
    {
        $searchModel = new ModelSearch([
            'model' => $this->modelClass,
            'scenario' => 'default',
            'likeAttributes' => $this->likeAttributes,
            'defaultOrder' => ['id' => SORT_DESC],
            'pageSize' => Yii::$app->request->get('page_size', $this->pageSize),
        ]);

        $params = Yii::$app->request->queryParams;
        if (!isset($params['ModelSearch']['status'])) {
            $params['ModelSearch']['status'] = '>' . MerchantApplication::STATUS_DELETED;
        }

        return $this->render('index', [
            'dataProvider' => $searchModel->search($params),
            'searchModel' => $searchModel,
        ]);
    }

    public function actionApprove()
    {
        $model = $this->findModel(Yii::$app->request->get('id'));
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $store = $this->ensureStore($model);
            $now = time();

            $model->store_id = $store->id;
            $model->audit_status = MerchantApplication::AUDIT_APPROVED;
            $model->audit_remark = Yii::$app->request->get('remark', 'Approved from backend.');
            $model->reviewed_at = $now;
            $model->reviewer_id = Yii::$app->user->id;
            if (!$model->save()) {
                throw new \RuntimeException($this->getError($model));
            }

            foreach ($model->requestedCategoryIds() as $categoryId) {
                $this->ensureCategoryAuth($store->id, $categoryId, $model->id);
            }

            $transaction->commit();
            $this->clearCache();
            return $this->redirectSuccess();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            return $this->redirectError($e->getMessage());
        }
    }

    public function actionReject()
    {
        $model = $this->findModel(Yii::$app->request->get('id'));
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $model->audit_status = MerchantApplication::AUDIT_REJECTED;
        $model->audit_remark = Yii::$app->request->get('remark', 'Rejected from backend.');
        $model->reviewed_at = time();
        $model->reviewer_id = Yii::$app->user->id;
        if (!$model->save()) {
            return $this->redirectError($this->getError($model));
        }

        $this->clearCache();
        return $this->redirectSuccess();
    }

    public function actionCategories()
    {
        $id = (int)Yii::$app->request->get('id');
        $application = $this->findModel($id);
        if (!$application) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $query = StoreCategoryAuth::find()
            ->where(['source_application_id' => $id])
            ->orderBy(['id' => SORT_DESC]);

        return $this->render('categories', [
            'application' => $application,
            'dataProvider' => new ActiveDataProvider([
                'query' => $query,
                'pagination' => ['pageSize' => $this->pageSize],
            ]),
        ]);
    }

    protected function findModel($id = null)
    {
        if (!$id) {
            return parent::findModel($id);
        }

        return MerchantApplication::findOne((int)$id);
    }

    private function ensureStore(MerchantApplication $application): Store
    {
        if ((int)$application->store_id > 0) {
            $store = Store::findOne((int)$application->store_id);
            if ($store) {
                return $store;
            }
        }

        $store = new Store();
        $store->user_id = (int)$application->user_id;
        $store->name = $application->company_name ?: $application->applicant_name;
        $store->brief = $application->audit_remark ?: 'Approved merchant store';
        $store->route = 'mall';
        $store->code = 'MERCHANT-' . date('YmdHis');
        $store->expired_at = time() + 365 * 86400;
        $store->status = Store::STATUS_ACTIVE;
        if ($store->hasAttribute('contact_name')) {
            $store->contact_name = $application->applicant_name;
        }
        if ($store->hasAttribute('contact_phone')) {
            $store->contact_phone = $application->mobile;
        }
        if ($store->hasAttribute('business_hours')) {
            $store->business_hours = '09:00-18:00';
        }
        if (!$store->save()) {
            throw new \RuntimeException($this->getError($store));
        }

        return $store;
    }

    private function ensureCategoryAuth(int $storeId, int $categoryId, int $applicationId)
    {
        if ($categoryId <= 0 || !Category::find()->where(['id' => $categoryId])->exists()) {
            return;
        }

        $auth = StoreCategoryAuth::find()
            ->where(['store_id' => $storeId, 'category_id' => $categoryId])
            ->one();
        if (!$auth) {
            $auth = new StoreCategoryAuth();
            $auth->store_id = $storeId;
            $auth->category_id = $categoryId;
        }

        $auth->source_application_id = $applicationId;
        $auth->audit_status = StoreCategoryAuth::AUDIT_APPROVED;
        $auth->audit_remark = 'Approved from merchant application.';
        $auth->authorized_at = time();
        $auth->status = StoreCategoryAuth::STATUS_ACTIVE;
        if (!$auth->save()) {
            throw new \RuntimeException($this->getError($auth));
        }
    }

    private function categoryOptions(): array
    {
        return Category::find()
            ->select(['name', 'id'])
            ->where(['>', 'status', Category::STATUS_DELETED])
            ->orderBy(['parent_id' => SORT_ASC, 'sort' => SORT_ASC, 'id' => SORT_ASC])
            ->indexBy('id')
            ->column();
    }
}
