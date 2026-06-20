<?php

namespace common\services\mall;

use common\models\mall\Order;
use Yii;

class DistributionCommissionService
{
    const RULE_STATUS_ACTIVE = 'active';
    const COMMISSION_STATUS_PENDING = 'pending';
    const COMMISSION_STATUS_APPROVED = 'approved';
    const COMMISSION_STATUS_REJECTED = 'rejected';
    const COMMISSION_STATUS_WITHDRAWN = 'withdrawn';

    public function run(int $storeId = 0, int $limit = 100, bool $apply = false, int $distributorId = 0): array
    {
        $orders = $this->candidateOrders($storeId, max(1, $limit), $distributorId);
        $result = $this->emptyResult($apply);

        foreach ($orders as $order) {
            $result['scanned']++;
            $blockedReason = $this->blockedReason($order);
            if ($blockedReason !== '') {
                $result['blockedOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, $blockedReason);
                continue;
            }

            if ($this->commissionExists((int)$order['id'])) {
                $result['duplicateOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, 'commission already exists');
                continue;
            }

            $rule = $this->activeRule((int)$order['store_id']);
            if (!$rule) {
                $result['blockedOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, 'missing active distribution rule');
                continue;
            }

            if (round((float)$order['amount'], 2) < round((float)$rule['min_order_amount'], 2)) {
                $result['blockedOrders']++;
                $result['blockedRows'][] = $this->blockedRow($order, 'below minimum order amount');
                continue;
            }

            $row = $this->commissionRow($order, $rule);
            $result['readyOrders']++;
            $result['orderAmount'] += (float)$row['order_amount'];
            $result['commissionAmount'] += (float)$row['commission_amount'];
            $this->addStoreSummary($result, $row);
            $this->addDistributorSummary($result, $row);

            if ($apply) {
                Yii::$app->db->createCommand()->insert('{{%mall_distribution_commission}}', $row)->execute();
                $result['commissionsCreated']++;
                $row['commission_id'] = (int)Yii::$app->db->getLastInsertID();
            } else {
                $row['commission_id'] = null;
            }
            $result['commissions'][] = $row;
        }

        $this->roundResult($result);
        return $result;
    }

    private function candidateOrders(int $storeId, int $limit, int $distributorId): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_order}}')
            ->where(['>', 'status', 0])
            ->andWhere(['>', 'fx_id', 0])
            ->andWhere(['in', 'payment_status', [Order::PAYMENT_STATUS_COD, Order::PAYMENT_STATUS_PAID]])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit);

        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($distributorId > 0) {
            $query->andWhere(['fx_id' => $distributorId]);
        }

        return $query->all(Yii::$app->db);
    }

    private function blockedReason(array $order): string
    {
        if ((int)$order['fx_id'] <= 0) {
            return 'missing distributor attribution';
        }
        if (!in_array((int)$order['payment_status'], [Order::PAYMENT_STATUS_COD, Order::PAYMENT_STATUS_PAID], true)) {
            return 'not paid/COD';
        }
        if ((int)$order['shipment_status'] < Order::SHIPMENT_STATUS_RECEIVED) {
            return 'not received';
        }
        if (round((float)$order['amount'], 2) <= 0) {
            return 'zero amount';
        }

        return '';
    }

    private function activeRule(int $storeId): ?array
    {
        $rule = (new \yii\db\Query())
            ->from('{{%mall_distribution_rule}}')
            ->where(['store_id' => $storeId, 'rule_status' => self::RULE_STATUS_ACTIVE, 'status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db);

        return $rule ?: null;
    }

    private function commissionExists(int $orderId): bool
    {
        return (new \yii\db\Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['order_id' => $orderId])
            ->exists(Yii::$app->db);
    }

    private function commissionRow(array $order, array $rule): array
    {
        $now = time();
        $amount = round((float)$order['amount'], 2);
        $rate = round((float)$rule['commission_rate'], 2);
        return [
            'store_id' => (int)$order['store_id'],
            'order_id' => (int)$order['id'],
            'order_sn' => (string)$order['sn'],
            'distributor_user_id' => (int)$order['fx_id'],
            'buyer_user_id' => (int)$order['user_id'],
            'order_amount' => $amount,
            'commission_rate' => $rate,
            'commission_amount' => round($amount * $rate / 100, 2),
            'commission_status' => self::COMMISSION_STATUS_PENDING,
            'source' => 'order_fx',
            'remark' => 'Created by mongoyia-distribution-test/run',
            'settled_at' => 0,
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    private function blockedRow(array $order, string $reason): array
    {
        return [
            'order_id' => (int)$order['id'],
            'store_id' => (int)$order['store_id'],
            'distributor_user_id' => (int)($order['fx_id'] ?? 0),
            'order_amount' => round((float)($order['amount'] ?? 0), 2),
            'reason' => $reason,
        ];
    }

    private function addStoreSummary(array &$result, array $row): void
    {
        $storeId = (int)$row['store_id'];
        if (!isset($result['stores'][$storeId])) {
            $result['stores'][$storeId] = [
                'store_id' => $storeId,
                'orders' => 0,
                'order_amount' => 0.0,
                'commission_amount' => 0.0,
            ];
        }
        $result['stores'][$storeId]['orders']++;
        $result['stores'][$storeId]['order_amount'] += (float)$row['order_amount'];
        $result['stores'][$storeId]['commission_amount'] += (float)$row['commission_amount'];
    }

    private function addDistributorSummary(array &$result, array $row): void
    {
        $userId = (int)$row['distributor_user_id'];
        if (!isset($result['distributors'][$userId])) {
            $result['distributors'][$userId] = [
                'distributor_user_id' => $userId,
                'orders' => 0,
                'order_amount' => 0.0,
                'commission_amount' => 0.0,
            ];
        }
        $result['distributors'][$userId]['orders']++;
        $result['distributors'][$userId]['order_amount'] += (float)$row['order_amount'];
        $result['distributors'][$userId]['commission_amount'] += (float)$row['commission_amount'];
    }

    private function emptyResult(bool $apply): array
    {
        return [
            'apply' => $apply,
            'scanned' => 0,
            'readyOrders' => 0,
            'blockedOrders' => 0,
            'duplicateOrders' => 0,
            'commissionsCreated' => 0,
            'orderAmount' => 0.0,
            'commissionAmount' => 0.0,
            'commissions' => [],
            'blockedRows' => [],
            'stores' => [],
            'distributors' => [],
        ];
    }

    private function roundResult(array &$result): void
    {
        foreach (['orderAmount', 'commissionAmount'] as $key) {
            $result[$key] = round((float)$result[$key], 2);
        }
        foreach (['stores', 'distributors'] as $group) {
            foreach ($result[$group] as &$row) {
                $row['order_amount'] = round((float)$row['order_amount'], 2);
                $row['commission_amount'] = round((float)$row['commission_amount'], 2);
            }
            unset($row);
            ksort($result[$group]);
        }
    }
}
