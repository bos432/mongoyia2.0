<?php

namespace console\controllers;

use backend\modules\mall\controllers\ProductController;
use common\models\mall\Product;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class ProductAuditTestController extends Controller
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
        $this->stdout("Mongoyia product audit Phase 2 test\n");

        $this->checkSchemas();
        if ($this->failures === 0) {
            $this->checkControllerRules();
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
        $this->requireColumns('{{%mall_product}}', ['audit_status', 'audit_remark', 'reviewed_at', 'reviewer_id']);
        $this->requireColumns('{{%store_category_auth}}', ['store_id', 'category_id', 'audit_status', 'status']);
    }

    private function checkControllerRules()
    {
        $this->section('Controller rules');
        $platform = new ProductAuditProbeController(true);
        $seller = new ProductAuditProbeController(false);

        $this->checkMethodExists('beforeEditSave');
        $this->checkMethodExists('beforeEditStatusSave');
        $this->checkMethodExists('validateProductCategoryAuthorization');
        $this->checkMethodExists('applyProductAuditState');
        $this->checkProductAuditPostGuard();

        $this->checkSellerSubmitState($seller);
        $this->checkPlatformApprovalState($platform);
        $this->checkSellerCannotDirectlyActivate($seller);
        $this->checkCategoryAuthorization($seller);
    }

    private function checkSellerSubmitState(ProductAuditProbeController $seller)
    {
        $product = $this->newProduct(1001, 2001, Product::STATUS_INACTIVE);
        $result = $seller->callBeforeEditSave(null, $product);
        if ($result !== true) {
            $this->fail('Seller product save should be allowed when no category authorization table rows exist for the store.');
            return;
        }
        if ($product->audit_status !== 'submitted' || (int)$product->reviewed_at !== 0 || (int)$product->reviewer_id !== 0) {
            $this->fail('Seller product save must mark audit_status=submitted and clear reviewer fields.');
            return;
        }
        $this->ok('Seller product save enters submitted audit state.');
    }

    private function checkPlatformApprovalState(ProductAuditProbeController $platform)
    {
        $product = $this->newProduct(1001, 2001, Product::STATUS_ACTIVE);
        $result = $platform->callBeforeEditStatusSave(null, $product, Product::STATUS_ACTIVE);
        if ($result !== true) {
            $this->fail('Platform product activation should be allowed for priced products.');
            return;
        }
        if ($product->audit_status !== 'approved' || (int)$product->reviewed_at <= 0) {
            $this->fail('Platform product activation must mark audit_status=approved and reviewed_at.');
            return;
        }
        $this->ok('Platform activation approves product audit state.');
    }

    private function checkSellerCannotDirectlyActivate(ProductAuditProbeController $seller)
    {
        $product = $this->newProduct(1001, 2001, Product::STATUS_INACTIVE);
        $result = $seller->callBeforeEditStatusSave(null, $product, Product::STATUS_ACTIVE);
        if ($result !== false || !$product->hasErrors('status')) {
            $this->fail('Seller direct activation must be blocked until platform review.');
            return;
        }
        $this->ok('Seller direct activation is blocked.');
    }

    private function checkCategoryAuthorization(ProductAuditProbeController $seller)
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $storeId = 990001;
            $allowedCategoryId = 990101;
            $blockedCategoryId = 990102;
            $now = time();
            $this->insertRow('{{%store_category_auth}}', [
                'store_id' => $storeId,
                'category_id' => $allowedCategoryId,
                'source_application_id' => 0,
                'audit_status' => 'approved',
                'authorized_at' => $now,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ]);

            $allowed = $this->newProduct($storeId, $allowedCategoryId, Product::STATUS_INACTIVE);
            $blocked = $this->newProduct($storeId, $blockedCategoryId, Product::STATUS_INACTIVE);

            if ($seller->callValidateProductCategoryAuthorization($allowed) !== true) {
                $this->fail('Authorized category should pass seller category validation.');
                $transaction->rollBack();
                return;
            }
            if ($seller->callValidateProductCategoryAuthorization($blocked) !== false || !$blocked->hasErrors('category_id')) {
                $this->fail('Unauthorized category should be blocked when store has category authorization rows.');
                $transaction->rollBack();
                return;
            }

            $transaction->rollBack();
            $this->ok('Category authorization limits seller product categories when configured.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Category authorization check failed: ' . $e->getMessage());
        }
    }

    private function newProduct(int $storeId, int $categoryId, int $status): Product
    {
        $product = new Product();
        $product->store_id = $storeId;
        $product->category_id = $categoryId;
        $product->name = 'Product Audit Fixture';
        $product->sku = 'PRODAUDIT';
        $product->price = 1;
        $product->status = $status;
        $product->audit_status = '';
        $product->reviewed_at = 0;
        $product->reviewer_id = 0;

        return $product;
    }

    private function insertRow(string $table, array $values): int
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
            } elseif ($column->allowNull) {
                $values[$name] = null;
            } else {
                $values[$name] = in_array($column->type, ['integer', 'bigint', 'smallint', 'decimal', 'float', 'double'], true) ? 0 : '';
            }
        }
        Yii::$app->db->createCommand()->insert($table, array_intersect_key($values, $schema->columns))->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function checkMethodExists(string $method)
    {
        $reflection = new \ReflectionClass(ProductController::class);
        if (!$reflection->hasMethod($method)) {
            $this->fail("ProductController is missing {$method}().");
            return;
        }
        $this->ok("ProductController has {$method}().");
    }

    private function checkProductAuditPostGuard(): void
    {
        $this->requireFileContains('@app/../backend/modules/mall/controllers/ProductController.php', [
            'MONGOYIA_PRODUCT_AUDIT_POST_VERB_GUARD_V1',
            "'approve'] = ['post']",
            "'reject'] = ['post']",
            "post('id', 0)",
            "post('remark', 'Approved from backend.')",
            "post('remark', 'Rejected from backend.')",
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/product/index.php', [
            'data-mongoyia-product-audit-post-guard',
            'csrfToken',
            "Url::to(['approve'])",
            "Url::to(['reject'])",
        ]);
    }

    private function requireFileContains(string $alias, array $needles): void
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
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

    private function section(string $name)
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}

class ProductAuditProbeController extends ProductController
{
    private $platform;

    public function __construct(bool $platform)
    {
        $this->platform = $platform;
        parent::__construct('product-audit-probe', Yii::$app);
    }

    public function isMallPlatformOperator()
    {
        return $this->platform;
    }

    protected function assertCanManageProduct($model)
    {
        return true;
    }

    public function callBeforeEditSave($id, $model)
    {
        return parent::beforeEditSave($id, $model);
    }

    public function callBeforeEditStatusSave($id, $model, $status)
    {
        return parent::beforeEditStatusSave($id, $model, $status);
    }

    public function callValidateProductCategoryAuthorization(Product $model)
    {
        return parent::validateProductCategoryAuthorization($model);
    }
}
