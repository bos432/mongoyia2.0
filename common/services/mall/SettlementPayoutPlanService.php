<?php

namespace common\services\mall;

use common\models\mall\Order;

class SettlementPayoutPlanService
{
    private $readiness;

    public function __construct(?SettlementReadinessService $readiness = null)
    {
        $this->readiness = $readiness ?: new SettlementReadinessService();
    }

    public function run(int $storeId = 0, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $query = Order::find()
            ->where(['shipment_status' => Order::SHIPMENT_STATUS_RECEIVED])
            ->andWhere(['>', 'status', Order::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        $result = [
            'scanned' => 0,
            'readyOrders' => 0,
            'blockedOrders' => 0,
            'readyAmount' => 0.0,
            'shipmentFeeDeducted' => 0.0,
            'netPayoutAmount' => 0.0,
            'stores' => [],
            'blockedRows' => [],
        ];

        foreach ($query->all() as $order) {
            $result['scanned']++;
            $reason = $this->readiness->settlementBlockReason($order);
            if ($reason !== '') {
                $result['blockedOrders']++;
                $this->addBlockedRow($result, $order, $reason);
                continue;
            }

            $storeId = (int)$order->store_id;
            if (!isset($result['stores'][$storeId])) {
                $result['stores'][$storeId] = [
                    'store_id' => $storeId,
                    'orders' => 0,
                    'orderAmount' => 0.0,
                    'shipmentFeeDeducted' => 0.0,
                    'netPayoutAmount' => 0.0,
                    'orderIds' => [],
                ];
            }

            $amount = round((float)$order->amount, 2);
            $fee = $this->readiness->deductionLogTotal($order);
            $result['readyOrders']++;
            $result['readyAmount'] += $amount;
            $result['shipmentFeeDeducted'] += $fee;
            $result['stores'][$storeId]['orders']++;
            $result['stores'][$storeId]['orderAmount'] += $amount;
            $result['stores'][$storeId]['shipmentFeeDeducted'] += $fee;
            $result['stores'][$storeId]['orderIds'][] = (int)$order->id;
        }

        foreach ($result['stores'] as &$row) {
            $row['orderAmount'] = round($row['orderAmount'], 2);
            $row['shipmentFeeDeducted'] = round($row['shipmentFeeDeducted'], 2);
            $row['netPayoutAmount'] = round($row['orderAmount'], 2);
        }
        unset($row);

        $result['stores'] = array_values($result['stores']);
        $result['readyAmount'] = round($result['readyAmount'], 2);
        $result['shipmentFeeDeducted'] = round($result['shipmentFeeDeducted'], 2);
        $result['netPayoutAmount'] = round($result['readyAmount'], 2);

        return $result;
    }

    private function addBlockedRow(array &$result, Order $order, string $reason): void
    {
        if (count($result['blockedRows']) >= 20) {
            return;
        }
        $result['blockedRows'][] = [
            'id' => (int)$order->id,
            'sn' => (string)$order->sn,
            'store_id' => (int)$order->store_id,
            'amount' => round((float)$order->amount, 2),
            'reason' => $reason,
        ];
    }
}
