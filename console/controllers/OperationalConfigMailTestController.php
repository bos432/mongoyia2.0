<?php

namespace console\controllers;

use common\models\mall\OperationalConfig;
use common\services\mall\OperationalConfigService;
use common\services\mall\OperationalMailConfigService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigMailTestController extends Controller
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
        $this->stdout("Mongoyia operational mail config check\n");

        $this->checkMarkers();
        if ($this->fixture && $this->failures === 0) {
            $this->runFixture();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkMarkers()
    {
        $this->section('Mail config markers');
        $this->requireFileContains('@app/../common/services/mall/OperationalMailConfigService.php', [
            'MONGOYIA_OPERATIONAL_MAIL_CONFIG_CENTER_V1',
            'SMTP 主机',
            'SMTP 密码',
            'sendTest',
            'runtimeConfig',
            'smtp_readiness',
        ]);
        $this->requireFileContains('@app/../common/components/mailer/SmtpMailer.php', [
            'OperationalMailConfigService',
            'operational_config_fallback',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OperationalConfigController.php', [
            'MONGOYIA_OPERATIONAL_CONFIG_BACKEND_POST_VERB_GUARD_V1',
            'actionSaveMail',
            'actionTestMail',
            'OperationalMailConfigService',
            "'save-mail'",
            "'test-mail'",
            "['post']",
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/operational-config/index.php', [
            'data-mongoyia-operational-mail-config',
            '邮件配置中心',
            '保存邮件配置',
            '发送测试邮件',
        ]);
    }

    private function runFixture()
    {
        $this->section('Fixture encryption/check');
        try {
            $table = Yii::$app->db->schema->getTableSchema('{{%mall_operational_config}}', true);
        } catch (\Throwable $e) {
            $this->fail('Database unavailable for mail config fixture: ' . $e->getMessage());
            return;
        }
        if ($table === null) {
            $this->fail('Missing mall_operational_config table. Run migration m260621_010000_mongoyia_operational_config_foundation.');
            return;
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            $password = 'codex-mail-password-' . time();
            $service = new OperationalMailConfigService(new OperationalConfigService('codex-mail-test-master-key'));
            $result = $service->save([
                'enabled' => '1',
                'host' => 'smtp.example.com',
                'port' => '465',
                'encryption' => 'ssl',
                'username' => 'mailer@example.com',
                'password' => $password,
                'from' => 'mailer@example.com',
                'from_name' => 'Mongoyia Test',
                'test_to' => 'ops@example.com',
            ]);
            if (($result['result'] ?? '') !== 'PASS') {
                $this->fail('SMTP fixture should pass required-field detection: ' . ($result['message'] ?? ''));
            } else {
                $this->ok('SMTP fixture passes required-field detection.');
            }

            $model = OperationalConfig::find()->where([
                'category' => 'mail',
                'provider' => 'smtp',
                'code' => 'password',
                'environment' => 'default',
            ])->one();
            if (!$model || (string)$model->value_plain !== '') {
                $this->fail('SMTP password fixture was not stored as encrypted sensitive config.');
            } elseif (strpos((string)$model->value_ciphertext, $password) !== false) {
                $this->fail('SMTP password ciphertext leaked the raw password.');
            } else {
                $this->ok('SMTP password fixture is encrypted and not stored in plaintext.');
            }

            $tx->rollBack();
            $this->ok('Operational mail fixture rows rolled back.');
        } catch (\Throwable $e) {
            $tx->rollBack();
            $this->fail('Operational mail fixture failed: ' . $e->getMessage());
        }
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

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
