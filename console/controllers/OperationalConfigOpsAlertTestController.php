<?php

namespace console\controllers;

use common\services\mall\OperationalOpsAlertService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigOpsAlertTestController extends Controller
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
        $this->stdout("Mongoyia operational ops alert check\n");
        $this->checkMarkers();
        $this->checkDefinitions();
        if ($this->fixture) {
            $this->warn('Fixture mode is static only locally; DB-backed alert config save should be rerun on a database-enabled environment.');
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkMarkers()
    {
        $this->section('Ops alert markers');
        $this->requireFileContains('@app/../common/services/mall/OperationalOpsAlertService.php', [
            'MONGOYIA_OPERATIONAL_OPS_ALERT_CENTER_V1',
            'taskDefinitions',
            'sendTestAlert',
            'alert_readiness',
            'test_alert',
            'backup_failed',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/OperationalConfigController.php', [
            'actionSaveAlert',
            'actionTestAlert',
            'OperationalOpsAlertService',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/operational-config/index.php', [
            'data-mongoyia-operational-ops-alert',
            '运维检查和告警中心',
            '任务检查',
            '保存告警配置',
            '发送测试告警',
            '不直接修改服务器',
        ]);
    }

    private function checkDefinitions()
    {
        $this->section('Task and alert definitions');
        $service = new OperationalOpsAlertService();
        $tasks = $service->taskDefinitions();
        if (count($tasks) < 6) {
            $this->fail('Expected core scheduled/health/backup/load task definitions.');
            return;
        }
        foreach (['auto_receive', 'settlement', 'statistics', 'cleanup', 'production_health', 'backup_verify'] as $key) {
            $found = false;
            foreach ($tasks as $task) {
                if (($task['key'] ?? '') === $key && !empty($task['command']) && !empty($task['frequency'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->fail("Missing task definition {$key}.");
                return;
            }
        }
        $fields = $service->alertFields();
        foreach (['email_enabled', 'recipients', 'triggers', 'task_timeout_minutes', 'disk_free_threshold_percent'] as $code) {
            if (empty($fields[$code])) {
                $this->fail("Missing alert field {$code}.");
                return;
            }
        }
        $this->ok('Ops task definitions and alert fields are covered.');
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
