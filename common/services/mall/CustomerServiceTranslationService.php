<?php

namespace common\services\mall;

use Yii;
use yii\helpers\Json;

class CustomerServiceTranslationService
{
    public const VERSION = 'MONGOYIA_CUSTOMER_SERVICE_TRANSLATION_V1';

    public const STATUS_NONE = 'none';
    public const STATUS_TRANSLATED = 'translated';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    private $configService;

    public function __construct(?OperationalConfigService $configService = null)
    {
        $this->configService = $configService ?: new OperationalConfigService();
    }

    public function languages(): array
    {
        return [
            'zh-CN' => '中文',
            'en' => 'English',
            'mn' => 'Монгол',
        ];
    }

    public function workLanguages(): array
    {
        return [
            'en' => 'English',
            'mn' => 'Монгол',
        ];
    }

    public function providerDefinitions(): array
    {
        return [
            'openai_compatible' => [
                'label' => 'OpenAI-compatible',
                'description' => '兼容 OpenAI Chat Completions 的翻译接口，用于客服消息自动翻译。',
                'fields' => [
                    'enabled' => $this->field('启用', false, false, 'switch'),
                    'base_url' => $this->field('接口地址', false, true, 'url', 'https://api.openai.com/v1'),
                    'api_key' => $this->field('API Key', true, true),
                    'model' => $this->field('模型', false, true, 'text', 'gpt-4o-mini'),
                    'timeout_seconds' => $this->field('超时秒数', false, true, 'number', '20'),
                    'staff_work_language' => $this->field('客服工作语言', false, true, 'select', 'en'),
                ],
            ],
            'google_compatible' => [
                'label' => 'Google-compatible',
                'description' => '兼容 Google Translate v2 的翻译接口，用于客服消息自动翻译。',
                'fields' => [
                    'enabled' => $this->field('启用', false, false, 'switch'),
                    'endpoint' => $this->field('接口地址', false, true, 'url', 'https://translation.googleapis.com/language/translate/v2'),
                    'api_key' => $this->field('API Key', true, true),
                    'timeout_seconds' => $this->field('超时秒数', false, true, 'number', '20'),
                    'staff_work_language' => $this->field('客服工作语言', false, true, 'select', 'en'),
                ],
            ],
        ];
    }

    public function snapshot(): array
    {
        $rows = $this->configService->redactedRows([
            'category' => 'translation',
            'environment' => 'default',
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
                    'value' => !empty($field['sensitive']) ? '' : $this->storedValue($provider, $code, $field),
                    'last_check_status' => $row['last_check_status'] ?? 'PENDING',
                    'last_check_message' => $row['last_check_message'] ?? '',
                ]);
            }

