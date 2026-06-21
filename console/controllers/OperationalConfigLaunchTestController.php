<?php

namespace console\controllers;

use common\services\mall\OperationalLaunchSignoffService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigLaunchTestController extends Controller
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
        $this->stdout("Mongoyia operational launch signoff check\n");
        $this->checkMarkers();
        $this->checkDefinitions();
        if ($this->fixture) {
            $this->warn('Fixture mode is static locally; DB-backed launch signoff save should be rerun on a database-enabled environment.');
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkMarkers()
    {
        $this->section('Launch signoff markers');
        $this->requireFileContains('@app/../common/services/mall/OperationalLaunchSignoffService.php', [
            'MONGOYIA_OPERATIONAL_LAUNCH_SIGNOFF_CENTER_V1',
            '压测报告引用',
            '备份恢复确认',
            '回滚负责人',
            'NO-GO',
            'GO，签核和证据引用已齐全',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OperationalConfigController.php', [
            'actionSaveLaunch',
            'OperationalLaunchSignoffService',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/operational-config/index.php', [
            'data-mongoyia-operational-launch-signoff',
            '上线签核和证据管理',
            '保存签核记录',
            '不保存支付密钥',
        ]);
    }

    private function checkDefinitions()
    {
        $this->section('Required signoff definitions');
        $fields = (new OperationalLaunchSignoffService())->fields();
        foreach (['load_test_report_ref', 'security_confirmed', 'business_signoff', 'payment_signoff', 'backup_restore_confirmed', 'launch_window', 'rollback_owner', 'rollback_plan_ref'] as $code) {
            if (empty($fields[$code]) || empty($fields[$code]['required'])) {
                $this->fail("Missing required launch field {$code}.");
                return;
            }
        }
        $this->ok('Launch signoff required fields are covered.');
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
