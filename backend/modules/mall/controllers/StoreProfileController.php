<?php

namespace backend\modules\mall\controllers;

use common\models\Store;
use Yii;
use yii\web\ForbiddenHttpException;

class StoreProfileController extends BaseController
{
    public $modelClass = Store::class;

    public function actionEdit()
    {
        $model = $this->findProfileStore();
        $this->assertCanEditStoreProfile($model);
        $profileStoreId = (int)$model->id;

        if (Yii::$app->request->isPost && $model->load(Yii::$app->request->post())) {
            // MONGOYIA_STORE_PROFILE_POST_STORE_ID_GUARD_V1: writes use the POST body target store only.
            $model->id = $profileStoreId;
            $this->keepProtectedStoreFields($model);
            if ($model->save()) {
                $this->clearCache();
                return $this->redirectSuccess(['edit', 'store_id' => $model->id]);
            }

            Yii::$app->logSystem->db($model->errors);
            $this->flashError($this->getError($model));
        }

        return $this->render('edit', [
            'model' => $model,
            'isPlatformOperator' => $this->isMallPlatformOperator(),
        ]);
    }

    private function findProfileStore(): Store
    {
        $request = Yii::$app->request;
        $storeId = $this->isMallPlatformOperator()
            ? ($request->isPost ? (int)$request->post('store_id', 0) : (int)$request->get('store_id', $this->getStoreId()))
            : (int)$this->getStoreId();

        $model = Store::findOne($storeId);
        if (!$model) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return $model;
    }

    private function assertCanEditStoreProfile(Store $model)
    {
        if ($this->isMallPlatformOperator()) {
            return true;
        }

        if ((int)$model->id !== (int)$this->getStoreId()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }

    private function keepProtectedStoreFields(Store $model)
    {
        $old = Store::findOne($model->id);
        if (!$old) {
            return;
        }

        foreach ([
            'parent_id',
            'user_id',
            'host_name',
            'code',
            'route',
            'expired_at',
            'language',
            'lang_frontend',
            'lang_backend',
            'lang_api',
            'fund',
            'fund_amount',
            'billable_fund',
            'income',
            'income_amount',
            'status',
        ] as $field) {
            if ($model->hasAttribute($field)) {
                $model->{$field} = $old->{$field};
            }
        }
    }
}
