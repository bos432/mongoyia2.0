<?php

namespace backend\modules\mall\controllers;

use common\services\mall\DistributionProfileService;
use common\services\mall\DistributionAnalyticsService;
use common\services\mall\DistributionInviteRewardWorkflowService;
use Yii;
use yii\web\ForbiddenHttpException;

class DistributionDistributorController extends BaseController
{
    public function actionIndex()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $profileStatus = (string)Yii::$app->request->get('profile_status', '');
        $limit = max(1, min(500, (int)Yii::$app->request->get('limit', 100)));
        $service = new DistributionProfileService();
        $analyticsService = new DistributionAnalyticsService();

        return $this->render('index', [
            'profileStatus' => $profileStatus,
            'limit' => $limit,
            'profiles' => $this->profileRows($profileStatus, $limit),
            'materials' => $service->materials(50),
            'risks' => $service->risks(0, $limit),
            'invites' => $this->inviteRows($limit),
            'inviteRewards' => $this->inviteRewardRows($limit),
            'analyticsRows' => $analyticsService->distributorRows($limit),
            'profileStatusLabels' => $this->profileStatusLabels(),
        ]);
    }

    public function actionProfileWorkflow()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', Yii::$app->request->get('id', 0));
        $action = (string)Yii::$app->request->post('workflow_action', Yii::$app->request->get('workflow_action', ''));
        $result = (new DistributionProfileService())->auditProfile($id, $action, true, (int)Yii::$app->user->id, 'backend distribution profile action');
        if ((int)$result['updated'] <= 0) {
            return $this->redirectError($result['skippedReason'] ?: Yii::t('app', 'No eligible records'));
        }

        return $this->redirectSuccess(['index']);
    }

    public function actionRiskWorkflow()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', Yii::$app->request->get('id', 0));
        $result = (new DistributionProfileService())->closeRisk($id, true, (int)Yii::$app->user->id);
        if ((int)$result['updated'] <= 0) {
            return $this->redirectError($result['skippedReason'] ?: Yii::t('app', 'No eligible records'));
        }

        return $this->redirectSuccess(['index']);
    }

    public function actionInviteRewardWorkflow()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', Yii::$app->request->get('id', 0));
        $action = (string)Yii::$app->request->post('workflow_action', Yii::$app->request->get('workflow_action', ''));
        $result = (new DistributionInviteRewardWorkflowService())->run([$id], $action, true, (int)Yii::$app->user->id, 'backend invite reward action');
        if ((int)$result['updated'] <= 0) {
            $reason = (string)($result['skipped'][0]['reason'] ?? '');
            return $this->redirectError($reason ?: Yii::t('app', 'No eligible records'));
        }

        return $this->redirectSuccess(['index']);
    }

    private function profileRows(string $status, int $limit): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_distribution_profile}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($status !== '') {
            $query->andWhere(['profile_status' => $status]);
        }

        return $query->all(Yii::$app->db);
    }

    private function inviteRows(int $limit): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_invite}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all(Yii::$app->db);
    }

    private function inviteRewardRows(int $limit): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_invite_reward}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all(Yii::$app->db);
    }

    private function profileStatusLabels(): array
    {
        return [
            DistributionProfileService::PROFILE_STATUS_PENDING => '待审核',
            DistributionProfileService::PROFILE_STATUS_APPROVED => '已通过',
            DistributionProfileService::PROFILE_STATUS_REJECTED => '已驳回',
        ];
    }
}
