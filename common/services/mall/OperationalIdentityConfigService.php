<?php

namespace common\services\mall;

use common\models\mall\OperationalConfig;
use Yii;
use yii\helpers\ArrayHelper;

class OperationalIdentityConfigService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_IDENTITY_CONFIG_V1';

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
            'google' => [
                'label' => 'Google',
                'description' => 'Google OAuth 登录配置。Client Secret 加密保存，回调地址需在 Google Cloud Console 中登记。',
                'routes' => [
                    'redirect' => '/social-auth/redirect',
                    'callback' => '/social-auth/callback',
                    'bind' => '/social-auth/bind',
                    'unbind' => '/social-auth/unbind',
                ],
                'fields' => [
                    'enabled' => $this->field('启用 Google 登录', false, true, 'switch'),
                    'mode' => $this->field('模式', false, true, 'mode'),
                    'client_id' => $this->field('Client ID', false, true),
                    'client_secret' => $this->field('Client Secret', true, true),
                    'auth_url' => $this->field('授权 URL', false, true, 'url', 'https://accounts.google.com/o/oauth2/v2/auth'),
                    'token_url' => $this->field('Token URL', false, true, 'url', 'https://oauth2.googleapis.com/token'),
                    'profile_url' => $this->field('UserInfo URL', false, true, 'url', 'https://openidconnect.googleapis.com/v1/userinfo'),
                    'redirect_path' => $this->field('回调 Path', false, true, 'text', '/social-auth/callback'),
                    'scopes' => $this->field('Scopes', false, true, 'text', 'openid email profile'),
                    'bind_policy' => $this->field('绑定策略', false, false, 'text', 'require_verified_email'),
                ],
            ],
            'facebook' => [
                'label' => 'Facebook',
                'description' => 'Facebook Login 配置。App Secret 加密保存，回调地址需在 Meta for Developers 中登记。',
                'routes' => [
                    'redirect' => '/social-auth/redirect',
                    'callback' => '/social-auth/callback',
                    'bind' => '/social-auth/bind',
                    'unbind' => '/social-auth/unbind',
                ],
                'fields' => [
                    'enabled' => $this->field('启用 Facebook 登录', false, true, 'switch'),
                    'mode' => $this->field('模式', false, true, 'mode'),
                    'client_id' => $this->field('App ID', false, true),
                    'client_secret' => $this->field('App Secret', true, true),
                    'auth_url' => $this->field('授权 URL', false, true, 'url', 'https://www.facebook.com/v19.0/dialog/oauth'),
                    'token_url' => $this->field('Token URL', false, true, 'url', 'https://graph.facebook.com/v19.0/oauth/access_token'),
                    'profile_url' => $this->field('Profile URL', false, true, 'url', 'https://graph.facebook.com/me'),
                    'redirect_path' => $this->field('回调 Path', false, true, 'text', '/social-auth/callback'),
                    'scopes' => $this->field('Scopes', false, true, 'text', 'email,public_profile'),
                    'bind_policy' => $this->field('绑定策略', false, false, 'text', 'require_verified_email'),
                ],
            ],
        ];
    }

    public function snapshot(string $environment = 'test', string $baseUrl = ''): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $rows = $this->configService->redactedRows([
            'store_id' => 0,
            'category' => 'identity',
            'environment' => $environment,
        ]);
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[$row['provider']][$row['code']] = $row;
        }

        $providers = [];
        foreach ($this->providerDefinitions() as $provider => $definition) {
            $fields = [];
            foreach ($definition['fields'] as $code => $field) {
                $row = $rowMap[$provider][$code] ?? null;
                $fields[$code] = array_merge($field, [
                    'code' => $code,
                    'configured' => $row ? (int)$row['configured'] === 1 : false,
                    'redacted_value' => $row['redacted_value'] ?? 'NOT CONFIGURED',
                    'value' => $this->formValue($provider, $code, $field, $environment),
                    'last_check_status' => $row['last_check_status'] ?? 'PENDING',
                    'last_check_message' => $row['last_check_message'] ?? '',
                ]);
            }

            $providers[$provider] = array_merge($definition, [
                'provider' => $provider,
                'fields' => $fields,
                'callback_urls' => $this->callbackUrls($provider, $environment, $baseUrl),
                'latest_check' => $this->latestProviderCheck($provider),
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
        $definition = $this->requireProvider($provider);
        $environment = $this->normalizeEnvironment($environment);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $input['enabled'] = (string)$enabled;
        $input['mode'] = $environment;

        foreach ($definition['fields'] as $code => $field) {
            $value = array_key_exists($code, $input) ? trim((string)$input[$code]) : '';
            if (($field['type'] ?? '') === 'mode') {
                $value = $environment;
            }
            if (!empty($field['sensitive']) && $value === '' && $this->isConfigured($provider, $code, $environment)) {
                continue;
            }

            $this->configService->save([
                'store_id' => 0,
                'category' => 'identity',
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

        return $this->checkProvider($provider, $environment, true);
    }

    public function checkProvider(string $provider, string $environment = 'test', bool $persist = true): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $definition = $this->requireProvider($provider);
        $values = [];
        foreach ($definition['fields'] as $code => $field) {
            $values[$code] = $this->storedValue($provider, $code, $field, $environment);
        }

        $result = $this->validateValues($provider, $environment, $values);
        if ($persist) {
            $this->configService->recordCheck([
                'store_id' => 0,
                'category' => 'identity',
                'provider' => $provider,
                'check_key' => 'readiness',
                'result' => $result['result'],
                'message' => $result['message'],
                'details' => $result['details'],
            ]);
        }

        return $result;
    }

    public function runtimeConfig(string $provider, string $preferredEnvironment = ''): array
    {
        $definition = $this->requireProvider($provider);
        $environment = $this->runtimeEnvironment($provider, $preferredEnvironment);
        $config = [
            'provider' => $provider,
            'environment' => $environment,
        ];
        foreach ($definition['fields'] as $code => $field) {
            $value = $this->storedValue($provider, $code, $field, $environment);
            if (($field['type'] ?? '') === 'mode' && $value === '') {
                $value = $environment;
            }
            $config[$code] = $value;
        }

        return $config;
    }

    public function callbackUrls(string $provider, string $environment = 'test', string $baseUrl = ''): array
    {
        $this->requireProvider($provider);
        $baseUrl = rtrim($baseUrl !== '' ? $baseUrl : $this->requestBaseUrl(), '/');
        $path = $this->storedValue($provider, 'redirect_path', ['default' => '/social-auth/callback'], $this->normalizeEnvironment($environment));
        $path = $path !== '' ? $path : '/social-auth/callback';
        $callback = $baseUrl === '' ? $path : $baseUrl . $path;

        return [
            'callback' => $callback . (strpos($callback, '?') === false ? '?' : '&') . 'provider=' . urlencode($provider),
            'redirect' => ($baseUrl === '' ? '/social-auth/redirect' : $baseUrl . '/social-auth/redirect') . '?provider=' . urlencode($provider),
            'bind' => ($baseUrl === '' ? '/social-auth/bind' : $baseUrl . '/social-auth/bind') . '?provider=' . urlencode($provider),
            'unbind' => ($baseUrl === '' ? '/social-auth/unbind' : $baseUrl . '/social-auth/unbind') . '?provider=' . urlencode($provider),
        ];
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
            'network_check' => 'not_called_by_readiness_command',
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

        return [
            'result' => 'PASS',
            'message' => $definition['label'] . ' 第三方登录配置已具备，可进入沙箱回调和绑定流程测试',
            'details' => $details,
        ];
    }

    private function storedValue(string $provider, string $code, array $field, string $environment): string
    {
        try {
            return (string)$this->configService->getValue('identity', $provider, $code, $environment, 0, (string)($field['default'] ?? ''));
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.operational_identity_config.read_failed');
            return '';
        }
    }

    private function formValue(string $provider, string $code, array $field, string $environment): string
    {
        if (!empty($field['sensitive'])) {
            return '';
        }

        return $this->storedValue($provider, $code, $field, $environment);
    }

    private function isConfigured(string $provider, string $code, string $environment): bool
    {
        $model = OperationalConfig::find()->where([
            'store_id' => 0,
            'category' => 'identity',
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

    private function latestProviderCheck(string $provider): array
    {
        foreach ($this->configService->latestChecks(50) as $check) {
            if (($check['category'] ?? '') === 'identity' && ($check['provider'] ?? '') === $provider) {
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
            throw new \InvalidArgumentException('Unsupported identity provider: ' . $provider);
        }

        return $definition;
    }

    private function normalizeEnvironment(string $environment): string
    {
        return array_key_exists($environment, $this->environments()) ? $environment : 'test';
    }

    private function runtimeEnvironment(string $provider, string $preferredEnvironment): string
    {
        if ($preferredEnvironment !== '') {
            return $this->normalizeEnvironment($preferredEnvironment);
        }

        foreach (['live', 'test'] as $environment) {
            try {
                $enabled = $this->configService->getValue('identity', $provider, 'enabled', $environment, 0, '');
            } catch (\Throwable $e) {
                Yii::warning($e->getMessage(), 'mall.operational_identity_config.environment_failed');
                return 'test';
            }
            if ((string)$enabled === '1') {
                return $environment;
            }
        }

        return 'test';
    }

    private function requestBaseUrl(): string
    {
        if (Yii::$app->has('request') && !Yii::$app->request->isConsoleRequest) {
            return Yii::$app->request->hostInfo;
        }

        return '';
    }
}
