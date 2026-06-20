<?php

namespace console\controllers;

use common\models\mall\Order;
use common\services\mall\DistributionCommissionService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaCommissionIntegrityController extends Controller
{
    public $fixture = true;
    public $strict = false;
    public $limit = 500;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['fixture', 'strict', 'limit']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia commission integrity check\n");
        $this->limit = max(1, (int)$this->limit);

        $this->checkSchema();
        if ($this->fixture) {
            $this->runFixture();
        } else {
            $this->runCurrentData();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_order}}', ['id', 'store_id', 'user_id', 'sn', 'amount', 'fx_id']);
        $this->requireColumns('{{%mall_distribution_commission}}', ['id', 'store_id', 'order_id', 'distributor_user_id', 'order_amount', 'commission_rate', 'commission_amount', 'commission_status']);
        $this->requireColumns('{{%mall_distribution_withdraw}}', ['id', 'distributor_user_id', 'amount', 'withdraw_status']);
    }

    private function runFixture()
    {
        $this->section('Fixture');
        $storeId = $this->firstSellerStoreId();
        $buyerId = $this->firstUserId();
        $distributorId = $this->secondUserId($buyerId);
        if ($storeId <= 0 || $buyerId <= 0 || $distributorId <= 0) {
            $this->fail('Need active seller store, buyer user, and distributor user for commission integrity fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $validOrderId = $this->createOrder($storeId, $buyerId, $distributorId, 'COMM-VALID', 100.00);
            $badAmountOrderId = $this->createOrder($storeId, $buyerId, $distributorId, 'COMM-BAD-AMOUNT', 60.00);

            $this->createCommission($storeId, $validOrderId, $distributorId, $buyerId, 100.00, 10.00, 10.00, DistributionCommissionService::COMMISSION_STATUS_APPROVED);
            $this->createCommission($storeId, $badAmountOrderId, $distributorId, $buyerId, 60.00, 10.00, 5.00, DistributionCommissionService::COMMISSION_STATUS_PENDING);
            $this->createCommission($storeId, 999999999, $distributorId, $buyerId, 30.00, 10.00, 3.00, DistributionCommissionService::COMMISSION_STATUS_PENDING);

            $result = $this->scanCommissions();
            $this->printResult($result);
            $this->assertSameInt(3, (int)$result['scanned'], 'Fixture scans three commission rows.');
            $this->assertSameInt(1, (int)$result['approvedRows'], 'Fixture counts one approved row.');
            $this->assertSameInt(2, (int)$result['pendingRows'], 'Fixture counts two pending rows.');
            $this->assertMoney(19.00, (float)$result['expectedCommissionAmount'], 'Fixture expected commission total is summed.');
            $this->assertMoney(18.00, (float)$result['actualCommissionAmount'], 'Fixture actual commission total is summed.');
            $this->assertIssue($result, 'amount mismatch');
            $this->assertIssue($result, 'missing order');

            $transaction->rollBack();
            $this->ok('Commission integrity fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Commission integrity fixture failed: ' . $e->getMessage());
        }
    }

    private function runCurrentData()
    {
        $this->section('Current data');
        $result = $this->scanCommissions();
        $this->printResult($result);
        if ($result['issueCount'] > 0) {
            $this->warn('Commission ledger has integrity issues; review issue rows before approval or withdrawal.');
        }
        if ($result['approvedRows'] === 0) {
            $this->warn('No approved commission rows are available for withdrawal readiness.');
        }
    }

    private function scanCommissions(): array
    {
        $rows = (new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->limit($this->limit)
            ->all(Yii::$app->db);

        $result = [
            'scanned' => 0,
            'pendingRows' => 0,
            'approvedRows' => 0,
            'withdrawnRows' => 0,
            'expectedCommissionAmount' => 0.0,
            'actualCommissionAmount' => 0.0,
            'issueCount' => 0,
            'issues' => [],
            'distributors' => [],
        ];

        foreach ($rows as $row) {
            $result['scanned']++;
            $status = (string)$row['commission_status'];
            if ($status === DistributionCommissionService::COMMISSION_STATUS_PENDING) {
                $result['pendingRows']++;
            } elseif ($status === DistributionCommissionService::COMMISSION_STATUS_APPROVED) {
                $result['approvedRows']++;
            } elseif ($status === DistributionCommissionService::COMMISSION_STATUS_WITHDRAWN) {
                $result['withdrawnRows']++;
            }

            $expected = round((float)$row['order_amount'] * (float)$row['commission_rate'] / 100, 2);
            $actual = round((float)$row['commission_amount'], 2);
            $result['expectedCommissionAmount'] += $expected;
            $result['actualCommissionAmount'] += $actual;
            $this->addDistributorSummary($result, $row, $expected, $actual);

            $order = $this->findOrder((int)$row['order_id']);
            if (!$order) {
                $this->addIssue($result, $row, 'missing order');
                continue;
            }
            if ((int)$order['fx_id'] !== (int)$row['distributor_user_id']) {
                $this->addIssue($result, $row, 'distributor mismatch');
            }
            if ((int)$order['store_id'] !== (int)$row['store_id']) {
                $this->addIssue($result, $row, 'store mismatch');
            }
            if (round((float)$order['amount'], 2) !== round((float)$row['order_amount'], 2)) {
                $this->addIssue($result, $row, 'order amount mismatch');
            }
            if ($expected !== $actual) {
                $this->addIssue($result, $row, 'amount mismatch');
            }
        }

        $result['expectedCommissionAmount'] = round($result['expectedCommissionAmount'], 2);
        $result['actualCommissionAmount'] = round($result['actualCommissionAmount'], 2);
        foreach ($result['distributors'] as &$row) {
            $row['expected_commission_amount'] = round((float)$row['expected_commission_amount'], 2);
            $row['actual_commission_amount'] = round((float)$row['actual_commission_amount'], 2);
        }
        unset($row);
        ksort($result['distributors']);

        return $result;
    }

    private function findOrder(int $orderId): ?array
    {
        $row = (new \yii\db\Query())
            ->from('{{%mall_order}}')
            ->where(['id' => $orderId])
            ->one(Yii::$app->db);

        return $row ?: null;
    }

    private function addDistributorSummary(array &$result, array $row, float $expected, float $actual)
    {
        $userId = (int)$row['distributor_user_id'];
        if (!isset($result['distributors'][$userId])) {
            $result['distributors'][$userId] = [
                'distributor_user_id' => $userId,
                'rows' => 0,
                'approved_rows' => 0,
                'expected_commission_amount' => 0.0,
                'actual_commission_amount' => 0.0,
            ];
        }
        $result['distributors'][$userId]['rows']++;
        if ((string)$row['commission_status'] === DistributionCommissionService::COMMISSION_STATUS_APPROVED) {
            $result['distributors'][$userId]['approved_rows']++;
        }
        $result['distributors'][$userId]['expected_commission_amount'] += $expected;
        $result['distributors'][$userId]['actual_commission_amount'] += $actual;
    }

    private function addIssue(array &$result, array $row, string $reason)
    {
        $result['issueCount']++;
        $result['issues'][] = [
            'commission_id' => (int)$row['id'],
            'order_id' => (int)$row['order_id'],
            'distributor_user_id' => (int)$row['distributor_user_id'],
            'reason' => $reason,
        ];
    }

    private function createOrder(int $storeId, int $buyerId, int $distributorId, string $prefix, float $amount): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_order}}', [
            'store_id' => $storeId,
            'parent_id' => 1,
            'user_id' => $buyerId,
            'address_id' => 0,
            'name' => 'Commission integrity fixture',
            'sn' => $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999),
            'first_name' => 'Codex',
            'last_name' => 'Commission',
            'country_id' => 0,
            'country' => '',
            'province_id' => 0,
            'province' => '',
            'city_id' => 0,
            'city' => '',
            'district_id' => 0,
            'district' => '',
            'address' => 'Local commission fixture',
            'address2' => '',
            'postcode' => '',
            'mobile' => '13800000000',
            'email' => 'codex_commission@mongoyia.local',
            'distance' => 0,
            'remark' => 'Created by mongoyia-commission-integrity/run --fixture=1',
            'payment_method' => Order::PAYMENT_METHOD_PAY,
            'payment_fee' => 0,
            'payment_status' => Order::PAYMENT_STATUS_PAID,
            'paid_at' => $now,
            'stock_deducted_at' => $now,
            'stock_refunded_at' => 0,
            'shipment_id' => 9021,
            'shipment_name' => 'Commission Express',
            'shipment_fee' => 0,
            'shipment_fee_deducted_at' => 0,
            'shipment_status' => Order::SHIPMENT_STATUS_RECEIVED,
            'logistics_review_status' => Order::LOGISTICS_REVIEW_PASSED,
            'logistics_reviewed_at' => $now,
            'logistics_reviewed_by' => 1,
            'logistics_review_remark' => '',
            'shipped_at' => $now,
            'product_amount' => $amount,
            'amount' => $amount,
            'number' => 1,
            'extra_fee' => 0,
            'discount' => 0,
            'tax' => 0,
            'invoice' => '',
            'fx_id' => $distributorId,
            'type' => 1,
            'sort' => 50,
            'status' => Order::SHIPMENT_STATUS_RECEIVED,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function createCommission(int $storeId, int $orderId, int $distributorId, int $buyerId, float $orderAmount, float $rate, float $commissionAmount, string $status)
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_commission}}', [
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_sn' => 'COMM-FIXTURE-' . $orderId,
            'distributor_user_id' => $distributorId,
            'buyer_user_id' => $buyerId,
            'order_amount' => $orderAmount,
            'commission_rate' => $rate,
            'commission_amount' => $commissionAmount,
            'commission_status' => $status,
            'source' => 'fixture',
            'remark' => 'Created by mongoyia-commission-integrity/run --fixture=1',
            'settled_at' => 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function printResult(array $result)
    {
        $this->stdout("Scanned: {$result['scanned']}\n");
        $this->stdout("Pending rows: {$result['pendingRows']}\n");
        $this->stdout("Approved rows: {$result['approvedRows']}\n");
        $this->stdout("Withdrawn rows: {$result['withdrawnRows']}\n");
        $this->stdout('Expected commission amount: ' . number_format((float)$result['expectedCommissionAmount'], 2) . "\n");
        $this->stdout('Actual commission amount: ' . number_format((float)$result['actualCommissionAmount'], 2) . "\n");
        $this->stdout("Issue count: {$result['issueCount']}\n");
        foreach ($result['distributors'] as $row) {
            $this->stdout("DISTRIBUTOR user={$row['distributor_user_id']} rows={$row['rows']} approved={$row['approved_rows']} expected=" . number_format((float)$row['expected_commission_amount'], 2) . ' actual=' . number_format((float)$row['actual_commission_amount'], 2) . "\n");
        }
        foreach ($result['issues'] as $row) {
            $this->stdout("ISSUE commission={$row['commission_id']} order={$row['order_id']} distributor={$row['distributor_user_id']} reason={$row['reason']}\n");
        }
    }

    private function assertIssue(array $result, string $reason)
    {
        foreach ($result['issues'] as $row) {
            if ($row['reason'] === $reason) {
                $this->ok("Fixture reports issue: {$reason}.");
                return;
            }
        }
        $this->fail("Fixture did not report issue: {$reason}.");
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
