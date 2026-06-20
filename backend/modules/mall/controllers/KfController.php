<?php

namespace backend\modules\mall\controllers;

use common\models\Store;
use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceTicketAssignService;
use common\services\mall\CustomerServiceTicketCreateService;
use common\services\mall\CustomerServiceTicketNoteService;
use common\services\mall\CustomerServiceTicketResultService;
use common\services\mall\CustomerServiceTicketWorkflowService;
use common\services\mall\CustomerServiceComplaintExportService;
use common\services\mall\CustomerServiceResultSignoffService;
use common\services\mall\CustomerServiceSlaHandlingService;
use common\services\mall\CustomerServiceResolutionExportService;
use common\services\mall\CustomerServiceSlaReadinessService;
use common\services\mall\CustomerServiceStatApplyLogReviewService;
use common\services\mall\CustomerServiceStatExportService;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Order
 *
 * Class OrderController
 * @package backend\modules\mall\controllers
 */
class KfController extends BaseController
{
    public function actionIndex()
    {
        $uid = Yii::$app->user->id;
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeMap = [];
        if ($isPlatformOperator) {
            $stores = Store::find()
                ->select(['id', 'name', 'user_id'])
                ->where(['status' => Store::STATUS_ACTIVE])
                ->andWhere(['>', 'user_id', 0])
                ->asArray()
                ->all();

            foreach ($stores as $store) {
                $storeMap[(int)$store['user_id']] = [
                    'id' => (int)$store['id'],
                    'name' => (string)$store['name'],
                ];
            }
        }

        return $this->render($this->action->id, [
            'uid' => $uid,
            'isPlatformOperator' => $isPlatformOperator,
            'storeMap' => $storeMap,
            'imAuthToken' => $this->createImAuthToken([
                'type' => $isPlatformOperator ? 'platform' : 'merchant',
                'user_id' => (string)$uid,
            ]),
        ]);
    }

    public function actionTickets()
    {
        $service = new CustomerServiceAdvancedService();
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $limit = max(1, min(500, (int)Yii::$app->request->get('limit', 100)));
        $filters = [
            'ticket_type' => (string)Yii::$app->request->get('ticket_type', ''),
            'ticket_status' => (string)Yii::$app->request->get('ticket_status', ''),
        ];

        return $this->render('tickets', [
            'isPlatformOperator' => $isPlatformOperator,
            'storeId' => $storeId,
            'limit' => $limit,
            'filters' => $filters,
            'stores' => $this->getStoresIdName(),
            'ticketTypes' => $service->supportedTicketTypes(),
            'ticketStatuses' => $service->supportedTicketStatuses(),
            'tickets' => $service->ticketRows($storeId, $limit, $filters),
            'statRows' => $service->statRows($storeId, 14),
        ]);
    }

    public function actionTicketView($id)
    {
        $service = new CustomerServiceAdvancedService();
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $ticket = $service->ticketRow((int)$id, $storeId);
        if (!$ticket) {
            throw new NotFoundHttpException(Yii::t('app', 'No results found.'));
        }

        return $this->render('ticket-view', [
            'isPlatformOperator' => $isPlatformOperator,
            'ticket' => $ticket,
            'events' => $service->eventRows((int)$ticket['id']),
            'workflowTargets' => $service->supportedTransitions()[(string)$ticket['ticket_status']] ?? [],
        ]);
    }

    public function actionStatExport()
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $request = Yii::$app->request;
        $service = new CustomerServiceStatExportService();
        $report = $service->run(
            $storeId,
            (string)$request->get('date_from', ''),
            (string)$request->get('date_to', ''),
            (int)$request->get('limit', 500)
        );
        $content = implode("\n", $service->csvLines($report)) . "\n";

