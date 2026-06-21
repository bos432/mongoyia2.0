<?php

namespace common\services\mall;

use common\models\mall\OperationalConfig;
use common\models\mall\OperationalConfigAudit;
use common\models\mall\OperationalConfigCheck;
use Yii;
use yii\helpers\Json;

class OperationalConfigService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_CONFIG_FOUNDATION_V1';

    private $masterKey;

    public function __construct(string $masterKey = '')
    {
        $this->masterKey = $masterKey !== '' ? $masterKey : $this->envValue('OP_CONFIG_MASTER_KEY');
    }

    public function save(array $data): OperationalConfig
    {
        $storeId = (int)($data['store_id'] ?? 0);
        $category = trim((string)($data['category'] ?? ''));
        $provider = trim((string)($data['provider'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        $environment = trim((string)($data['environment'] ?? 'test'));
        $isSensitive = (int)($data['is_sensitive'] ?? 1) === 1;
        $value = (string)($data['value'] ?? '');

        if ($category === '' || $code === '') {
            throw new \InvalidArgumentException('Operational config category and code are required.');
        }

        $model = OperationalConfig::find()->where([
            'store_id' => $storeId,
            'category' => $category,
            'provider' => $provider,
            'code' => $code,
            'environment' => $environment,
        ])->one();
        if (!$model) {
            $model = new OperationalConfig();
            $model->store_id = $storeId;
            $model->category = $category;
            $model->provider = $provider;
            $model->code = $code;
            $model->environment = $environment;
        }

        $oldRedacted = $this->redactedModelValue($model);
        $model->label = (string)($data['label'] ?? $model->label ?? $code);
        $model->is_enabled = (int)($data['is_enabled'] ?? $model->is_enabled ?? 0);
        $model->is_sensitive = $isSensitive ? 1 : 0;
        $model->metadata_json = $this->encodeJson($data['metadata'] ?? []);
        $model->remark = (string)($data['remark'] ?? $model->remark ?? '');

        if ($isSensitive) {
            $model->value_plain = '';
            $model->value_ciphertext = $this->encrypt($value);
        } else {
            $model->value_plain = $value;
            $model->value_ciphertext = '';
        }
        $model->value_hash = $value === '' ? '' : hash('sha256', $value);

        if (!$model->save()) {
            throw new \RuntimeException('Operational config save failed: ' . Json::encode($model->errors));
        }

        $this->audit($model, 'save', $oldRedacted, $this->redactValue($value, $isSensitive));
        return $model;
    }

    public function getValue(string $category, string $provider, string $code, string $environment = 'test', int $storeId = 0, $default = '')
    {
        $model = OperationalConfig::find()->where([
            'store_id' => $storeId,
            'category' => $category,
            'provider' => $provider,
            'code' => $code,
            'environment' => $environment,
            'status' => OperationalConfig::STATUS_ACTIVE,
        ])->one();
        if (!$model) {
            return $default;
        }

        return (int)$model->is_sensitive === 1 ? $this->decrypt((string)$model->value_ciphertext) : (string)$model->value_plain;
    }

    public function redactedRows(array $filter = []): array
    {
        $query = OperationalConfig::find()->where(['status' => OperationalConfig::STATUS_ACTIVE]);
        foreach (['store_id', 'category', 'provider', 'environment'] as $field) {
            if (array_key_exists($field, $filter) && $filter[$field] !== '' && $filter[$field] !== null) {
                $query->andWhere([$field => $filter[$field]]);
            }
        }

        $rows = [];
        foreach ($query->orderBy(['category' => SORT_ASC, 'provider' => SORT_ASC, 'code' => SORT_ASC])->all() as $model) {
            $rows[] = [
                'id' => (int)$model->id,
                'category' => (string)$model->category,
                'provider' => (string)$model->provider,
                'code' => (string)$model->code,
                'label' => (string)$model->label,
                'environment' => (string)$model->environment,
                'is_enabled' => (int)$model->is_enabled,
                'is_sensitive' => (int)$model->is_sensitive,
                'configured' => $this->isConfigured($model) ? 1 : 0,
                'redacted_value' => $this->redactedModelValue($model),
                'last_check_status' => (string)$model->last_check_status,
                'last_check_message' => (string)$model->last_check_message,
                'last_checked_at' => (int)$model->last_checked_at,
                'updated_at' => (int)$model->updated_at,
            ];
        }

        return $rows;
    }

    public function recordCheck(array $data): OperationalConfigCheck
    {
        $model = new OperationalConfigCheck();
        $model->store_id = (int)($data['store_id'] ?? 0);
        $model->category = (string)($data['category'] ?? '');
        $model->provider = (string)($data['provider'] ?? '');
        $model->check_key = (string)($data['check_key'] ?? '');
        $model->result = $this->normalizeResult((string)($data['result'] ?? 'PENDING'));
        $model->message = mb_substr((string)($data['message'] ?? ''), 0, 255, 'UTF-8');
        $model->details_json = $this->encodeJson($data['details'] ?? []);
        $model->checked_at = (int)($data['checked_at'] ?? time());
        $model->operator_user_id = $this->currentUserId();
        if (!$model->save()) {
            throw new \RuntimeException('Operational config check save failed: ' . Json::encode($model->errors));
        }

        $config = OperationalConfig::find()->where([
            'store_id' => $model->store_id,
            'category' => $model->category,
            'provider' => $model->provider,
            'code' => $model->check_key,
        ])->one();
        if ($config) {
            $config->last_checked_at = $model->checked_at;
            $config->last_check_status = $model->result;
            $config->last_check_message = $model->message;
            $config->save(false);
        }

        return $model;
    }

    public function latestChecks(int $limit = 10): array
    {
        return OperationalConfigCheck::find()
            ->where(['status' => OperationalConfigCheck::STATUS_ACTIVE])
            ->orderBy(['checked_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->asArray()
            ->all();
    }

    public function summary(): array
    {
        $rows = $this->redactedRows();
        $summary = [
            'version' => self::VERSION,
            'config_count' => count($rows),
            'enabled_count' => 0,
            'sensitive_count' => 0,
            'configured_count' => 0,
            'missing_master_key' => $this->masterKey === '' ? 1 : 0,
            'rows' => $rows,
            'latest_checks' => $this->latestChecks(10),
        ];

        foreach ($rows as $row) {
            $summary['enabled_count'] += (int)$row['is_enabled'] === 1 ? 1 : 0;
            $summary['sensitive_count'] += (int)$row['is_sensitive'] === 1 ? 1 : 0;
            $summary['configured_count'] += (int)$row['configured'] === 1 ? 1 : 0;
        }

        return $summary;
    }

    public function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($this->masterKey === '') {
            throw new \RuntimeException('OP_CONFIG_MASTER_KEY is required for sensitive operational config.');
        }
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL extension is required for operational config encryption.');
        }

        $iv = random_bytes(16);
        $key = hash('sha256', $this->masterKey, true);
        $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('Operational config encryption failed.');
        }
        $hmac = hash_hmac('sha256', $iv . $cipher, $key);

        return base64_encode(Json::encode([
            'v' => 1,
            'alg' => 'AES-256-CBC',
            'iv' => base64_encode($iv),
            'data' => base64_encode($cipher),
            'hmac' => $hmac,
        ]));
    }

    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        if ($this->masterKey === '') {
            throw new \RuntimeException('OP_CONFIG_MASTER_KEY is required for sensitive operational config.');
        }

        $json = base64_decode($payload, true);
        $data = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data) || empty($data['iv']) || empty($data['data']) || empty($data['hmac'])) {
            throw new \RuntimeException('Operational config encrypted payload is invalid.');
        }

        $iv = base64_decode((string)$data['iv'], true);
        $cipher = base64_decode((string)$data['data'], true);
        if ($iv === false || $cipher === false) {
            throw new \RuntimeException('Operational config encrypted payload is malformed.');
        }

        $key = hash('sha256', $this->masterKey, true);
        $expected = hash_hmac('sha256', $iv . $cipher, $key);
        if (!hash_equals($expected, (string)$data['hmac'])) {
            throw new \RuntimeException('Operational config encrypted payload signature mismatch.');
        }

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('Operational config decryption failed.');
        }

        return $plain;
    }

    public function redactValue(string $value, bool $sensitive = true): string
    {
        if ($value === '') {
            return 'NOT CONFIGURED';
        }
        if (!$sensitive) {
            return mb_strlen($value, 'UTF-8') > 80 ? mb_substr($value, 0, 77, 'UTF-8') . '...' : $value;
        }

        $length = mb_strlen($value, 'UTF-8');
        if ($length <= 4) {
            return '***';
        }

        return mb_substr($value, 0, 2, 'UTF-8') . str_repeat('*', min(8, max(3, $length - 4))) . mb_substr($value, $length - 2, 2, 'UTF-8');
    }

    private function redactedModelValue($model): string
    {
        if (!$model || !$model->id) {
            return 'NOT CONFIGURED';
        }
        if ((int)$model->is_sensitive === 1) {
            return $this->isConfigured($model) ? 'CONFIGURED:' . substr((string)$model->value_hash, 0, 8) : 'NOT CONFIGURED';
        }

        return $this->redactValue((string)$model->value_plain, false);
    }

    private function isConfigured(OperationalConfig $model): bool
    {
        return (int)$model->is_sensitive === 1
            ? (string)$model->value_ciphertext !== ''
            : (string)$model->value_plain !== '';
    }

    private function audit(OperationalConfig $config, string $action, string $oldRedacted, string $newRedacted): void
    {
        $model = new OperationalConfigAudit();
        $model->config_id = (int)$config->id;
        $model->store_id = (int)$config->store_id;
        $model->category = (string)$config->category;
        $model->provider = (string)$config->provider;
        $model->code = (string)$config->code;
        $model->action = $action;
        $model->old_redacted = $oldRedacted;
        $model->new_redacted = $newRedacted;
        $model->operator_user_id = $this->currentUserId();
        $model->request_ip = Yii::$app->has('request') && !Yii::$app->request->isConsoleRequest ? (string)Yii::$app->request->userIP : 'console';
        if (!$model->save()) {
            Yii::warning($model->errors, 'mall.operational_config_audit.save_failed');
        }
    }

    private function encodeJson($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }

        return Json::encode($value);
    }

    private function normalizeResult(string $result): string
    {
        $upper = strtoupper(trim($result));
        return in_array($upper, ['PASS', 'WARN', 'FAIL', 'PENDING', 'BLOCKED'], true) ? $upper : 'WARN';
    }

    private function currentUserId(): int
    {
        return Yii::$app->has('user') && !Yii::$app->user->isGuest ? (int)Yii::$app->user->id : 0;
    }

    private function envValue(string $key): string
    {
        if (function_exists('env')) {
            return (string)env($key, '');
        }

        $value = getenv($key);
        return $value === false ? '' : (string)$value;
    }
}
