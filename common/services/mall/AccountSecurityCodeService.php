<?php

namespace common\services\mall;

use common\models\BaseModel;
use common\models\User;
use Yii;

class AccountSecurityCodeService
{
    public const VERSION = 'MONGOYIA_ACCOUNT_SECURITY_CODE_RUNTIME_V1';
    public const TABLE = '{{%mall_account_security_code}}';
    public const HASH_STORAGE_POLICY = 'security_code_hash_only_no_plaintext';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_MOBILE = 'mobile';
    public const PURPOSE_LOGIN = 'login';

    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_FAILED = 'failed';
    public const DELIVERY_RESERVED = 'reserved';
    public const DELIVERY_SKIPPED = 'skipped';

    public const VERIFY_PASS = 'pass';
    public const VERIFY_FAIL = 'fail';
    public const VERIFY_LOCKED = 'locked';
    public const VERIFY_EXPIRED = 'expired';

    private $policyService;

    public function __construct(?OperationalAccountSecurityService $policyService = null)
    {
        $this->policyService = $policyService ?: new OperationalAccountSecurityService();
    }

    public function requestCode(string $channel, string $target, string $purpose = self::PURPOSE_LOGIN): array
    {
        $channel = $this->normalizeChannel($channel);
        $purpose = $this->normalizePurpose($purpose);
        $target = $this->normalizeTarget($channel, $target);
        if ($target === '') {
            return $this->result(false, 'INVALID_TARGET', 'A valid email or mobile target is required.');
        }
        if (!$this->policyService->codeLoginEnabled($channel)) {
            return $this->result(false, 'SECURITY_CODE_LOGIN_DISABLED', 'Security-code login is disabled.');
        }
        if (!$this->tableExists(self::TABLE)) {
            return $this->result(false, 'SECURITY_CODE_TABLE_MISSING', 'Security-code table is missing; run migrations first.');
        }

        $policy = $this->policyService->runtimePolicy();
        $user = $this->findUser($channel, $target);
        if (!$user) {
            $this->recordCode($channel, $target, $purpose, '', $policy, self::DELIVERY_SKIPPED, 'Target user not found.');
            return $this->result(true, 'SECURITY_CODE_REQUEST_ACCEPTED', 'If the account exists, a security code will be sent.');
        }

        if ($channel === self::CHANNEL_MOBILE) {
            $this->recordCode($channel, $target, $purpose, '', $policy, self::DELIVERY_RESERVED, 'Mobile/SMS provider is reserved until provider evidence is accepted.', (int)$user->id);
            return $this->result(false, 'SECURITY_CODE_MOBILE_RESERVED', 'Mobile security-code delivery is reserved until SMS or APP provider evidence is accepted.');
        }

        $code = $this->generateCode((int)$policy['code_length']);
        $rowId = $this->recordCode($channel, $target, $purpose, $code, $policy, self::DELIVERY_SENT, '', (int)$user->id);
        $delivery = $this->sendEmailCode($target, $code, (int)$policy['code_ttl_seconds']);
        if (!$delivery['success']) {
            $this->updateDelivery($rowId, self::DELIVERY_FAILED, $delivery['message']);
            return $this->result(false, 'SECURITY_CODE_DELIVERY_FAILED', $delivery['message']);
        }

        return $this->result(true, 'SECURITY_CODE_SENT', 'Security code sent.', [
            'expires_in' => (int)$policy['code_ttl_seconds'],
            'target' => $this->maskTarget($channel, $target),
        ]);
    }