            $providers[$provider] = array_merge($definition, [
                'provider' => $provider,
                'fields' => $fields,
                'latest_check' => $this->latestProviderCheck($provider),
            ]);
        }

        return [
            'version' => self::VERSION,
            'providers' => $providers,
            'languages' => $this->languages(),
            'work_languages' => $this->workLanguages(),
        ];
    }

    public function saveProvider(string $provider, array $input): array
    {
        $definition = $this->requireProvider($provider);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $input['enabled'] = (string)$enabled;

        foreach ($definition['fields'] as $code => $field) {
            $value = array_key_exists($code, $input) ? trim((string)$input[$code]) : '';
            if (!empty($field['sensitive']) && $value === '' && $this->isConfigured($provider, $code)) {
                continue;
            }

            $this->configService->save([
                'store_id' => 0,
                'category' => 'translation',
                'provider' => $provider,
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

        return $this->checkProvider($provider, true);
    }

    public function checkProvider(string $provider, bool $persist = true): array
    {
        $definition = $this->requireProvider($provider);
        $values = [];
        foreach ($definition['fields'] as $code => $field) {
            $values[$code] = $this->storedValue($provider, $code, $field);
        }

        $enabled = $this->truthy($values['enabled'] ?? '');
        $missing = [];
        foreach ($definition['fields'] as $code => $field) {
            if (empty($field['required_for_enable'])) {
                continue;
            }
            if (trim((string)($values[$code] ?? '')) === '') {
                $missing[] = $field['label'];
            }
        }

        $details = [
            'provider' => $provider,
            'enabled' => $enabled ? 1 : 0,
            'missing' => $missing,
            'languages' => array_keys($this->languages()),
            'translation_status' => [
                self::STATUS_NONE,
                self::STATUS_TRANSLATED,
                self::STATUS_FAILED,
                self::STATUS_SKIPPED,
            ],
        ];

        if ($missing) {
            $result = [
                'result' => $enabled ? 'FAIL' : 'WARN',
                'message' => ($enabled ? '已启用但缺少：' : '未启用，缺少：') . implode('、', $missing),
                'details' => $details,
            ];
        } elseif (!$enabled) {
            $result = [
                'result' => 'WARN',
                'message' => $definition['label'] . ' 配置完整但当前未启用',
                'details' => $details,
            ];
        } else {
            $result = [
                'result' => 'PASS',
                'message' => $definition['label'] . ' 必填配置已具备，可执行测试翻译',
                'details' => $details,
            ];
        }

        if ($persist) {
            $this->recordCheck($provider, 'readiness', $result);
        }

        return $result;
    }

    public function testProvider(string $provider, string $text = 'Hello', string $sourceLanguage = 'en', string $targetLanguage = 'mn'): array
    {
        $check = $this->checkProvider($provider, false);
        if (($check['result'] ?? '') !== 'PASS') {
            $this->recordCheck($provider, 'provider_test', $check);
            return $check;
        }

        $result = $this->translate($text, $sourceLanguage, $targetLanguage, $provider);
        $checkResult = [
            'result' => $result['status'] === self::STATUS_TRANSLATED || $result['status'] === self::STATUS_SKIPPED ? 'PASS' : 'FAIL',
            'message' => $result['status'] === self::STATUS_TRANSLATED || $result['status'] === self::STATUS_SKIPPED
                ? '测试翻译成功'
                : '测试翻译失败：' . $result['error'],
            'details' => [
                'source_language' => $result['source_language'],
                'target_language' => $result['target_language'],
                'provider' => $result['provider'],
                'status' => $result['status'],
                'text_length' => mb_strlen($text, 'UTF-8'),
            ],
        ];
        $this->recordCheck($provider, 'provider_test', $checkResult);

        return $checkResult + ['translation' => $result];
    }

    public function runtimeConfig(string $provider): array
    {
        $definition = $this->requireProvider($provider);
        $config = ['provider' => $provider];
        foreach ($definition['fields'] as $code => $field) {
            $config[$code] = $this->storedValue($provider, $code, $field);
        }

        $config['enabled'] = $this->truthy($config['enabled'] ?? '');
        $config['timeout_seconds'] = max(3, min(60, (int)($config['timeout_seconds'] ?? 20)));
        return $config;
    }

    public function defaultStaffWorkLanguage(): string
    {
        foreach (array_keys($this->providerDefinitions()) as $provider) {
            $config = $this->runtimeConfig($provider);
            $language = $this->normalizeLanguage((string)($config['staff_work_language'] ?? ''));
            if (!empty($config['enabled']) && isset($this->workLanguages()[$language])) {
                return $language;
            }
        }

        foreach (array_keys($this->providerDefinitions()) as $provider) {
            $config = $this->runtimeConfig($provider);
            $language = $this->normalizeLanguage((string)($config['staff_work_language'] ?? ''));
            if (isset($this->workLanguages()[$language])) {
                return $language;
            }
        }

        return 'en';
    }

    public function translate(string $text, string $sourceLanguage = '', string $targetLanguage = 'en', string $provider = ''): array
    {
        $original = $text;
        $text = trim($text);
        $sourceLanguage = $this->normalizeLanguage($sourceLanguage !== '' ? $sourceLanguage : $this->detectLanguage($text));
        $targetLanguage = $this->normalizeLanguage($targetLanguage);

        if ($text === '') {
            return $this->result($original, '', $sourceLanguage, $targetLanguage, self::STATUS_SKIPPED, $provider, '');
        }
        if ($sourceLanguage === $targetLanguage) {
            return $this->result($original, $original, $sourceLanguage, $targetLanguage, self::STATUS_SKIPPED, $provider, '');
        }

        $provider = $provider !== '' ? $provider : $this->selectEnabledProvider();
        if ($provider === '') {
            return $this->result($original, $original, $sourceLanguage, $targetLanguage, self::STATUS_FAILED, '', 'No enabled translation provider.');
        }

        try {
            $config = $this->runtimeConfig($provider);
            if (empty($config['enabled'])) {
                return $this->result($original, $original, $sourceLanguage, $targetLanguage, self::STATUS_FAILED, $provider, 'Translation provider is disabled.');
            }

            if ($provider === 'openai_compatible') {
                $translated = $this->translateOpenAiCompatible($text, $sourceLanguage, $targetLanguage, $config);
            } elseif ($provider === 'google_compatible') {
                $translated = $this->translateGoogleCompatible($text, $sourceLanguage, $targetLanguage, $config);
            } else {
                throw new \InvalidArgumentException('Unsupported translation provider: ' . $provider);
            }

            return $this->result($original, trim($translated), $sourceLanguage, $targetLanguage, self::STATUS_TRANSLATED, $provider, '');
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.customer_service_translation.failed');
            return $this->result($original, $original, $sourceLanguage, $targetLanguage, self::STATUS_FAILED, $provider, $this->shortError($e->getMessage()));
        }
    }

    public function messageMetadata(array $translation): array
    {
        return [
            'original_content' => (string)($translation['original_text'] ?? ''),
            'source_language' => (string)($translation['source_language'] ?? ''),
            'target_language' => (string)($translation['target_language'] ?? ''),
            'translated_content' => (string)($translation['translated_text'] ?? ''),
            'translation_status' => (string)($translation['status'] ?? self::STATUS_NONE),
            'translation_provider' => (string)($translation['provider'] ?? ''),
            'translation_error' => (string)($translation['error'] ?? ''),
            'translated_at' => in_array((string)($translation['status'] ?? ''), [self::STATUS_TRANSLATED, self::STATUS_FAILED], true) ? time() : 0,
        ];
    }

    public function detectLanguage(string $text): string
    {
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $text)) {
            return 'zh-CN';
        }
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $text)) {
            return 'mn';
        }

        return 'en';
    }

    private function translateOpenAiCompatible(string $text, string $sourceLanguage, string $targetLanguage, array $config): string
    {
        $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
        $apiKey = (string)($config['api_key'] ?? '');
        $model = (string)($config['model'] ?? '');
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new \RuntimeException('OpenAI-compatible translation config is incomplete.');
        }

        $url = str_ends_with($baseUrl, '/chat/completions') ? $baseUrl : $baseUrl . '/chat/completions';
        $payload = [
            'model' => $model,
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Translate customer-service chat messages. Return only the translated text.',
                ],
                [
                    'role' => 'user',
                    'content' => "Source language: {$sourceLanguage}\nTarget language: {$targetLanguage}\nText:\n{$text}",
                ],
            ],
        ];

        $response = $this->curlJson($url, Json::encode($payload), [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ], (int)$config['timeout_seconds']);
        $data = Json::decode($response, true);
        $translated = $data['choices'][0]['message']['content'] ?? '';
        if (!is_string($translated) || trim($translated) === '') {
            throw new \RuntimeException('OpenAI-compatible translation response is empty.');
        }

        return $translated;
    }

    private function translateGoogleCompatible(string $text, string $sourceLanguage, string $targetLanguage, array $config): string
    {
        $endpoint = (string)($config['endpoint'] ?? '');
        $apiKey = (string)($config['api_key'] ?? '');
        if ($endpoint === '' || $apiKey === '') {
            throw new \RuntimeException('Google-compatible translation config is incomplete.');
        }

        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $url = $endpoint . $separator . 'key=' . rawurlencode($apiKey);
        $payload = http_build_query([
            'q' => $text,
            'source' => $this->googleLanguage($sourceLanguage),
            'target' => $this->googleLanguage($targetLanguage),
            'format' => 'text',
        ]);

        $response = $this->curlJson($url, $payload, [
            'Content-Type: application/x-www-form-urlencoded',
        ], (int)$config['timeout_seconds']);
        $data = Json::decode($response, true);
        $translated = $data['data']['translations'][0]['translatedText'] ?? '';
        if (!is_string($translated) || trim($translated) === '') {
            throw new \RuntimeException('Google-compatible translation response is empty.');
        }

        return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function curlJson(string $url, string $payload, array $headers, int $timeout): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required for customer-service translation.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException('Translation request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Translation provider returned HTTP ' . $status);
        }

        return (string)$body;
    }

    private function selectEnabledProvider(): string
    {
        foreach (array_keys($this->providerDefinitions()) as $provider) {
            $config = $this->runtimeConfig($provider);
            if (!empty($config['enabled'])) {
                return $provider;
            }
        }

        return '';
    }

    private function storedValue(string $provider, string $code, array $field): string
    {
        try {
            return (string)$this->configService->getValue('translation', $provider, $code, 'default', 0, (string)($field['default'] ?? ''));
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.customer_service_translation.read_failed');
            return '';
        }
    }

    private function isConfigured(string $provider, string $code): bool
    {
        foreach ($this->configService->redactedRows(['category' => 'translation', 'provider' => $provider, 'environment' => 'default']) as $row) {
            if (($row['code'] ?? '') === $code) {
                return (int)($row['configured'] ?? 0) === 1;
            }
        }

        return false;
    }

    private function latestProviderCheck(string $provider): array
    {
        foreach ($this->configService->latestChecks(50) as $check) {
            if (($check['category'] ?? '') === 'translation' && ($check['provider'] ?? '') === $provider) {
                return $check;
            }
        }

        return [];
    }

    private function recordCheck(string $provider, string $key, array $result): void
    {
        $this->configService->recordCheck([
            'category' => 'translation',
            'provider' => $provider,
            'check_key' => $key,
            'result' => $result['result'] ?? 'WARN',
            'message' => $result['message'] ?? '',
            'details' => $result['details'] ?? [],
        ]);
    }

    private function result(string $original, string $translated, string $sourceLanguage, string $targetLanguage, string $status, string $provider, string $error): array
    {
        return [
            'original_text' => $original,
            'translated_text' => $translated,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'status' => $status,
            'provider' => $provider,
            'error' => $error,
        ];
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        if (str_starts_with($language, 'zh')) {
            return 'zh-CN';
        }
        if (str_starts_with($language, 'mn')) {
            return 'mn';
        }

        return 'en';
    }

    private function googleLanguage(string $language): string
    {
        return $language === 'zh-CN' ? 'zh-CN' : $language;
    }

    private function truthy($value): bool
    {
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    private function shortError(string $message): string
    {
        return mb_substr($message, 0, 255, 'UTF-8');
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
        $definitions = $this->providerDefinitions();
        if (!isset($definitions[$provider])) {
            throw new \InvalidArgumentException('Unsupported customer-service translation provider: ' . $provider);
        }

        return $definitions[$provider];
    }
}
