<?php

namespace backend\modules\mall\controllers;

use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementDraftWorkflowService;
use common\services\mall\SettlementCloseService;
use common\services\mall\SettlementPayoutEvidenceService;
use Yii;
use yii\web\ForbiddenHttpException;

class SettlementDraftController extends BaseController
{
    public function actionIndex()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $storeId = (int)Yii::$app->request->get('store_id', 0);
        $limit = max(1, min(500, (int)Yii::$app->request->get('limit', 100)));
        $draftId = (int)Yii::$app->request->get('draft_id', 0);
        $drafts = $this->draftRows($storeId, $limit);

        return $this->render('index', [
            'storeId' => $storeId,
            'limit' => $limit,
            'draftId' => $draftId,
            'stores' => $this->getStoresIdName(),
            'drafts' => $drafts,
            'evidenceRows' => (new SettlementPayoutEvidenceService())->evidenceRows(array_column($drafts, 'id')),
            'orders' => $draftId > 0 ? $this->draftOrderRows($draftId) : [],
        ]);
    }

    public function actionWorkflow()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', Yii::$app->request->get('id', 0));
        $action = (string)Yii::$app->request->post('workflow_action', Yii::$app->request->get('workflow_action', ''));
        if ($id <= 0 || $action === '') {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        try {
            $result = (new SettlementDraftWorkflowService())->run([$id], $action, true, 'backend settlement draft action');
            if ((int)$result['updated'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason);
            }
            $this->clearCache();
            return $this->redirectSuccess();
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }
    }

    public function actionPayoutEvidence()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        try {
            $result = (new SettlementPayoutEvidenceService())->run($id, (float)$request->post('amount', 0), (string)$request->post('transaction_no', ''), true, [
                'currency' => (string)$request->post('currency', 'MNT'),
                'channel' => (string)$request->post('channel', 'offline'),
                'evidenceFile' => (string)$request->post('evidence_file', ''),
                'remark' => (string)$request->post('remark', 'backend settlement payout evidence'),
            ]);
            if ((int)$result['created'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason);
            }
            $this->clearCache();
            return $this->redirectSuccess();
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }
    }

    public function actionClose()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $id = (int)Yii::$app->request->post('id', 0);
        if ($id <= 0) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        try {
            $result = (new SettlementCloseService())->run([$id], true, 'backend settlement close');
            if ((int)$result['closed'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason);
            }
            $this->clearCache();
            return $this->redirectSuccess();
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage());
        }
    }

    private function draftRows(int $storeId, int $limit): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_settlement_draft}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        return $query->all(Yii::$app->db);
    }

    private function draftOrderRows(int $draftId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_settlement_draft_order}}')
            ->where(['draft_id' => $draftId, 'status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all(Yii::$app->db);
    }

    public function draftStatusLabels(): array
    {
        return [
            SettlementDraftService::DRAFT_STATUS_DRAFT => '草案',
            SettlementDraftService::DRAFT_STATUS_SUBMITTED => '已提交',
            SettlementDraftService::DRAFT_STATUS_APPROVED => '已审核',
            SettlementDraftService::DRAFT_STATUS_REJECTED => '已驳回',
            SettlementDraftService::DRAFT_STATUS_CANCELLED => '已取消',
            SettlementDraftService::DRAFT_STATUS_CLOSED => '已关闭',
        ];
    }
}
