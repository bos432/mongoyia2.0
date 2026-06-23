<?php

namespace common\services\mall;

use Yii;

class OperationalAccountSecurityService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_ACCOUNT_SECURITY_V1';
    public const CATEGORY = 'account_security';
    public const PROVIDER = 'policy';
    public const ENVIRONMENT = 'default';

    private $configService;

    public function __construct(?OperationalConfigService $configService = null)
    {
        $this->configService = $configService ?: new OperationalConfigService();
    }

    public function fieldDefinitions(): array
    {
        return [
            'email_reset_enabled' => $this->field('启用邮箱找回密码', false, 'switch', '1'),
            'mobile_reset_enabled' => $this->field('启用手机找回密码', false, 'switch', '0'),
            'email_code_login_enabled' => $this->field('启用邮箱安全码登录', false, 'switch', '0'),
            'mobile_code_login_enabled' => $this->field('启用手机安全码登录', false, 'switch', '0'),
            'code_length' => $this->field('安全码长度', true, 'number', '6'),
            'code_ttl_seconds' => $this->field('安全码有效秒数', true, 'number', '600'),
            'max_attempts' => $this->field('最多尝试次数', true, 'number', '5'),
            'lock_minutes' => $this->field('失败锁定分钟', true, 'number', '15'),
            'allowed_channels' => $this->field('允许渠道', false, 'text', 'email'),
            'audit_enabled' => $this->field('启用操作日志', true, 'switch', '1'),
        ];
    }

    public function snapshot(): array
    {
        $rows = $this->configService->redactedRows([
            'store_id' => 0,
            'category' => self::CATEGORY,
            'provider' => self::PROVIDER,
            'environment' => self::ENVIRONMENT,
        ]);
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[$row['code']] = $row;
        }

        $fields = [];
        foreach ($this->fieldDefinitions() as $code => $field) {
            $row = $rowMap[$code] ?? null;
            $fields[$code] = array_merge($field, [
                'code' => $code,
                'configured' => $row ? (int)$row['configured'] === 1 : false,
                'value' => $this->storedValue($code, $field),
                'redacted_value' => $row['redacted_value'] ?? 'NOT CONFIGURED',
                'last_check_status' => $row['last_check_status'] ?? 'PENDING',
                'last_check_message' => $row['last_check_message'] ?? '',
            ]);
        }

        return [
            'version' => self::VERSION,
            'fields' => $fields,
            'latest_check' => $this->latestCheck(),
            'policy' => $this->runtimePolicy(),
        ];
    }

    public function save(array $input): array
    {
        foreach ($this->fieldDefinitions() as $code => $field) {
            $value = array_key_exists($code, $input) ? trim((string)$input[$code]) : (string)($field['default'] ?? '');
            if (($field['type'] ?? '') === 'switch') {
                $value = !empty($input[$code]) ? '1' : '0';
            }

            $this->configService->save([
                'store_id' => 0,
                'category' => self::CATEGORY,
                'provider' => self::PROVIDER,
                'code' => $code,
                'label' => $field['label'],
                'environment' => self::ENVIRONMENT,
                'is_enabled' => 1,
                'is_sensitive' => 0,
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

    public function check(bool $persist = true): array
    {
        $policy = $this->runtimePolicy();
        $issues = [];
        $warnings = [];

        $length = (int)$policy['code_length'];
        if ($length < 4 || $length > 8) {
            $issues[] = '安全码长度需在 4-8 位之间';
        }
        $ttl = (int)$policy['code_ttl_seconds'];
        if ($ttl < 60 || $ttl > 1800) {
            $issues[] = '安全码有效期需在 60-1800 秒之间';
        }
        $attempts = (int)$policy['max_attempts'];
        if ($attempts < 1 || $attempts > 10) {
            $issues[] = '最多尝试次数需在 1-10 次之间';
        }
        $lock = (int)$policy['lock_minutes'];
        if ($lock < 1 || $lock > 1440) {
            $issues[] = '失败锁定时间需在 1-1440 分钟之间';
        }

        $emailCodeEnabled = (string)$policy['email_code_login_enabled'] === '1';
        $mobileCodeEnabled = (string)$policy['mobile_code_login_enabled'] === '1';
        $emailResetEnabled = (string)$policy['email_reset_enabled'] === '1';
        $mobileResetEnabled = (string)$policy['mobile_reset_enabled'] === '1';

        if (!$emailResetEnabled && !$mobileResetEnabled) {
            $warnings[] = '邮箱和手机找回密码都未启用';
        }
        if (($emailCodeEnabled || $mobileCodeEnabled) && (string)$policy['audit_enabled'] !== '1') {
            $issues[] = '安全码登录启用时必须启用操作日志';
        }
        if ($mobileCodeEnabled || $mobileResetEnabled) {
            $warnings[] = '手机验证码需要短信服务商或 APP 推送通道证据';
        }

        $result = $issues ? 'FAIL' : ($warnings ? 'WARN' : 'PASS');
        $message = $issues ? implode('；', $issues) : ($warnings ? implode('；', $warnings) : '账号安全策略已具备基础上线条件');
        $data = [
            'result' => $result,
            'message' => $message,
            'details' => [
                'issues' => $issues,
                'warnings' => $warnings,
                'policy' => $policy,
            ],
        ];

        if ($persist) {
            $this->configService->recordCheck([
                'store_id' => 0,
                'category' => self::CATEGORY,
                'provider' => self::PROVIDER,
                'check_key' => 'readiness',
                'result' => $data['result'],
                'message' => $data['message'],
                'details' => $data['details'],
            ]);
        }

        return $data;
    }

    public function runtimePolicy(): array
    {
        $policy = [];
        foreach ($this->fieldDefinitions() as $code => $field) {
            $policy[$code] = $this->storedValue($code, $field);
        }

        return $policy;
    }

    public function codeLoginEnabled(string $channel): bool
    {
        $policy = $this->runtimePolicy();
        $channel = strtolower(trim($channel));
        if ($channel === 'mobile') {
            return (string)($policy['mobile_code_login_enabled'] ?? '0') === '1';
        }

        return (string)($policy['email_code_login_enabled'] ?? '0') === '1';
    }

    private function storedValue(string $code, array $field): string
    {
        try {
            return (string)$this->configService->getValue(
                self::CATEGORY,
                self::PROVIDER,
                $code,
                self::ENVIRONMENT,
                0,
                (string)($field['default'] ?? '')
            );
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.operational_account_security.read_failed');
            return (string)($field['default'] ?? '');
        }
    }

    private function latestCheck(): array
    {
        foreach ($this->configService->latestChecks(50) as $check) {
            if (($check['category'] ?? '') === self::CATEGORY && ($check['provider'] ?? '') === self::PROVIDER) {
                return $check;
            }
        }

        return [];
    }

    private function field(string $label, bool $requiredForEnable, string $type = 'text', string $default = ''): array
    {
        return [
            'label' => $label,
            'required_for_enable' => $requiredForEnable,
            'type' => $type,
            'default' => $default,
            'sensitive' => false,
        ];
    }
}
