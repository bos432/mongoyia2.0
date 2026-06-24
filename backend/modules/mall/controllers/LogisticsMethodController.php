<?php

namespace backend\modules\mall\controllers;

use common\models\BaseModel;
use common\models\mall\LogisticsMethod;
use common\models\mall\StoreLogisticsMethod;
use Yii;
use yii\web\ForbiddenHttpException;

class LogisticsMethodController extends BaseController
{
    public const SELECTION_POST_GUARD_VERSION = 'MONGOYIA_LOGISTICS_METHOD_SELECTION_POST_GUARD_V1';

    public $modelClass = LogisticsMethod::class;

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions']['select'] = ['post'];
        $behaviors['verbs']['actions']['unselect'] = ['post'];

        return $behaviors;
    }

    public function actionIndex()
    {
        $storeId = $this->resolveStoreId();

        return $this->render('index', [
            'storeId' => $storeId,
            'stores' => $this->isMallPlatformOperator() ? $this->getStoresIdName() : [],
            'isPlatformOperator' => $this->isMallPlatformOperator(),
            'methods' => $this->methodRows($storeId),
        ]);
    }

    public function actionEdit($id = null)
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $model = $id ? LogisticsMethod::findOne((int)$id) : new LogisticsMethod();
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $this->clearCache();
            return $this->redirectSuccess(['index']);
        }

        return $this->render('edit', [
            'model' => $model,
        ]);
    }

    public function actionSelect()
    {
        return $this->setStoreMethod(StoreLogisticsMethod::SELECTION_ENABLED);
    }

    public function actionUnselect()
    {
        return $this->setStoreMethod(StoreLogisticsMethod::SELECTION_DISABLED);
    }

    private function setStoreMethod(string $selectionStatus)
    {
        $storeId = $this->resolveStoreId();
        $methodId = (int)Yii::$app->request->post('method_id', 0);
        $method = LogisticsMethod::find()
            ->where(['id' => $methodId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->one();
        if (!$method) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $model = StoreLogisticsMethod::find()
            ->where(['store_id' => $storeId, 'logistics_method_id' => $methodId])
            ->one();
        if (!$model) {
            $model = new StoreLogisticsMethod();
            $model->store_id = $storeId;
            $model->logistics_method_id = $methodId;
        }

        $model->selection_status = $selectionStatus;
        $model->status = $selectionStatus === StoreLogisticsMethod::SELECTION_ENABLED ? StoreLogisticsMethod::STATUS_ACTIVE : StoreLogisticsMethod::STATUS_INACTIVE;
        if ($selectionStatus === StoreLogisticsMethod::SELECTION_ENABLED) {
            $model->selected_at = time();
        }
        if (!$model->save()) {
            return $this->redirectError($this->getError($model));
        }

        $this->clearCache();
        return $this->redirectSuccess(['index', 'store_id' => $storeId]);
    }

    private function resolveStoreId(): int
    {
        if ($this->isMallPlatformOperator()) {
            $requested = Yii::$app->request->isPost
                ? (int)Yii::$app->request->post('store_id', 0)
                : (int)Yii::$app->request->get('store_id', 0);
            if ($requested > 0) {
                return $requested;
            }
        }

        return (int)$this->getStoreId();
    }

    private function methodRows(int $storeId): array
    {
        $rows = (new \yii\db\Query())
            ->select([
                'id' => 'm.id',
                'name' => 'm.name',
                'code' => 'm.code',
                'provider' => 'm.provider',
                'base_fee' => 'm.base_fee',
                'fee_per_kg' => 'm.fee_per_kg',
                'fee_per_volume' => 'm.fee_per_volume',
                'tracking_url' => 'm.tracking_url',
                'status' => 'm.status',
                'selection_status' => 'slm.selection_status',
                'selected_at' => 'slm.selected_at',
            ])
            ->from(['m' => '{{%logistics_method}}'])
            ->leftJoin(['slm' => '{{%store_logistics_method}}'], 'slm.logistics_method_id = m.id AND slm.store_id = :storeId', [':storeId' => $storeId])
            ->where(['>', 'm.status', BaseModel::STATUS_DELETED])
            ->orderBy(['m.sort' => SORT_ASC, 'm.id' => SORT_DESC])
            ->all(Yii::$app->db);

        foreach ($rows as &$row) {
            $row['selection_status'] = $row['selection_status'] ?: StoreLogisticsMethod::SELECTION_DISABLED;
        }
        unset($row);

        return $rows;
    }
}
