<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MerchantOnboardingTestController extends Controller
{
    public $cleanup = true;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;
    private $createdStoreId = 0;
    private $createdApplicationId = 0;
    private $createdCategoryId = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'cleanup',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia merchant onboarding Phase 2 test\n");

        $this->checkSchemas();
        if ($this->failures === 0) {
            $this->checkSellerSubmissionEntrance();
            $this->runFixtureFlow();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchemas()
    {
        $this->section('Schema');
        $this->requireColumns('{{%merchant_application}}', [
            'id',
            'store_id',
            'user_id',
            'applicant_name',
            'company_name',
            'requested_category_ids',
            'audit_status',
            'audit_remark',
            'submitted_at',
            'reviewed_at',
            'reviewer_id',
            'status',
        ]);
        $this->requireColumns('{{%store_category_auth}}', [
            'id',
            'store_id',
            'category_id',
            'source_application_id',
            'audit_status',
            'authorized_at',
            'status',
        ]);
        $this->requireColumns('{{%store}}', [
            'id',
            'name',
            'name_en',
            'name_mn',
            'brief',
            'brief_en',
            'brief_mn',
            'main_products',
            'logo',
            'contact_name',
            'contact_phone',
            'business_hours',
        ]);
        $this->requireColumns('{{%mall_product}}', [
            'id',
            'store_id',
            'category_id',
            'audit_status',
            'audit_remark',
            'reviewed_at',
            'reviewer_id',
        ]);
    }

    private function runFixtureFlow()
    {
        $this->section('Fixture flow');
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $now = time();
            $suffix = date('YmdHis');
            $this->createdCategoryId = $this->insertRow('{{%mall_category}}', [
                'store_id' => 5,
                'parent_id' => 0,
                'name' => 'MERCHFIX Category ' . $suffix,
                'brief' => 'Phase 2 onboarding fixture category',
                'is_nav' => 0,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);

            $userId = $this->existingUserId();
            $this->createdStoreId = $this->insertRow('{{%store}}', [
                'parent_id' => 0,
                'user_id' => $userId,
                'name' => 'MERCHFIX Store ' . $suffix,
                'name_en' => 'MERCHFIX Store EN',
                'name_mn' => 'MERCHFIX Store MN',
                'brief' => 'Phase 2 onboarding fixture store',
                'brief_en' => 'Phase 2 onboarding fixture store EN',
                'brief_mn' => 'Phase 2 onboarding fixture store MN',
                'main_products' => 'test goods',
                'host_name' => '',
                'code' => 'MERCHFIX-' . $suffix,
                'qrcode' => '',
                'logo' => '',
                'route' => 'mall',
                'expired_at' => $now + 86400,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
                'contact_name' => 'Fixture Contact',
                'contact_phone' => '000000',
                'business_hours' => '09:00-18:00',
            ]);

            $this->createdApplicationId = $this->insertRow('{{%merchant_application}}', [
                'store_id' => 0,
                'user_id' => $userId,
                'applicant_name' => 'MERCHFIX Applicant',
                'mobile' => '000000',
                'email' => 'merchant-fixture@example.invalid',
                'company_name' => 'MERCHFIX Company ' . $suffix,
                'business_license' => 'MERCHFIX-LICENSE',
                'requested_category_ids' => json_encode([$this->createdCategoryId]),
                'audit_status' => 'submitted',
                'submitted_at' => $now,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);

            $this->ok("Created submitted merchant application #{$this->createdApplicationId}.");

            $submitted = (new \yii\db\Query())
                ->from('{{%merchant_application}}')
                ->where(['id' => $this->createdApplicationId, 'audit_status' => 'submitted'])
                ->one($db);
            if (!$submitted || (int)$submitted['user_id'] !== $userId) {
                $this->fail('Seller-submitted merchant application sample is invalid.');
                return;
            }
            $this->ok('Seller-submitted merchant application sample is valid.');

            $db->createCommand()->update('{{%merchant_application}}', [
                'store_id' => $this->createdStoreId,
                'audit_status' => 'approved',
                'audit_remark' => 'Phase 2 fixture approved',
                'reviewed_at' => $now,
                'reviewer_id' => 1,
                'updated_at' => $now,
                'updated_by' => 1,
            ], ['id' => $this->createdApplicationId])->execute();

            $this->insertRow('{{%store_category_auth}}', [
                'store_id' => $this->createdStoreId,
                'category_id' => $this->createdCategoryId,
                'source_application_id' => $this->createdApplicationId,
                'audit_status' => 'approved',
                'audit_remark' => 'Phase 2 fixture category authorization',
                'authorized_at' => $now,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);

            $this->assertFixtureState();

            if ($this->cleanup) {
                $transaction->rollBack();
                $this->ok('Fixture data rolled back by default cleanup.');
            } else {
                $transaction->commit();
                $this->warn('Fixture data was committed because --cleanup=0 was used.');
            }
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Fixture flow failed: ' . $e->getMessage());
        }
    }

    private function checkSellerSubmissionEntrance()
    {
        $this->section('Seller submission entrance');
        $this->requireFileContains('@app/../backend/modules/mall/controllers/MerchantApplicationController.php', ['actionMy', "action->id !== 'my'", 'categoryOptions']);
        $this->requireFileContains('@app/../backend/modules/mall/views/merchant-application/my.php', ['我的入驻申请', 'requested_category_ids', 'checkboxList']);

        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => '/mall/merchant-application/my', 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail('Missing active backend permission /mall/merchant-application/my. Run migration m260618_161000_mongoyia_merchant_application_self_permission.');
            return;
        }
        $this->ok('Permission exists: /mall/merchant-application/my');

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail('Seller role 50 is missing /mall/merchant-application/my permission.');
            return;
        }
        $this->ok('Seller role can access my merchant application page.');
    }

    private function assertFixtureState()
    {
        $db = Yii::$app->db;
        $application = (new \yii\db\Query())
            ->from('{{%merchant_application}}')
            ->where(['id' => $this->createdApplicationId])
            ->one($db);
        if (!$application || $application['audit_status'] !== 'approved' || (int)$application['store_id'] !== $this->createdStoreId) {
            $this->fail('Application approval state was not persisted correctly.');
            return;
        }
        $this->ok('Application approval state is valid.');

        $authCount = (int)(new \yii\db\Query())
            ->from('{{%store_category_auth}}')
            ->where([
                'store_id' => $this->createdStoreId,
                'category_id' => $this->createdCategoryId,
                'audit_status' => 'approved',
            ])
            ->count('*', $db);
        if ($authCount !== 1) {
            $this->fail("Expected one category authorization row, found {$authCount}.");
            return;
        }
        $this->ok('Category authorization row is valid.');

        $store = (new \yii\db\Query())
            ->select(['name_en', 'name_mn', 'brief_en', 'brief_mn', 'main_products', 'contact_name', 'contact_phone', 'business_hours'])
            ->from('{{%store}}')
            ->where(['id' => $this->createdStoreId])
            ->one($db);
        foreach (['name_en', 'name_mn', 'brief_en', 'brief_mn', 'main_products', 'contact_name', 'contact_phone', 'business_hours'] as $field) {
            if (!isset($store[$field]) || trim((string)$store[$field]) === '') {
                $this->fail("Store profile field {$field} was not saved.");
                return;
            }
        }
        $this->ok('Store multilingual/profile fields are writable.');
    }

    private function existingUserId(): int
    {
        $id = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', -10])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
        if ($id <= 0) {
            throw new \RuntimeException('No active user row is available for store.user_id foreign key.');
        }

        return $id;
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
            $values[$name] = $this->fallbackValue($column->type);
        }

        return array_intersect_key($values, $schema->columns);
    }

    private function fallbackValue(string $type)
    {
        if (in_array($type, ['integer', 'bigint', 'smallint'], true)) {
            return 0;
        }
        if (in_array($type, ['decimal', 'float', 'double'], true)) {
            return 0;
        }
        if ($type === 'boolean') {
            return 0;
        }

        return '';
    }

    private function requireColumns(string $table, array $columns)
    {
        $schema = Yii::$app->db->schema->getTableSchema($table, true);
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
