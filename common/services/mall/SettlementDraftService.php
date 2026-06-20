<?php

namespace common\services\mall;

use common\models\mall\Order;
use Yii;

class SettlementDraftService
{
    const DRAFT_STATUS_DRAFT = 'draft';
    const DRAFT_STATUS_SUBMITTED = 'submitted';
    const DRAFT_STATUS_APPROVED = 'approved';
    const DRAFT_STATUS_REJECTED = 'rejected';
    const DRAFT_STATUS_CANCELLED = 'cancelled';
    const DRAFT_STATUS_CLOSED = 'closed';

    private $readiness;

    public function __construct(?SettlementReadinessService $readiness = null)
    {
        $this->readiness = $readiness ?: new SettlementReadinessService();
    }

    public function run(int $storeId = 0, int $limit = 100, bool $apply = false): array
    {
        $limit = max(1, $limit);
        $result = [
            'apply' => $apply,
            'scanned' => 0,
            'readyOrders' => 0,
            'draftsCreated' => 0,
            'ordersInserted' => 0,
            'blockedOrders' => 0,
            'duplicateOrders' => 0,
            'orderAmount' => 0.0,
            'shipmentFeeDeducted' => 0.0,
            'netAmount' => 0.0,
            'drafts' => [],
            'blockedRows' => [],
        ];

        $storeRows = [];
        foreach ($this->candidateOrders($storeId, $limit) as $order) {
            $result['scanned']++;
            $orderId = (int)$order->id;
            $blockReason = $this->readiness->settlementBlockReason($order);
            if ($blockReason !== '') {
                $result['blockedOrders']++;
                $this->addBlockedRow($result, $order, $blockReason);
                continue;
            }

            if ($this->activeDraftOrderExists($orderId)) {
                $result['duplicateOrders']++;
                $this->addBlockedRow($result, $order, 'already in active settlement draft');
                continue;
            }

            $storeKey = (int)$order->store_id;
            if (!isset($storeRows[$storeKey])) {
                $storeRows[$storeKey] = [
                    'store_id' => $storeKey,
                    'order_count' => 0,
                    'order_amount' => 0.0,
                    'shipment_fee_deducted' => 0.0,
                    'net_amount' => 0.0,
                    'order_ids' => [],
                    'orders' => [],
                ];
            }

            $amount = round((float)$order->amount, 2);
            $fee = $this->readiness->deductionLogTotal($order);
            $storeRows[$storeKey]['order_count']++;
            $storeRows[$storeKey]['order_amount'] += $amount;
            $storeRows[$storeKey]['shipment_fee_deducted'] += $fee;
            $storeRows[$storeKey]['net_amount'] += $amount;
            $storeRows[$storeKey]['order_ids'][] = $orderId;
            $storeRows[$storeKey]['orders'][] = [
                'id' => $orderId,
                'sn' => (string)$order->sn,
                'store_id' => $storeKey,
                'amount' => $amount,
                'shipment_fee_deducted' => $fee,
                'payment_status' => (int)$order->payment_status,
                'shipment_status' => (int)$order->shipment_status,
                'logistics_review_status' => (int)$order->logistics_review_status,
            ];
        }

        foreach ($storeRows as &$storeRow) {
            $storeRow['order_amount'] = round($storeRow['order_amount'], 2);
            $storeRow['shipment_fee_deducted'] = round($storeRow['shipment_fee_deducted'], 2);
            $storeRow['net_amount'] = round($storeRow['net_amount'], 2);
            $result['readyOrders'] += (int)$storeRow['order_count'];
            $result['orderAmount'] += (float)$storeRow['order_amount'];
            $result['shipmentFeeDeducted'] += (float)$storeRow['shipment_fee_deducted'];
            $result['netAmount'] += (float)$storeRow['net_amount'];
        }
        unset($storeRow);

        if ($apply && $storeRows) {
            $this->createDrafts($storeRows, $result);
        } else {
            foreach ($storeRows as $storeRow) {
                $result['drafts'][] = $this->draftPreviewRow($storeRow, null);
            }
        }

        $result['orderAmount'] = round($result['orderAmount'], 2);
        $result['shipmentFeeDeducted'] = round($result['shipmentFeeDeducted'], 2);
        $result['netAmount'] = round($result['netAmount'], 2);

        return $result;
    }

