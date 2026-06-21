<?php

namespace common\services\mall;

use common\components\mailer\SmtpMailer;
use Yii;

class OperationalMailConfigService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_MAIL_CONFIG_CENTER_V1';

    private $configService;

    public function __construct(?OperationalConfigService $configService = null)
    {
        $this->configService = $configService ?: new OperationalConfigService();
    }

    public function fields(): array
    {
        return [
            'enabled' => $this->field('启用邮件', false, false, 'switch'),
            'host' => $this->field('SMTP 主机', false, true),
            'port' => $this->field('SMTP 端口', false, true, 'number', '465'),
            'encryption' => $this->field('加密方式', false, true, 'select', 'ssl'),
            'username' => $this->field('SMTP 账号', false, true),
            'password' => $this->field('SMTP 密码', true, true),
            'from' => $this->field('发件人邮箱', false, true),
            'from_name' => $this->field('发件人名称', false, false),
            'test_to' => $this->field('测试收件人', false, false),
        ];
    }

    public function encryptionOptions(): array
    {
        return [
            '' => '无',
            'ssl' => 'SSL',
            'tls' => 'TLS',
        ];
    }

    public function snapshot(): array
    {
        $rows = $this->configService->redactedRows([
            'category' => 'mail',
            'provider' => 'smtp',
            'environment' => 'default',
        ]);
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[$row['code']] = $row;
        }

        $fields = [];
        foreach ($this->fields() as $code => $field) {
            $row = $rowMap[$code] ?? null;
            $fields[$code] = array_merge($field, [
                'code' => $code,
                'configured' => $row ? (int)$row['configured'] === 1 : false,
                'redacted_value' => $row['redacted_value'] ?? 'NOT CONFIGURED',
                'value' => !empty($field['sensitive']) ? '' : $this->storedValue($code, $field),
            ]);
        }

        return [
            'version' => self::VERSION,
            'fields' => $fields,
            'latest_check' => $this->latestCheck(),
            'encryption_options' => $this->encryptionOptions(),
        ];
    }

    public function save(array $input): array
    {
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $input['enabled'] = (string)$enabled;
        foreach ($this->fields() as $code => $field) {
            $value = array_key_exists($code, $input) ? trim((string)$input[$code]) : '';
            if (!empty($field['sensitive']) && $value === '' && $this->isConfigured($code)) {
                continue;
            }
            $this->configService->save([
                'store_id' => 0,
                'category' => 'mail',
                'provider' => 'smtp',
                'code' => $code,
                'label' => $field['label'],
                'environment' => 'default',
                'is_enabled' => $enabled,
                'is_sensitive' => !empty($field['sensitive']) ? 1 : 0,
                'value' => $value,
                'metadata' => [
                    'type' => $field['type'] ?? 'text',
                    'required_for_enable' => !empty($field['required_for_enable']) ? 1 : 0,
                    'version' => self::VERSION,
                ],
            ]);
        }

        return $this->check(true);
    }

    public function runtimeConfig(array $fallbacks = []): array
    {
        if (!$fallbacks) {
            $fallbacks = $this->envFallbacks();
        }
        $config = [];
        foreach ($this->fields() as $code => $field) {
            $value = $this->storedValue($code, $field);
            if ($value === '' && array_key_exists($code, $fallbacks)) {
                $value = (string)$fallbacks[$code];
            }
            $config[$code] = $value;
        }

        return [
            'enabled' => in_array(strtolower((string)($config['enabled'] ?? '0')), ['1', 'true', 'yes', 'on'], true),
            'host' => (string)($config['host'] ?? ''),
            'port' => (string)($config['port'] ?? ''),
            'encryption' => (string)($config['encryption'] ?? ''),
            'username' => (string)($config['username'] ?? ''),
            'password' => (string)($config['password'] ?? ''),
            'from' => (string)($config['from'] ?? ''),
            'from_name' => (string)($config['from_name'] ?? ''),
            'test_to' => (string)($config['test_to'] ?? ''),
        ];
    }

    public function check(bool $persist = true): array
    {
        $config = $this->runtimeConfig();
        $missing = [];
        foreach ($this->fields() as $code => $field) {
            if (!empty($field['required_for_enable']) && trim((string)($config[$code] ?? '')) === '') {
                $missing[] = $field['label'];
            }
        }
        $enabled = !empty($config['enabled']);
        if ($missing) {
            $result = [
                'result' => $enabled ? 'FAIL' : 'WARN',
                'message' => ($enabled ? '已启用但缺少：' : '未启用，缺少：') . implode('、', $missing),
                'details' => ['missing' => $missing, 'enabled' => $enabled ? 1 : 0],
            ];
        } elseif (!$enabled) {
            $result = [
                'result' => 'WARN',
                'message' => 'SMTP 配置完整但当前未启用',
                'details' => ['enabled' => 0],
            ];
        } else {
            $result = [
                'result' => 'PASS',
                'message' => 'SMTP 必填配置已具备，可发送测试邮件',
                'details' => ['enabled' => 1],
            ];
        }

        if ($persist) {
            $this->recordCheck($result);
        }

        return $result;
    }

    public function sendTest(string $to = ''): array
    {
        $config = $this->runtimeConfig();
        $to = trim($to !== '' ? $to : (string)($config['test_to'] ?? ''));
        $check = $this->check(false);
        if (($check['result'] ?? '') !== 'PASS') {
            $this->recordCheck($check);
            return $check;
        }
        if ($to === '') {
            $result = [
                'result' => 'FAIL',
                'message' => '测试收件人不能为空',
                'details' => ['test_to' => 'missing'],
            ];
            $this->recordCheck($result);
            return $result;
        }

        $from = (string)$config['from'];
        if ((string)$config['from_name'] !== '') {
            $from = [$from => (string)$config['from_name']];
        }
        $sent = (new SmtpMailer($config))->send($to, 'Mongoyia SMTP test', 'Mongoyia SMTP test mail sent at ' . date('Y-m-d H:i:s'), [], $from);
        $result = [
            'result' => $sent === true ? 'PASS' : 'FAIL',
            'message' => $sent === true ? '测试邮件发送成功' : '测试邮件发送失败',
            'details' => ['test_to' => $to],
        ];
        if ($sent instanceof \Throwable) {
            $result['message'] = '测试邮件发送失败：' . $sent->getMessage();
        }
        $this->recordCheck($result);

        return $result;
    }

    private function recordCheck(array $result): void
    {
        $this->configService->recordCheck([
            'category' => 'mail',
            'provider' => 'smtp',
            'check_key' => 'smtp_readiness',
            'result' => $result['result'] ?? 'WARN',
            'message' => $result['message'] ?? '',
            'details' => $result['details'] ?? [],
        ]);
    }

    private function envFallbacks(): array
    {
        $host = Yii::$app->params['smtp_host'] ?? env('SMTP_HOST', env('MAIL_HOST', ''));
        $username = Yii::$app->params['smtp_username'] ?? env('SMTP_USERNAME', env('MAIL_USERNAME', ''));
        return [
            'enabled' => $host && $username ? '1' : '0',
            'host' => $host,
            'port' => Yii::$app->params['smtp_port'] ?? env('SMTP_PORT', env('MAIL_PORT', '465')),
            'encryption' => Yii::$app->params['smtp_encryption'] ?? env('SMTP_ENCRYPTION', env('MAIL_ENCRYPTION', 'ssl')),
            'username' => $username,
            'password' => Yii::$app->params['smtp_password'] ?? env('SMTP_PASSWORD', env('MAIL_PASSWORD', '')),
            'from' => Yii::$app->params['smtp_from'] ?? env('SMTP_FROM', env('MAIL_FROM_ADDRESS', $username)),
            'from_name' => Yii::$app->params['senderName'] ?? env('MAIL_FROM_NAME', ''),
            'test_to' => '',
        ];
    }

    private function storedValue(string $code, array $field): string
    {
        try {
            return (string)$this->configService->getValue('mail', 'smtp', $code, 'default', 0, (string)($field['default'] ?? ''));
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.operational_mail_config.read_failed');
            return '';
        }
    }

    private function isConfigured(string $code): bool
    {
        foreach ($this->configService->redactedRows(['category' => 'mail', 'provider' => 'smtp', 'environment' => 'default']) as $row) {
            if (($row['code'] ?? '') === $code) {
                return (int)($row['configured'] ?? 0) === 1;
            }
        }

        return false;
    }

    private function latestCheck(): array
    {
        foreach ($this->configService->latestChecks(50) as $check) {
            if (($check['category'] ?? '') === 'mail' && ($check['provider'] ?? '') === 'smtp') {
                return $check;
            }
        }

        return [];
    }

    private function field(string $label, bool $sensitive, bool $requiredForEnable, string $type = 'text', string $default = ''): array
    {
        return [
            'label' => $label,
            'sensitive' => $sensitive,
            'required_for_enable' => $requiredForEnable,
            'type' => $type,
            'default' => $default,
        ];
    }
}
