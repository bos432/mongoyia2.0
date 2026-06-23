<?php

namespace console\controllers;

use common\models\mall\MerchantApplication;
use common\models\mall\Product;
use common\models\mall\StoreCategoryAuth;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MerchantBackendClosureTestController extends Controller
{
    public $cleanup = true;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'cleanup',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia merchant backend Phase 2 closure test\n");

        $this->checkFiles();
        $this->checkPermissions();
        $this->checkBackendRoutes();
        $this->checkAuditPostGuards();
        if ($this->failures === 0) {
            $this->runFixtureFlow();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles()
    {
        $this->section('Backend files');
        foreach ([
            'common/models/mall/MerchantApplication.php',
            'common/models/mall/StoreCategoryAuth.php',
            'backend/modules/mall/controllers/MerchantApplicationController.php',
            'backend/modules/mall/controllers/StoreCategoryAuthController.php',
            'backend/modules/mall/views/merchant-application/index.php',
            'backend/modules/mall/views/merchant-application/categories.php',
            'backend/modules/mall/views/store-category-auth/index.php',
            'console/migrations/m260618_151000_mongoyia_merchant_backend_permissions.php',
        ] as $file) {
            $this->requireFile($file);
        }
    }

    private function checkPermissions()
    {
        $this->section('Backend permissions');
        $schema = Yii::$app->db->schema->getTableSchema('{{%base_permission}}', true);
        if (!$schema) {
            $this->warn('base_permission table is missing; route access cannot be validated in this database.');
            return;
        }

        foreach ([
            '/mall/merchant-application/index',
            '/mall/merchant-application/*',
            '/mall/store-category-auth/index',
            '/mall/store-category-auth/*',
            '/mall/product/approve*',
            '/mall/product/reject*',
        ] as $path) {
            $exists = (new \yii\db\Query())
                ->from('{{%base_permission}}')
                ->where(['path' => $path, 'status' => 1])
                ->exists(Yii::$app->db);
            if (!$exists) {
                $this->fail("Missing active backend permission {$path}. Run migration m260618_151000_mongoyia_merchant_backend_permissions.");
                continue;
            }
            $this->ok("Permission exists: {$path}");
        }
    }

    private function checkBackendRoutes()
    {
        $this->section('Route methods');
        foreach ([
            ['backend\modules\mall\controllers\MerchantApplicationController', ['actionIndex', 'actionApprove', 'actionReject', 'actionCategories']],
            ['backend\modules\mall\controllers\StoreCategoryAuthController', ['actionIndex', 'actionApprove', 'actionReject']],
            ['backend\modules\mall\controllers\ProductController', ['actionApprove', 'actionReject']],
        ] as [$class, $methods]) {
            if (!class_exists($class)) {
                $this->fail("Missing class {$class}.");
                continue;
            }
            foreach ($methods as $method) {
                if (!method_exists($class, $method)) {
                    $this->fail("Missing method {$class}::{$method}.");
                    continue;
                }
                $this->ok("Route method exists: {$class}::{$method}");
            }
        }
    }

    private function checkAuditPostGuards(): void
    {
        $this->section('Audit POST guards');
        $this->requireFileContains('backend/modules/mall/controllers/MerchantApplicationController.php', [
            'MONGOYIA_MERCHANT_APPLICATION_AUDIT_POST_GUARD_V1',
            "'approve'] = ['post']",
            "'reject'] = ['post']",
            "post('id', 0)",
            "post('remark', 'Approved from backend.')",
            "post('remark', 'Rejected from backend.')",
        ]);
        $this->requireFileContains('backend/modules/mall/views/merchant-application/index.php', [
            'data-mongoyia-merchant-application-post-guard',
            'csrfToken',
            "Url::to([\$route])",
        ]);
        $this->requireFileContains('backend/modules/mall/controllers/StoreCategoryAuthController.php', [
            'MONGOYIA_STORE_CATEGORY_AUTH_AUDIT_POST_GUARD_V1',
            "'approve'] = ['post']",
            "'reject'] = ['post']",
            "post('id', 0)",
            "post('remark', \$auditStatus . ' from backend.')",
        ]);
        $this->requireFileContains('backend/modules/mall/views/store-category-auth/index.php', [
            'data-mongoyia-store-category-auth-post-guard',
            'csrfToken',
            "Url::to([\$route])",
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
            $userId = $this->existingUserId();
            $categoryId = $this->insertRow('{{%mall_category}}', [
                'store_id' => 5,
                'parent_id' => 0,
                'name' => 'MERCHBACK Category ' . $suffix,
                'brief' => 'Merchant backend fixture category',
                'is_nav' => 0,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);
            $storeId = $this->insertRow('{{%store}}', [
                'parent_id' => 0,
                'user_id' => $userId,
                'name' => 'MERCHBACK Store ' . $suffix,
                'brief' => 'Merchant backend fixture store',
                'host_name' => '',
                'code' => 'MERCHBACK-' . $suffix,
                'qrcode' => '',
                'route' => 'mall',
                'expired_at' => $now + 86400,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);
            $applicationId = $this->insertRow('{{%merchant_application}}', [
                'store_id' => $storeId,
                'user_id' => $userId,
                'applicant_name' => 'MERCHBACK Applicant',
                'mobile' => '000000',
                'email' => 'merchant-backend@example.invalid',
                'company_name' => 'MERCHBACK Company ' . $suffix,
                'business_license' => 'MERCHBACK-LICENSE',
                'requested_category_ids' => json_encode([$categoryId]),
                'audit_status' => MerchantApplication::AUDIT_SUBMITTED,
                'submitted_at' => $now,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);

            $application = MerchantApplication::findOne($applicationId);
            if (!$application || $application->requestedCategoryIds() !== [$categoryId]) {
                $this->fail('MerchantApplication model cannot read requested category ids.');
                throw new \RuntimeException('Invalid requested categories.');
            }
            $this->ok('MerchantApplication model reads requested category ids.');

            $application->audit_status = MerchantApplication::AUDIT_APPROVED;
            $application->audit_remark = 'Merchant backend closure approved';
            $application->reviewed_at = $now;
            $application->reviewer_id = 1;
            if (!$application->save()) {
                throw new \RuntimeException('Application approval save failed: ' . json_encode($application->errors));
            }
            $this->ok('Merchant application approval state is writable.');

            $auth = new StoreCategoryAuth();
            $auth->store_id = $storeId;
            $auth->category_id = $categoryId;
            $auth->source_application_id = $applicationId;
            $auth->audit_status = StoreCategoryAuth::AUDIT_APPROVED;
            $auth->audit_remark = 'Merchant backend closure authorized';
            $auth->status = StoreCategoryAuth::STATUS_ACTIVE;
            if (!$auth->save()) {
                throw new \RuntimeException('Category auth save failed: ' . json_encode($auth->errors));
            }
            $this->ok('Store category authorization model is writable.');

            $productId = $this->insertRow('{{%mall_product}}', [
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'name' => 'MERCHBACK Product ' . $suffix,
                'sku' => 'MERCHBACK-' . $suffix,
                'stock' => 10,
                'price' => 1.00,
                'market_price' => 1.00,
                'audit_status' => 'submitted',
                'status' => Product::STATUS_INACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);
            $product = Product::findOne($productId);
            $product->audit_status = 'approved';
            $product->audit_remark = 'Merchant backend closure product approved';
            $product->reviewed_at = $now;
            $product->reviewer_id = 1;
            $product->status = Product::STATUS_ACTIVE;
            if (!$product->save()) {
                throw new \RuntimeException('Product approval save failed: ' . json_encode($product->errors));
            }
            $this->ok('Product audit approval state is writable.');

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

    private function existingUserId(): int
    {
        $id = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', -10])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
        if ($id <= 0) {
            throw new \RuntimeException('No active user row is available for fixture ownership.');
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

    private function requireFile(string $path)
    {
        if (is_file(Yii::getAlias('@app') . '/../' . $path)) {
            $this->ok("File exists: {$path}");
            return;
        }

        $this->fail("Missing file {$path}.");
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $full = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($full)) {
            $this->fail("Missing file {$path}.");
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} is missing marker {$needle}.");
                return;
            }
        }

        $this->ok("File markers exist: {$path}");
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
