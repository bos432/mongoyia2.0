<?php

namespace console\controllers;

use common\models\BaseModel;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceAcceptanceFixtureController extends Controller
{
    public $apply = false;
    public $platformUsername = 'codex_platform_backend_test_5';
    public $platformPassword = 'CodexTest123';
    public $platformStoreId = 5;
    public $platformRoleId = 55;
    public $sellerUsername = 'zhishichanquan';
    public $sellerPassword = '123456';
    public $sellerRoleId = 50;
    public $sellerStoreId = 0;
    public $createSellerStore = true;
    public $sellerStoreName = 'Codex Customer Service Acceptance Store';

    private $failures = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'apply',
            'platformUsername',
            'platformPassword',
            'platformStoreId',
            'platformRoleId',
            'sellerUsername',
            'sellerPassword',
            'sellerRoleId',
            'sellerStoreId',
            'createSellerStore',
            'sellerStoreName',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service acceptance fixture\n");
        $this->stdout('Mode: ' . ($this->apply ? 'apply' : 'dry-run') . "\n");

        $this->ensurePlatformUser();
        $this->ensureSellerUser();
        $this->clearRoleCache();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->pending} pending change(s).\n");
        if ($this->failures > 0) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function ensurePlatformUser(): void
    {
        $user = $this->userByUsername((string)$this->platformUsername);
        if (!$user) {
            $this->pending("Create platform customer-service acceptance user '{$this->platformUsername}'.");
            if ($this->apply) {
                $id = $this->createUser((string)$this->platformUsername, (string)$this->platformPassword, (int)$this->platformStoreId, 'platform');
                if ($id <= 0) {
                    return;
                }
                $user = $this->userById($id);
                $this->ok("Created platform customer-service acceptance user '{$this->platformUsername}' with id {$id}.");
            }
        }

        if (!$user) {
            return;
        }

        $this->ensureUserLoginState($user, (string)$this->platformPassword, (int)$this->platformStoreId, 'platform acceptance user');
        $this->ensureRole((int)$user['id'], (int)$this->platformStoreId, (int)$this->platformRoleId, 'platform acceptance user');
    }

    private function ensureSellerUser(): void
    {
        $user = $this->userByUsername((string)$this->sellerUsername);
        if (!$user) {
            $this->pending("Create seller customer-service acceptance user '{$this->sellerUsername}'.");
            if ($this->apply) {
                $id = $this->createUser((string)$this->sellerUsername, (string)$this->sellerPassword, 0, 'seller');
                if ($id <= 0) {
                    return;
                }
                $user = $this->userById($id);
                $this->ok("Created seller customer-service acceptance user '{$this->sellerUsername}' with id {$id}.");
            }
        }

        if (!$user) {
            $this->pending('Create seller acceptance store after the seller user exists.');
            return;
        }

        $storeId = $this->sellerStoreId((int)$user['id'], (int)($user['store_id'] ?? 0));
        if ($storeId <= 0) {
            if (!$this->createSellerStore) {
                $this->fail("Seller customer-service acceptance user '{$this->sellerUsername}' has no non-platform store. Pass --sellerStoreId=<id> or allow --createSellerStore=1.");
                return;
            }

            $this->pending("Create seller customer-service acceptance store for user {$user['id']}.");
            if ($this->apply) {
                $storeId = $this->createSellerStore((int)$user['id']);
                if ($storeId <= 0) {
                    return;
                }
                $this->ok("Created seller customer-service acceptance store {$storeId} for user {$user['id']}.");
            }
        }

        if ($storeId <= 0) {
            return;
        }

        $this->ensureUserLoginState($user, (string)$this->sellerPassword, $storeId, 'seller acceptance user');
        $this->ensureRole((int)$user['id'], $storeId, (int)$this->sellerRoleId, 'seller acceptance user');
    }

    private function ensureUserLoginState(array $user, string $password, int $storeId, string $label): void
    {
        $updates = [];
        if ((int)$user['status'] !== BaseModel::STATUS_ACTIVE) {
            $updates['status'] = BaseModel::STATUS_ACTIVE;
        }
        if ((int)($user['store_id'] ?? 0) !== $storeId) {
            $updates['store_id'] = $storeId;
        }
        if ($password !== '') {
            $updates['password_hash'] = Yii::$app->security->generatePasswordHash($password);
        }

        if (!$updates) {
            $this->ok("{$label} {$user['id']} already has active login state.");
            return;
        }

        $this->pending("Update {$label} {$user['id']} login fields: " . implode(', ', array_keys($updates)) . '.');
        if (!$this->apply) {
            return;
        }

        $updates['updated_at'] = time();
        Yii::$app->db->createCommand()->update('{{%user}}', $updates, ['id' => (int)$user['id']])->execute();
        $this->ok("Updated {$label} {$user['id']} login fields.");
    }

    private function createUser(string $username, string $password, int $storeId, string $label): int
    {
        $now = time();
        $row = [
            'store_id' => $storeId,
            'parent_id' => 0,
            'username' => $username,
            'auth_key' => Yii::$app->security->generateRandomString(32),
            'password_hash' => Yii::$app->security->generatePasswordHash($password),
            'email' => $username . '@acceptance.local',
            'auth_role' => 1,
            'name' => $username,
            'type' => BaseModel::TYPE_DEFAULT,
            'sort' => BaseModel::SORT_DEFAULT,
            'status' => BaseModel::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ];

        try {
            Yii::$app->db->createCommand()->insert('{{%user}}', $row)->execute();
        } catch (\Throwable $e) {
            $this->fail("Failed to create {$label} user '{$username}': {$e->getMessage()}");
            return 0;
        }

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function ensureRole(int $userId, int $storeId, int $roleId, string $label): void
    {
        $role = (new \yii\db\Query())
            ->from('{{%base_role}}')
            ->where(['id' => $roleId, 'status' => BaseModel::STATUS_ACTIVE])
            ->one(Yii::$app->db);
        if (!$role) {
            $this->fail("{$label} role {$roleId} is missing or inactive.");
            return;
        }

        $userRole = (new \yii\db\Query())
            ->from('{{%base_user_role}}')
            ->where(['user_id' => $userId, 'role_id' => $roleId])
            ->one(Yii::$app->db);

        if ($userRole && (int)$userRole['status'] === BaseModel::STATUS_ACTIVE && (int)$userRole['store_id'] === $storeId) {
            $this->ok("{$label} {$userId} already has role {$roleId}.");
            return;
        }

        $this->pending("Grant role {$roleId} to {$label} {$userId}.");
        if (!$this->apply) {
            return;
        }

        $now = time();
        if ($userRole) {
            Yii::$app->db->createCommand()->update('{{%base_user_role}}', [
                'store_id' => $storeId,
                'status' => BaseModel::STATUS_ACTIVE,
                'updated_at' => $now,
            ], ['id' => (int)$userRole['id']])->execute();
        } else {
            Yii::$app->db->createCommand()->insert('{{%base_user_role}}', [
                'store_id' => $storeId,
                'user_id' => $userId,
                'role_id' => $roleId,
                'type' => BaseModel::TYPE_DEFAULT,
                'sort' => BaseModel::SORT_DEFAULT,
                'status' => BaseModel::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ])->execute();
        }

        $this->ok("Granted role {$roleId} to {$label} {$userId}.");
    }

    private function sellerStoreId(int $userId, int $fallbackStoreId): int
    {
        if ((int)$this->sellerStoreId > 0 && !$this->isPlatformStoreId((int)$this->sellerStoreId)) {
            return (int)$this->sellerStoreId;
        }

        $storeId = (new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['user_id' => $userId])
            ->andWhere(['not in', 'id', $this->platformStoreIds()])
            ->andWhere(['>', 'id', 0])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);

        if ($storeId) {
            return (int)$storeId;
        }

        return ($fallbackStoreId > 0 && !$this->isPlatformStoreId($fallbackStoreId)) ? $fallbackStoreId : 0;
    }

    private function createSellerStore(int $userId): int
    {
        $now = time();
        $row = [
            'parent_id' => 0,
            'user_id' => $userId,
            'name' => (string)$this->sellerStoreName,
            'brief' => 'Phase 8 customer-service acceptance store',
            'host_name' => '',
            'code' => 'cs_accept_' . $userId,
            'qrcode' => '',
            'logo' => '',
            'route' => 'mall',
            'expired_at' => strtotime('+10 years'),
            'remark' => 'Created by customer-service-acceptance-fixture/run',
            'language' => 32767,
            'lang_source' => 'zh-CN',
            'lang_frontend' => 32767,
            'lang_frontend_default' => '',
            'lang_backend' => 32767,
            'lang_backend_default' => '',
            'lang_api' => 32767,
            'lang_api_default' => '',
            'fund' => 0,
            'fund_amount' => 0,
            'billable_fund' => 0,
            'income' => 0,
            'income_amount' => 0,
            'income_count' => 0,
            'consume_count' => 0,
            'consume_amount' => 0,
            'history_amount' => 0,
            'param1' => '',
            'param2' => '',
            'param3' => '',
            'param4' => 0,
            'param5' => 0,
            'param6' => 0,
            'chain' => '',
            'grade' => 0,
            'type' => BaseModel::TYPE_DEFAULT,
            'sort' => BaseModel::SORT_DEFAULT,
            'status' => BaseModel::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ];

        $row = $this->filterTableColumns('{{%store}}', $row);

        try {
            Yii::$app->db->createCommand()->insert('{{%store}}', $row)->execute();
        } catch (\Throwable $e) {
            $this->fail("Failed to create seller acceptance store: {$e->getMessage()}");
            return 0;
        }

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function filterTableColumns(string $table, array $row): array
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        if (!$schema) {
            return $row;
        }

        return array_intersect_key($row, $schema->columns);
    }

    private function isPlatformStoreId(int $storeId): bool
    {
        return in_array($storeId, $this->platformStoreIds(), true);
    }

    private function platformStoreIds(): array
    {
        $configured = trim((string)(Yii::$app->params['mallPlatformOperatorStoreIds'] ?? ''));
        if ($configured === '') {
            $configured = trim((string)(Yii::$app->params['mallPlatformStoreIds'] ?? ''));
        }

        $storeIds = $configured === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $configured))));
        if (!$storeIds) {
            $storeIds[] = (int)(Yii::$app->params['defaultStoreId'] ?? 0);
        }

        return array_values(array_unique(array_filter($storeIds)));
    }

    private function userByUsername(string $username): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%user}}')
            ->where(['username' => $username])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function userById(int $id): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%user}}')
            ->where(['id' => $id])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function clearRoleCache(): void
    {
        if (!$this->apply || !isset(Yii::$app->cacheSystem)) {
            return;
        }

        Yii::$app->cacheSystem->clearAllUserRole();
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function pending(string $message): void
    {
        $this->pending++;
        $this->stdout(($this->apply ? 'APPLY ' : 'TODO  ') . $message . "\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
