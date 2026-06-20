<?php

namespace console\controllers;

use common\models\base\FundLog;
use common\models\Store;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDepositReadinessController extends Controller
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
        $this->stdout("Mongoyia merchant deposit readiness check\n");

        $this->checkSchema();
        $this->checkBackendEntrances();
        $this->checkPermission('/mall/merchant-deposit/index');
        $this->checkDepositFixture();
        $this->checkCurrentData();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%store}}', ['id', 'name', 'fund', 'fund_amount', 'consume_amount', 'consume_count']);
        $this->requireColumns('{{%base_fund_log}}', ['id', 'store_id', 'user_id', 'name', 'change', 'original', 'balance', 'remark', 'type', 'status']);
    }

    private function checkBackendEntrances()
    {
        $this->section('Backend entrances');
        $this->requireFileContains('@app/../backend/modules/mall/controllers/MerchantDepositController.php', ['actionIndex', 'actionAdjust', 'fundLogs']);
        $this->requireFileContains('@app/../backend/modules/mall/views/merchant-deposit/index.php', ['商家预存金', '预存金流水', '充值/扣费']);
    }

    private function checkDepositFixture()
    {
        $this->section('Deposit fixture');
        $storeId = $this->firstSellerStoreId();
        if ($storeId <= 0) {
            $this->fail('No seller store is available for deposit fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $store = Store::findOne($storeId);
            $original = (float)$store->fund;
            Store::updateAllCounters(['fund' => 20, 'fund_amount' => 20], ['id' => $storeId]);
            $this->createFundLog($storeId, 20, $original, $original + 20, 'DEPFIX recharge', FundLog::TYPE_RECHARGE);
            $this->ok('Deposit recharge updates store balance and writes fund log.');

            Store::updateAllCounters(['fund' => -5, 'consume_amount' => 5, 'consume_count' => 1], ['id' => $storeId]);
            $this->createFundLog($storeId, -5, $original + 20, $original + 15, 'DEPFIX logistics difference', FundLog::TYPE_CONSUME);
            $this->ok('Deposit deduction updates store balance and writes fund log.');

            $balance = (float)(new \yii\db\Query())
                ->select('fund')
                ->from('{{%store}}')
                ->where(['id' => $storeId])
                ->scalar(Yii::$app->db);
            if (round($balance, 2) !== round($original + 15, 2)) {
                throw new \RuntimeException("Expected fixture balance " . ($original + 15) . ", got {$balance}.");
            }
            $this->ok('Deposit fixture balance calculation is consistent.');

            $transaction->rollBack();
            $this->ok('Deposit fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Deposit fixture failed: ' . $e->getMessage());
        }
    }

    private function checkCurrentData()
    {
        $this->section('Current data');
        $storeCount = (int)(new \yii\db\Query())
            ->from('{{%store}}')
            ->where(['>', 'status', Store::STATUS_DELETED])
            ->count('*', Yii::$app->db);
        $logCount = (int)(new \yii\db\Query())
            ->from('{{%base_fund_log}}')
            ->where(['>', 'status', FundLog::STATUS_DELETED])
            ->count('*', Yii::$app->db);

        $this->ok("Stores with deposit fields: {$storeCount}; fund log rows: {$logCount}.");
        if ($storeCount === 0) {
            $this->warn('No active stores found for merchant deposit manual verification.');
        }
    }

    private function createFundLog(int $storeId, float $change, float $original, float $balance, string $name, int $type)
    {
        $log = new FundLog();
        $log->store_id = $storeId;
        $log->user_id = 1;
        $log->name = $name;
        $log->change = $change;
        $log->original = $original;
        $log->balance = $balance;
        $log->remark = 'mongoyia-deposit-readiness fixture';
        $log->type = $type;
        if (!$log->save()) {
            throw new \RuntimeException(json_encode($log->errors, JSON_UNESCAPED_UNICODE));
        }
    }

    private function checkPermission(string $path)
    {
        $this->section('Permission');
        $permissionId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%base_permission}}')
            ->where(['path' => $path, 'status' => 1])
            ->scalar(Yii::$app->db);
        if ($permissionId <= 0) {
            $this->fail("Missing active backend permission {$path}.");
            return;
        }

        $sellerGrant = (new \yii\db\Query())
            ->from('{{%base_role_permission}}')
            ->where(['role_id' => 50, 'permission_id' => $permissionId, 'status' => 1])
            ->exists(Yii::$app->db);
        if (!$sellerGrant) {
            $this->fail("Seller role 50 is missing {$path} permission.");
            return;
        }

        $this->ok("Permission exists and seller role can access {$path}.");
    }

    private function firstSellerStoreId(): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%store}}')
            ->where(['>', 'id', 0])
            ->andWhere(['>', 'status', 0])
            ->andWhere(['not in', 'id', [5]])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
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
