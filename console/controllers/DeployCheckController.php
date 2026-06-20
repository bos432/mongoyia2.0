<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class DeployCheckController extends Controller
{
    public $phpEnv = '.env';
    public $imEnv = '../../im后端/im后端/.env';
    public $strict = false;
    public $skipConnectivity = false;
    public $profile = 'local';

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'phpEnv',
            'imEnv',
            'strict',
            'skipConnectivity',
            'profile',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia deployment configuration check\n");

        $phpEnvPath = $this->resolvePath($this->phpEnv);
        $phpEnv = $this->readEnv($phpEnvPath);
        $imEnvPath = $this->resolvePath($this->imEnv);
        $imEnv = $this->readEnv($imEnvPath);

        $this->checkPhpEnv($phpEnv, $phpEnvPath);
        $this->checkPhpRuntime();
        $this->checkWritablePaths();
        $this->checkImEnv($phpEnv, $imEnv, $imEnvPath);
        if ($this->skipConnectivity) {
            $this->section('Connectivity');
            $this->warn('Connectivity checks skipped.');
        } else {
            $this->checkConnections($phpEnv);
            $this->checkSchema();
        }
        $this->checkPaymentEnv($phpEnv);
        $this->checkProfileReadiness($phpEnv, $imEnv);

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkPhpEnv(array $env, string $phpEnvPath)
    {
        $this->section('PHP .env');
        if (!$env) {
            $this->fail("PHP .env not found or empty: {$phpEnvPath}");
            return;
        }

        $this->requireKeys($env, [
            'DB_DSN',
            'DB_USERNAME',
            'DB_PASSWORD',
            'DB_TABLE_PREFIX',
            'YII_DEBUG',
            'YII_ENV',
            'DEFAULT_STORE_ID',
            'DEFAULT_ROUTE',
            'STORE_PLATFORM_DOMAIN',
            'WEB_BASE_URL',
            'MALL_PLATFORM_MODE',
            'MALL_PLATFORM_OPERATOR_STORE_IDS',
            'REDIS_HOST',
            'REDIS_PORT',
            'REDIS_DATABASE',
            'UPLOAD_HTTP_PREFIX',
            'IM_WEBSOCKET_URL',
            'IM_AUTH_SECRET',
            'CHAT_UPLOAD_URL',
        ], 'PHP .env');

        $this->warnIfPlaceholder($env, 'DB_PASSWORD', 'DB_PASSWORD is still a placeholder.');
        $this->warnIfPlaceholder($env, 'IM_AUTH_SECRET', 'IM_AUTH_SECRET must be a real long random secret on test/prod.', ['local-im-auth-secret']);

        if (($env['YII_DEBUG'] ?? 'false') === 'true') {
            $this->warn('YII_DEBUG=true; test/prod should normally use false.');
        }

        $ws = (string)($env['IM_WEBSOCKET_URL'] ?? '');
        $domain = (string)($env['STORE_PLATFORM_DOMAIN'] ?? '');
        if ($domain && !str_contains($domain, '127.0.0.1') && !str_contains($domain, 'localhost') && str_contains($ws, '127.0.0.1')) {
            $this->warn('IM_WEBSOCKET_URL points to localhost while STORE_PLATFORM_DOMAIN is not local.');
        }

        if (($env['DEFAULT_ROUTE'] ?? '') !== 'mall') {
            $this->warn('DEFAULT_ROUTE is not mall; frontend may not default to Mongoyia mall.');
        }

        $this->checkHostRouteEnv($env);
        $this->checkRuntimeHostFile($env);
        $this->checkUrlEnv($env);
        $this->checkPhpUploadRuntimeLimits();

        $webBaseHost = $this->hostFromDomain($env['WEB_BASE_URL'] ?? '');
        $platformHost = $this->hostFromDomain($env['STORE_PLATFORM_DOMAIN'] ?? '');
        if ($webBaseHost && $platformHost && $webBaseHost !== $platformHost) {
            $this->warn("WEB_BASE_URL host '{$webBaseHost}' differs from STORE_PLATFORM_DOMAIN '{$platformHost}'.");
        }
    }

    private function checkImEnv(array $phpEnv, array $imEnv, string $imEnvPath)
    {
        $this->section('Python IM .env');
        if (!$imEnv) {
            $this->fail("IM .env not found or empty: {$imEnvPath}");
            return;
        }

        $this->requireKeys($imEnv, [
            'DB_HOST',
            'DB_PORT',
            'DB_USERNAME',
            'DB_PASSWORD',
            'DB_DATABASE',
            'IM_HOST',
            'IM_PORT',
            'IM_AUTH_SECRET',
            'IM_MAX_TEXT_MESSAGE_LENGTH',
            'IM_MAX_IMAGE_MESSAGE_LENGTH',
            'IM_CHAT_TABLE',
        ], 'Python IM .env');

        $this->warnIfPlaceholder($imEnv, 'IM_AUTH_SECRET', 'Python IM_AUTH_SECRET must be a real long random secret on test/prod.', ['local-im-auth-secret']);
        $this->checkImBindEnv($phpEnv, $imEnv);
        $this->checkPositiveIntEnv($imEnv, 'IM_MAX_TEXT_MESSAGE_LENGTH', 'Python IM .env', 1, 10000);
        $this->checkPositiveIntEnv($imEnv, 'IM_MAX_IMAGE_MESSAGE_LENGTH', 'Python IM .env', 1, 8192);

        if (($phpEnv['IM_AUTH_SECRET'] ?? '') !== ($imEnv['IM_AUTH_SECRET'] ?? null)) {
            $this->fail('PHP IM_AUTH_SECRET and Python IM_AUTH_SECRET do not match.');
        } else {
            $this->ok('PHP/Python IM_AUTH_SECRET match.');
        }

        $this->checkImDatabaseMatchesPhp($phpEnv, $imEnv);

        if (($imEnv['IM_CHAT_TABLE'] ?? '') !== (($phpEnv['DB_TABLE_PREFIX'] ?? 'fb_') . 'chat')) {
            $this->warn('IM_CHAT_TABLE does not match DB_TABLE_PREFIX + chat.');
        }
    }

    private function checkImBindEnv(array $phpEnv, array $imEnv)
    {
        $this->checkPositiveIntEnv($imEnv, 'IM_PORT', 'Python IM .env', 1, 65535);

        $imHost = trim((string)($imEnv['IM_HOST'] ?? ''));
        if ($imHost === '') {
            $this->fail('Python IM .env IM_HOST must not be empty.');
            return;
        }

        if (preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $imHost) || str_contains($imHost, '/') || str_contains($imHost, '?') || str_contains($imHost, '#')) {
            $this->fail('Python IM .env IM_HOST must be a bind host such as 0.0.0.0 or 127.0.0.1, not a URL.');
            return;
        }

        $ws = (string)($phpEnv['IM_WEBSOCKET_URL'] ?? '');
        $wsHost = strtolower((string)parse_url($ws, PHP_URL_HOST));
        $wsPort = parse_url($ws, PHP_URL_PORT);
        $profile = strtolower((string)$this->profile);
        if ($profile === 'local' && in_array($wsHost, ['127.0.0.1', 'localhost', '::1'], true)) {
            if ($wsPort === null) {
                $this->warn('Local IM_WEBSOCKET_URL points to localhost but has no explicit port; it may not reach the Python IM service.');
            } elseif ((int)$wsPort !== (int)($imEnv['IM_PORT'] ?? 0)) {
                $this->warn("Local IM_WEBSOCKET_URL port {$wsPort} differs from Python IM_PORT " . ($imEnv['IM_PORT'] ?? '') . '.');
            } else {
                $this->ok('Local IM_WEBSOCKET_URL port matches Python IM_PORT.');
            }
        }
    }

    private function checkImDatabaseMatchesPhp(array $phpEnv, array $imEnv)
    {
        $profile = strtolower((string)$this->profile);
        $hardFail = in_array($profile, ['test', 'prod'], true);
        $phpDsn = $this->mysqlDsnParts((string)($phpEnv['DB_DSN'] ?? ''));
        if (!$phpDsn) {
            $message = 'Could not parse PHP DB_DSN for IM database consistency check.';
            $hardFail ? $this->fail($message) : $this->warn($message);
            return;
        }

        $mismatches = [];
        $phpHost = $this->normalizeDbHost($phpDsn['host'] ?? '');
        $imHost = $this->normalizeDbHost($imEnv['DB_HOST'] ?? '');
        if ($phpHost !== $imHost) {
            $mismatches[] = "host PHP={$phpDsn['host']} IM={$imEnv['DB_HOST']}";
        }

        $phpPort = (string)($phpDsn['port'] ?? '3306');
        $imPort = (string)($imEnv['DB_PORT'] ?? '3306');
        if ($phpPort !== $imPort) {
            $mismatches[] = "port PHP={$phpPort} IM={$imPort}";
        }

        if ((string)($phpDsn['dbname'] ?? '') !== (string)($imEnv['DB_DATABASE'] ?? '')) {
            $mismatches[] = "database PHP=" . ($phpDsn['dbname'] ?? '') . " IM=" . ($imEnv['DB_DATABASE'] ?? '');
        }

        if ((string)($phpEnv['DB_USERNAME'] ?? '') !== (string)($imEnv['DB_USERNAME'] ?? '')) {
            $mismatches[] = "username PHP=" . ($phpEnv['DB_USERNAME'] ?? '') . " IM=" . ($imEnv['DB_USERNAME'] ?? '');
        }

        if ($mismatches) {
            $message = 'PHP and Python IM database settings differ: ' . implode('; ', $mismatches) . '.';
            $hardFail ? $this->fail($message) : $this->warn($message);
            return;
        }

        $this->ok('PHP and Python IM database settings match.');
    }

    private function checkPhpRuntime()
    {
        $this->section('PHP runtime');
        $profile = strtolower((string)$this->profile);
        $hardFail = in_array($profile, ['test', 'prod'], true);
        $missing = [];

        foreach (['json', 'redis', 'curl', 'libxml', 'dom', 'gd', 'fileinfo', 'openssl', 'mbstring', 'pdo_mysql'] as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = 'ext-' . $extension;
            }
        }

        foreach (['getimagesize', 'fsockopen', 'hash_hmac', 'random_bytes'] as $function) {
            if (!function_exists($function)) {
                $missing[] = 'function ' . $function;
            }
        }

        if ($missing) {
            $message = 'Missing PHP runtime requirements: ' . implode(', ', $missing) . '.';
            $hardFail ? $this->fail($message) : $this->warn($message);
            return;
        }

        $this->ok('Required PHP extensions and runtime functions are available.');
    }

    private function checkWritablePaths()
    {
        $this->section('Filesystem');
        $profile = strtolower((string)$this->profile);
        $hardFail = in_array($profile, ['test', 'prod'], true);
        $paths = [
            'runtime' => $this->projectRoot() . '/runtime',
            'frontend/runtime' => $this->projectRoot() . '/frontend/runtime',
            'web/assets' => $this->projectRoot() . '/web/assets',
            'web/attachment' => $this->projectRoot() . '/web/attachment',
            'web/attachment/chat' => $this->projectRoot() . '/web/attachment/chat',
        ];

        $failures = [];
        foreach ($paths as $label => $path) {
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                $failures[] = "{$label} cannot be created at {$path}";
                continue;
            }

            $file = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.mongoyia_write_check_' . bin2hex(random_bytes(4));
            if (@file_put_contents($file, 'ok') === false) {
                $failures[] = "{$label} is not writable at {$path}";
                continue;
            }
            @unlink($file);
        }

        if ($failures) {
            $message = 'Filesystem write checks failed: ' . implode('; ', $failures) . '.';
            $hardFail ? $this->fail($message) : $this->warn($message);
            return;
        }

        $this->ok('Runtime, assets, and attachment paths are writable.');
    }

    private function checkUrlEnv(array $env)
    {
        $profile = strtolower((string)$this->profile);
        $hardFail = in_array($profile, ['test', 'prod'], true);
        $platformHost = $this->hostFromDomain($env['STORE_PLATFORM_DOMAIN'] ?? '');

        foreach ([
            'CHAT_UPLOAD_URL' => (string)($env['CHAT_UPLOAD_URL'] ?? ''),
            'UPLOAD_HTTP_PREFIX' => (string)($env['UPLOAD_HTTP_PREFIX'] ?? ''),
        ] as $key => $value) {
            $value = trim($value);
            if ($value === '') {
                $this->fail("PHP .env {$key} must not be empty.");
                continue;
            }

            if (str_starts_with($value, '/')) {
                continue;
            }

            $scheme = parse_url($value, PHP_URL_SCHEME);
            $host = $this->hostFromDomain($value);
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                $message = "PHP .env {$key} must be a root-relative path or an absolute http(s) URL.";
                $hardFail ? $this->fail($message) : $this->warn($message);
                continue;
            }

            if ($hardFail && $scheme !== 'https') {
                $this->fail("PHP .env {$key} must use https for {$profile} when absolute.");
            }
            if ($hardFail && $platformHost && $host !== $platformHost) {
                $this->fail("PHP .env {$key} host '{$host}' must match STORE_PLATFORM_DOMAIN '{$platformHost}' for {$profile}.");
            }
        }
    }

    private function checkConnections(array $env)
    {
        $this->section('Connectivity');

        try {
            $db = Yii::createObject([
                'class' => 'yii\db\Connection',
                'dsn' => $env['DB_DSN'] ?? '',
                'username' => $env['DB_USERNAME'] ?? '',
                'password' => $env['DB_PASSWORD'] ?? '',
                'charset' => $env['DB_CHARSET'] ?? 'utf8mb4',
                'tablePrefix' => $env['DB_TABLE_PREFIX'] ?? 'fb_',
            ]);
            $db->open();
            $db->createCommand('SELECT 1')->queryScalar();
            $db->close();
            $this->ok('Database connection works.');
        } catch (\Throwable $e) {
            $this->fail('Database connection failed: ' . $e->getMessage());
        }

        try {
            $redis = Yii::createObject([
                'class' => 'yii\redis\Connection',
                'hostname' => $env['REDIS_HOST'] ?? '127.0.0.1',
                'port' => (int)($env['REDIS_PORT'] ?? 6379),
                'database' => (int)($env['REDIS_DATABASE'] ?? 0),
            ]);
            $redis->open();
            $redis->executeCommand('PING');
            $redis->close();
            $this->ok('Redis connection works.');
        } catch (\Throwable $e) {
            $this->warn('Redis connection failed: ' . $e->getMessage());
        }

        $ws = (string)($env['IM_WEBSOCKET_URL'] ?? '');
        $host = parse_url($ws, PHP_URL_HOST) ?: '127.0.0.1';
        $port = (int)(parse_url($ws, PHP_URL_PORT) ?: 0);
        $scheme = parse_url($ws, PHP_URL_SCHEME);
        if (!$port && $scheme === 'wss') {
            $port = 443;
        } elseif (!$port && $scheme === 'ws') {
            $port = 80;
        }

        if ($port > 0) {
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($socket) {
                fclose($socket);
                $this->ok("IM socket {$host}:{$port} is reachable.");
            } else {
                $this->warn("IM socket {$host}:{$port} is not reachable: {$errstr}");
            }
        } else {
            $this->warn('Could not parse IM_WEBSOCKET_URL port.');
        }
    }

    private function checkPaymentEnv(array $env)
    {
        $this->section('Payment');
        $profile = strtolower((string)$this->profile);
        $hardFail = in_array($profile, ['test', 'prod'], true);
        if (($env['LIANLIAN_SANDBOX'] ?? 'true') !== 'true') {
            $this->warn('LIANLIAN_SANDBOX is not true; confirm production credentials and callback allowlist before launch.');
        }

        foreach (['QPAY_AUTH_BASIC', 'QPAY_INVOICE_CODE', 'QPAY_AUTH_URL', 'QPAY_INVOICE_URL', 'LIANLIAN_MERCHANT_ID', 'LIANLIAN_PUBLIC_KEY', 'LIANLIAN_PRIVATE_KEY'] as $key) {
            if ($this->isPlaceholder($env[$key] ?? '')) {
                $this->warn("{$key} is empty or placeholder; payment provider flow may be unavailable.");
            }
        }

        foreach (['QPAY_CALLBACK_HMAC_SECRET', 'LIANLIAN_CALLBACK_HMAC_SECRET'] as $key) {
            if ($this->isPlaceholder($env[$key] ?? '')) {
                $this->warn("{$key} is empty or placeholder; callback HMAC enforcement is disabled.");
            }
        }

        $platformHost = $this->hostFromDomain($env['STORE_PLATFORM_DOMAIN'] ?? '');
        foreach (['QPAY_AUTH_URL', 'QPAY_INVOICE_URL', 'QPAY_CALLBACK_BASE', 'LIANLIAN_CALLBACK_BASE'] as $key) {
            if (!array_key_exists($key, $env)) {
                $this->fail("PHP .env missing {$key}.");
                continue;
            }

            $scheme = parse_url($env[$key], PHP_URL_SCHEME);
            $callbackHost = $this->hostFromDomain($env[$key]);
            if (!$callbackHost) {
                $message = "{$key} is not a valid callback URL.";
                $hardFail ? $this->fail($message) : $this->warn($message);
                continue;
            }

            if ($hardFail && $scheme !== 'https') {
                $this->fail("{$key} must use https for {$profile}.");
            }
            if ($hardFail && $this->isExampleHost($callbackHost)) {
                $this->fail("{$key} must be replaced with a real {$profile} host.");
            }
            if (in_array($key, ['QPAY_CALLBACK_BASE', 'LIANLIAN_CALLBACK_BASE'], true) && $platformHost && $callbackHost !== $platformHost) {
                $message = "{$key} host '{$callbackHost}' differs from STORE_PLATFORM_DOMAIN '{$platformHost}'.";
                $hardFail ? $this->fail($message) : $this->warn($message);
            }
        }
    }

    private function checkProfileReadiness(array $phpEnv, array $imEnv)
    {
        $profile = strtolower((string)$this->profile);
        if (!in_array($profile, ['local', 'test', 'prod'], true)) {
            $this->section('Profile readiness');
            $this->fail("Unknown profile '{$this->profile}'. Use local, test, or prod.");
            return;
        }

        if ($profile === 'local') {
            return;
        }

        $this->section('Profile readiness: ' . $profile);
        $this->failIfPlaceholder($phpEnv, 'DB_PASSWORD', 'DB_PASSWORD must be set for ' . $profile . '.');
        $this->failIfPlaceholder($phpEnv, 'IM_AUTH_SECRET', 'PHP IM_AUTH_SECRET must be a real secret for ' . $profile . '.', ['local-im-auth-secret']);
        $this->failIfPlaceholder($imEnv, 'IM_AUTH_SECRET', 'Python IM_AUTH_SECRET must be a real secret for ' . $profile . '.', ['local-im-auth-secret']);

        $phpSecret = (string)($phpEnv['IM_AUTH_SECRET'] ?? '');
        if (!$this->isPlaceholder($phpSecret) && strlen($phpSecret) < 32) {
            $this->fail('PHP IM_AUTH_SECRET should be at least 32 characters.');
        }
        $imSecret = (string)($imEnv['IM_AUTH_SECRET'] ?? '');
        if (!$this->isPlaceholder($imSecret) && strlen($imSecret) < 32) {
            $this->fail('Python IM_AUTH_SECRET should be at least 32 characters.');
        }

        if (($phpEnv['YII_DEBUG'] ?? 'false') !== 'false') {
            $this->fail('YII_DEBUG must be false for ' . $profile . '.');
        }

        if (strtolower((string)($phpEnv['YII_ENV'] ?? '')) !== $profile) {
            $this->fail('YII_ENV must be ' . $profile . ' for ' . $profile . ' profile.');
        }

        if (($phpEnv['DEFAULT_ROUTE'] ?? '') !== 'mall') {
            $this->fail('DEFAULT_ROUTE must be mall for Mongoyia ' . $profile . '.');
        }

        $webBase = (string)($phpEnv['WEB_BASE_URL'] ?? '');
        $webScheme = parse_url($webBase, PHP_URL_SCHEME);
        if ($webScheme !== 'https') {
            $this->fail('WEB_BASE_URL must use https for ' . $profile . '.');
        }

        $webBaseHost = $this->hostFromDomain($webBase);
        $platformHost = $this->hostFromDomain($phpEnv['STORE_PLATFORM_DOMAIN'] ?? '');
        if (!$webBaseHost || !$platformHost || $webBaseHost !== $platformHost) {
            $this->fail('WEB_BASE_URL host must match STORE_PLATFORM_DOMAIN for ' . $profile . '.');
        }
        foreach ([
            'STORE_PLATFORM_DOMAIN' => $platformHost,
            'WEB_BASE_URL' => $webBaseHost,
        ] as $key => $host) {
            if ($this->isExampleHost($host)) {
                $this->fail("{$key} must be replaced with a real {$profile} host.");
            }
        }

        $ws = (string)($phpEnv['IM_WEBSOCKET_URL'] ?? '');
        $wsScheme = parse_url($ws, PHP_URL_SCHEME);
        $wsHost = strtolower((string)parse_url($ws, PHP_URL_HOST));
        if ($wsScheme !== 'wss') {
            $this->fail('IM_WEBSOCKET_URL must use wss for ' . $profile . '.');
        }
        if (in_array($wsHost, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->fail('IM_WEBSOCKET_URL must not point to localhost for ' . $profile . '.');
        }
        if ($this->isExampleHost($wsHost)) {
            $this->fail('IM_WEBSOCKET_URL must be replaced with a real ' . $profile . ' host.');
        }

        foreach (['QPAY_AUTH_BASIC', 'QPAY_INVOICE_CODE', 'QPAY_AUTH_URL', 'QPAY_INVOICE_URL', 'LIANLIAN_MERCHANT_ID', 'LIANLIAN_PUBLIC_KEY', 'LIANLIAN_PRIVATE_KEY'] as $key) {
            $this->failIfPlaceholder($phpEnv, $key, "{$key} must be configured for {$profile}.");
        }

        foreach (['QPAY_CALLBACK_HMAC_SECRET', 'LIANLIAN_CALLBACK_HMAC_SECRET'] as $key) {
            $this->failIfPlaceholder($phpEnv, $key, "{$key} must be configured for {$profile}.");
            $value = (string)($phpEnv[$key] ?? '');
            if (!$this->isPlaceholder($value) && strlen($value) < 32) {
                $this->fail("{$key} should be at least 32 characters.");
            }
        }

        foreach (['QPAY_CALLBACK_MAX_AGE_SECONDS', 'LIANLIAN_CALLBACK_MAX_AGE_SECONDS'] as $key) {
            if ((int)($phpEnv[$key] ?? 0) <= 0) {
                $this->fail("{$key} must be greater than 0 for {$profile}.");
            }
        }

        if ($profile === 'test' && (($phpEnv['LIANLIAN_SANDBOX'] ?? 'true') !== 'true')) {
            $this->fail('LIANLIAN_SANDBOX must stay true for test profile.');
        }
        if ($profile === 'prod' && (($phpEnv['LIANLIAN_SANDBOX'] ?? 'true') === 'true')) {
            $this->fail('LIANLIAN_SANDBOX must be false for prod profile.');
        }

        if ($this->failures === 0) {
            $this->ok("{$profile} profile readiness checks passed.");
        }
    }

    private function checkSchema()
    {
        $this->section('Database schema');
        $this->requireMigrations([
            'm260608_150000_mongoyia_order_parent_id',
            'm260608_160000_mongoyia_order_stock_deducted_at',
            'm260608_170000_mongoyia_order_stock_refunded_at',
            'm260608_180000_mongoyia_payment_attempt',
            'm260608_182000_mongoyia_payment_attempt_business_key',
            'm260608_184000_mongoyia_chat_context',
            'm260608_185000_mongoyia_chat_read_state',
        ]);

        $this->requireTableColumns('{{%mall_order}}', [
            'parent_id',
            'stock_deducted_at',
            'stock_refunded_at',
        ]);
        $this->requireTableColumns('{{%mall_payment_attempt}}', [
            'id',
            'store_id',
            'order_id',
            'provider',
            'event',
            'business_key',
            'merchant_transaction_id',
            'gateway_transaction_id',
            'payload',
            'payload_hash',
            'result',
            'error_message',
            'processed_at',
            'status',
        ]);
        $this->requireTableColumns('{{%chat}}', [
            'product_id',
            'store_id',
            'user_read_at',
            'merchant_read_at',
        ]);

        $this->requireIndexes('{{%mall_order}}', ['mall_order_k0']);
        $this->requireIndexes('{{%mall_payment_attempt}}', [
            'mall_payment_attempt_k0',
            'mall_payment_attempt_k4',
            'mall_payment_attempt_k5',
        ]);
        $this->requireIndexes('{{%chat}}', ['chat_k0', 'chat_k1', 'chat_k2', 'chat_k3', 'chat_k4']);
    }

    private function requireMigrations(array $migrations)
    {
        $table = Yii::$app->db->schema->getTableSchema('{{%migration}}', true);
        if ($table === null) {
            $this->fail('Migration table is missing.');
            return;
        }

        $applied = (new \yii\db\Query())
            ->select('version')
            ->from('{{%migration}}')
            ->where(['version' => $migrations])
            ->column(Yii::$app->db);
        $applied = array_flip($applied);

        foreach ($migrations as $migration) {
            if (!isset($applied[$migration])) {
                $this->fail("Migration {$migration} has not been applied.");
            }
        }

        if (count($applied) === count($migrations)) {
            $this->ok('Required Mongoyia migrations are applied.');
        }
    }

    private function requireTableColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        if ($schema === null) {
            $this->fail("Table {$table} is missing.");
            return;
        }

        $missing = [];
        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                $missing[] = $column;
            }
        }

        if ($missing) {
            $this->fail("Table {$table} missing columns: " . implode(', ', $missing) . '.');
        } else {
            $this->ok("Table {$table} required columns exist.");
        }
    }

    private function requireIndexes(string $table, array $indexes)
    {
        if (Yii::$app->db->driverName !== 'mysql') {
            return;
        }

        $rawTable = Yii::$app->db->schema->getRawTableName($table);
        $rows = Yii::$app->db->createCommand('SHOW INDEX FROM ' . Yii::$app->db->quoteTableName($rawTable))->queryAll();
        $existing = [];
        foreach ($rows as $row) {
            $existing[$row['Key_name']] = true;
        }

        $missing = [];
        foreach ($indexes as $index) {
            if (!isset($existing[$index])) {
                $missing[] = $index;
            }
        }

        if ($missing) {
            $this->fail("Table {$table} missing indexes: " . implode(', ', $missing) . '.');
        } else {
            $this->ok("Table {$table} required indexes exist.");
        }
    }

    private function requireKeys(array $env, array $keys, string $label)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $env)) {
                $this->fail("{$label} missing {$key}.");
            }
        }
    }

    private function checkPositiveIntEnv(array $env, string $key, string $label, int $min, int $max)
    {
        $value = $env[$key] ?? null;
        if (!is_numeric($value) || (string)(int)$value !== trim((string)$value)) {
            $this->fail("{$label} {$key} must be an integer.");
            return;
        }

        $intValue = (int)$value;
        if ($intValue < $min || $intValue > $max) {
            $this->fail("{$label} {$key} must be between {$min} and {$max}.");
        }
    }

    private function checkPhpUploadRuntimeLimits()
    {
        $requiredBytes = 6 * 1024 * 1024;
        $profile = strtolower((string)$this->profile);
        $hardFail = in_array($profile, ['test', 'prod'], true);

        foreach ([
            'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
            'post_max_size' => (string)ini_get('post_max_size'),
        ] as $key => $value) {
            $bytes = $this->phpShorthandBytes($value);
            if ($bytes === null) {
                $message = "Could not parse PHP {$key}='{$value}'; chat image upload limit cannot be verified.";
                $hardFail ? $this->fail($message) : $this->warn($message);
                continue;
            }

            if ($bytes > 0 && $bytes < $requiredBytes) {
                $message = "PHP {$key}='{$value}' is below 6M; test/prod must allow chat uploads above the 5MB business limit so oversized images reach application validation.";
                $hardFail ? $this->fail($message) : $this->warn($message);
            }
        }
    }

    private function phpShorthandBytes(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^(-?\d+)([kmg])?$/i', $value, $matches)) {
            return null;
        }

        $bytes = (int)$matches[1];
        switch (strtolower($matches[2] ?? '')) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
                break;
        }

        return $bytes;
    }

    private function warnIfPlaceholder(array $env, string $key, string $message, array $extraBadValues = [])
    {
        if ($this->isPlaceholder($env[$key] ?? '', $extraBadValues)) {
            $this->warn($message);
        }
    }

    private function failIfPlaceholder(array $env, string $key, string $message, array $extraBadValues = [])
    {
        if ($this->isPlaceholder($env[$key] ?? '', $extraBadValues)) {
            $this->fail($message);
        }
    }

    private function isPlaceholder($value, array $extraBadValues = [])
    {
        $value = trim((string)$value);
        if ($value === '' || in_array($value, array_merge(['change-me', 'password'], $extraBadValues), true)) {
            return true;
        }

        return str_starts_with($value, 'replace-with-');
    }

    private function checkHostRouteEnv(array $env)
    {
        $legacyHosts = $this->domainList($env['LEGACY_HOST_DOMAINS'] ?? '');
        $backendOnlyHosts = $this->domainList($env['BACKEND_ONLY_DOMAINS'] ?? '');
        $hostRouteMap = $this->hostRouteMap($env['HOST_ROUTE_MAP'] ?? '');

        foreach ($backendOnlyHosts as $backendOnlyHost) {
            if (in_array($backendOnlyHost, $legacyHosts, true)) {
                $this->warn("BACKEND_ONLY_DOMAINS contains legacy domain {$backendOnlyHost}.");
            }
        }

        foreach ($hostRouteMap as $host => $route) {
            if (in_array($host, $legacyHosts, true)) {
                $this->warn("HOST_ROUTE_MAP contains legacy domain {$host}.");
            }
            if (!in_array($route, ['site', 'pay', 'cms', 'bbs', 'mall', 'wechat', 'mini', 'chat'], true)) {
                $this->warn("HOST_ROUTE_MAP has unsupported route '{$route}' for {$host}.");
            }
        }

        $platformHost = $this->hostFromDomain($env['STORE_PLATFORM_DOMAIN'] ?? '');
        if ($platformHost && isset($hostRouteMap[$platformHost]) && $hostRouteMap[$platformHost] !== 'mall') {
            $this->warn("HOST_ROUTE_MAP routes platform domain {$platformHost} to {$hostRouteMap[$platformHost]} instead of mall.");
        }
    }

    private function checkRuntimeHostFile(array $env)
    {
        $hostPath = $this->projectRoot() . '/frontend/runtime/host.php';
        if (!is_file($hostPath)) {
            $this->warn('frontend/runtime/host.php is missing; backend store config regeneration should create it.');
            return;
        }

        $hostMap = require $hostPath;
        if (!is_array($hostMap)) {
            $this->warn('frontend/runtime/host.php does not return an array.');
            return;
        }

        $normalized = [];
        foreach ($hostMap as $host => $route) {
            $normalizedHost = $this->hostFromDomain($host);
            if ($normalizedHost !== '') {
                $normalized[$normalizedHost] = (string)$route;
            }
        }

        foreach ($this->domainList($env['LEGACY_HOST_DOMAINS'] ?? '') as $legacyHost) {
            if (array_key_exists($legacyHost, $normalized)) {
                $this->warn("frontend/runtime/host.php contains legacy domain {$legacyHost}.");
            }
        }

        foreach ($this->platformHosts($env) as $platformHost) {
            if (($normalized[$platformHost] ?? 'mall') !== 'mall') {
                $this->warn("frontend/runtime/host.php routes platform domain {$platformHost} to {$normalized[$platformHost]} instead of mall.");
            }
        }
    }

    private function platformHosts(array $env)
    {
        $hosts = [];
        foreach ([$env['STORE_PLATFORM_DOMAIN'] ?? '', parse_url($env['WEB_BASE_URL'] ?? '', PHP_URL_HOST)] as $value) {
            $host = $this->hostFromDomain($value);
            if ($host === '') {
                continue;
            }
            $hosts[] = $host;
            if (str_starts_with($host, 'www.')) {
                $hosts[] = substr($host, 4);
            }
        }

        return array_values(array_unique($hosts));
    }

    private function hostRouteMap($value)
    {
        $map = [];
        foreach (array_filter(array_map('trim', explode(',', (string)$value))) as $pair) {
            if (!str_contains($pair, ':')) {
                $this->warn("HOST_ROUTE_MAP entry '{$pair}' must use domain:route.");
                continue;
            }

            [$host, $route] = array_map('trim', explode(':', $pair, 2));
            $host = $this->hostFromDomain($host);
            if ($host === '' || $route === '') {
                $this->warn("HOST_ROUTE_MAP entry '{$pair}' is incomplete.");
                continue;
            }

            $map[$host] = $route;
        }

        return $map;
    }

    private function domainList($value)
    {
        $domains = [];
        foreach (array_filter(array_map('trim', explode(',', (string)$value))) as $domain) {
            $host = $this->hostFromDomain($domain);
            if ($host !== '') {
                $domains[] = $host;
            }
        }

        return array_values(array_unique($domains));
    }

    private function hostFromDomain($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!$host && !str_contains($value, '://')) {
            $host = parse_url('https://' . $value, PHP_URL_HOST);
        }

        return strtolower((string)$host);
    }

    private function isExampleHost($host)
    {
        $host = strtolower(trim((string)$host));
        return $host === 'example.com' || str_ends_with($host, '.example.com');
    }

    private function mysqlDsnParts(string $dsn)
    {
        $dsn = trim($dsn);
        if (!str_starts_with($dsn, 'mysql:')) {
            return [];
        }

        $parts = [];
        foreach (explode(';', substr($dsn, strlen('mysql:'))) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $pair, 2));
            $parts[strtolower($key)] = $value;
        }
        if (!isset($parts['host']) || !isset($parts['dbname'])) {
            return [];
        }
        if (!isset($parts['port'])) {
            $parts['port'] = '3306';
        }

        return $parts;
    }

    private function normalizeDbHost($host)
    {
        $host = strtolower(trim((string)$host));
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? 'localhost' : $host;
    }

    private function readEnv(string $path)
    {
        if (!is_file($path)) {
            return [];
        }

        $env = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $env[$key] = trim($value, "\"'");
        }

        return $env;
    }

    private function resolvePath(string $path)
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        return $this->projectRoot() . '/' . $path;
    }

    private function projectRoot()
    {
        return dirname(__DIR__, 2);
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