        return Yii::$app->response->sendContentAsFile(
            $content,
            'mongoyia-customer-service-stat-export-' . date('Ymd-His') . '.csv',
            ['mimeType' => 'text/csv', 'inline' => false]
        );
    }

    public function actionComplaintExport()
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $request = Yii::$app->request;
        $service = new CustomerServiceComplaintExportService();
        $report = $service->run(
            $storeId,
            (string)$request->get('ticket_status', ''),
            (string)$request->get('date_from', ''),
            (string)$request->get('date_to', ''),
            (int)$request->get('limit', 500)
        );
        $content = implode("\n", $service->csvLines($report)) . "\n";

        return Yii::$app->response->sendContentAsFile(
            $content,
            'mongoyia-customer-service-complaint-export-' . date('Ymd-His') . '.csv',
            ['mimeType' => 'text/csv', 'inline' => false]
        );
    }

    public function actionResolutionExport()
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $request = Yii::$app->request;
        $service = new CustomerServiceResolutionExportService();
        $report = $service->run(
            $storeId,
            (string)$request->get('ticket_type', ''),
            (string)$request->get('date_from', ''),
            (string)$request->get('date_to', ''),
            (int)$request->get('limit', 500)
        );
        $content = implode("\n", $service->csvLines($report)) . "\n";

        return Yii::$app->response->sendContentAsFile(
            $content,
            'mongoyia-customer-service-resolution-export-' . date('Ymd-His') . '.csv',
            ['mimeType' => 'text/csv', 'inline' => false]
        );
    }

    public function actionSlaReadiness()
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $request = Yii::$app->request;
        $service = new CustomerServiceSlaReadinessService();
        $report = $service->run(
            $storeId,
            (string)$request->get('ticket_type', ''),
            (string)$request->get('date_from', ''),
            (string)$request->get('date_to', ''),
            (int)$request->get('first_response_seconds', 1800),
            (int)$request->get('resolution_seconds', 86400),
            (int)$request->get('limit', 500)
        );
        $content = implode("\n", $service->csvLines($report)) . "\n";

        return Yii::$app->response->sendContentAsFile(
            $content,
            'mongoyia-customer-service-sla-readiness-' . date('Ymd-His') . '.csv',
            ['mimeType' => 'text/csv', 'inline' => false]
        );
    }

    public function actionSlaHandling()
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $request = Yii::$app->request;
        $service = new CustomerServiceSlaHandlingService();
        $report = $service->run(
            $storeId,
            (string)$request->get('ticket_type', ''),
            (string)$request->get('date_from', ''),
            (string)$request->get('date_to', ''),
            (int)$request->get('first_response_seconds', 1800),
            (int)$request->get('resolution_seconds', 86400),
            (int)$request->get('watch_window_seconds', 3600),
            (int)$request->get('limit', 500)
        );
        $content = implode("\n", $service->csvLines($report)) . "\n";

        return Yii::$app->response->sendContentAsFile(
            $content,
            'mongoyia-customer-service-sla-handling-' . date('Ymd-His') . '.csv',
            ['mimeType' => 'text/csv', 'inline' => false]
        );
    }

    public function actionResultSignoff()
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $request = Yii::$app->request;
        $service = new CustomerServiceResultSignoffService();
        $report = $service->run(
            $storeId,
            (string)$request->get('ticket_type', ''),
            (string)$request->get('date_from', ''),
            (string)$request->get('date_to', ''),
            (int)$request->get('limit', 500)
        );
        $content = implode("\n", $service->csvLines($report)) . "\n";

        return Yii::$app->response->sendContentAsFile(
            $content,
            'mongoyia-customer-service-result-signoff-' . date('Ymd-His') . '.csv',
            ['mimeType' => 'text/csv', 'inline' => false]
        );
    }

    public function actionStatApplyLog()
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $request = Yii::$app->request;
        $service = new CustomerServiceStatApplyLogReviewService();
        $filters = [
            'date_from' => (string)$request->get('date_from', ''),
            'date_to' => (string)$request->get('date_to', ''),
            'batch_sn' => (string)$request->get('batch_sn', ''),
            'operation' => (string)$request->get('operation', ''),
        ];
        $limit = max(1, min(500, (int)$request->get('limit', 100)));

        return $this->render('stat-apply-log', [
            'isPlatformOperator' => $isPlatformOperator,
            'storeId' => $storeId,
            'stores' => $this->getStoresIdName(),
            'filters' => $filters,
            'limit' => $limit,
            'report' => $service->run(
                $storeId,
                $filters['date_from'],
                $filters['date_to'],
                $filters['batch_sn'],
                $filters['operation'],
                $limit
            ),
        ]);
    }

    public function actionTicketWorkflow()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $request = Yii::$app->request;
        $id = (int)$request->post('id', 0);
        $targetStatus = (string)$request->post('target_status', '');
        if ($id <= 0 || $targetStatus === '') {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $operatorType = $isPlatformOperator
            ? CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM
            : CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT;

        try {
            $result = (new CustomerServiceTicketWorkflowService())->run(
                [$id],
                $targetStatus,
                true,
                (int)Yii::$app->user->id,
                $operatorType,
                'backend customer-service ticket workflow',
                $storeId
            );
            if ((int)$result['updated'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason, ['ticket-view', 'id' => $id]);
            }

            $this->clearCache();
            return $this->redirectSuccess(['ticket-view', 'id' => $id], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['ticket-view', 'id' => $id]);
        }
    }

    public function actionTicketCreate()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $request = Yii::$app->request;
        $isPlatformOperator = $this->isMallPlatformOperator();
        $scopeStoreId = $this->readableStoreId($isPlatformOperator);
        $storeId = $isPlatformOperator ? (int)$request->post('store_id', 0) : $scopeStoreId;
        $ticketType = (string)$request->post('ticket_type', '');
        $operatorType = $isPlatformOperator
            ? CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM
            : CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT;

        try {
            $result = (new CustomerServiceTicketCreateService())->run([
                'store_id' => $storeId,
                'product_id' => (int)$request->post('product_id', 0),
                'order_id' => (int)$request->post('order_id', 0),
                'order_sn' => (string)$request->post('order_sn', ''),
                'customer_user_id' => (int)$request->post('customer_user_id', 0),
                'customer_uuid' => (string)$request->post('customer_uuid', ''),
                'merchant_user_id' => $isPlatformOperator ? (int)$request->post('merchant_user_id', 0) : (int)Yii::$app->user->id,
                'platform_user_id' => $isPlatformOperator ? (int)Yii::$app->user->id : 0,
                'chat_uuid' => (string)$request->post('chat_uuid', ''),
                'title' => (string)$request->post('title', ''),
                'content' => (string)$request->post('content', ''),
                'remark' => 'backend customer-service ticket create',
            ], $ticketType, true, (int)Yii::$app->user->id, $operatorType, $scopeStoreId);

            if ((int)$result['created'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason, ['tickets', 'store_id' => $storeId]);
            }

            $this->clearCache();
            return $this->redirectSuccess(['ticket-view', 'id' => (int)$result['ticketId'], 'store_id' => $storeId], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['tickets', 'store_id' => $storeId]);
        }
    }

    public function actionTicketAssign()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $request = Yii::$app->request;
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $operatorType = $isPlatformOperator
            ? CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM
            : CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT;
        $assignmentType = $isPlatformOperator
            ? (string)$request->post('assignment_type', CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_MERCHANT)
            : CustomerServiceTicketAssignService::ASSIGNMENT_TYPE_MERCHANT;

        try {
            $result = (new CustomerServiceTicketAssignService())->run(
                $id,
                $assignmentType,
                (int)$request->post('assignee_user_id', 0),
                true,
                (int)Yii::$app->user->id,
                $operatorType,
                (string)$request->post('remark', ''),
                $storeId
            );
            if ((int)$result['assigned'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason, ['ticket-view', 'id' => $id]);
            }

            $this->clearCache();
            return $this->redirectSuccess(['ticket-view', 'id' => $id], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['ticket-view', 'id' => $id]);
        }
    }

    public function actionTicketNote()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $request = Yii::$app->request;
        $id = (int)$request->post('id', 0);
        $content = (string)$request->post('content', '');
        if ($id <= 0) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $operatorType = $isPlatformOperator
            ? CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM
            : CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT;

        try {
            $result = (new CustomerServiceTicketNoteService())->run(
                $id,
                $content,
                true,
                (int)Yii::$app->user->id,
                $operatorType,
                $storeId
            );
            if ((int)$result['noted'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason, ['ticket-view', 'id' => $id]);
            }

            $this->clearCache();
            return $this->redirectSuccess(['ticket-view', 'id' => $id], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['ticket-view', 'id' => $id]);
        }
    }

    public function actionTicketResult()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $request = Yii::$app->request;
        $id = (int)$request->post('id', 0);
        $resultText = (string)$request->post('result', '');
        if ($id <= 0) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $operatorType = $isPlatformOperator
            ? CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM
            : CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT;

        try {
            $result = (new CustomerServiceTicketResultService())->run(
                $id,
                $resultText,
                true,
                (int)Yii::$app->user->id,
                $operatorType,
                $storeId
            );
            if ((int)$result['written'] <= 0) {
                $reason = $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records');
                return $this->redirectError($reason, ['ticket-view', 'id' => $id]);
            }

            $this->clearCache();
            return $this->redirectSuccess(['ticket-view', 'id' => $id], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['ticket-view', 'id' => $id]);
        }
    }

    private function readableStoreId(bool $isPlatformOperator): int
    {
        if ($isPlatformOperator) {
            return max(0, (int)Yii::$app->request->get('store_id', 0));
        }

        $storeId = (int)$this->getStoreId();
        return $storeId > 0 ? $storeId : -1;
    }

    private function createImAuthToken(array $payload)
    {
        $secret = (string)(Yii::$app->params['imAuthSecret'] ?? '');
        if ($secret === '') {
            return '';
        }

        $payload['exp'] = time() + (int)(Yii::$app->params['imAuthTokenTtl'] ?? 3600);
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $secret);

        return $encodedPayload . '.' . $signature;
    }
}
