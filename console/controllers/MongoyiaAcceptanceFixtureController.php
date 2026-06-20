<?php

namespace console\controllers;

use common\models\BaseModel;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaAcceptanceFixtureController extends Controller
{
    public $apply = false;
    public $failOnPending = false;
    public $platformStoreId = 5;
    public $platformUsername = 'codex_platform_backend_test_5';
    public $platformPassword = 'CodexTest123';
    public $platformRoleId = 55;
    public $paymentUserId = 71;
    public $paymentStoreId = 5;
    public $paymentUsername = 'codex_payment_test_71';
    public $paymentPassword = 'CodexPay123';
    public $paymentRoleId = 100;

    private $failures = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'apply',
            'failOnPending',
            'platformStoreId',
            'platformUsername',
            'platformPassword',
            'platformRoleId',
            'paymentUserId',
            'paymentStoreId',
            'paymentUsername',
            'paymentPassword',
            'paymentRoleId',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia acceptance fixture\n");
        $this->stdout('Mode: ' . ($this->apply ? 'apply' : 'dry-run') . "\n");

        // Create fixed-id fixtures first so auto-increment users cannot occupy
        // an acceptance id such as the payment regression user.
        $this->ensureUser([
            'label' => 'payment frontend user',
            'id' => (int)$this->paymentUserId,
            'username' => $this->paymentUsername,
            'password' => $this->paymentPassword,
            'store_id' => (int)$this->paymentStoreId,
            'role_id' => (int)$this->paymentRoleId,
        ]);

        $this->ensureUser([
            'label' => 'platform backend user',
            'username' => $this->platformUsername,
            'password' => $this->platformPassword,
            'store_id' => (int)$this->platformStoreId,
            'role_id' => (int)$this->platformRoleId,
        ]);

        $this->clearRoleCache();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->pending} pending change(s).\n");
        if ($this->failures > 0) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->apply && $this->failOnPending && $this->pending > 0) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function ensureUser(array $fixture)
    {
        $label = $fixture['label'];
        $user = null;

        if (!empty($fixture['id'])) {
            $user = $this->one('{{%user}}', ['id' => (int)$fixture['id']]);
            if ($user && (string)$user['username'] !== (string)$fixture['username']) {
                $this->fail("{$label} id {$fixture['id']} is occupied by '{$user['username']}', expected '{$fixture['username']}'.");
                return;
            }
        }

        if (!$user) {
            $user = $this->one('{{%user}}', ['username' => $fixture['username']]);
        }

        if (!$user) {
            $this->pending("Create {$label} '{$fixture['username']}'.");
            if ($this->apply) {
                $id = $this->createUser($fixture);
                if (!$id) {
                    return;
                }
                $user = $this->one('{{%user}}', ['id' => $id]);
                $this->ok("Created {$label} '{$fixture['username']}' with id {$id}.");
            }
        } else {
            $updates = [];
            if ((int)$user['store_id'] !== (int)$fixture['store_id']) {
                $updates['store_id'] = (int)$fixture['store_id'];
            }
            if ((int)$user['status'] !== BaseModel::STATUS_ACTIVE) {
                $updates['status'] = BaseModel::STATUS_ACTIVE;
            }
            if (trim((string)$user['password_hash']) === '') {
                $updates['password_hash'] = Yii::$app->security->generatePasswordHash($fixture['password']);
            }

            if ($updates) {
                $this->pending("Update {$label} '{$fixture['username']}' fields: " . implode(', ', array_keys($updates)) . '.');
                if ($this->apply) {
                    $updates['updated_at'] = time();
                    Yii::$app->db->createCommand()->update('{{%user}}', $updates, ['id' => (int)$user['id']])->execute();
                    $this->ok("Updated {$label} '{$fixture['username']}'.");
                    $user = $this->one('{{%user}}', ['id' => (int)$user['id']]);
                }
            } else {
                $this->ok("{$label} '{$fixture['username']}' exists with id {$user['id']}.");
            }
        }

        if ($user && !empty($fixture['role_id'])) {
            $this->ensureRole((int)$user['id'], (int)$fixture['store_id'], (int)$fixture['role_id'], $label);
        }
    }

    private function createUser(array $fixture)
    {
        $now = time();
        $row = [
            'store_id' => (int)$fixture['store_id'],
            'parent_id' => 0,
            'username' => (string)$fixture['username'],
            'auth_key' => Yii::$app->security->generateRandomString(32),
            'password_hash' => Yii::$app->security->generatePasswordHash($fixture['password']),
            'email' => $fixture['username'] . '@acceptance.local',
            'auth_role' => 1,
            'name' => (string)$fixture['username'],
            'type' => BaseModel::TYPE_DEFAULT,
            'sort' => BaseModel::SORT_DEFAULT,
            'status' => BaseModel::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ];

        if (!empty($fixture['id'])) {
            $row['id'] = (int)$fixture['id'];
        }

        try {
            Yii::$app->db->createCommand()->insert('{{%user}}', $row)->execute();
        } catch (\Throwable $e) {
            $this->fail("Failed to create {$fixture['label']} '{$fixture['username']}': {$e->getMessage()}");
            return 0;
        }

        return (int)($fixture['id'] ?? Yii::$app->db->getLastInsertID());
    }

    private function ensureRole(int $userId, int $storeId, int $roleId, string $label)
    {
        $role = $this->one('{{%base_role}}', ['id' => $roleId, 'status' => BaseModel::STATUS_ACTIVE]);
        if (!$role) {
            $this->fail("{$label} role {$roleId} is missing or inactive.");
            return;
        }

        $userRole = $this->one('{{%base_user_role}}', ['user_id' => $userId, 'role_id' => $roleId]);
        if ($userRole && (int)$userRole['status'] === BaseModel::STATUS_ACTIVE) {
            $this->ok("{$label} user {$userId} has role {$roleId}.");
            return;
        }

        $this->pending("Grant role {$roleId} to {$label} user {$userId}.");
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

        $this->ok("Granted role {$roleId} to {$label} user {$userId}.");
    }

    private function clearRoleCache()
    {
        if (!$this->apply || !isset(Yii::$app->cacheSystem)) {
            return;
        }

        Yii::$app->cacheSystem->clearAllUserRole();
    }

    private function one(string $table, array $where)
    {
        return (new \yii\db\Query())->from($table)->where($where)->one(Yii::$app->db);
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function pending(string $message)
    {
        $this->pending++;
        $this->stdout(($this->apply ? 'APPLY ' : 'TODO  ') . $message . "\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