    public function loginWithCode(string $channel, string $target, string $code): array
    {
        $channel = $this->normalizeChannel($channel);
        $target = $this->normalizeTarget($channel, $target);
        $code = trim($code);
        if ($target === '' || $code === '') {
            return $this->result(false, 'INVALID_SECURITY_CODE_INPUT', 'Target and security code are required.');
        }
        if (!$this->policyService->codeLoginEnabled($channel)) {
            return $this->result(false, 'SECURITY_CODE_LOGIN_DISABLED', 'Security-code login is disabled.');
        }
        if (!$this->tableExists(self::TABLE)) {
            return $this->result(false, 'SECURITY_CODE_TABLE_MISSING', 'Security-code table is missing; run migrations first.');
        }

        $row = $this->latestActiveCode($channel, $target, self::PURPOSE_LOGIN);
        if (!$row) {
            return $this->result(false, 'SECURITY_CODE_NOT_FOUND', 'Security code is invalid or expired.');
        }
        $now = time();
        if ((int)$row['lock_until'] > $now) {
            return $this->result(false, 'SECURITY_CODE_LOCKED', 'Too many attempts. Please try later.', ['verify_status' => self::VERIFY_LOCKED]);
        }
        if ((int)$row['expires_at'] < $now) {
            $this->markVerify($row, self::VERIFY_EXPIRED, 'Security code expired.');
            return $this->result(false, 'SECURITY_CODE_EXPIRED', 'Security code expired.', ['verify_status' => self::VERIFY_EXPIRED]);
        }
        if (!Yii::$app->security->validatePassword($code, (string)$row['code_hash'])) {
            $attempts = (int)$row['attempt_count'] + 1;
            $lockUntil = $attempts >= (int)$row['max_attempts'] ? $now + ((int)$row['lock_minutes'] * 60) : 0;
            $this->markAttempt($row, $attempts, $lockUntil);
            return $this->result(false, 'SECURITY_CODE_INVALID', 'Security code is invalid.', [
                'verify_status' => $lockUntil > 0 ? self::VERIFY_LOCKED : self::VERIFY_FAIL,
                'attempt_count' => $attempts,
            ]);
        }

        $user = User::findOne((int)$row['user_id']);
        if (!$user || (int)$user->status !== BaseModel::STATUS_ACTIVE) {
            $this->markVerify($row, self::VERIFY_FAIL, 'Target user unavailable.');
            return $this->result(false, 'SECURITY_CODE_USER_UNAVAILABLE', 'Target user is unavailable.');
        }

        Yii::$app->user->login($user, 3600 * 24 * 30);
        $this->markConsumed($row);

        return $this->result(true, 'SECURITY_CODE_LOGIN_SUCCESS', 'Security-code login successful.', [
            'user_id' => (int)$user->id,
            'verify_status' => self::VERIFY_PASS,
        ]);
    }

    public function runtimeReadiness(): array
    {
        $policy = $this->policyService->runtimePolicy();
        return [
            'version' => self::VERSION,
            'table' => self::TABLE,
            'table_exists' => $this->tableExists(self::TABLE),
            'email_code_login_enabled' => (string)($policy['email_code_login_enabled'] ?? '0'),
            'mobile_code_login_enabled' => (string)($policy['mobile_code_login_enabled'] ?? '0'),
            'hash_policy' => 'security_code_hash_only_no_plaintext',
            'mobile_delivery' => 'reserved_until_sms_or_app_provider_evidence',
        ];
    }

