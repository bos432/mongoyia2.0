<?php

namespace common\services\mall;

use common\models\mall\OperationalConfig;
use Yii;
use yii\helpers\ArrayHelper;

class OperationalPaymentConfigService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_PAYMENT_CONFIG_CENTER_V1';

    private $configService;

    public function __construct(?OperationalConfigService $configService = null)
    {
        $this->configService = $configService ?: new OperationalConfigService();
    }

    public function environments(): array
    {
        return [
            'test' => '测试模式',
            'live' => '正式模式',
        ];
    }

    public function providerDefinitions(): array
    {
        return [
            'qpay' => [
                'label' => 'QPay',
                'description' => '蒙古本地 QPay 支付，保留现有回调审计、金额校验、重复回调保护和 HMAC 校验。',
                'routes' => [
                    'create' => '/mall/payment/qpay',
                    'callback' => '/mall/payment/qpayres',
                ],
                'fields' => [
                    'enabled' => $this->field('启用 QPay', false, true, 'switch'),
                    'mode' => $this->field('模式', false, true, 'mode'),
                    'auth_basic' => $this->field('Basic Auth', true, true),
                    'invoice_code' => $this->field('Invoice Code', false, true),
                    'auth_url' => $this->field('Auth URL', false, true, 'url', 'https://merchant.qpay.mn/v2/auth/token'),
                    'invoice_url' => $this->field('Invoice URL', false, true, 'url', 'https://merchant.qpay.mn/v2/invoice'),
                    'callback_base' => $this->field('回调域名', false, true, 'url'),
                    'callback_secret' => $this->field('回调 URL Secret', true, false),
                    'callback_hmac_secret' => $this->field('回调 HMAC Secret', true, true),
                    'callback_allowed_ips' => $this->field('回调白名单 IP', false, false),
                    'callback_max_age_seconds' => $this->field('回调时间窗秒数', false, true, 'number', '300'),
                ],
            ],
            'lianlian' => [
                'label' => 'LianLian',
                'description' => '连连国际支付，保留现有 SDK 创建订单、查询、回调审计、金额校验和 HMAC 校验。',
                'routes' => [
                    'create' => '/mall/payment/lianlian',
                    'return_callback' => '/mall/payment/succeeded',
                ],
                'fields' => [
                    'enabled' => $this->field('启用 LianLian', false, true, 'switch'),
                    'mode' => $this->field('模式', false, true, 'mode'),
                    'merchant_id' => $this->field('Merchant ID', false, true),
                    'sub_merchant_id' => $this->field('Sub Merchant ID', false, false),
                    'public_key' => $this->field('平台公钥', true, true, 'textarea'),
                    'private_key' => $this->field('商户私钥', true, true, 'textarea'),
                    'callback_base' => $this->field('回调域名', false, true, 'url'),
                    'callback_secret' => $this->field('回调 URL Secret', true, false),
                    'callback_hmac_secret' => $this->field('回调 HMAC Secret', true, true),
                    'callback_allowed_ips' => $this->field('回调白名单 IP', false, false),
                    'callback_max_age_seconds' => $this->field('回调时间窗秒数', false, true, 'number', '300'),
                ],
            ],
            'paypal' => [
                'label' => 'PayPal',
                'description' => 'PayPal Orders/Webhook API 配置；本阶段先完成后台配置、检测和启停保护，后续接入完整运行时。',
                'routes' => [
                    'create' => '/mall/payment/paypal',
                    'return' => '/mall/payment/paypal-return',
                    'cancel' => '/mall/payment/paypal-cancel',
                    'webhook' => '/mall/payment/paypal-webhook',
                ],
                'fields' => [
                    'enabled' => $this->field('启用 PayPal', false, true, 'switch'),
                    'mode' => $this->field('模式', false, true, 'mode'),
                    'client_id' => $this->field('Client ID', false, true),
                    'client_secret' => $this->field('Client Secret', true, true),
                    'webhook_id' => $this->field('Webhook ID', false, true),
                    'callback_base' => $this->field('回调域名', false, true, 'url'),
                    'return_path' => $this->field('Return Path', false, true, 'text', '/mall/payment/paypal-return'),
                    'cancel_path' => $this->field('Cancel Path', false, true, 'text', '/mall/payment/paypal-cancel'),
                    'webhook_path' => $this->field('Webhook Path', false, true, 'text', '/mall/payment/paypal-webhook'),
                    'webhook_hmac_secret' => $this->field('Webhook HMAC Secret', true, true),
                    'currency' => $this->field('币种', false, true, 'text', 'USD'),
                ],
            ],
        ];
    }

    public function snapshot(string $environment = 'test', string $baseUrl = '', int $storeId = 0): array
    {
        $storeId = max(0, $storeId);
        $providers = [];
        $rows = $this->configService->redactedRows([
            'store_id' => $storeId,
            'category' => 'payment',
            'environment' => $environment,
        ]);
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[$row['provider']][$row['code']] = $row;
        }

        foreach ($this->providerDefinitions() as $provider => $definition) {
            $fields = [];
            foreach ($definition['fields'] as $code => $field) {
                $row = $rowMap[$provider][$code] ?? null;
                $fields[$code] = array_merge($field, [
                    'code' => $code,
                    'configured' => $row ? (int)$row['configured'] === 1 : false,
                    'redacted_value' => $row['redacted_value'] ?? 'NOT CONFIGURED',
                    'value' => $this->formValue($provider, $code, $field, $environment, $storeId),
                    'last_check_status' => $row['last_check_status'] ?? 'PENDING',
                    'last_check_message' => $row['last_check_message'] ?? '',
                ]);
            }

            $providers[$provider] = array_merge($definition, [
                'provider' => $provider,
                'fields' => $fields,
                'callback_urls' => $this->callbackUrls($provider, $environment, $baseUrl),
                'latest_check' => $this->latestProviderCheck($provider, $storeId),
            ]);
        }

        return [
            'version' => self::VERSION,
            'store_id' => $storeId,
            'environment' => $environment,
            'providers' => $providers,
        ];
    }

    public function saveProvider(string $provider, string $environment, array $input, int $storeId = 0): array
    {
        $storeId = max(0, $storeId);
        $definition = $this->requireProvider($provider);
        $environment = $this->normalizeEnvironment($environment);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $input['enabled'] = (string)$enabled;
        $input['mode'] = $environment;

        if ($enabled === 1 && $environment === 'live') {
            $preview = $this->validateValues($provider, $environment, $this->candidateValues($provider, $environment, $definition, $input, $storeId));
            if ($preview['result'] !== 'PASS') {
                throw new \InvalidArgumentException('正式支付启用前必须通过必填配置检测：' . $preview['message']);
            }
        }

        foreach ($definition['fields'] as $code => $field) {
            $value = array_key_exists($code, $input) ? trim((string)$input[$code]) : '';
            if (($field['type'] ?? '') === 'mode') {
                $value = $environment;
            }
            if (!empty($field['sensitive']) && $value === '' && $this->isConfigured($provider, $code, $environment)) {
                continue;
            }

            $this->configService->save([
                'store_id' => $storeId,
                'category' => 'payment',
                'provider' => $provider,
                'code' => $code,
                'label' => $field['label'],
                'environment' => $environment,
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

        return $this->checkProvider($provider, $environment, true, $storeId);
    }

    private function candidateValues(string $provider, string $environment, array $definition, array $input, int $storeId = 0): array
    {
        $candidate = $input;
        foreach ($definition['fields'] as $code => $field) {
            if (($field['type'] ?? '') === 'mode') {
                $candidate[$code] = $environment;
                continue;
            }
            $value = array_key_exists($code, $candidate) ? trim((string)$candidate[$code]) : '';
            if (!empty($field['sensitive']) && $value === '' && $this->isConfigured($provider, $code, $environment, $storeId)) {
                $candidate[$code] = $this->storedValue($provider, $code, $field, $environment, $storeId);
            }
        }

        return $candidate;
    }

    public function checkProvider(string $provider, string $environment = 'test', bool $persist = true, int $storeId = 0): array
    {
        $storeId = max(0, $storeId);
        $environment = $this->normalizeEnvironment($environment);
        $definition = $this->requireProvider($provider);
        $values = [];
        foreach ($definition['fields'] as $code => $field) {
            $values[$code] = $this->storedValue($provider, $code, $field, $environment, $storeId);
        }

        $result = $this->validateValues($provider, $environment, $values);
        if ($persist) {
            $this->configService->recordCheck([
                'store_id' => $storeId,
                'category' => 'payment',
                'provider' => $provider,
                'check_key' => 'readiness',
                'result' => $result['result'],
                'message' => $result['message'],
                'details' => $result['details'],
            ]);
        }

        return $result;
    }

    public function runtimeConfig(string $provider, array $fallbacks = [], string $preferredEnvironment = '', int $storeId = 0): array
    {
        $storeId = max(0, $storeId);
        $definition = $this->requireProvider($provider);
        $environment = $this->runtimeEnvironment($provider, $preferredEnvironment, $storeId);
        $config = [
            'provider' => $provider,
            'store_id' => $storeId,
            'environment' => $environment,
        ];

        foreach ($definition['fields'] as $code => $field) {
            $value = $this->storedValue($provider, $code, $field, $environment, $storeId);
            if ($value === '' && $storeId > 0) {
                $value = $this->storedValue($provider, $code, $field, $environment, 0);
            }
            if ($value === '' && array_key_exists($code, $fallbacks)) {
                $value = (string)$fallbacks[$code];
            }
            if (($field['type'] ?? '') === 'mode' && $value === '') {
                $value = $environment;
            }
            $config[$code] = $value;
        }

        if (($config['enabled'] ?? '') === '' && array_key_exists('enabled', $fallbacks)) {
            $config['enabled'] = (string)$fallbacks['enabled'];
        }

        return $config;
    }

    public function callbackUrls(string $provider, string $environment = 'test', string $baseUrl = ''): array
    {
        $definition = $this->requireProvider($provider);
        $baseUrl = rtrim($baseUrl !== '' ? $baseUrl : $this->requestBaseUrl(), '/');
        $urls = [];
        foreach ($definition['routes'] as $key => $path) {
            $urls[$key] = $baseUrl === '' ? $path : $baseUrl . $path;
        }

        return $urls;
    }

    private function validateValues(string $provider, string $environment, array $values): array
    {
        $definition = $this->requireProvider($provider);
        $enabled = !empty($values['enabled']) && !in_array((string)$values['enabled'], ['0', 'false', 'off'], true);
        $missing = [];
        foreach ($definition['fields'] as $code => $field) {
            if (empty($field['required_for_enable'])) {
                continue;
            }
            $value = trim((string)($values[$code] ?? ''));
            if ($value === '') {
                $missing[] = $field['label'];
            }
        }

        $details = [
            'provider' => $provider,
            'environment' => $environment,
            'enabled' => $enabled ? 1 : 0,
            'missing' => $missing,
            'callback_urls' => $this->callbackUrls($provider, $environment),
            'network_check' => $provider === 'paypal' ? 'reserved_for_paypal_runtime_stage' : 'not_required_for_local_config_check',
        ];

        if ($missing) {
            return [
                'result' => $enabled ? 'FAIL' : 'WARN',
                'message' => ($enabled ? '已启用但缺少：' : '未启用，缺少：') . implode('、', $missing),
                'details' => $details,
            ];
        }
        if (!$enabled) {
            return [
                'result' => 'WARN',
                'message' => $definition['label'] . ' 配置完整但当前未启用',
                'details' => $details,
            ];
        }
        if ($provider === 'paypal') {
            return [
                'result' => 'WARN',
                'message' => 'PayPal 资料完整；API 连通和 Webhook 验签将在 PayPal 运行时阶段完成',
                'details' => $details,
            ];
        }

        return [
            'result' => 'PASS',
            'message' => $definition['label'] . ' 必填支付配置已具备，可进入支付回归测试',
            'details' => $details,
        ];
    }

    private function storedValue(string $provider, string $code, array $field, string $environment, int $storeId = 0): string
    {
        try {
            return (string)$this->configService->getValue('payment', $provider, $code, $environment, max(0, $storeId), (string)($field['default'] ?? ''));
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.operational_payment_config.read_failed');
            return '';
        }
    }

    private function formValue(string $provider, string $code, array $field, string $environment, int $storeId = 0): string
    {
        if (!empty($field['sensitive'])) {
            return '';
        }

        return $this->storedValue($provider, $code, $field, $environment, $storeId);
    }

    private function isConfigured(string $provider, string $code, string $environment, int $storeId = 0): bool
    {
        $model = OperationalConfig::find()->where([
            'store_id' => max(0, $storeId),
            'category' => 'payment',
            'provider' => $provider,
            'code' => $code,
            'environment' => $environment,
            'status' => OperationalConfig::STATUS_ACTIVE,
        ])->one();

        if (!$model) {
            return false;
        }

        return (int)$model->is_sensitive === 1
            ? (string)$model->value_ciphertext !== ''
            : (string)$model->value_plain !== '';
    }

    private function latestProviderCheck(string $provider, int $storeId = 0): array
    {
        $checks = $this->configService->latestChecks(50);
        foreach ($checks as $check) {
            if (($check['category'] ?? '') === 'payment'
                && ($check['provider'] ?? '') === $provider
                && (int)($check['store_id'] ?? 0) === max(0, $storeId)
            ) {
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

    private function requireProvider(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $definition = ArrayHelper::getValue($this->providerDefinitions(), $provider);
        if (!$definition) {
            throw new \InvalidArgumentException('Unsupported payment provider: ' . $provider);
        }

        return $definition;
    }

    private function normalizeEnvironment(string $environment): string
    {
        return array_key_exists($environment, $this->environments()) ? $environment : 'test';
    }

    private function requestBaseUrl(): string
    {
        if (Yii::$app->has('request') && !Yii::$app->request->isConsoleRequest) {
            return Yii::$app->request->hostInfo;
        }

        return '';
    }

    private function runtimeEnvironment(string $provider, string $preferredEnvironment, int $storeId = 0): string
    {
        if ($preferredEnvironment !== '') {
            return $this->normalizeEnvironment($preferredEnvironment);
        }

        foreach (['live', 'test'] as $environment) {
            try {
                $enabled = $this->configService->getValue('payment', $provider, 'enabled', $environment, max(0, $storeId), '');
                if ((string)$enabled === '' && $storeId > 0) {
                    $enabled = $this->configService->getValue('payment', $provider, 'enabled', $environment, 0, '');
                }
            } catch (\Throwable $e) {
                Yii::warning($e->getMessage(), 'mall.operational_payment_config.environment_failed');
                return 'test';
            }
            if ((string)$enabled === '1') {
                return $environment;
            }
        }

        return 'test';
    }
}
