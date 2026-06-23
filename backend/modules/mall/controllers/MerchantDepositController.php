<?php

namespace backend\modules\mall\controllers;

use common\models\base\FundLog;
use common\models\Store;
use Yii;
use yii\db\Query;

class MerchantDepositController extends BaseController
{
    public const ADJUST_POST_GUARD_VERSION = 'MONGOYIA_MERCHANT_DEPOSIT_ADJUST_POST_GUARD_V1';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions']['adjust'] = ['post'];

        return $behaviors;
    }

    public function actionIndex()
    {
        $storeId = $this->resolveStoreId();
        $store = Store::findOne($storeId);
        if (!$store) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        return $this->render('index', [
            'storeId' => $storeId,
            'store' => $store,
            'stores' => $this->isMallPlatformOperator() ? $this->getStoresIdName() : [],
            'isPlatformOperator' => $this->isMallPlatformOperator(),
            'logs' => $this->fundLogs($storeId),
        ]);
    }

    public function actionAdjust()
    {
        if (!$this->isMallPlatformOperator()) {
            return $this->redirectError(Yii::t('app', 'No Auth'));
        }

        $storeId = $this->resolveStoreId();
        $amount = round((float)Yii::$app->request->post('amount', 0), 2);
        $name = trim((string)Yii::$app->request->post('name', ''));
        $remark = trim((string)Yii::$app->request->post('remark', ''));
        if ($amount == 0.0) {
            return $this->redirectError('预存金变动金额不能为 0');
        }

        $store = Store::findOne($storeId);
        if (!$store) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }
        if ($amount < 0 && (float)$store->fund + $amount < 0) {
            return $this->redirectError('预存金余额不足，不能扣成负数');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $original = (float)$store->fund;
            Store::updateAllCounters([
                'fund' => $amount,
                'fund_amount' => $amount > 0 ? $amount : 0,
                'consume_amount' => $amount < 0 ? abs($amount) : 0,
                'consume_count' => $amount < 0 ? 1 : 0,
            ], ['id' => $storeId]);

            $log = new FundLog();
            $log->store_id = $storeId;
            $log->user_id = Yii::$app->user->id;
            $log->name = $name !== '' ? $name : ($amount > 0 ? '商家预存金充值' : '商家预存金扣费');
            $log->change = $amount;
            $log->original = $original;
            $log->balance = $original + $amount;
            $log->remark = $remark;
            $log->type = $amount > 0 ? FundLog::TYPE_RECHARGE : FundLog::TYPE_CONSUME;
            if (!$log->save()) {
                throw new \RuntimeException($this->getError($log));
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            return $this->redirectError($e->getMessage());
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

    private function fundLogs(int $storeId): array
    {
        return (new Query())
            ->select(['id', 'name', 'change', 'original', 'balance', 'remark', 'type', 'created_at', 'created_by'])
            ->from('{{%base_fund_log}}')
            ->where(['store_id' => $storeId])
            ->andWhere(['>', 'status', FundLog::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit(50)
            ->all(Yii::$app->db);
    }
}
