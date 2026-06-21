<?php

namespace backend\modules\mall\controllers;

use common\models\Store;
use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceComplaintEvidenceService;
use common\services\mall\CustomerServiceQuickReplyService;
use common\services\mall\CustomerServiceRatingService;
use common\services\mall\CustomerServiceTicketAssignService;
use common\services\mall\CustomerServiceTicketCreateService;
use common\services\mall\CustomerServiceTicketNoteService;
use common\services\mall\CustomerServiceTicketResultService;
use common\services\mall\CustomerServiceTicketWorkflowService;
use common\services\mall\CustomerServiceComplaintExportService;
use common\services\mall\CustomerServiceResultSignoffService;
use common\services\mall\CustomerServiceSessionContextService;
use common\services\mall\CustomerServiceSlaHandlingService;
use common\services\mall\CustomerServiceResolutionExportService;
use common\services\mall\CustomerServiceSlaReadinessService;
use common\services\mall\CustomerServiceStatApplyLogReviewService;
use common\services\mall\CustomerServiceStatExportService;
use common\services\mall\CustomerServiceStatWidgetReadinessService;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UploadedFile;
use yii\web\Response;

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
            'quickReplies' => (new CustomerServiceQuickReplyService())->workbenchRows($isPlatformOperator ? 0 : (int)$this->getStoreId()),
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
        $slaOptions = [
            'first_response_seconds' => max(60, min(86400, (int)Yii::$app->request->get('first_response_seconds', 1800))),
            'resolution_seconds' => max(300, min(2592000, (int)Yii::$app->request->get('resolution_seconds', 86400))),
            'watch_window_seconds' => max(60, min(86400, (int)Yii::$app->request->get('watch_window_seconds', 3600))),
        ];
        $slaReadiness = (new CustomerServiceSlaReadinessService())->run(
            $storeId,
            $filters['ticket_type'],
            '',
            '',
            $slaOptions['first_response_seconds'],
            $slaOptions['resolution_seconds'],
            $limit
        );
        $slaHandling = (new CustomerServiceSlaHandlingService())->run(
            $storeId,
            $filters['ticket_type'],
            '',
            '',
            $slaOptions['first_response_seconds'],
            $slaOptions['resolution_seconds'],
            $slaOptions['watch_window_seconds'],
            $limit
        );

        return $this->render('tickets', [
            'isPlatformOperator' => $isPlatformOperator,
            'storeId' => $storeId,
            'limit' => $limit,
            'filters' => $filters,
            'slaOptions' => $slaOptions,
            'slaReadiness' => $slaReadiness,
            'slaHandling' => $slaHandling,
            'statDashboard' => (new CustomerServiceStatWidgetReadinessService())->run($storeId, '', '', 60),
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
            'evidenceFiles' => (new CustomerServiceComplaintEvidenceService())->evidenceList($ticket),
            'ratingRows' => (new CustomerServiceRatingService())->rowsForTicket($ticket),
            'workflowTargets' => $service->supportedTransitions()[(string)$ticket['ticket_status']] ?? [],
        ]);
    }

    public function actionSessionContext()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $isPlatformOperator = $this->isMallPlatformOperator();
        $scopeStoreId = $this->readableStoreId($isPlatformOperator);
        $result = (new CustomerServiceSessionContextService())->build(Yii::$app->request->get(), $scopeStoreId);
        if (!empty($result['error'])) {
            Yii::$app->response->statusCode = 403;
        }

        return $result;
    }

    public function actionTicketCreateFromSession()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (!Yii::$app->request->isPost) {
            Yii::$app->response->statusCode = 405;
            return ['success' => false, 'message' => Yii::t('app', 'Invalid request method.')];
        }

        $request = Yii::$app->request;
        $isPlatformOperator = $this->isMallPlatformOperator();
        $scopeStoreId = $this->readableStoreId($isPlatformOperator);
        $storeId = $isPlatformOperator ? (int)$request->post('store_id', 0) : $scopeStoreId;
        $ticketType = (string)$request->post('ticket_type', CustomerServiceAdvancedService::TICKET_TYPE_ORDER_ASSIST);
        $operatorType = $isPlatformOperator
            ? CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM
            : CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT;
        $title = trim((string)$request->post('title', ''));
        if ($title === '') {
            $title = $ticketType === CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT ? '聊天投诉工单' : '聊天订单协助工单';
        }

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
                'title' => $title,
                'content' => (string)$request->post('content', ''),
                'remark' => 'backend customer-service chat workbench ticket create',
                'source' => 'chat-workbench',
            ], $ticketType, true, (int)Yii::$app->user->id, $operatorType, $scopeStoreId);

            if ((int)$result['created'] <= 0) {
                Yii::$app->response->statusCode = 409;
                return [
                    'success' => false,
                    'message' => $result['skipped'][0]['reason'] ?? Yii::t('app', 'No eligible records'),
                    'ticket_id' => (int)($result['skipped'][0]['ticketId'] ?? 0),
                ];
            }

            $this->clearCache();
            return [
                'success' => true,
                'message' => Yii::t('app', 'Operate Successfully'),
                'ticket_id' => (int)$result['ticketId'],
                'ticket_sn' => (string)$result['ticketSn'],
                'ticket_type' => $ticketType,
            ];
        } catch (\Throwable $e) {
            Yii::$app->response->statusCode = 400;
            return ['success' => false, 'message' => $e->getMessage()];
        }
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

    public function actionQuickReplies()
    {
        $service = new CustomerServiceQuickReplyService();
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $filters = [
            'category' => (string)Yii::$app->request->get('category', ''),
            'keyword' => (string)Yii::$app->request->get('keyword', ''),
        ];

        return $this->render('quick-replies', [
            'isPlatformOperator' => $isPlatformOperator,
            'storeId' => $storeId,
            'stores' => $this->getStoresIdName(),
            'categories' => $service->categories(),
            'categoryLabels' => $service->categoryLabels(),
            'filters' => $filters,
            'rows' => $service->rows($storeId, $filters, 500),
        ]);
    }

    public function actionQuickReplySave()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        try {
            (new CustomerServiceQuickReplyService())->save(
                Yii::$app->request->post(),
                $isPlatformOperator,
                $storeId,
                (int)Yii::$app->user->id
            );
            $this->clearCache();
            return $this->redirectSuccess(['quick-replies', 'store_id' => $storeId], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['quick-replies', 'store_id' => $storeId]);
        }
    }

    public function actionQuickReplyDelete()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        try {
            (new CustomerServiceQuickReplyService())->delete(
                (int)Yii::$app->request->post('id', 0),
                $isPlatformOperator,
                $storeId,
                (int)Yii::$app->user->id
            );
            $this->clearCache();
            return $this->redirectSuccess(['quick-replies', 'store_id' => $storeId], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['quick-replies', 'store_id' => $storeId]);
        }
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

    public function actionComplaintEvidenceUpload()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $request = Yii::$app->request;
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return $this->redirectError(Yii::t('app', 'Invalid id'), ['tickets']);
        }

        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $operatorType = $isPlatformOperator
            ? CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM
            : CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT;
        $file = UploadedFile::getInstanceByName('evidence_file');
        if (!$file) {
            return $this->redirectError('请选择投诉证据图片。', ['ticket-view', 'id' => $id]);
        }

        try {
            (new CustomerServiceComplaintEvidenceService())->upload(
                $id,
                $file,
                (string)$request->post('note', ''),
                (int)Yii::$app->user->id,
                $operatorType,
                $storeId
            );
            $this->clearCache();
            return $this->redirectSuccess(['ticket-view', 'id' => $id], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['ticket-view', 'id' => $id]);
        }
    }

    public function actionComplaintEvidenceDelete()
    {
        if (!Yii::$app->request->isPost) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request method.'));
        }

        $request = Yii::$app->request;
        $id = (int)$request->post('id', 0);
        $evidenceId = (string)$request->post('evidence_id', '');
        if ($id <= 0 || $evidenceId === '') {
            return $this->redirectError(Yii::t('app', 'Invalid id'), ['tickets']);
        }

        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);
        $operatorType = $isPlatformOperator
            ? CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM
            : CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT;

        try {
            (new CustomerServiceComplaintEvidenceService())->delete(
                $id,
                $evidenceId,
                (int)Yii::$app->user->id,
                $operatorType,
                $storeId
            );
            $this->clearCache();
            return $this->redirectSuccess(['ticket-view', 'id' => $id], Yii::t('app', 'Operate Successfully'));
        } catch (\Throwable $e) {
            return $this->redirectError($e->getMessage(), ['ticket-view', 'id' => $id]);
        }
    }

    public function actionComplaintEvidenceView($id, $evidence_id)
    {
        $isPlatformOperator = $this->isMallPlatformOperator();
        $storeId = $this->readableStoreId($isPlatformOperator);

        try {
            $file = (new CustomerServiceComplaintEvidenceService())->viewFile((int)$id, (string)$evidence_id, $storeId);
        } catch (\Throwable $e) {
            throw new NotFoundHttpException($e->getMessage());
        }

        return Yii::$app->response->sendFile($file['path'], $file['name'], [
            'mimeType' => $file['mime'],
            'inline' => true,
        ]);
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
