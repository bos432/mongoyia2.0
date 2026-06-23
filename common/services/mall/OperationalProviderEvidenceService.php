<?php

namespace common\services\mall;

class OperationalProviderEvidenceService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_PROVIDER_EVIDENCE_V1';

    private $configService;

    public function __construct(?OperationalConfigService $configService = null)
    {
        $this->configService = $configService ?: new OperationalConfigService();
    }

    public function providerDefinitions(): array
    {
        return [
            'qpay' => $this->provider('QPay', '支付沙箱/正式资料、回调地址和 HMAC 检测证据。', ['backend_config_checked', 'callback_configured', 'test_result_ref', 'redaction_confirmed', 'reviewer']),
            'lianlian' => $this->provider('LianLian', '连连商户号、公私钥、回调地址和 HMAC 检测证据。', ['backend_config_checked', 'callback_configured', 'test_result_ref', 'redaction_confirmed', 'reviewer']),
            'paypal' => $this->provider('PayPal', 'PayPal Client、Webhook、Return/Cancel URL 和沙箱支付证据。', ['backend_config_checked', 'callback_configured', 'test_result_ref', 'redaction_confirmed', 'reviewer']),
            'smtp' => $this->provider('SMTP Mail', 'SMTP 配置、测试发送和发件人证据。', ['backend_config_checked', 'test_result_ref', 'redaction_confirmed', 'reviewer']),
            'translation' => $this->provider('Translation API', '客服翻译驱动、测试翻译和密钥脱敏证据。', ['backend_config_checked', 'test_result_ref', 'redaction_confirmed', 'reviewer']),
            'alert' => $this->provider('Alert Channel', '告警收件人、触发条件和测试告警证据。', ['backend_config_checked', 'test_result_ref', 'redaction_confirmed', 'reviewer']),
        ];
    }

    public function fields(): array
    {
        return [
            'backend_config_checked' => $this->field('后台配置已检测', 'switch', true),
            'callback_configured' => $this->field('服务商回调/返回地址已配置', 'switch', false),
            'test_result_ref' => $this->field('测试结果/沙箱证据引用', 'text', true),
            'evidence_ref' => $this->field('补充证据引用', 'text', false),
            'redaction_confirmed' => $this->field('证据已脱敏且不含密钥', 'switch', true),
            'reviewer' => $this->field('审核人/负责人', 'text', true),
            'notes' => $this->field('备注', 'textarea', false),
        ];
    }

    public function snapshot(string $environment = 'test'): array
    {
        $providers = [];
        foreach ($this->providerDefinitions() as $code => $definition) {
            $fields = [];
            foreach ($this->fields() as $fieldCode => $field) {
                if (!in_array($fieldCode, $definition['fields'], true) && !in_array($fieldCode, ['evidence_ref', 'notes'], true)) {
                    continue;
                }
                $value = $this->value($code, $fieldCode, $environment);
                $fields[$fieldCode] = array_merge($field, [
                    'code' => $fieldCode,
                    'value' => $value,
                    'configured' => $value !== '',
                ]);
            }
            $providers[] = array_merge($definition, [
                'provider' => $code,
                'fields' => $fields,
                'latest_check' => $this->checkProvider($code, $environment, false),
            ]);
        }

        return [
            'version' => self::VERSION,
            'environment' => $environment,
            'providers' => $providers,
        ];
    }

    public function saveProvider(string $provider, string $environment, array $input): array
    {
        $definition = $this->definition($provider);
        $allowed = array_unique(array_merge($definition['fields'], ['evidence_ref', 'notes']));
        foreach ($allowed as $code) {
            $this->configService->save([
                'store_id' => 0,
                'category' => 'provider_evidence',
                'provider' => $provider,
                'code' => $code,
                'label' => $this->fields()[$code]['label'] ?? $code,
                'environment' => $environment,
                'is_enabled' => 1,
                'is_sensitive' => 0,
                'value' => $this->normalizeInputValue($code, $input[$code] ?? ''),
                'metadata' => ['version' => self::VERSION, 'provider' => $provider],
            ]);
        }

        return $this->checkProvider($provider, $environment, true);
    }

    public function checkProvider(string $provider, string $environment = 'test', bool $persist = true): array
    {
        $definition = $this->definition($provider);
        $missing = [];
        foreach ($definition['required'] as $code) {
            if ($this->value($provider, $code, $environment) === '') {
                $missing[] = $code;
            }
        }

        $leaks = [];
        foreach (array_keys($this->fields()) as $code) {
            $value = $this->value($provider, $code, $environment);
            if ($value !== '' && $this->looksSensitive($value)) {
                $leaks[] = $code;
            }
        }

        if (!empty($leaks)) {
            $result = 'FAIL';
            $message = '证据引用疑似包含密钥或令牌，请改为脱敏报告/工单/截图编号：' . implode(', ', $leaks);
        } elseif (!empty($missing)) {
            $result = 'FAIL';
            $message = '缺少服务商证据字段：' . implode(', ', $missing);
        } else {
            $result = 'PASS';
            $message = $definition['label'] . ' 服务商证据已记录且未发现明文密钥。';
        }

        $check = [
            'category' => 'provider_evidence',
            'provider' => $provider,
            'check_key' => 'readiness',
            'result' => $result,
            'message' => $message,
            'details' => [
                'version' => self::VERSION,
                'environment' => $environment,
                'missing' => $missing,
                'sensitive_like_fields' => $leaks,
            ],
        ];
        if ($persist) {
            $this->configService->recordCheck($check);
        }

        return [
            'result' => $result,
            'message' => $message,
            'missing' => $missing,
            'sensitive_like_fields' => $leaks,
        ];
    }

    private function provider(string $label, string $description, array $required): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'required' => $required,
            'fields' => $required,
        ];
    }

    private function field(string $label, string $type, bool $required): array
    {
        return [
            'label' => $label,
            'type' => $type,
            'required' => $required,
        ];
    }

    private function definition(string $provider): array
    {
        $definitions = $this->providerDefinitions();
        if (!isset($definitions[$provider])) {
            throw new \InvalidArgumentException('Unsupported provider evidence target: ' . $provider);
        }

        return $definitions[$provider];
    }

    private function value(string $provider, string $code, string $environment): string
    {
        return (string)$this->configService->getValue('provider_evidence', $provider, $code, $environment, 0, '');
    }

    private function normalizeInputValue(string $code, $value): string
    {
        if (in_array($code, ['backend_config_checked', 'callback_configured', 'redaction_confirmed'], true)) {
            return (string)$value === '1' ? '1' : '';
        }

        return trim((string)$value);
    }

    private function looksSensitive(string $value): bool
    {
        return preg_match('/(PRIVATE KEY|BEGIN RSA|Basic\s+[A-Za-z0-9+\/=]{8,}|Bearer\s+[A-Za-z0-9._-]{8,}|client_secret\s*[:=]|api[_-]?key\s*[:=]|hmac[_-]?secret\s*[:=])/i', $value) === 1;
    }
}
