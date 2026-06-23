<?php

namespace frontend\controllers;

use common\services\mall\OperationalAccountSecurityService;
use Yii;
use yii\web\Response;

class AccountSecurityController extends BaseController
{
    public const MONGOYIA_ACCOUNT_SECURITY_BOUNDARY_V1 = 'MONGOYIA_ACCOUNT_SECURITY_BOUNDARY_V1';
    public const SECURITY_CODE_POLICY_GATE = 'security_code_login_requires_delivery_provider_and_audit';

    public function actionRequestCode($channel = 'email')
    {
        $channel = $this->normalizeChannel($channel);
        $service = new OperationalAccountSecurityService();
        if (!$service->codeLoginEnabled($channel)) {
            return $this->disabledResponse($channel);
        }

        Yii::$app->session->setFlash('warning', 'Security-code delivery is reserved until provider evidence and audit storage are accepted.');
        return $this->reservedResponse($channel, 'SECURITY_CODE_DELIVERY_RESERVED');
    }

    public function actionLoginCode($channel = 'email')
    {
        $channel = $this->normalizeChannel($channel);
        $service = new OperationalAccountSecurityService();
        if (!$service->codeLoginEnabled($channel)) {
            return $this->disabledResponse($channel);
        }

        Yii::$app->session->setFlash('warning', 'Security-code login is reserved until verification storage is accepted.');
        return $this->reservedResponse($channel, 'SECURITY_CODE_LOGIN_RESERVED');
    }

    private function disabledResponse(string $channel)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->statusCode = 404;

        return [
            'success' => false,
            'code' => 'SECURITY_CODE_LOGIN_DISABLED',
            'channel' => $channel,
            'message' => 'Security-code login is disabled',
        ];
    }

    private function reservedResponse(string $channel, string $code)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->statusCode = 503;

        return [
            'success' => false,
            'code' => $code,
            'channel' => $channel,
            'message' => 'Security-code flow is not ready for live traffic',
        ];
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        return $channel === 'mobile' ? 'mobile' : 'email';
    }
}
