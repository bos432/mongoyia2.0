<?php

namespace frontend\controllers;

use common\services\mall\OperationalIdentityConfigService;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SocialAuthController extends BaseController
{
    public const MONGOYIA_SOCIAL_AUTH_BOUNDARY_V1 = 'MONGOYIA_SOCIAL_AUTH_BOUNDARY_V1';
    public const PROVIDER_ACCEPTANCE_GATE = 'third_party_login_requires_provider_acceptance';
    public const SECRET_LOGGING_POLICY = 'provider_secret_never_logged';

    public function actionRedirect($provider = '')
    {
        $provider = $this->normalizeProvider($provider);
        $config = $this->providerConfig($provider);
        if (!$this->providerEnabled($config)) {
            return $this->disabledResponse($provider);
        }

        Yii::$app->session->setFlash('warning', 'Third-party login provider acceptance is not complete.');
        return $this->redirect(['/site/login']);
    }

    public function actionCallback($provider = '')
    {
        $provider = $this->normalizeProvider($provider);
        $config = $this->providerConfig($provider);
        if (!$this->providerEnabled($config)) {
            return $this->disabledResponse($provider);
        }

        Yii::$app->session->setFlash('warning', 'Third-party login callback is reserved until provider evidence is accepted.');
        return $this->redirect(['/site/login']);
    }

    public function actionBind($provider = '')
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['/site/login', 'returnUrl' => Yii::$app->request->getUrl()]);
        }

        $provider = $this->normalizeProvider($provider);
        $config = $this->providerConfig($provider);
        if (!$this->providerEnabled($config)) {
            return $this->disabledResponse($provider);
        }

        Yii::$app->session->setFlash('warning', 'Third-party account bind is reserved until provider evidence is accepted.');
        return $this->goHome();
    }

    public function actionUnbind($provider = '')
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['/site/login', 'returnUrl' => Yii::$app->request->getUrl()]);
        }

        $provider = $this->normalizeProvider($provider);
        $config = $this->providerConfig($provider);
        if (!$this->providerEnabled($config)) {
            return $this->disabledResponse($provider);
        }

        Yii::$app->session->setFlash('warning', 'Third-party account unbind is reserved until provider evidence is accepted.');
        return $this->goHome();
    }

    protected function providerConfig(string $provider): array
    {
        try {
            return (new OperationalIdentityConfigService())->runtimeConfig($provider);
        } catch (\Throwable $e) {
            Yii::warning([
                'provider' => $provider,
                'message' => $e->getMessage(),
            ], 'mall.identity.provider_config_failed');
            return [
                'provider' => $provider,
                'enabled' => '0',
            ];
        }
    }

    protected function providerEnabled(array $config): bool
    {
        return !empty($config['enabled']) && !in_array(strtolower((string)$config['enabled']), ['0', 'false', 'off', 'no'], true);
    }

    protected function disabledResponse(string $provider)
    {
        if (Yii::$app->request->isAjax) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            Yii::$app->response->statusCode = 404;
            return [
                'success' => false,
                'code' => 'SOCIAL_AUTH_DISABLED',
                'provider' => $provider,
                'message' => 'Third-party login provider is disabled',
            ];
        }

        Yii::$app->session->setFlash('error', 'Third-party login provider is disabled.');
        return $this->redirect(['/site/login']);
    }

    protected function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, ['google', 'facebook'], true)) {
            throw new BadRequestHttpException('Unsupported third-party login provider.');
        }

        return $provider;
    }
}
