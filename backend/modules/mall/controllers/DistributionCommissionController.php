<?php

namespace backend\modules\mall\controllers;

use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionCommissionWorkflowService;
use Yii;
use yii\web\ForbiddenHttpException;

class DistributionCommissionController extends BaseController
{
    public const BACKEND_WORKFLOW_VERB_GUARD_VERSION = 'MONGOYIA_DISTRIBUTION_COMMISSION_WITHDRAW_POST_VERB_GUARD_V1';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions']['workflow'] = ['post'];

        return $behaviors;
    }

    public function actionIndex()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $storeId = (int)Yii::$app->request->get('store_id', 0);
        $status = (string)Yii::$app->request->get('commission_status', '');
        $limit = max(1, min(500, (int)Yii::$app->request->get('limit', 100)));

        return $this->render('index', [
            'storeId' => $storeId,
            'status' => $status,
            'limit' => $limit,
            'stores' => $this->getStoresIdName(),
            'rules' => $this->ruleRows($storeId),
            'commissions' => $this->commissionRows($storeId, $status, $limit),
            'summary' => $this->summaryRows($storeId),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function actionWorkflow()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', 0);
        $action = (string)Yii::$app->request->post('workflow_action', '');
        if ($id <= 0 || $action === '') {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        try {
            $result = (new DistributionCommissionWorkflowService())->run([$id], $action, true, 'backend distribution commission action');
            if ((int)$result['updated'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason);
            }
            $this->clearCache();
            return $this->redirectSuccess(['index']);
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }
    }

    private function ruleRows(int $storeId): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_distribution_rule}}')
            ->where(['status' => 1])
            ->orderBy(['store_id' => SORT_ASC, 'id' => SORT_DESC])
            ->limit(100);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        return $query->all(Yii::$app->db);
    }

    private function commissionRows(int $storeId, string $status, int $limit): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($status !== '') {
            $query->andWhere(['commission_status' => $status]);
        }

        return $query->all(Yii::$app->db);
    }

    private function summaryRows(int $storeId): array
    {
        $query = (new \yii\db\Query())
            ->select([
                'store_id',
                'commission_status',
                'rows' => 'COUNT(*)',
                'order_amount' => 'SUM(order_amount)',
                'commission_amount' => 'SUM(commission_amount)',
            ])
            ->from('{{%mall_distribution_commission}}')
            ->where(['status' => 1])
            ->groupBy(['store_id', 'commission_status'])
            ->orderBy(['store_id' => SORT_ASC, 'commission_status' => SORT_ASC]);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        return $query->all(Yii::$app->db);
    }

    public function statusLabels(): array
    {
        return [
            DistributionCommissionService::COMMISSION_STATUS_PENDING => '待审核',
            DistributionCommissionService::COMMISSION_STATUS_APPROVED => '已通过',
            DistributionCommissionService::COMMISSION_STATUS_REJECTED => '已驳回',
            DistributionCommissionService::COMMISSION_STATUS_WITHDRAWN => '已提现',
        ];
    }
}