    public function activeDraftOrderExists(int $orderId): bool
    {
        return (new \yii\db\Query())
            ->from('{{%mall_settlement_draft_order}} sdo')
            ->innerJoin('{{%mall_settlement_draft}} sd', 'sd.id = sdo.draft_id')
            ->where(['sdo.order_id' => $orderId, 'sdo.status' => 1, 'sd.status' => 1])
            ->andWhere(['<>', 'sd.draft_status', self::DRAFT_STATUS_CANCELLED])
            ->exists(Yii::$app->db);
    }

    private function candidateOrders(int $storeId, int $limit): array
    {
        $query = Order::find()
            ->where(['shipment_status' => Order::SHIPMENT_STATUS_RECEIVED])
            ->andWhere(['>', 'status', Order::STATUS_DELETED])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }

        return $query->all();
    }

    private function createDrafts(array $storeRows, array &$result): void
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($storeRows as $storeRow) {
                $freshOrders = [];
                foreach ($storeRow['orders'] as $orderRow) {
                    if ($this->activeDraftOrderExists((int)$orderRow['id'])) {
                        $result['duplicateOrders']++;
                        continue;
                    }
                    $freshOrders[] = $orderRow;
                }
                if (!$freshOrders) {
                    continue;
                }

                $storeRow['orders'] = $freshOrders;
                $storeRow['order_ids'] = array_map(static function ($row) {
                    return (int)$row['id'];
                }, $freshOrders);
                $storeRow['order_count'] = count($freshOrders);
                $storeRow['order_amount'] = round(array_sum(array_column($freshOrders, 'amount')), 2);
                $storeRow['shipment_fee_deducted'] = round(array_sum(array_column($freshOrders, 'shipment_fee_deducted')), 2);
                $storeRow['net_amount'] = round($storeRow['order_amount'], 2);

                $draftId = $this->insertDraft($storeRow);
                $this->insertDraftOrders($draftId, $storeRow['orders']);
                $result['draftsCreated']++;
                $result['ordersInserted'] += count($storeRow['orders']);
                $result['drafts'][] = $this->draftPreviewRow($storeRow, $draftId);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function insertDraft(array $storeRow): int
    {
        $now = time();
        Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft}}', [
            'store_id' => (int)$storeRow['store_id'],
            'sn' => $this->generateDraftSn((int)$storeRow['store_id']),
            'order_count' => (int)$storeRow['order_count'],
            'order_amount' => round((float)$storeRow['order_amount'], 2),
            'shipment_fee_deducted' => round((float)$storeRow['shipment_fee_deducted'], 2),
            'net_amount' => round((float)$storeRow['net_amount'], 2),
            'draft_status' => self::DRAFT_STATUS_DRAFT,
            'remark' => 'Created by mongoyia-settlement-draft-readiness/run',
            'type' => 1,
            'sort' => 50,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => 1,
            'updated_by' => 1,
        ])->execute();

        return (int)Yii::$app->db->getLastInsertID();
    }

    private function insertDraftOrders(int $draftId, array $orders): void
    {
        $now = time();
        foreach ($orders as $order) {
            Yii::$app->db->createCommand()->insert('{{%mall_settlement_draft_order}}', [
                'draft_id' => $draftId,
                'order_id' => (int)$order['id'],
                'order_sn' => (string)$order['sn'],
                'store_id' => (int)$order['store_id'],
                'order_amount' => round((float)$order['amount'], 2),
                'shipment_fee_deducted' => round((float)$order['shipment_fee_deducted'], 2),
                'payment_status' => (int)$order['payment_status'],
                'shipment_status' => (int)$order['shipment_status'],
                'logistics_review_status' => (int)$order['logistics_review_status'],
                'type' => 1,
                'sort' => 50,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => 1,
                'updated_by' => 1,
            ])->execute();
        }
    }

    private function draftPreviewRow(array $storeRow, ?int $draftId): array
    {
        return [
            'draft_id' => $draftId,
            'store_id' => (int)$storeRow['store_id'],
            'order_count' => (int)$storeRow['order_count'],
            'order_amount' => round((float)$storeRow['order_amount'], 2),
            'shipment_fee_deducted' => round((float)$storeRow['shipment_fee_deducted'], 2),
            'net_amount' => round((float)$storeRow['net_amount'], 2),
            'order_ids' => $storeRow['order_ids'],
        ];
    }

    private function generateDraftSn(int $storeId): string
    {
        return 'SETD-' . date('YmdHis') . '-' . $storeId . '-' . mt_rand(1000, 9999);
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
