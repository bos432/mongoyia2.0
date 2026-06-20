<?php

namespace common\services\mall;

use common\models\base\FundLog;
use common\models\mall\Order;
use common\models\Store;
use Yii;

class LogisticsFeeAdjustmentService
{
    public function run(int $storeId = 0, int $limit = 100, bool $apply = false): array
    {
        $limit = max(1, $limit);
        $query = Order::find()
            ->where(['>', 'shipment_fee', 0])
            ->andWhere(['>', 'status', Order::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        $result = [
            'ordersWithFee' => 0,
            'adjustable' => 0,
            'applied' => 0,
            'blocked' => 0,
            'reported' => 0,
            'plannedAmount' => 0.0,
            'appliedAmount' => 0.0,
            'rows' => [],
        ];

        foreach ($query->all() as $order) {
            $this->handleOrder($result, $order, $apply);
        }

        $result['plannedAmount'] = round($result['plannedAmount'], 2);
        $result['appliedAmount'] = round($result['appliedAmount'], 2);

        return $result;
    }

    private function handleOrder(array &$result, Order $order, bool $apply): void
    {
        $result['ordersWithFee']++;
        $fee = round((float)$order->shipment_fee, 2);
        $logTotal = $this->deductionLogTotal($order);
        $delta = round($fee - $logTotal, 2);

        if ((int)$order->shipment_fee_deducted_at <= 0) {
            $result['reported']++;
            $this->addRow($result, $order, 'report-only', 'fee not deducted', $fee, $logTotal, 0);
            return;
        }

        if ($logTotal <= 0) {
            $result['adjustable']++;
            $result['plannedAmount'] += $fee;
            if (!$apply) {
                $this->addRow($result, $order, 'dry-run', 'missing deduction log can be repaired', $fee, $logTotal, $fee);
                return;
            }

            $this->writeMissingLogRepair($order, $fee);
            $result['applied']++;
            $result['appliedAmount'] += $fee;
            $this->addRow($result, $order, 'applied', 'missing deduction log repaired', $fee, $logTotal, $fee);
            return;
        }

        if (abs($delta) < 0.01) {
            return;
        }

        if ($delta > 0) {
            $result['adjustable']++;
            $result['plannedAmount'] += $delta;
            if (!$apply) {
                $this->addRow($result, $order, 'dry-run', 'deduction amount can be adjusted', $fee, $logTotal, $delta);
                return;
            }

            if (!$this->deductAdjustment($order, $delta)) {
                $result['blocked']++;
                $this->addRow($result, $order, 'blocked', 'merchant deposit balance is insufficient', $fee, $logTotal, $delta);
                return;
            }

            $result['applied']++;
            $result['appliedAmount'] += $delta;
            $this->addRow($result, $order, 'applied', 'deduction amount adjusted', $fee, $logTotal, $delta);
            return;
        }

        $result['reported']++;
        $this->addRow($result, $order, 'report-only', 'deduction amount exceeds shipment fee', $fee, $logTotal, $delta);
    }

    private function writeMissingLogRepair(Order $order, float $fee): void
    {
        $store = Store::findOne((int)$order->store_id);
        if (!$store) {
            throw new \RuntimeException('Store not found for missing logistics fee log repair.');
        }
        $balance = round((float)$store->fund, 2);
        $this->createFundLog(
            (int)$order->store_id,
            $order,
            -$fee,
            $balance + $fee,
            $balance,
            'shipment_fee_missing_log_repair order_sn=' . $order->sn
        );
    }

    private function deductAdjustment(Order $order, float $delta): bool
    {
        $store = Store::findOne((int)$order->store_id);
        if (!$store) {
            throw new \RuntimeException('Store not found for logistics fee adjustment.');
        }
        $original = round((float)$store->fund, 2);
        if ($original < $delta) {
            return false;
        }

        $updated = Store::updateAllCounters([
            'fund' => -$delta,
            'consume_amount' => $delta,
            'consume_count' => 1,
        ], ['and', ['id' => (int)$order->store_id], ['>=', 'fund', $delta]]);
        if (!$updated) {
            return false;
        }

        $this->createFundLog(
            (int)$order->store_id,
            $order,
            -$delta,
            $original,
            $original - $delta,
            'shipment_fee_adjustment order_sn=' . $order->sn
        );

        return true;
    }

    private function addRow(array &$result, Order $order, string $status, string $reason, float $fee, float $logTotal, float $amount): void
    {
        if (count($result['rows']) >= 20) {
            return;
        }
        $result['rows'][] = [
            'id' => (int)$order->id,
            'sn' => (string)$order->sn,
            'store_id' => (int)$order->store_id,
            'status' => $status,
            'reason' => $reason,
            'fee' => round($fee, 2),
            'logTotal' => round($logTotal, 2),
            'amount' => round($amount, 2),
        ];
    }

    private function deductionLogTotal(Order $order): float
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

    private function createFundLog(int $storeId, Order $order, float $change, float $original, float $balance, string $remark): void
    {
        $log = new FundLog();
        $log->store_id = $storeId;
        $log->user_id = $this->operatorId();
        $log->name = '物流费调账：订单 #' . $order->id;
        $log->change = $change;
        $log->original = $original;
        $log->balance = $balance;
        $log->remark = $remark;
        $log->type = FundLog::TYPE_CONSUME;
        if (!$log->save()) {
            throw new \RuntimeException(json_encode($log->errors, JSON_UNESCAPED_UNICODE));
        }
    }

    private function operatorId(): int
    {
        try {
            if (Yii::$app->has('user') && !Yii::$app->user->isGuest) {
                return (int)Yii::$app->user->id;
            }
        } catch (\Throwable $e) {
        }

        return 1;
    }
}
