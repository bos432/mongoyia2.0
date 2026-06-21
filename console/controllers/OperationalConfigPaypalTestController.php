<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigPaypalTestController extends Controller
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
        $this->stdout("Mongoyia operational PayPal runtime check\n");

        $this->checkPaymentController();
        $this->checkOperationalConfig();
        $this->checkUiBoundary();
        if ($this->fixture) {
            $this->warn('Fixture mode is static only until PayPal sandbox credentials are available in backend config.');
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkPaymentController()
    {
        $this->section('PayPal runtime controller');
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/PaymentController.php', [
            'public function actionPaypal',
            'public function actionPaypalReturn',
            'public function actionPaypalCancel',
            'public function actionPaypalWebhook',
            'paypalRequest',
            'paypalAccessToken',
            'verifyPaypalWebhook',
            'verify-webhook-signature',
            'paypalCaptureAmount',
            'paypalOrderIdFromPayload',
            'PaymentAttempt::RESULT_IGNORED',
            'PayPal webhook processed',
        ]);
    }

    private function checkOperationalConfig()
    {
        $this->section('Backend config source');
        $this->requireFileContains('@app/../common/services/mall/OperationalPaymentConfigService.php', [
            'paypal',
            'client_id',
            'client_secret',
            'webhook_id',
            'return_path',
            'cancel_path',
            'webhook_path',
            'webhook_hmac_secret',
            'runtimeConfig',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/operational-config/index.php', [
            "payment['providers']",
            "provider['fields']",
            '回调/返回地址',
            '保存并检测',
        ]);
    }

    private function checkUiBoundary()
    {
        $this->section('Frontend UI boundary');
        $path = Yii::getAlias('@app/../web/resources/mall/default/views/payment/index.php');
        if (!is_file($path)) {
            $this->fail("Missing payment view {$path}.");
            return;
        }
        $content = (string)file_get_contents($path);
        foreach (['/mall/payment/paypal', 'Pay with PayPal', 'PAYPAL_CLIENT_ID'] as $marker) {
            if (strpos($content, $marker) !== false) {
                $this->fail("PayPal UI marker {$marker} is exposed before browser sandbox acceptance.");
                return;
            }
        }
        $this->ok('PayPal runtime routes exist while the frontend payment button remains reserved.');
    }

    private function requireFileContains(string $alias, array $needles)
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($path);
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

    private function warn(string $message)
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
