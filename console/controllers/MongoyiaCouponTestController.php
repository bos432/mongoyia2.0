<?php

namespace console\controllers;

use common\models\mall\Coupon;
use common\models\mall\CouponType;
use common\models\mall\StoreCouponParticipation;
use common\models\mall\UserCoupon;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaCouponTestController extends Controller
{
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia coupon closure test\n");

        $this->checkSchemas();
        $this->checkModels();
        $this->checkFrontendEntrances();
        $this->checkBackendEntrances();
        $this->checkMerchantCouponClosure();
        $this->checkDataReadiness();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchemas()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_coupon_type}}', ['id', 'store_id', 'name', 'money', 'min_amount', 'max_times', 'started_at', 'ended_at', 'status']);
        $this->requireColumns('{{%mall_coupon}}', ['id', 'store_id', 'user_id', 'coupon_type_id', 'order_id', 'used_at', 'status']);
        $this->requireColumns('{{%mall_user_coupon}}', ['id', 'uid', 'cid']);
        $this->requireColumns('{{%store_coupon_participation}}', ['id', 'store_id', 'coupon_type_id', 'participation_status', 'joined_at', 'left_at', 'status']);
    }

    private function checkModels()
    {
        $this->section('Models');
        foreach ([CouponType::class, Coupon::class, UserCoupon::class, StoreCouponParticipation::class] as $class) {
            if (!class_exists($class)) {
                $this->fail("Missing model {$class}.");
                continue;
            }
            $this->ok("Model exists: {$class}");
        }

        ob_start();
        $count = UserCoupon::get_times(0);
        $output = ob_get_clean();
        if ($output !== '') {
            $this->fail('UserCoupon::get_times() must not print debug output.');
        } elseif (!is_int($count)) {
            $this->fail('UserCoupon::get_times() must return an integer count.');
        } else {
            $this->ok('UserCoupon::get_times() returns a clean integer count.');
        }
    }

    private function checkFrontendEntrances()
    {
        $this->section('Frontend entrances');
        $this->requireFileContains('@app/../web/resources/mall/default/views/cart/checkout.php', ['coupon-code', 'coupon-btn']);
        $this->requireFileContains('@app/../web/resources/mall/default/views/layouts/nav.php', ['/mall/user/coupon']);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/UserController.php', [
            'MONGOYIA_USER_COUPON_CLAIM_POST_GUARD_V1',
            "'getcode' => ['POST']",
            "post('cid', 0)",
            'exists(Yii::$app->db)',
            '{{%mall_user_coupon}}',
            'actionCoupon',
        ]);
        $this->requireFileNotContains('@app/../frontend/modules/mall/controllers/UserController.php', [
            '$count($count)',
            "request->get('cid')",
            'fb_mall_user_coupon',
        ]);
    }

    private function checkBackendEntrances()
    {
        $this->section('Backend entrances');
        $this->requireFileContains('@app/../backend/modules/mall/controllers/CouponTypeController.php', ['actionFhAjax', 'fb_mall_user_coupon']);
        $this->requireFileContains('@app/../backend/modules/mall/views/coupon-type/index.php', ['Coupon']);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/MerchantCouponController.php', [
            'MONGOYIA_MERCHANT_COUPON_POST_VERB_GUARD_V1',
            "'join'] = ['post']",
            "'leave'] = ['post']",
            "post('coupon_type_id', 0)",
            'actionIndex',
            'actionJoin',
            'actionLeave',
            'StoreCouponParticipation',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/merchant-coupon/index.php', [
            '商家优惠券',
            '平台券参与',
            '领取/使用记录',
            'data-mongoyia-merchant-coupon-post-guard',
            'csrfToken',
        ]);
    }

    private function checkMerchantCouponClosure()
    {
        $this->section('Merchant coupon participation');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/merchant-coupon/index', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active backend permission /mall/merchant-coupon/index. Run migration m260618_162000_mongoyia_merchant_coupon_participation.');
            return;
        }
        $this->ok('Permission exists: /mall/merchant-coupon/index');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 is missing merchant coupon permission.');
            return;
        }
        $this->ok('Seller role can access merchant coupon page.');

        $this->runParticipationFixture();
    }

    private function runParticipationFixture()
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $now = time();
            $storeId = $this->firstSellerStoreId();
            $couponTypeId = $this->insertRow('{{%mall_coupon_type}}', [
                'store_id' => 5,
                'name' => 'COUPONFIX Platform Coupon ' . date('YmdHis'),
                'money' => '5',
                'min_amount' => 10,
                'max_times' => 100,
                'started_at' => $now,
                'ended_at' => $now + 86400,
                'sn' => 'COUPONFIX',
                'type' => CouponType::TYPE_FIXED,
                'status' => CouponType::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);

            $participation = new StoreCouponParticipation();
            $participation->store_id = $storeId;
            $participation->coupon_type_id = $couponTypeId;
            $participation->participation_status = StoreCouponParticipation::PARTICIPATION_JOINED;
            $participation->joined_at = $now;
            $participation->status = StoreCouponParticipation::STATUS_ACTIVE;
            if (!$participation->save()) {
                throw new \RuntimeException(json_encode($participation->errors, JSON_UNESCAPED_UNICODE));
            }
            $this->ok('Merchant can join a platform coupon in fixture flow.');

            $participation->participation_status = StoreCouponParticipation::PARTICIPATION_LEFT;
            $participation->left_at = $now + 1;
            $participation->status = StoreCouponParticipation::STATUS_INACTIVE;
            if (!$participation->save()) {
                throw new \RuntimeException(json_encode($participation->errors, JSON_UNESCAPED_UNICODE));
            }
            $this->ok('Merchant can leave a platform coupon in fixture flow.');

            $transaction->rollBack();
            $this->ok('Merchant coupon fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Merchant coupon fixture failed: ' . $e->getMessage());
        }
    }

    private function checkDataReadiness()
    {
        $this->section('Data readiness');
        $typeCount = (int)(new \yii\db\Query())->from('{{%mall_coupon_type}}')->count('*', Yii::$app->db);
        $activeTypeCount = (int)(new \yii\db\Query())->from('{{%mall_coupon_type}}')->where(['>', 'status', 0])->count('*', Yii::$app->db);
        $userCouponCount = (int)(new \yii\db\Query())->from('{{%mall_user_coupon}}')->count('*', Yii::$app->db);
        $participationCount = (int)(new \yii\db\Query())->from('{{%store_coupon_participation}}')->count('*', Yii::$app->db);

        if ($typeCount === 0) {
            $this->warn('No coupon type data found; settlement calculation can be smoke-tested but not business-tested.');
        } elseif ($activeTypeCount === 0) {
            $this->warn("Coupon type table has {$typeCount} row(s), but none are active.");
        } else {
            $this->ok("Coupon type data exists: {$activeTypeCount} active / {$typeCount} total.");
        }
        $this->ok("User coupon claim rows: {$userCouponCount}.");
        $this->ok("Store coupon participation rows: {$participationCount}.");
    }

    private function firstSellerStoreId(): int
    {
        $storeId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'id', 0])
            ->andWhere(['>', 'status', 0])
            ->andWhere(['not in', 'id', [5]])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
        if ($storeId <= 0) {
            throw new \RuntimeException('No seller store is available for merchant coupon fixture.');
        }

        return $storeId;
    }

    private function insertRow(string $table, array $values): int
    {
        $values = $this->normalizeInsertValues($table, $values);
        Yii::$app->db->createCommand()->insert($table, $values)->execute();
        return (int)Yii::$app->db->getLastInsertID();
    }

    private function normalizeInsertValues(string $table, array $values): array
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
        if (!$schema) {
            throw new \RuntimeException("Missing table {$table}.");
        }

        foreach ($schema->columns as $name => $column) {
            if ($column->autoIncrement || array_key_exists($name, $values)) {
                continue;
            }
            if ($column->defaultValue !== null) {
                $values[$name] = $column->defaultValue;
                continue;
            }
            if ($column->allowNull) {
                $values[$name] = null;
                continue;
            }
            $values[$name] = in_array($column->type, ['integer', 'bigint', 'smallint', 'decimal', 'float', 'double'], true) ? 0 : '';
        }

        return array_intersect_key($values, $schema->columns);
    }

    private function requireColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema) {
            $this->fail("Missing table {$table}.");
            return;
        }

        foreach ($columns as $column) {
            if (!isset($schema->columns[$column])) {
                $this->fail("Table {$table} missing column {$column}.");
                return;
            }
        }
        $this->ok("Table {$table} has required columns.");
    }

    private function requireFileContains(string $alias, array $needles)
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function requireFileNotContains(string $alias, array $needles)
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                $this->fail("File {$path} still contains stale marker '{$needle}'.");
                return;
            }
        }
        $this->ok("File has no stale markers: {$path}");
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
