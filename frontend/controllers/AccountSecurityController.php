<?php

namespace frontend\controllers;

use common\services\mall\AccountSecurityCodeService;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Response;

class AccountSecurityController extends BaseController
{
    public const MONGOYIA_ACCOUNT_SECURITY_BOUNDARY_V1 = 'MONGOYIA_ACCOUNT_SECURITY_BOUNDARY_V1';
    public const MONGOYIA_ACCOUNT_SECURITY_CODE_RUNTIME_V1 = 'MONGOYIA_ACCOUNT_SECURITY_CODE_RUNTIME_V1';
    public const FRONTEND_SECURITY_CODE_POST_GUARD_VERSION = 'MONGOYIA_FRONTEND_SECURITY_CODE_POST_GUARD_V1';
    public const SECURITY_CODE_POLICY_GATE = 'security_code_login_requires_delivery_provider_and_audit';
    public const SECURITY_CODE_RUNTIME_GATE = 'security_code_delivery_storage_runtime_enabled_email_only';
    public const SECURITY_CODE_DELIVERY_RESERVED = 'SECURITY_CODE_DELIVERY_RESERVED';

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'request-code' => ['POST'],
                    'login-code' => ['POST'],
                ],
            ],
        ];
    }

    public function actionRequestCode($channel = 'email')
    {
        $channel = $this->normalizeChannel($channel);
        $target = $this->requestValue('target');
        $result = (new AccountSecurityCodeService())->requestCode($channel, $target);

        return $this->jsonResponse($channel, $result);
    }

    public function actionLoginCode($channel = 'email')
    {
        $channel = $this->normalizeChannel($channel);
        $target = $this->requestValue('target');
        $code = $this->requestValue('code');
        $result = (new AccountSecurityCodeService())->loginWithCode($channel, $target, $code);

        return $this->jsonResponse($channel, $result);
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

    private function jsonResponse(string $channel, array $result)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->statusCode = $this->statusCodeForResult($result);

        return array_merge(['channel' => $channel], $result);
    }

    private function statusCodeForResult(array $result): int
    {
        if (!empty($result['success'])) {
            return 200;
        }

        $code = (string)($result['code'] ?? '');
        if ($code === 'SECURITY_CODE_LOGIN_DISABLED') {
            return 404;
        }
        if ($code === 'SECURITY_CODE_TABLE_MISSING' ||
            $code === 'SECURITY_CODE_DELIVERY_FAILED' ||
            $code === 'SECURITY_CODE_MOBILE_RESERVED') {
            return 503;
        }
        if ($code === 'SECURITY_CODE_LOCKED') {
            return 423;
        }
        if ($code === 'SECURITY_CODE_EXPIRED') {
            return 410;
        }

        return 400;
    }

    private function requestValue(string $name): string
    {
        $request = Yii::$app->request;
        $value = $request->post($name, '');

        return trim((string)$value);
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        return $channel === 'mobile' ? 'mobile' : 'email';
    }
}
