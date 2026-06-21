<?php

namespace common\services\mall;

class OperationalOpsAlertService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_OPS_ALERT_CENTER_V1';

    private $configService;

    public function __construct(?OperationalConfigService $configService = null)
    {
        $this->configService = $configService ?: new OperationalConfigService();
    }

    public function taskDefinitions(): array
    {
        return [
            ['key' => 'auto_receive', 'name' => '自动收货', 'command' => 'php yii mongoyia-auto-receive/run', 'frequency' => '每小时', 'advice' => '检查订单自动收货是否按计划执行'],
            ['key' => 'settlement', 'name' => '结算检查', 'command' => 'php yii mongoyia-settlement-readiness/run', 'frequency' => '每日', 'advice' => '检查商家结算草稿和异常订单'],
            ['key' => 'statistics', 'name' => '统计检查', 'command' => 'php yii merchant-stat-test/run', 'frequency' => '每日', 'advice' => '检查统计页和关键指标是否可用'],
            ['key' => 'cleanup', 'name' => '清理检查', 'command' => 'php yii mongoyia-test-cleanup/run --failOnPending=1', 'frequency' => '每日', 'advice' => '确认测试/临时数据没有残留'],
            ['key' => 'production_health', 'name' => '生产健康检查', 'command' => 'php yii deploy-check/run --profile=prod --strict=1', 'frequency' => '每小时', 'advice' => '检查 DB/Redis/IM/支付/文件系统状态'],
            ['key' => 'backup_verify', 'name' => '备份检查', 'command' => 'php yii mongoyia-production-backup-verify/run', 'frequency' => '每日', 'advice' => '确认备份和恢复证据可用'],
            ['key' => 'load_smoke', 'name' => '负载 smoke', 'command' => 'php yii mongoyia-production-load-smoke/run', 'frequency' => '发布前/每日', 'advice' => '保留正式压测前的轻量负载证据'],
        ];
    }

    public function alertFields(): array
    {
        return [
            'email_enabled' => $this->field('启用邮件告警', false, 'switch', '0'),
            'recipients' => $this->field('告警收件人', false, 'text', ''),
            'triggers' => $this->field('触发条件', false, 'textarea', 'payment_config_failed,mail_failed,task_timeout,disk_low,redis_down,im_down,backup_failed'),
            'task_timeout_minutes' => $this->field('任务超时分钟', false, 'number', '60'),
            'disk_free_threshold_percent' => $this->field('磁盘剩余阈值%', false, 'number', '15'),
            'webhook_url' => $this->field('预留 Webhook URL', true, 'text', ''),
            'webhook_secret' => $this->field('预留 Webhook Secret', true, 'text', ''),
        ];
    }

    public function snapshot(): array
    {
        $rows = $this->configService->redactedRows([
            'category' => 'alert',
            'provider' => 'email',
            'environment' => 'default',
        ]);
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[$row['code']] = $row;
        }

        $fields = [];
        foreach ($this->alertFields() as $code => $field) {
            $row = $rowMap[$code] ?? null;
            $fields[$code] = array_merge($field, [
                'code' => $code,
                'configured' => $row ? (int)$row['configured'] === 1 : false,
                'redacted_value' => $row['redacted_value'] ?? 'NOT CONFIGURED',
                'value' => !empty($field['sensitive']) ? '' : $this->value($code, $field['default']),
            ]);
        }

        return [
            'version' => self::VERSION,
            'tasks' => $this->taskStatusRows(),
            'fields' => $fields,
            'latest_checks' => $this->configService->latestChecks(20),
        ];
    }

    public function saveAlertConfig(array $input): array
    {
        $input['email_enabled'] = !empty($input['email_enabled']) ? '1' : '0';
        foreach ($this->alertFields() as $code => $field) {
            $value = array_key_exists($code, $input) ? trim((string)$input[$code]) : '';
            if (!empty($field['sensitive']) && $value === '' && $this->configured($code)) {
                continue;
            }
            $this->configService->save([
                'store_id' => 0,
                'category' => 'alert',
                'provider' => 'email',
                'code' => $code,
                'label' => $field['label'],
                'environment' => 'default',
                'is_enabled' => (int)$input['email_enabled'],
                'is_sensitive' => !empty($field['sensitive']) ? 1 : 0,
                'value' => $value,
                'metadata' => ['version' => self::VERSION, 'type' => $field['type']],
            ]);
        }

        return $this->check(true);
    }

    public function check(bool $persist = true): array
    {
        $enabled = $this->value('email_enabled', '0') === '1';
        $recipients = $this->value('recipients', '');
        $result = !$enabled ? 'WARN' : ($recipients === '' ? 'FAIL' : 'PASS');
        $message = !$enabled ? '邮件告警未启用' : ($recipients === '' ? '邮件告警已启用但收件人为空' : '邮件告警配置已具备');
        $data = [
            'result' => $result,
            'message' => $message,
            'details' => ['tasks' => array_column($this->taskDefinitions(), 'key')],
        ];
        if ($persist) {
            $this->configService->recordCheck([
                'category' => 'alert',
                'provider' => 'email',
                'check_key' => 'alert_readiness',
                'result' => $result,
                'message' => $message,
                'details' => $data['details'],
            ]);
        }

        return $data;
    }

    public function sendTestAlert(): array
    {
        $check = $this->check(false);
        if (($check['result'] ?? '') !== 'PASS') {
            $this->recordAlertResult($check);
            return $check;
        }
        $recipients = array_values(array_filter(array_map('trim', explode(',', $this->value('recipients', '')))));
        $to = $recipients[0] ?? '';
        $result = (new OperationalMailConfigService())->sendTest($to);
        $result['message'] = '测试告警：' . ($result['message'] ?? '');
        $this->recordAlertResult($result);

        return $result;
    }

    private function taskStatusRows(): array
    {
        $checks = $this->configService->latestChecks(100);
        $rows = [];
        foreach ($this->taskDefinitions() as $task) {
            $latest = [];
            foreach ($checks as $check) {
                if (($check['check_key'] ?? '') === $task['key']) {
                    $latest = $check;
                    break;
                }
            }
            $rows[] = array_merge($task, [
                'last_result' => $latest['result'] ?? 'PENDING',
                'last_message' => $latest['message'] ?? '暂无运行证据',
                'last_run_at' => (int)($latest['checked_at'] ?? 0),
            ]);
        }

        return $rows;
    }

    private function recordAlertResult(array $result): void
    {
        $this->configService->recordCheck([
            'category' => 'alert',
            'provider' => 'email',
            'check_key' => 'test_alert',
            'result' => $result['result'] ?? 'WARN',
            'message' => $result['message'] ?? '',
            'details' => $result['details'] ?? [],
        ]);
    }

    private function value(string $code, string $default): string
    {
        try {
            return (string)$this->configService->getValue('alert', 'email', $code, 'default', 0, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function configured(string $code): bool
    {
        foreach ($this->configService->redactedRows(['category' => 'alert', 'provider' => 'email', 'environment' => 'default']) as $row) {
            if (($row['code'] ?? '') === $code) {
                return (int)($row['configured'] ?? 0) === 1;
            }
        }

        return false;
    }

    private function field(string $label, bool $sensitive, string $type, string $default): array
    {
        return ['label' => $label, 'sensitive' => $sensitive, 'type' => $type, 'default' => $default];
    }
}
