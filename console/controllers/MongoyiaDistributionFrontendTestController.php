<?php

namespace console\controllers;

use common\services\mall\DistributionCommissionService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionFrontendTestController extends Controller
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
        $this->stdout("Mongoyia distribution frontend Phase 4 test\n");
        $this->checkFiles();
        $this->checkSchema();
        $this->checkFixture();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles()
    {
        $this->section('Frontend files');
        $this->requireFileContains('frontend/modules/mall/controllers/UserController.php', [
            'actionDistribution',
            'mall_distribution_commission',
            'fxid',
        ]);
        $this->requireFileContains('web/resources/mall/default/views/user/_nav.php', [
            '/mall/user/distribution',
            'Distribution',
            'fa-share-alt',
        ]);
        $this->requireFileContains('web/resources/mall/default/views/user/distribution.php', [
            'Distribution Center',
            'Promotion Link',
            'Commission Summary',
            'Commission Records',
            'fxid=',
            'read-only',
        ]);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_rule}}', ['id', 'store_id', 'commission_rate', 'rule_status', 'status']);
        $this->requireColumns('{{%mall_distribution_commission}}', ['id', 'store_id', 'order_id', 'distributor_user_id', 'order_amount', 'commission_rate', 'commission_amount', 'commission_status']);
        $this->requireColumns('{{%mall_distribution_withdraw}}', ['id', 'distributor_user_id', 'amount', 'withdraw_status']);
    }

    private function checkFixture()
    {
        $this->section('Rollback fixture');
        $storeId = $this->firstSellerStoreId();
        $distributorId = $this->firstUserId();
        $buyerId = $this->secondUserId($distributorId);
        if ($storeId <= 0 || $distributorId <= 0 || $buyerId <= 0) {
            $this->fail('Need active seller store, distributor user, and buyer user for frontend fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $this->createCommission($storeId, $distributorId, $buyerId, 100.00, 10.00, 10.00, DistributionCommissionService::COMMISSION_STATUS_PENDING);
            $this->createCommission($storeId, $distributorId, $buyerId, 80.00, 12.50, 10.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $this->createCommission($storeId, $distributorId, $buyerId, 40.00, 5.00, 2.00, DistributionCommissionService::COMMISSION_STATUS_REJECTED);
            $this->createCommission($storeId, $buyerId, $distributorId, 60.00, 10.00, 6.00, DistributionCommissionService::COMMISSION_STATUS_PENDING);

            $summary = $this->summaryForDistributor($distributorId);
            $records = $this->recordsForDistributor($distributorId);
            $this->printSummary($summary);

            $this->assertSameInt(3, count($records), 'Frontend ledger only shows current distributor rows.');
            $this->assertSummary($summary, DistributionCommissionService::COMMISSION_STATUS_PENDING, 1, 100.00, 10.00);
            $this->assertSummary($summary, DistributionCommissionService::COMMISSION_STATUS_APPROVED, 1, 80.00, 10.00);
            $this->assertSummary($summary, DistributionCommissionService::COMMISSION_STATUS_REJECTED, 1, 40.00, 2.00);

            $transaction->rollBack();
            $this->ok('Distribution frontend fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution frontend fixture failed: ' . $e->getMessage());
        }
    }

    private function createCommission(int $storeId, int $distributorId, int $buyerId, float $orderAmount, float $rate, float $commissionAmount, string $status)
    {
        $now = time();
        $orderId = 960000000 + mt_rand(10000, 99999);
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_commission}}', [
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_sn' => 'DIST-FRONT-' . $orderId,
            'distributor_user_id' => $distributorId,
            'buyer_user_id' => $buyerId,
            'order_amount' => $orderAmount,
            'commission_rate' => $rate,
            'commission_amount' => $commissionAmount,
            'commission_status' => $status,
            'source' => 'frontend_fixture',
            'remark' => 'Created by mongoyia-distribution-frontend-test/run',
            'settled_at' => $status === DistributionCommissionService::COMMISSION_STATUS_APPROVED ? $now : 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function summaryForDistributor(int $distributorId): array
    {
        $rows = (new \yii\db\Query())
            ->select([
                'commission_status',
                'rows' => 'COUNT(*)',
                'order_amount' => 'SUM(order_amount)',
                'commission_amount' => 'SUM(commission_amount)',
            ])
            ->from('{{%mall_distribution_commission}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->groupBy(['commission_status'])
            ->all(Yii::$app->db);

        $summary = [];
        foreach ($rows as $row) {
            $summary[(string)$row['commission_status']] = [
                'rows' => (int)$row['rows'],
                'order_amount' => round((float)$row['order_amount'], 2),
                'commission_amount' => round((float)$row['commission_amount'], 2),
            ];
        }

        return $summary;
    }

    private function recordsForDistributor(int $distributorId): array
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['distributor_user_id' => $distributorId, 'status' => 1])
            ->all(Yii::$app->db);
    }

    private function printSummary(array $summary)
    {
        foreach ($summary as $status => $row) {
            $this->stdout("SUMMARY status={$status} rows={$row['rows']} order=" . number_format((float)$row['order_amount'], 2) . ' commission=' . number_format((float)$row['commission_amount'], 2) . "\n");
        }
    }

    private function assertSummary(array $summary, string $status, int $rows, float $orderAmount, float $commissionAmount)
    {
        if (!isset($summary[$status])) {
            $this->fail("Missing summary bucket {$status}.");
            return;
        }
        $this->assertSameInt($rows, (int)$summary[$status]['rows'], "Summary {$status} row count is correct.");
        $this->assertMoney($orderAmount, (float)$summary[$status]['order_amount'], "Summary {$status} order amount is correct.");
        $this->assertMoney($commissionAmount, (float)$summary[$status]['commission_amount'], "Summary {$status} commission amount is correct.");
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

    private function firstUserId(): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', 0])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
    }

    private function secondUserId(int $excludeUserId): int
    {
        return (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', 0])
            ->andWhere(['<>', 'id', $excludeUserId])
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

    private function requireFileContains(string $path, array $needles)
    {
        $fullPath = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function assertSameInt(int $expected, int $actual, string $message)
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertMoney(float $expected, float $actual, string $message)
    {
        if (round($expected, 2) !== round($actual, 2)) {
            $this->fail("{$message} Expected " . number_format($expected, 2) . ', got ' . number_format($actual, 2) . '.');
            return;
        }
        $this->ok($message);
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
