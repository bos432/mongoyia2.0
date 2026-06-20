<?php

namespace console\controllers;

use common\models\mall\Order;
use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionInviteService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaDistributionInviteTestController extends Controller
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
        $this->stdout("Mongoyia distribution invite/reward Phase 4 test\n");
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
        $this->section('Files');
        $this->requireFileContains('common/services/mall/DistributionInviteService.php', ['class DistributionInviteService', 'recordInvite', 'invite_reward_amount', 'REWARD_STATUS_PENDING']);
        $this->requireFileContains('frontend/modules/mall/controllers/UserController.php', ['DistributionInviteService', 'inviteSummary', 'distributionInviteRewardStatusLabels']);
        $this->requireFileContains('web/resources/mall/default/views/user/distribution.php', ['Invite Rewards', 'No invite rewards yet', 'ledger records only']);
        $this->requireFileContains('backend/modules/mall/controllers/DistributionDistributorController.php', ['inviteRows', 'inviteRewardRows']);
        $this->requireFileContains('backend/modules/mall/views/distribution-distributor/index.php', ['邀请关系', '邀请奖励', '暂无邀请奖励']);
        $this->requireFileContains('console/migrations/m260618_191000_mongoyia_distribution_invite_reward.php', ['mall_distribution_invite', 'mall_distribution_invite_reward', 'invite_reward_amount']);
    }

    private function checkSchema()
    {
        $this->section('Schema');
        $this->requireColumns('{{%mall_distribution_rule}}', ['id', 'store_id', 'commission_rate', 'invite_reward_amount', 'rule_status']);
        $this->requireColumns('{{%mall_distribution_invite}}', ['id', 'distributor_user_id', 'invited_user_id', 'invite_status', 'first_order_id']);
        $this->requireColumns('{{%mall_distribution_invite_reward}}', ['id', 'invite_id', 'order_id', 'distributor_user_id', 'invited_user_id', 'reward_amount', 'reward_status']);
    }

    private function checkFixture()
    {
        $this->section('Rollback fixture');
        $storeId = $this->firstSellerStoreId();
        $distributorId = $this->firstDistributorUserId();
        $invitedUserId = $this->secondUserId($distributorId);
        if ($storeId <= 0 || $distributorId <= 0 || $invitedUserId <= 0) {
            $this->fail('Need active seller store, distributor user, and invited user for invite reward fixture.');
            return;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $service = new DistributionInviteService();

            $dryInvite = $service->recordInvite($distributorId, $invitedUserId, false, 'fixture_fxid');
            $this->assertSameInt(0, (int)$dryInvite['created'] + (int)$dryInvite['updated'], 'Invite dry-run does not persist.');

            $invite = $service->recordInvite($distributorId, $invitedUserId, true, 'fixture_fxid');
            $this->assertSameInt(1, (int)$invite['created'], 'Invite relation is created.');
            $inviteId = $this->inviteId($invitedUserId);
            $this->assertPositive($inviteId, 'Invite relation is queryable by invited user.');

            $selfInvite = $service->recordInvite($distributorId, $distributorId, true, 'fixture_fxid');
            $this->assertSameString('self invite is not allowed', (string)$selfInvite['skippedReason'], 'Self invite is blocked.');

            $this->createRule($storeId, 10.00, 0.00, 6.00);
            $readyOrder = $this->createOrder($storeId, $invitedUserId, $distributorId, 'DIST-INVITE-READY', 120.00, Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_RECEIVED);
            $blockedOrder = $this->createOrder($storeId, $invitedUserId, $distributorId, 'DIST-INVITE-BLOCKED', 80.00, Order::PAYMENT_STATUS_PAID, Order::SHIPMENT_STATUS_SHIPPING);

            $dryRun = $service->run($storeId, 10, false, $distributorId);
            $this->printResult($dryRun);
            $this->assertSameInt(2, (int)$dryRun['scanned'], 'Invite reward dry-run scans fixture attributed orders.');
            $this->assertSameInt(1, (int)$dryRun['readyOrders'], 'Invite reward dry-run finds one ready first-order reward.');
            $this->assertSameInt(1, (int)$dryRun['blockedOrders'], 'Invite reward dry-run reports blocked orders.');
            $this->assertMoney(6.00, (float)$dryRun['rewardAmount'], 'Invite reward amount is calculated from rule.');
            $this->assertBlockedReason($dryRun, $blockedOrder->id, 'not received');
            $this->assertNoReward($readyOrder->id, 'Dry-run does not persist invite reward.');

            $apply = $service->run($storeId, 10, true, $distributorId);
            $this->printResult($apply);
            $this->assertSameInt(1, (int)$apply['rewardsCreated'], 'Invite reward apply creates one reward row.');
            $this->assertPersistedReward($readyOrder->id, $inviteId, $distributorId, $invitedUserId, 6.00);
            $this->assertInviteFirstOrder($inviteId, $readyOrder->id, 'Invite relation stores first order id after reward apply.');

            $repeat = $service->run($storeId, 10, true, $distributorId);
            $this->printResult($repeat);
            $this->assertSameInt(0, (int)$repeat['rewardsCreated'], 'Repeat invite reward apply creates no duplicate rows.');
            $this->assertSameInt(1, (int)$repeat['duplicateOrders'], 'Repeat invite reward apply blocks existing reward by order id.');

            $summary = $service->summary($distributorId);
            $this->assertSameInt(1, count($summary['invites']), 'Distributor invite summary includes invite relation.');
            $this->assertRewardSummary($summary['rewardSummary'], DistributionInviteService::REWARD_STATUS_PENDING, 1, 6.00);

            $transaction->rollBack();
            $this->ok('Distribution invite/reward fixture data rolled back.');
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->fail('Distribution invite/reward fixture failed: ' . $e->getMessage());
        }
    }

    private function createRule(int $storeId, float $rate, float $minAmount, float $inviteRewardAmount)
    {
        Yii::$app->db->createCommand()->insert('{{%mall_distribution_rule}}', [
            'store_id' => $storeId,
            'name' => 'Fixture invite reward rule',
            'commission_rate' => $rate,
            'min_order_amount' => $minAmount,
            'invite_reward_amount' => $inviteRewardAmount,
            'rule_status' => DistributionCommissionService::RULE_STATUS_ACTIVE,
            'remark' => 'Created by mongoyia-distribution-invite-test/run',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();
    }

    private function createOrder(int $storeId, int $buyerId, int $distributorId, string $prefix, float $amount, int $paymentStatus, int $shipmentStatus): Order
    {
        $order = new Order();
        $order->store_id = $storeId;
        $order->parent_id = 1;
        $order->user_id = $buyerId;
        $order->address_id = 0;
        $order->name = 'Distribution invite fixture';
        $order->sn = $prefix . '-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
        $order->first_name = 'Codex';
        $order->last_name = 'Invite';
        $order->country_id = 0;
        $order->country = '';
        $order->province_id = 0;
        $order->province = '';
        $order->city_id = 0;
        $order->city = '';
        $order->district_id = 0;
        $order->district = '';
        $order->address = 'Local invite fixture';
        $order->address2 = '';
        $order->postcode = '';
        $order->mobile = '13800000000';
        $order->email = 'codex_distribution_invite@mongoyia.local';
        $order->distance = 0;
        $order->remark = 'Created by mongoyia-distribution-invite-test/run';
        $order->payment_method = Order::PAYMENT_METHOD_PAY;
        $order->payment_fee = 0;
        $order->payment_status = $paymentStatus;
        $order->paid_at = time();
        $order->stock_deducted_at = time();
        $order->stock_refunded_at = 0;
        $order->shipment_id = 9030;
        $order->shipment_name = 'Invite Reward Express';
        $order->shipment_fee = 0;
        $order->shipment_fee_deducted_at = 0;
        $order->shipment_status = $shipmentStatus;
        $order->logistics_review_status = Order::LOGISTICS_REVIEW_PASSED;
        $order->logistics_reviewed_at = time();
        $order->logistics_reviewed_by = 1;
        $order->logistics_review_remark = '';
        $order->shipped_at = time();
        $order->product_amount = $amount;
        $order->amount = $amount;
        $order->number = 1;
        $order->extra_fee = 0;
        $order->discount = 0;
        $order->tax = 0;
        $order->invoice = '';
        $order->fx_id = $distributorId;
        $order->type = Order::TYPE_DEFAULT;
        $order->sort = Order::SORT_DEFAULT;
        $order->status = $shipmentStatus;
        if (!$order->save()) {
            throw new \RuntimeException(json_encode($order->errors, JSON_UNESCAPED_UNICODE));
        }

        return $order;
    }

    private function printResult(array $result)
    {
        $this->stdout('Mode: ' . ($result['apply'] ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Scanned: {$result['scanned']}\n");
        $this->stdout("Ready orders: {$result['readyOrders']}\n");
        $this->stdout("Blocked orders: {$result['blockedOrders']}\n");
        $this->stdout("Duplicate orders: {$result['duplicateOrders']}\n");
        $this->stdout("Rewards created: {$result['rewardsCreated']}\n");
        $this->stdout('Reward amount: ' . number_format((float)$result['rewardAmount'], 2) . "\n");
        foreach ($result['distributors'] as $row) {
            $this->stdout("DISTRIBUTOR user={$row['distributor_user_id']} rewards={$row['rewards']} reward=" . number_format((float)$row['reward_amount'], 2) . "\n");
        }
        foreach ($result['rewards'] as $row) {
            $id = $row['reward_id'] === null ? 'preview' : ('#' . $row['reward_id']);
            $this->stdout("REWARD {$id} order={$row['order_id']} distributor={$row['distributor_user_id']} invited={$row['invited_user_id']} reward=" . number_format((float)$row['reward_amount'], 2) . "\n");
        }
        foreach ($result['blockedRows'] as $row) {
            $this->stdout("BLOCKED order={$row['order_id']} distributor={$row['distributor_user_id']} invited={$row['buyer_user_id']} reason={$row['reason']}\n");
        }
    }

    private function inviteId(int $invitedUserId): int
    {
        return (int)(new \yii\db\Query())->select('id')->from('{{%mall_distribution_invite}}')->where(['invited_user_id' => $invitedUserId, 'status' => 1])->scalar(Yii::$app->db);
    }

    private function assertPersistedReward(int $orderId, int $inviteId, int $distributorId, int $invitedUserId, float $rewardAmount)
    {
        $row = (new \yii\db\Query())->from('{{%mall_distribution_invite_reward}}')->where(['order_id' => $orderId, 'status' => 1])->one(Yii::$app->db);
        if (!$row) {
            $this->fail("Invite reward row for order {$orderId} was not found.");
            return;
        }

        $this->assertSameInt($inviteId, (int)$row['invite_id'], "Invite reward {$orderId} invite id is correct.");
        $this->assertSameInt($distributorId, (int)$row['distributor_user_id'], "Invite reward {$orderId} distributor is correct.");
        $this->assertSameInt($invitedUserId, (int)$row['invited_user_id'], "Invite reward {$orderId} invited user is correct.");
        $this->assertMoney($rewardAmount, (float)$row['reward_amount'], "Invite reward {$orderId} amount is correct.");
    }

    private function assertInviteFirstOrder(int $inviteId, int $orderId, string $message)
    {
        $actual = (int)(new \yii\db\Query())->select('first_order_id')->from('{{%mall_distribution_invite}}')->where(['id' => $inviteId])->scalar(Yii::$app->db);
        $this->assertSameInt($orderId, $actual, $message);
    }

    private function assertNoReward(int $orderId, string $message)
    {
        $exists = (new \yii\db\Query())->from('{{%mall_distribution_invite_reward}}')->where(['order_id' => $orderId])->exists(Yii::$app->db);
        if ($exists) {
            $this->fail($message . ' Reward unexpectedly exists.');
            return;
        }
        $this->ok($message);
    }

    private function assertBlockedReason(array $result, int $orderId, string $reason)
    {
        foreach ($result['blockedRows'] as $row) {
            if ((int)$row['order_id'] === $orderId && $row['reason'] === $reason) {
                $this->ok("Blocked order {$orderId} reason is {$reason}.");
                return;
            }
        }
        $this->fail("Blocked order {$orderId} reason {$reason} was not found.");
    }

    private function assertRewardSummary(array $summary, string $status, int $rows, float $rewardAmount)
    {
        foreach ($summary as $row) {
            if ((string)$row['reward_status'] !== $status) {
                continue;
            }
            $this->assertSameInt($rows, (int)$row['rows'], "Reward summary {$status} row count is correct.");
            $this->assertMoney($rewardAmount, (float)$row['reward_amount'], "Reward summary {$status} amount is correct.");
            return;
        }
        $this->fail("Missing reward summary bucket {$status}.");
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

    private function firstDistributorUserId(): int
    {
        $userId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['>', 'status', 0])
            ->andWhere(['not in', 'id', (new \yii\db\Query())
                ->select('fx_id')
                ->from('{{%mall_order}}')
                ->where(['>', 'fx_id', 0])
                ->andWhere(['>', 'status', 0])
                ->andWhere(['in', 'payment_status', [Order::PAYMENT_STATUS_COD, Order::PAYMENT_STATUS_PAID]])
            ])
            ->orderBy(['id' => SORT_ASC])
            ->scalar(Yii::$app->db);
        if ($userId > 0) {
            return $userId;
        }

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

    private function assertPositive(int $actual, string $message)
    {
        if ($actual <= 0) {
            $this->fail("{$message} Expected positive integer, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertSameInt(int $expected, int $actual, string $message)
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertSameString(string $expected, string $actual, string $message)
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
