<?php

namespace console\controllers;

use common\models\mall\OperationalConfig;
use common\services\mall\OperationalConfigService;
use common\services\mall\OperationalPaymentConfigService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigPaymentTestController extends Controller
{
    public $fixture = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['fixture']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia operational payment config check\n");

        $this->checkBackendMarkers();
        $this->checkServiceDefinitions();
        $this->checkProviderDefinitions();

        if ($this->fixture && $this->failures === 0) {
            $this->runFixture();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkBackendMarkers()
    {
        $this->section('Backend files');
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OperationalConfigController.php', [
            'actionSavePayment',
            'actionCheckPayment',
            'OperationalPaymentConfigService',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/operational-config/index.php', [
            'data-mongoyia-operational-payment-config',
            '支付配置中心',
            '保存并检测',
            '仅检测',
            '回调/返回地址',
        ]);
        $this->requireFileContains('@app/../common/services/mall/OperationalPaymentConfigService.php', [
            'MONGOYIA_OPERATIONAL_PAYMENT_CONFIG_CENTER_V1',
            'qpay',
            'lianlian',
            'paypal',
            'callback_hmac_secret',
            'webhook_hmac_secret',
            '正式支付启用前必须通过必填配置检测',
            'runtimeConfig',
        ]);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/PaymentController.php', [
            'OperationalPaymentConfigService',
            'paymentProviderConfig',
            'qpayRuntimeDefaults',
            'lianlianRuntimeDefaults',
            'MONGOYIA_PAYMENT_RUNTIME_NO_SECRET_ENV_FALLBACK_V1',
            'paymentProviderReadyForRuntime',
            'buildOperationalCallbackUrl',
            'assertCallbackSignatureValue',
            "PayConstant::isConfigured(\$lianlianConfig)",
        ]);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/PayConstant.php', [
            'loadFromArray',
            'isConfigured(array $config = null)',
        ]);
    }

    private function checkServiceDefinitions()
    {
        $this->section('Service definitions');
        $service = new OperationalPaymentConfigService(new OperationalConfigService('codex-payment-test-master-key'));
        $providers = $service->providerDefinitions();
        foreach (['qpay', 'lianlian', 'paypal'] as $provider) {
            if (!isset($providers[$provider])) {
                $this->fail("Missing provider definition: {$provider}");
                continue;
            }
            foreach (['enabled', 'mode'] as $code) {
                if (empty($providers[$provider]['fields'][$code])) {
                    $this->fail("Provider {$provider} missing field {$code}.");
                    continue 2;
                }
            }
            $this->ok("Provider definition exists: {$provider}");
        }
    }

    private function checkProviderDefinitions()
    {
        $this->section('Provider field coverage');
        $service = new OperationalPaymentConfigService(new OperationalConfigService('codex-payment-test-master-key'));
        $providers = $service->providerDefinitions();
        $required = [
            'qpay' => ['auth_basic', 'invoice_code', 'auth_url', 'invoice_url', 'callback_hmac_secret'],
            'lianlian' => ['merchant_id', 'public_key', 'private_key', 'callback_hmac_secret'],
            'paypal' => ['client_id', 'client_secret', 'webhook_id', 'return_path', 'cancel_path', 'webhook_path', 'webhook_hmac_secret'],
        ];
        foreach ($required as $provider => $codes) {
            foreach ($codes as $code) {
                $field = $providers[$provider]['fields'][$code] ?? null;
                if (!$field || empty($field['required_for_enable'])) {
                    $this->fail("Provider {$provider} field {$code} must be required for enablement.");
                    continue 2;
                }
            }
            $this->ok("Provider {$provider} required fields are covered.");
        }
    }

    private function runFixture()
    {
        $this->section('Fixture save/check/redaction');
        try {
            $table = Yii::$app->db->schema->getTableSchema('{{%mall_operational_config}}', true);
        } catch (\Throwable $e) {
            $this->fail('Database unavailable for payment config fixture: ' . $e->getMessage());
            return;
        }
        if ($table === null) {
            $this->fail('Missing mall_operational_config table. Run migration m260621_010000_mongoyia_operational_config_foundation.');
            return;
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            $secret = 'Basic codex-payment-secret-' . time();
            $service = new OperationalPaymentConfigService(new OperationalConfigService('codex-payment-test-master-key'));
            $result = $service->saveProvider('qpay', 'test', [
                'enabled' => '1',
                'auth_basic' => $secret,
                'invoice_code' => 'CODX-INVOICE',
                'auth_url' => 'https://merchant.qpay.mn/v2/auth/token',
                'invoice_url' => 'https://merchant.qpay.mn/v2/invoice',
                'callback_base' => 'https://demo2026.mongoyia.com',
                'callback_hmac_secret' => 'codex-hmac-secret',
                'callback_max_age_seconds' => '300',
            ]);

            if (($result['result'] ?? '') !== 'PASS') {
                $this->fail('QPay fixture should pass required-field detection: ' . ($result['message'] ?? ''));
            } else {
                $this->ok('QPay fixture passes required-field detection.');
            }

            $authBasic = OperationalConfig::find()->where([
                'category' => 'payment',
                'provider' => 'qpay',
                'code' => 'auth_basic',
                'environment' => 'test',
            ])->one();
            if (!$authBasic || (string)$authBasic->value_plain !== '') {
                $this->fail('QPay Basic Auth fixture was not stored as encrypted sensitive config.');
            } elseif (strpos((string)$authBasic->value_ciphertext, $secret) !== false) {
                $this->fail('QPay Basic Auth ciphertext leaked the raw secret.');
            } else {
                $this->ok('QPay Basic Auth fixture is encrypted and not stored in plaintext.');
            }

            $rows = (new OperationalConfigService('codex-payment-test-master-key'))->redactedRows([
                'category' => 'payment',
                'provider' => 'qpay',
                'environment' => 'test',
            ]);
            $serialized = json_encode($rows);
            if ($serialized === false || strpos($serialized, $secret) !== false) {
                $this->fail('Redacted payment config output leaked the raw secret.');
            } else {
                $this->ok('Redacted payment config output hides sensitive values.');
            }

            $tx->rollBack();
            $this->ok('Operational payment fixture rows rolled back.');
        } catch (\Throwable $e) {
            $tx->rollBack();
            $this->fail('Operational payment fixture failed: ' . $e->getMessage());
        }
    }

    private function requireFileContains(string $alias, array $needles)
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function section(string $name)
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
