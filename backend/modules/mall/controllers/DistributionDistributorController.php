<?php

namespace backend\modules\mall\controllers;

use common\services\mall\DistributionProfileService;
use common\services\mall\DistributionAnalyticsService;
use common\services\mall\DistributionInviteRewardWorkflowService;
use common\services\mall\DistributionMaterialPhase15Service;
use common\services\mall\DistributionSupportContentService;
use common\services\mall\DistributionSignoffPhase15Service;
use Yii;
use yii\web\ForbiddenHttpException;

class DistributionDistributorController extends BaseController
{
    public const BACKEND_VERB_GUARD_VERSION = 'MONGOYIA_DISTRIBUTION_PHASE15_BACKEND_POST_VERB_GUARD_V1';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        foreach ([
            'profile-workflow',
            'risk-workflow',
            'invite-reward-workflow',
            'support-content-save',
            'support-content-disable',
            'material-save',
            'material-disable',
            'signoff-evidence-save',
            'signoff-evidence-review',
        ] as $action) {
            $behaviors['verbs']['actions'][$action] = ['post'];
        }

        return $behaviors;
    }

    public function actionIndex()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $profileStatus = (string)Yii::$app->request->get('profile_status', '');
        $limit = max(1, min(500, (int)Yii::$app->request->get('limit', 100)));
        $service = new DistributionProfileService();
        $materialService = new DistributionMaterialPhase15Service();
        $supportService = new DistributionSupportContentService();
        $signoffService = new DistributionSignoffPhase15Service();
        $analyticsService = new DistributionAnalyticsService();
        $supportType = (string)Yii::$app->request->get('support_type', '');
        $supportLanguage = (string)Yii::$app->request->get('support_language', '');

        return $this->render('index', [
            'profileStatus' => $profileStatus,
            'limit' => $limit,
            'profiles' => $this->profileRows($profileStatus, $limit),
            'materials' => $materialService->materials('', '', false, 50),
            'materialLanguageLabels' => $supportService->languageLabels(),
            'risks' => $service->risks(0, $limit),
            'supportType' => $supportType,
            'supportLanguage' => $supportLanguage,
            'supportContents' => $supportService->contents($supportType, $supportLanguage, false, $limit),
            'supportTypeLabels' => $supportService->typeLabels(),
            'supportLanguageLabels' => $supportService->languageLabels(),
            'supportStatusLabels' => $supportService->statusLabels(),
            'signoffRows' => $signoffService->evidenceRows('', '', $limit),
            'signoffSummary' => $signoffService->summary(),
            'signoffTypeLabels' => $signoffService->evidenceTypeLabels(),
            'signoffStatusLabels' => $signoffService->statusLabels(),
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

        $id = (int)Yii::$app->request->post('id', 0);
        $action = (string)Yii::$app->request->post('workflow_action', '');
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

        $id = (int)Yii::$app->request->post('id', 0);
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

        $id = (int)Yii::$app->request->post('id', 0);
        $action = (string)Yii::$app->request->post('workflow_action', '');
        $result = (new DistributionInviteRewardWorkflowService())->run([$id], $action, true, (int)Yii::$app->user->id, 'backend invite reward action');
        if ((int)$result['updated'] <= 0) {
            $reason = (string)($result['skipped'][0]['reason'] ?? '');
            return $this->redirectError($reason ?: Yii::t('app', 'No eligible records'));
        }

        return $this->redirectSuccess(['index']);
    }

    public function actionSupportContentSave()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $result = (new DistributionSupportContentService())->saveContent(Yii::$app->request->post(), true, (int)Yii::$app->user->id);
        if ($result['skippedReason'] !== '') {
            return $this->redirectError($result['skippedReason'], ['index']);
        }

        return $this->redirectSuccess(['index']);
    }

    public function actionSupportContentDisable()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', 0);
        $result = (new DistributionSupportContentService())->disableContent($id, true, (int)Yii::$app->user->id);
        if ((int)$result['updated'] <= 0) {
            return $this->redirectError($result['skippedReason'] ?: Yii::t('app', 'No eligible records'), ['index']);
        }

        return $this->redirectSuccess(['index']);
    }

    public function actionMaterialSave()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $result = (new DistributionMaterialPhase15Service())->saveMaterial(Yii::$app->request->post(), true, (int)Yii::$app->user->id);
        if ($result['skippedReason'] !== '') {
            return $this->redirectError($result['skippedReason'], ['index']);
        }

        return $this->redirectSuccess(['index']);
    }

    public function actionMaterialDisable()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', 0);
        $result = (new DistributionMaterialPhase15Service())->disableMaterial($id, true, (int)Yii::$app->user->id);
        if ((int)$result['updated'] <= 0) {
            return $this->redirectError($result['skippedReason'] ?: Yii::t('app', 'No eligible records'), ['index']);
        }

        return $this->redirectSuccess(['index']);
    }

    public function actionSignoffEvidenceSave()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $result = (new DistributionSignoffPhase15Service())->saveEvidence(Yii::$app->request->post(), true, (int)Yii::$app->user->id);
        if ($result['skippedReason'] !== '') {
            return $this->redirectError($result['skippedReason'], ['index']);
        }

        return $this->redirectSuccess(['index']);
    }

    public function actionSignoffEvidenceReview()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', 0);
        $action = (string)Yii::$app->request->post('workflow_action', '');
        $remark = (string)Yii::$app->request->post('review_remark', 'backend signoff review');
        $result = (new DistributionSignoffPhase15Service())->reviewEvidence($id, $action, true, (int)Yii::$app->user->id, $remark);
        if ((int)$result['updated'] <= 0) {
            return $this->redirectError($result['skippedReason'] ?: Yii::t('app', 'No eligible records'), ['index']);
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
