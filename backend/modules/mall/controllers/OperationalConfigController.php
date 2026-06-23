<?php

namespace backend\modules\mall\controllers;

use common\services\mall\OperationalConfigService;
use common\services\mall\CustomerServiceTranslationService;
use common\services\mall\MerchantPaymentConfigService;
use common\services\mall\OperationalAccountSecurityService;
use common\services\mall\OperationalIdentityConfigService;
use common\services\mall\OperationalLaunchSignoffService;
use common\services\mall\OperationalMailConfigService;
use common\services\mall\OperationalOpsAlertService;
use common\services\mall\OperationalPaymentConfigService;
use common\services\mall\OperationalPhase10ReadinessService;
use common\services\mall\OperationalProviderEvidenceService;
use Yii;
use yii\web\ForbiddenHttpException;

class OperationalConfigController extends BaseController
{
    public const BACKEND_VERB_GUARD_VERSION = 'MONGOYIA_OPERATIONAL_CONFIG_BACKEND_POST_VERB_GUARD_V1';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        foreach ([
            'save-payment',
            'check-payment',
            'save-account-security',
            'check-account-security',
            'save-identity-config',
            'check-identity-config',
            'save-merchant-payment-permission',
            'save-merchant-payment',
            'check-merchant-payment',
            'save-translation',
            'check-translation',
            'test-translation',
            'save-mail',
            'test-mail',
            'save-alert',
            'test-alert',
            'save-launch',
            'save-provider-evidence',
            'check-provider-evidence',
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

        return $this->render('index', [
            'summary' => (new OperationalConfigService())->summary(),
            'payment' => (new OperationalPaymentConfigService())->snapshot(
                (string)Yii::$app->request->get('environment', 'test')
            ),
            'paymentEnvironments' => (new OperationalPaymentConfigService())->environments(),
            'mail' => (new OperationalMailConfigService())->snapshot(),
            'translation' => (new CustomerServiceTranslationService())->snapshot(),
            'opsAlert' => (new OperationalOpsAlertService())->snapshot(),
            'launch' => (new OperationalLaunchSignoffService())->snapshot(),
            'providerEvidence' => (new OperationalProviderEvidenceService())->snapshot(
                (string)Yii::$app->request->get('environment', 'test')
            ),
            'phase10Readiness' => (new OperationalPhase10ReadinessService())->snapshot(
                (string)Yii::$app->request->get('environment', 'test')
            ),
        ]);
    }

    public function actionSavePayment()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new OperationalPaymentConfigService())->saveProvider(
                $provider,
                $environment,
                (array)$request->post('config', [])
            );
            Yii::$app->session->setFlash('success', '支付配置已保存，检测结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '支付配置保存失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => $environment]);
    }

    public function actionCheckPayment()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new OperationalPaymentConfigService())->checkProvider($provider, $environment, true);
            Yii::$app->session->setFlash('success', '支付配置检测完成：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '支付配置检测失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => $environment]);
    }

    public function actionMerchantPayment()
    {
        $environment = (string)Yii::$app->request->get('environment', 'test');
        $storeId = $this->merchantPaymentStoreId();

        return $this->render('merchant-payment', [
            'snapshot' => (new MerchantPaymentConfigService())->snapshot($environment, $storeId),
            'paymentEnvironments' => (new OperationalPaymentConfigService())->environments(),
            'isPlatformOperator' => $this->isMallPlatformOperator(),
        ]);
    }

    public function actionIdentityConfig()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $environment = (string)Yii::$app->request->get('environment', 'test');

        return $this->render('identity-config', [
            'snapshot' => (new OperationalIdentityConfigService())->snapshot($environment),
            'identityEnvironments' => (new OperationalIdentityConfigService())->environments(),
        ]);
    }

    public function actionAccountSecurity()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return $this->render('account-security', [
            'snapshot' => (new OperationalAccountSecurityService())->snapshot(),
        ]);
    }

    public function actionSaveAccountSecurity()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        try {
            $result = (new OperationalAccountSecurityService())->save((array)Yii::$app->request->post('security', []));
            Yii::$app->session->setFlash('success', '账号安全策略已保存，检测结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '账号安全策略保存失败：' . $e->getMessage());
        }

        return $this->redirect(['account-security']);
    }

    public function actionCheckAccountSecurity()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        try {
            $result = (new OperationalAccountSecurityService())->check(true);
            Yii::$app->session->setFlash('success', '账号安全策略检测完成：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '账号安全策略检测失败：' . $e->getMessage());
        }

        return $this->redirect(['account-security']);
    }

    public function actionSaveIdentityConfig()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new OperationalIdentityConfigService())->saveProvider(
                $provider,
                $environment,
                (array)$request->post('config', [])
            );
            Yii::$app->session->setFlash('success', '第三方登录配置已保存，检测结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '第三方登录配置保存失败：' . $e->getMessage());
        }

        return $this->redirect(['identity-config', 'environment' => $environment]);
    }

    public function actionCheckIdentityConfig()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new OperationalIdentityConfigService())->checkProvider($provider, $environment, true);
            Yii::$app->session->setFlash('success', '第三方登录配置检测完成：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '第三方登录配置检测失败：' . $e->getMessage());
        }

        return $this->redirect(['identity-config', 'environment' => $environment]);
    }

    public function actionSaveMerchantPaymentPermission()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $storeId = (int)$request->post('store_id', 0);
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new MerchantPaymentConfigService())->savePermission($storeId, (array)$request->post('permission', []));
            Yii::$app->session->setFlash('success', '商家支付权限已保存：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '商家支付权限保存失败：' . $e->getMessage());
        }

        return $this->redirect(['merchant-payment', 'store_id' => $storeId, 'environment' => $environment]);
    }

    public function actionSaveMerchantPayment()
    {
        $request = Yii::$app->request;
        $storeId = $this->merchantPaymentStoreId((int)$request->post('store_id', 0));
        $provider = (string)$request->post('provider', '');
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new MerchantPaymentConfigService())->saveProvider(
                $storeId,
                $provider,
                $environment,
                (array)$request->post('config', [])
            );
            Yii::$app->session->setFlash('success', '商家支付配置已保存，检测结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '商家支付配置保存失败：' . $e->getMessage());
        }

        return $this->redirect(['merchant-payment', 'store_id' => $storeId, 'environment' => $environment]);
    }

    public function actionCheckMerchantPayment()
    {
        $request = Yii::$app->request;
        $storeId = $this->merchantPaymentStoreId((int)$request->post('store_id', 0));
        $provider = (string)$request->post('provider', '');
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new MerchantPaymentConfigService())->checkProvider($storeId, $provider, $environment, true);
            Yii::$app->session->setFlash('success', '商家支付配置检测完成：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '商家支付配置检测失败：' . $e->getMessage());
        }

        return $this->redirect(['merchant-payment', 'store_id' => $storeId, 'environment' => $environment]);
    }

    public function actionSaveTranslation()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        try {
            $result = (new CustomerServiceTranslationService())->saveProvider(
                $provider,
                (array)$request->post('config', [])
            );
            Yii::$app->session->setFlash('success', '客服翻译配置已保存，检测结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '客服翻译配置保存失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => (string)$request->post('environment', 'test')]);
    }

    public function actionCheckTranslation()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        try {
            $result = (new CustomerServiceTranslationService())->checkProvider($provider, true);
            Yii::$app->session->setFlash('success', '客服翻译配置检测完成：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '客服翻译配置检测失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => (string)$request->post('environment', 'test')]);
    }

    public function actionTestTranslation()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        try {
            $result = (new CustomerServiceTranslationService())->testProvider(
                $provider,
                (string)$request->post('test_text', 'Hello'),
                (string)$request->post('source_language', 'en'),
                (string)$request->post('target_language', 'mn')
            );
            Yii::$app->session->setFlash(($result['result'] ?? '') === 'PASS' ? 'success' : 'error', '客服翻译测试结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '客服翻译测试失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => (string)$request->post('environment', 'test')]);
    }

    public function actionSaveMail()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        try {
            $result = (new OperationalMailConfigService())->save((array)Yii::$app->request->post('mail', []));
            Yii::$app->session->setFlash('success', '邮件配置已保存，检测结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '邮件配置保存失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => (string)Yii::$app->request->post('environment', 'test')]);
    }

    public function actionTestMail()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        try {
            $result = (new OperationalMailConfigService())->sendTest((string)Yii::$app->request->post('test_to', ''));
            Yii::$app->session->setFlash($result['result'] === 'PASS' ? 'success' : 'error', '邮件测试结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '邮件测试失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => (string)Yii::$app->request->post('environment', 'test')]);
    }

    public function actionSaveAlert()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        try {
            $result = (new OperationalOpsAlertService())->saveAlertConfig((array)Yii::$app->request->post('alert', []));
            Yii::$app->session->setFlash('success', '告警配置已保存，检测结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '告警配置保存失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => (string)Yii::$app->request->post('environment', 'test')]);
    }

    public function actionTestAlert()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        try {
            $result = (new OperationalOpsAlertService())->sendTestAlert();
            Yii::$app->session->setFlash($result['result'] === 'PASS' ? 'success' : 'error', '告警测试结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '告警测试失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => (string)Yii::$app->request->post('environment', 'test')]);
    }

    public function actionSaveLaunch()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        try {
            $result = (new OperationalLaunchSignoffService())->save((array)Yii::$app->request->post('launch', []));
            Yii::$app->session->setFlash($result['result'] === 'PASS' ? 'success' : 'error', '上线签核 readiness：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '上线签核保存失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => (string)Yii::$app->request->post('environment', 'test')]);
    }

    public function actionSaveProviderEvidence()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new OperationalProviderEvidenceService())->saveProvider(
                $provider,
                $environment,
                (array)$request->post('evidence', [])
            );
            Yii::$app->session->setFlash('success', '服务商证据已保存，检测结果：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '服务商证据保存失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => $environment]);
    }

    public function actionCheckProviderEvidence()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $request = Yii::$app->request;
        $provider = (string)$request->post('provider', '');
        $environment = (string)$request->post('environment', 'test');
        try {
            $result = (new OperationalProviderEvidenceService())->checkProvider($provider, $environment, true);
            Yii::$app->session->setFlash('success', '服务商证据检测完成：' . $result['result'] . ' - ' . $result['message']);
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', '服务商证据检测失败：' . $e->getMessage());
        }

        return $this->redirect(['index', 'environment' => $environment]);
    }

    private function merchantPaymentStoreId(int $requestedStoreId = 0): int
    {
        if ($this->isMallPlatformOperator()) {
            return $requestedStoreId > 0 ? $requestedStoreId : (int)Yii::$app->request->get('store_id', 0);
        }

        $ownStoreId = (int)$this->getStoreId();
        $targetStoreId = $requestedStoreId > 0 ? $requestedStoreId : (int)Yii::$app->request->get('store_id', $ownStoreId);
        if ($targetStoreId > 0 && $targetStoreId !== $ownStoreId) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return $ownStoreId;
    }
}
