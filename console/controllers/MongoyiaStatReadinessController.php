<?php

namespace console\controllers;

use common\models\mall\Order;
use common\models\mall\ProductVisit;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaStatReadinessController extends Controller
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
        $this->stdout("Mongoyia statistics readiness check\n");

        $this->checkSchemas();
        $this->checkProductVisitModel();
        $this->checkAggregateQueries();
        $this->checkReportEntrances();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchemas()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_product_visit}}', ['id', 'pid', 'time']);
        $this->requireColumns('{{%mall_product}}', ['id', 'store_id', 'sales', 'click', 'status']);
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'payment_status', 'amount', 'created_at', 'status']);
    }

    private function checkProductVisitModel()
    {
        $this->section('Product visit');
        if (!class_exists(ProductVisit::class)) {
            $this->fail('ProductVisit model is missing.');
            return;
        }
        $visitCount = (int)(new \yii\db\Query())->from('{{%mall_product_visit}}')->count('*', Yii::$app->db);
        if ($visitCount === 0) {
            $this->warn('No product visit rows found; product page should be opened before statistics evidence.');
        } else {
            $this->ok("Product visit rows: {$visitCount}.");
        }
    }

    private function checkAggregateQueries()
    {
        $this->section('Aggregate queries');
        $storeRows = (new \yii\db\Query())
            ->select([
                'store_id',
                'orders' => 'COUNT(*)',
                'amount' => 'SUM(amount)',
            ])
            ->from('{{%mall_order}}')
            ->where(['payment_status' => [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD]])
            ->groupBy('store_id')
            ->limit(10)
            ->all(Yii::$app->db);
        $this->ok('Store order/sales aggregate query works; rows=' . count($storeRows) . '.');

        $productRows = (new \yii\db\Query())
            ->select(['id', 'store_id', 'sales', 'click'])
            ->from('{{%mall_product}}')
            ->orderBy(['sales' => SORT_DESC, 'id' => SORT_ASC])
            ->limit(10)
            ->all(Yii::$app->db);
        if (!$productRows) {
            $this->warn('No product rows found for sales/click statistics.');
        } else {
            $this->ok('Product sales/click aggregate source works; rows=' . count($productRows) . '.');
        }
    }

    private function checkReportEntrances()
    {
        $this->section('Report entrances');
        $this->requireFileContains('@app/../backend/modules/mall/views/product/index.php', ['sales', 'stock']);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/DefaultController.php', ['sales']);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/CategoryController.php', ['sales', 'price']);
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
