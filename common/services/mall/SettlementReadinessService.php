<?php

namespace common\services\mall;

use common\models\base\FundLog;
use common\models\mall\Order;
use Yii;

class SettlementReadinessService
{
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
            'ready' => 0,
            'pendingReview' => 0,
            'feeIssues' => 0,
            'refunded' => 0,
            'readyAmount' => 0.0,
            'blockedAmount' => 0.0,
            'rows' => [],
        ];

        foreach ($query->all() as $order) {
            $result['scanned']++;
            $reason = $this->settlementBlockReason($order);
            if ($reason === '') {
                $result['ready']++;
                $result['readyAmount'] += (float)$order->amount;
                $this->addRow($result, $order, 'ready');
                continue;
            }

            $result['blockedAmount'] += (float)$order->amount;
            if ($reason === 'refunded order') {
                $result['refunded']++;
            } elseif (strpos($reason, 'logistics review') === 0) {
                $result['pendingReview']++;
            } else {
                $result['feeIssues']++;
            }
            $this->addRow($result, $order, $reason);
        }

        $result['readyAmount'] = round($result['readyAmount'], 2);
        $result['blockedAmount'] = round($result['blockedAmount'], 2);

        return $result;
    }

    public function settlementBlockReason(Order $order): string
    {
        if ((int)$order->payment_status === Order::PAYMENT_STATUS_REFUND) {
            return 'refunded order';
        }
        if (!in_array((int)$order->payment_status, [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD], true)) {
            return 'not paid/COD';
        }
        if ((int)$order->logistics_review_status !== Order::LOGISTICS_REVIEW_PASSED) {
            return 'logistics review pending';
        }

        $fee = round((float)$order->shipment_fee, 2);
        if ($fee <= 0) {
            return '';
        }
        if ((int)$order->shipment_fee_deducted_at <= 0) {
            return 'logistics fee not deducted';
        }
        if (abs($this->deductionLogTotal($order) - $fee) >= 0.01) {
            return 'logistics fee not reconciled';
        }

        return '';
    }

    public function deductionLogTotal(Order $order): float
    {
        $rows = (new \yii\db\Query())
            ->select('change')
            ->from('{{%base_fund_log}}')
            ->where(['store_id' => (int)$order->store_id, 'type' => FundLog::TYPE_CONSUME])
            ->andWhere([
                'or',
                ['like', 'remark', 'shipment_fee_deduction order_sn=' . $order->sn],
                ['like', 'remark', 'shipment_fee_missing_log_repair order_sn=' . $order->sn],
                ['like', 'remark', 'shipment_fee_adjustment order_sn=' . $order->sn],
            ])
            ->all(Yii::$app->db);

        $total = 0.0;
        foreach ($rows as $row) {
            $total += abs((float)$row['change']);
        }

        return round($total, 2);
    }

    private function addRow(array &$result, Order $order, string $reason): void
    {
        if (count($result['rows']) >= 20) {
            return;
        }
        $result['rows'][] = [
            'id' => (int)$order->id,
            'sn' => (string)$order->sn,
            'store_id' => (int)$order->store_id,
            'payment_status' => (int)$order->payment_status,
            'shipment_status' => (int)$order->shipment_status,
            'logistics_review_status' => (int)$order->logistics_review_status,
            'shipment_fee' => round((float)$order->shipment_fee, 2),
            'logTotal' => $this->deductionLogTotal($order),
            'amount' => round((float)$order->amount, 2),
            'reason' => $reason,
        ];
    }
}
