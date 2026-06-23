<?php

namespace backend\modules\mall\controllers;

use common\services\mall\DistributionWithdrawService;
use Yii;
use yii\web\ForbiddenHttpException;

class DistributionWithdrawController extends BaseController
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

        $status = (string)Yii::$app->request->get('withdraw_status', '');
        $limit = max(1, min(500, (int)Yii::$app->request->get('limit', 100)));

        return $this->render('index', [
            'status' => $status,
            'limit' => $limit,
            'withdrawRows' => $this->withdrawRows($status, $limit),
            'summary' => $this->summaryRows(),
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
            $result = (new DistributionWithdrawService())->audit($id, $action, true, (int)Yii::$app->user->id, 'backend distribution withdraw action');
            if ((int)$result['updated'] <= 0) {
                return $this->redirectError($result['skippedReason'] ?: Yii::t('app', 'No eligible records'));
            }
            $this->clearCache();
            return $this->redirectSuccess(['index']);
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }
    }

    private function withdrawRows(string $status, int $limit): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($status !== '') {
            $query->andWhere(['withdraw_status' => $status]);
        }

        return $query->all(Yii::$app->db);
    }

    private function summaryRows(): array
    {
        return (new \yii\db\Query())
            ->select([
                'withdraw_status',
                'rows' => 'COUNT(*)',
                'amount' => 'SUM(amount)',
            ])
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['status' => 1])
            ->groupBy(['withdraw_status'])
            ->orderBy(['withdraw_status' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    public function statusLabels(): array
    {
        return [
            DistributionWithdrawService::WITHDRAW_STATUS_PENDING => '待审核',
            DistributionWithdrawService::WITHDRAW_STATUS_APPROVED => '已通过',
            DistributionWithdrawService::WITHDRAW_STATUS_REJECTED => '已驳回',
        ];
    }
}
