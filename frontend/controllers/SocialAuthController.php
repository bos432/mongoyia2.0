<?php

namespace frontend\controllers;

use common\services\mall\OperationalIdentityConfigService;
use common\services\mall\SocialIdentityService;
use Yii;
use yii\filters\VerbFilter;
use yii\helpers\Url;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class SocialAuthController extends BaseController
{
    public const MONGOYIA_SOCIAL_AUTH_BOUNDARY_V1 = 'MONGOYIA_SOCIAL_AUTH_BOUNDARY_V1';
    public const MONGOYIA_SOCIAL_AUTH_RUNTIME_V1 = 'MONGOYIA_SOCIAL_AUTH_RUNTIME_V1';
    public const SOCIAL_AUTH_UNBIND_POST_GUARD_VERSION = 'MONGOYIA_SOCIAL_AUTH_UNBIND_POST_GUARD_V1';
    public const PROVIDER_ACCEPTANCE_GATE = 'third_party_login_requires_provider_acceptance';
    public const SECRET_LOGGING_POLICY = 'provider_secret_never_logged';
    public const FIRST_LOGIN_BIND_POLICY = 'require_existing_session_before_first_login';

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'unbind' => ['POST'],
                ],
            ],
        ];
    }

    public function actionRedirect($provider = '')
    {
        $provider = $this->normalizeProvider($provider);
        $config = $this->providerConfig($provider);
        if (!$this->providerEnabled($config)) {
            return $this->disabledResponse($provider);
        }

        try {
            $url = (new SocialIdentityService())->authorizationUrl(
                $provider,
                false,
                (string)Yii::$app->request->get('returnUrl', '')
            );
            return $this->redirect($url);
        } catch (\Throwable $e) {
            Yii::warning(['provider' => $provider, 'message' => $e->getMessage()], 'mall.social_auth.redirect_failed');
            return $this->errorResponse($provider, $e->getMessage(), 503);
        }
    }

    public function actionCallback($provider = '')
    {
        $provider = $this->normalizeProvider($provider);
        $code = (string)Yii::$app->request->get('code', '');
        $state = (string)Yii::$app->request->get('state', '');
        if ($code === '' || $state === '') {
            Yii::$app->session->setFlash('error', 'Third-party login callback is missing code or state.');
            return $this->redirect(['/site/login']);
        }

        try {
            $result = (new SocialIdentityService())->handleCallback($provider, $code, $state);
            if (($result['action'] ?? '') === 'logged_in') {
                Yii::$app->session->setFlash('success', ucfirst($provider) . ' login successful.');
            } elseif (($result['action'] ?? '') === 'bound') {
                Yii::$app->session->setFlash('success', ucfirst($provider) . ' account bound.');
            } else {
                Yii::$app->session->setFlash('warning', $result['message'] ?? 'Please sign in and bind this third-party account first.');
            }
            return $this->redirect($result['return_url'] ?? ['/site/index']);
        } catch (\Throwable $e) {
            Yii::warning(['provider' => $provider, 'message' => $e->getMessage()], 'mall.social_auth.callback_failed');
            Yii::$app->session->setFlash('error', $e->getMessage());
            return $this->redirect(['/site/login']);
        }
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

        try {
            $url = (new SocialIdentityService())->authorizationUrl(
                $provider,
                true,
                (string)Yii::$app->request->get('returnUrl', Yii::$app->request->referrer ?: Url::to(['/site/index']))
            );
            return $this->redirect($url);
        } catch (\Throwable $e) {
            Yii::warning(['provider' => $provider, 'message' => $e->getMessage()], 'mall.social_auth.bind_failed');
            Yii::$app->session->setFlash('error', $e->getMessage());
            return $this->goHome();
        }
    }

    public function actionUnbind($provider = '')
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['/site/login', 'returnUrl' => Yii::$app->request->getUrl()]);
        }

        $provider = $provider !== '' ? $provider : (string)Yii::$app->request->post('provider', '');
        $provider = $this->normalizeProvider($provider);
        try {
            (new SocialIdentityService())->unbind($provider, (int)Yii::$app->user->id);
            Yii::$app->session->setFlash('success', ucfirst($provider) . ' account unbound.');
        } catch (\Throwable $e) {
            Yii::warning(['provider' => $provider, 'message' => $e->getMessage()], 'mall.social_auth.unbind_failed');
            Yii::$app->session->setFlash('error', $e->getMessage());
        }

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

    protected function errorResponse(string $provider, string $message, int $statusCode = 400)
    {
        if (Yii::$app->request->isAjax) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            Yii::$app->response->statusCode = $statusCode;
            return [
                'success' => false,
                'code' => 'SOCIAL_AUTH_UNAVAILABLE',
                'provider' => $provider,
                'message' => $message,
            ];
        }

        Yii::$app->session->setFlash('error', $message);
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
