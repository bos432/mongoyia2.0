<?php

namespace common\services\mall;

use common\models\BaseModel;
use common\models\User;
use Yii;
use yii\helpers\Json;
use yii\helpers\Url;

class SocialIdentityService
{
    public const VERSION = 'MONGOYIA_SOCIAL_IDENTITY_RUNTIME_V1';
    public const TABLE = '{{%mall_social_identity}}';
    public const SESSION_KEY_PREFIX = 'mongoyia_social_auth_state_';
    public const BIND_POLICY_REQUIRE_EXISTING_SESSION = 'require_existing_session_before_first_login';
    public const PROVIDER_RESPONSE_ERROR_POLICY = 'provider_response_errors_are_sanitized';

    private $configService;

    public function __construct(?OperationalIdentityConfigService $configService = null)
    {
        $this->configService = $configService ?: new OperationalIdentityConfigService();
    }

    public function authorizationUrl(string $provider, bool $bind = false, string $returnUrl = ''): string
    {
        $provider = $this->normalizeProvider($provider);
        $config = $this->runtimeConfig($provider);
        $this->assertRunnableConfig($provider, $config);

        $state = $this->createState($provider, $bind, $returnUrl);
        $callbackUrl = Url::to(['/social-auth/callback', 'provider' => $provider], true);
        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'scope' => $config['scopes'],
            'state' => $state,
        ];
        if ($provider === 'google') {
            $params['access_type'] = 'online';
            $params['prompt'] = 'select_account';
        }

