<?php

namespace backend\modules\mall\controllers;

use common\models\BaseModel;
use common\models\mall\CouponType;
use common\models\mall\StoreCouponParticipation;
use Yii;
use yii\db\Query;

class MerchantCouponController extends BaseController
{
    public const BACKEND_PARTICIPATION_VERB_GUARD_VERSION = 'MONGOYIA_MERCHANT_COUPON_POST_VERB_GUARD_V1';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions']['join'] = ['post'];
        $behaviors['verbs']['actions']['leave'] = ['post'];

        return $behaviors;
    }

    public function actionIndex()
    {
        $storeId = $this->resolveStoreId();

        return $this->render('index', [
            'storeId' => $storeId,
            'stores' => $this->isMallPlatformOperator() ? $this->getStoresIdName() : [],
            'isPlatformOperator' => $this->isMallPlatformOperator(),
            'storeCoupons' => $this->storeCoupons($storeId),
            'platformCoupons' => $this->platformCoupons($storeId),
            'usageRows' => $this->usageRows($storeId),
        ]);
    }

    public function actionJoin()
    {
        return $this->setParticipation(StoreCouponParticipation::PARTICIPATION_JOINED);
    }

    public function actionLeave()
    {
        return $this->setParticipation(StoreCouponParticipation::PARTICIPATION_LEFT);
    }

    private function setParticipation(string $status)
    {
        $storeId = $this->resolveStoreId();
        $couponTypeId = (int)Yii::$app->request->post('coupon_type_id', 0);
        $couponType = CouponType::find()
            ->where(['id' => $couponTypeId, 'store_id' => $this->platformStoreIds()])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->one();
        if (!$couponType) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $model = StoreCouponParticipation::find()
            ->where(['store_id' => $storeId, 'coupon_type_id' => $couponTypeId])
            ->one();
        if (!$model) {
            $model = new StoreCouponParticipation();
            $model->store_id = $storeId;
            $model->coupon_type_id = $couponTypeId;
        }

        $now = time();
        $model->participation_status = $status;
        $model->status = $status === StoreCouponParticipation::PARTICIPATION_JOINED ? BaseModel::STATUS_ACTIVE : BaseModel::STATUS_INACTIVE;
        if ($status === StoreCouponParticipation::PARTICIPATION_JOINED) {
            $model->joined_at = $now;
        } else {
            $model->left_at = $now;
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
            $requested = (int)Yii::$app->request->post('store_id', Yii::$app->request->get('store_id', 0));
            if ($requested > 0) {
                return $requested;
            }
        }

        return (int)$this->getStoreId();
    }

    private function storeCoupons(int $storeId): array
    {
        return CouponType::find()
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', BaseModel::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->asArray()
            ->all();
    }

    private function platformCoupons(int $storeId): array
    {
        $rows = (new Query())
            ->select([
                'id' => 'ct.id',
                'name' => 'ct.name',
                'money' => 'ct.money',
                'min_amount' => 'ct.min_amount',
                'started_at' => 'ct.started_at',
                'ended_at' => 'ct.ended_at',
                'status' => 'ct.status',
                'participation_status' => 'scp.participation_status',
                'joined_at' => 'scp.joined_at',
                'left_at' => 'scp.left_at',
            ])
            ->from(['ct' => '{{%mall_coupon_type}}'])
            ->leftJoin(['scp' => '{{%store_coupon_participation}}'], 'scp.coupon_type_id = ct.id AND scp.store_id = :storeId', [':storeId' => $storeId])
            ->where(['ct.store_id' => $this->platformStoreIds()])
            ->andWhere(['>', 'ct.status', BaseModel::STATUS_DELETED])
            ->orderBy(['ct.id' => SORT_DESC])
            ->all(Yii::$app->db);

        foreach ($rows as &$row) {
            $row['participation_status'] = $row['participation_status'] ?: StoreCouponParticipation::PARTICIPATION_LEFT;
        }
        unset($row);

        return $rows;
    }

    private function usageRows(int $storeId): array
    {
        return (new Query())
            ->select([
                'coupon_id' => 'c.id',
                'coupon_type_id' => 'c.coupon_type_id',
                'user_id' => 'c.user_id',
                'name' => 'c.name',
                'money' => 'c.money',
                'order_id' => 'c.order_id',
                'used_at' => 'c.used_at',
                'status' => 'c.status',
                'created_at' => 'c.created_at',
            ])
            ->from(['c' => '{{%mall_coupon}}'])
            ->where(['c.store_id' => $storeId])
            ->andWhere(['>', 'c.status', BaseModel::STATUS_DELETED])
            ->orderBy(['c.used_at' => SORT_DESC, 'c.id' => SORT_DESC])
            ->limit(30)
            ->all(Yii::$app->db);
    }

    private function platformStoreIds(): array
    {
        $ids = $this->getMallPlatformOperatorStoreIds();
        return $ids ?: [(int)(Yii::$app->params['defaultStoreId'] ?? 0)];
    }
}