    private function recordCode(string $channel, string $target, string $purpose, string $plainCode, array $policy, string $deliveryStatus, string $errorSummary = '', int $userId = 0): int
    {
        $now = time();
        $ttl = (int)$policy['code_ttl_seconds'];
        $codeHash = $plainCode !== '' ? Yii::$app->security->generatePasswordHash($plainCode) : '';
        Yii::$app->db->createCommand()->insert(self::TABLE, [
            'store_id' => $this->storeIdForUser($userId),
            'user_id' => $userId,
            'channel' => $channel,
            'purpose' => $purpose,
            'target_hash' => $this->targetHash($channel, $target),
            'target_masked' => $this->maskTarget($channel, $target),
            'code_hash' => $codeHash,
            'expires_at' => $ttl > 0 ? $now + $ttl : $now + 600,
            'attempt_count' => 0,
            'max_attempts' => (int)$policy['max_attempts'],
            'lock_minutes' => (int)$policy['lock_minutes'],
            'lock_until' => 0,
            'delivery_status' => $deliveryStatus,
            'verify_status' => '',
            'error_summary' => $errorSummary,
            'consumed_at' => 0,
            'sent_at' => $deliveryStatus === self::DELIVERY_SENT ? $now : 0,
            'sort' => 50,
            'status' => BaseModel::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $userId > 0 ? $userId : 1,
            'updated_by' => $userId > 0 ? $userId : 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function sendEmailCode(string $email, string $code, int $ttlSeconds): array
    {
        try {
            $minutes = max(1, (int)ceil($ttlSeconds / 60));
            $ok = Yii::$app->mailer->compose()
                ->setTo($email)
                ->setSubject('Mongoyia security code')
                ->setTextBody("Your Mongoyia security code is {$code}. It expires in {$minutes} minutes.")
                ->send();
            if (!$ok) {
                return ['success' => false, 'message' => 'Mailer returned false while sending security code.'];
            }
            return ['success' => true, 'message' => 'Security code email sent.'];
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.account_security_code.mail_failed');
            return ['success' => false, 'message' => 'Security-code email delivery failed: ' . $e->getMessage()];
        }
    }

    private function updateDelivery(int $rowId, string $deliveryStatus, string $errorSummary): void
    {
        if ($rowId <= 0) {
            return;
        }

        Yii::$app->db->createCommand()->update(self::TABLE, [
            'delivery_status' => $deliveryStatus,
            'error_summary' => $errorSummary,
            'updated_at' => time(),
        ], ['id' => $rowId])->execute();
    }

    private function latestActiveCode(string $channel, string $target, string $purpose): array
    {
        $row = (new \yii\db\Query())
            ->from(self::TABLE)
            ->where([
                'channel' => $channel,
                'purpose' => $purpose,
                'target_hash' => $this->targetHash($channel, $target),
                'delivery_status' => self::DELIVERY_SENT,
                'status' => BaseModel::STATUS_ACTIVE,
                'consumed_at' => 0,
            ])
            ->andWhere(['<>', 'code_hash', ''])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db);

        return $row ?: [];
    }

    private function markAttempt(array $row, int $attempts, int $lockUntil): void
    {
        Yii::$app->db->createCommand()->update(self::TABLE, [
            'attempt_count' => $attempts,
            'lock_until' => $lockUntil,
            'verify_status' => $lockUntil > 0 ? self::VERIFY_LOCKED : self::VERIFY_FAIL,
            'updated_at' => time(),
        ], ['id' => (int)$row['id']])->execute();
    }

    private function markVerify(array $row, string $status, string $errorSummary): void
    {
        Yii::$app->db->createCommand()->update(self::TABLE, [
            'verify_status' => $status,
            'error_summary' => $errorSummary,
            'updated_at' => time(),
        ], ['id' => (int)$row['id']])->execute();
    }

    private function markConsumed(array $row): void
    {
        Yii::$app->db->createCommand()->update(self::TABLE, [
            'verify_status' => self::VERIFY_PASS,
            'consumed_at' => time(),
            'updated_at' => time(),
        ], ['id' => (int)$row['id']])->execute();
    }

    private function findUser(string $channel, string $target)
    {
        $query = User::find()->where(['status' => BaseModel::STATUS_ACTIVE]);
        if ($channel === self::CHANNEL_MOBILE) {
            return $query->andWhere(['mobile' => $target])->one();
        }

        return $query->andWhere(['email' => $target])->one();
    }

    private function generateCode(int $length): string
    {
        $length = max(4, min(8, $length));
        $min = (int)pow(10, $length - 1);
        $max = (int)pow(10, $length) - 1;
        return (string)random_int($min, $max);
    }

    private function targetHash(string $channel, string $target): string
    {
        return hash('sha256', $channel . '|' . strtolower($target) . '|' . Yii::$app->id);
    }

    private function maskTarget(string $channel, string $target): string
    {
        if ($channel === self::CHANNEL_EMAIL && strpos($target, '@') !== false) {
            [$name, $domain] = explode('@', $target, 2);
            return substr($name, 0, 2) . '***@' . $domain;
        }

        return substr($target, 0, 3) . '****' . substr($target, -2);
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        return $channel === self::CHANNEL_MOBILE ? self::CHANNEL_MOBILE : self::CHANNEL_EMAIL;
    }

    private function normalizePurpose(string $purpose): string
    {
        $purpose = strtolower(trim($purpose));
        return $purpose !== '' ? $purpose : self::PURPOSE_LOGIN;
    }

    private function normalizeTarget(string $channel, string $target): string
    {
        $target = trim($target);
        if ($channel === self::CHANNEL_EMAIL) {
            $target = strtolower($target);
            return filter_var($target, FILTER_VALIDATE_EMAIL) ? $target : '';
        }

        return preg_match('/^[0-9+\-\s]{6,32}$/', $target) ? preg_replace('/\s+/', '', $target) : '';
    }

    private function storeIdForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $row = User::find()->select(['store_id'])->where(['id' => $userId])->asArray()->one();
        return (int)($row['store_id'] ?? 0);
    }

    private function result(bool $success, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'success' => $success,
            'code' => $code,
            'message' => $message,
        ], $extra);
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