        return rtrim($config['auth_url'], '?') . (strpos($config['auth_url'], '?') === false ? '?' : '&') . http_build_query($params);
    }

    public function handleCallback(string $provider, string $code, string $state): array
    {
        $provider = $this->normalizeProvider($provider);
        $stateData = $this->consumeState($provider, $state);
        $config = $this->runtimeConfig($provider);
        $this->assertRunnableConfig($provider, $config);

        $token = $this->exchangeToken($provider, $config, $code);
        $profile = $this->fetchProfile($provider, $config, $token);
        $identity = $this->normalizeProfile($provider, $profile);
        if ($identity['provider_user_id'] === '') {
            throw new \RuntimeException('Provider profile did not include a stable user id.');
        }

        $bindRequested = !empty($stateData['bind']);
        if ($bindRequested || !Yii::$app->user->isGuest) {
            if (Yii::$app->user->isGuest) {
                throw new \RuntimeException('Please sign in before binding a third-party account.');
            }

            $this->bindIdentity($provider, (int)Yii::$app->user->id, $identity, $profile);
            return [
                'action' => 'bound',
                'provider' => $provider,
                'return_url' => $stateData['returnUrl'] ?: Url::to(['/site/index']),
                'profile' => $identity,
            ];
        }

        $row = $this->findIdentity($provider, $identity['provider_user_id']);
        if (!$row) {
            return [
                'action' => 'needs_bind',
                'provider' => $provider,
                'return_url' => Url::to(['/site/login']),
                'profile' => $identity,
                'message' => 'Third-party account is not bound to a local user.',
            ];
        }

        $user = User::findOne((int)$row['user_id']);
        if (!$user || (int)$user->status !== BaseModel::STATUS_ACTIVE) {
            throw new \RuntimeException('Bound local user is unavailable.');
        }

        Yii::$app->user->login($user, 3600 * 24 * 30);
        $this->touchIdentity((int)$row['id'], $profile);

        return [
            'action' => 'logged_in',
            'provider' => $provider,
            'return_url' => $stateData['returnUrl'] ?: Url::to(['/site/index']),
            'profile' => $identity,
        ];
    }

    public function unbind(string $provider, int $userId): bool
    {
        $provider = $this->normalizeProvider($provider);
        if ($userId <= 0 || !$this->tableExists(self::TABLE)) {
            return false;
        }

        Yii::$app->db->createCommand()->update(self::TABLE, [
            'status' => BaseModel::STATUS_DELETED,
            'updated_at' => time(),
            'updated_by' => $userId,
        ], [
            'provider' => $provider,
            'user_id' => $userId,
            'status' => BaseModel::STATUS_ACTIVE,
        ])->execute();

        return true;
    }

    public function runtimeReadiness(): array
    {
        return [
            'version' => self::VERSION,
            'table' => self::TABLE,
            'table_exists' => $this->tableExists(self::TABLE),
            'providers' => array_keys($this->configService->providerDefinitions()),
            'bind_policy' => self::BIND_POLICY_REQUIRE_EXISTING_SESSION,
            'secret_logging_policy' => 'provider_secret_never_logged',
        ];
    }

    private function bindIdentity(string $provider, int $userId, array $identity, array $rawProfile): void
    {
        if (!$this->tableExists(self::TABLE)) {
            throw new \RuntimeException('Social identity table is missing; run migrations first.');
        }

        $existing = $this->findIdentity($provider, $identity['provider_user_id']);
        if ($existing && (int)$existing['user_id'] !== $userId) {
            throw new \RuntimeException('This third-party account is already bound to another local user.');
        }

        $user = User::findOne($userId);
        $storeId = $user ? (int)$user->store_id : 0;
        $now = time();
        $data = [
            'store_id' => $storeId,
            'user_id' => $userId,
            'provider' => $provider,
            'provider_user_id' => $identity['provider_user_id'],
            'email' => $identity['email'],
            'email_verified' => $identity['email_verified'] ? 1 : 0,
            'display_name' => $identity['name'],
            'avatar_url' => $identity['avatar'],
            'profile_json' => Json::encode($this->redactedProfile($rawProfile)),
            'last_login_at' => $now,
            'status' => BaseModel::STATUS_ACTIVE,
            'updated_at' => $now,
            'updated_by' => $userId,
        ];

        if ($existing) {
            Yii::$app->db->createCommand()->update(self::TABLE, $data, ['id' => (int)$existing['id']])->execute();
            return;
        }

        $data = array_merge($data, [
            'sort' => 50,
            'created_at' => $now,
            'created_by' => $userId,
        ]);
        Yii::$app->db->createCommand()->insert(self::TABLE, $data)->execute();
    }

    private function touchIdentity(int $id, array $rawProfile): void
    {
        if ($id <= 0 || !$this->tableExists(self::TABLE)) {
            return;
        }

        Yii::$app->db->createCommand()->update(self::TABLE, [
            'profile_json' => Json::encode($this->redactedProfile($rawProfile)),
            'last_login_at' => time(),
            'updated_at' => time(),
        ], ['id' => $id])->execute();
    }

    private function findIdentity(string $provider, string $providerUserId): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return [];
        }

        $row = (new \yii\db\Query())
            ->from(self::TABLE)
            ->where([
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'status' => BaseModel::STATUS_ACTIVE,
            ])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db);

        return $row ?: [];
    }

    private function exchangeToken(string $provider, array $config, string $code): array
    {
        $callbackUrl = Url::to(['/social-auth/callback', 'provider' => $provider], true);
        $response = $this->curlJson($config['token_url'], [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $callbackUrl,
        ]);

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Provider token response did not include access_token.');
        }

        return $response;
    }

    private function fetchProfile(string $provider, array $config, array $token): array
    {
        $url = $config['profile_url'];
        if ($provider === 'facebook') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query([
                'fields' => 'id,name,email,picture',
                'access_token' => $token['access_token'],
            ]);
            return $this->curlJson($url);
        }

        return $this->curlJson($url, [], [
            'Authorization: Bearer ' . $token['access_token'],
        ], 'GET');
    }

    private function normalizeProfile(string $provider, array $profile): array
    {
        if ($provider === 'facebook') {
            return [
                'provider_user_id' => (string)($profile['id'] ?? ''),
                'email' => (string)($profile['email'] ?? ''),
                'email_verified' => !empty($profile['email']),
                'name' => (string)($profile['name'] ?? ''),
                'avatar' => (string)($profile['picture']['data']['url'] ?? ''),
            ];
        }

        return [
            'provider_user_id' => (string)($profile['sub'] ?? ''),
            'email' => (string)($profile['email'] ?? ''),
            'email_verified' => !empty($profile['email_verified']),
            'name' => (string)($profile['name'] ?? ''),
            'avatar' => (string)($profile['picture'] ?? ''),
        ];
    }

    private function curlJson(string $url, array $post = [], array $headers = [], string $method = 'POST'): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Provider HTTP client is unavailable.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Provider HTTP client could not be initialized.');
        }

        $headers[] = 'Accept: application/json';
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException('Provider request failed: ' . $error);
        }

        if ($status < 200 || $status >= 400) {
            throw new \RuntimeException('Provider request returned unavailable response status ' . $status . '.');
        }

        $data = $this->decodeProviderJson((string)$raw, $status);
        if (!is_array($data)) {
            throw new \RuntimeException('Provider request returned invalid JSON response status ' . $status . '.');
        }

        return $data;
    }

    private function decodeProviderJson(string $raw, int $status): array
    {
        $body = trim($raw);
        if ($body === '') {
            throw new \RuntimeException('Provider request returned empty response status ' . $status . '.');
        }

        try {
            $data = Json::decode($body, true);
        } catch (\Throwable $e) {
            Yii::warning([
                'status' => $status,
                'body_sha256_16' => substr(hash('sha256', $body), 0, 16),
                'message' => $e->getMessage(),
            ], 'mall.social_auth.provider_json_decode_failed');
            throw new \RuntimeException('Provider request returned invalid JSON response status ' . $status . '.');
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Provider request returned non-object JSON response status ' . $status . '.');
        }

        return $data;
    }

    private function createState(string $provider, bool $bind, string $returnUrl): string
    {
        $state = Yii::$app->security->generateRandomString(32);
        $payload = [
            'provider' => $provider,
            'bind' => $bind ? 1 : 0,
            'returnUrl' => $this->safeReturnUrl($returnUrl),
            'createdAt' => time(),
        ];
        Yii::$app->session->set(self::SESSION_KEY_PREFIX . $state, $payload);
        return $state;
    }

    private function consumeState(string $provider, string $state): array
    {
        $key = self::SESSION_KEY_PREFIX . $state;
        $payload = Yii::$app->session->get($key);
        Yii::$app->session->remove($key);
        if (!is_array($payload) || ($payload['provider'] ?? '') !== $provider) {
            throw new \RuntimeException('Invalid or expired third-party login state.');
        }
        if (time() - (int)($payload['createdAt'] ?? 0) > 900) {
            throw new \RuntimeException('Third-party login state expired.');
        }

        return $payload;
    }

    private function runtimeConfig(string $provider): array
    {
        $config = $this->configService->runtimeConfig($provider);
        $config['provider'] = $provider;
        return $config;
    }

    private function assertRunnableConfig(string $provider, array $config): void
    {
        $enabled = !empty($config['enabled']) && !in_array(strtolower((string)$config['enabled']), ['0', 'false', 'off', 'no'], true);
        if (!$enabled) {
            throw new \RuntimeException(ucfirst($provider) . ' login is disabled.');
        }

        foreach (['client_id', 'client_secret', 'auth_url', 'token_url', 'profile_url'] as $field) {
            if (trim((string)($config[$field] ?? '')) === '') {
                throw new \RuntimeException(ucfirst($provider) . " login missing {$field}.");
            }
        }
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, ['google', 'facebook'], true)) {
            throw new \InvalidArgumentException('Unsupported third-party login provider.');
        }

        return $provider;
    }

    private function redactedProfile(array $profile): array
    {
        unset($profile['access_token'], $profile['refresh_token'], $profile['id_token'], $profile['token']);
        return $profile;
    }

    private function safeReturnUrl(string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '' || preg_match('/^https?:\/\//i', $returnUrl)) {
            return '';
        }

        return $returnUrl;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Yii::$app->db->schema->getTableSchema($table, true) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
