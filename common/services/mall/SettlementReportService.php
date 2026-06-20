<?php

namespace common\services\mall;

use Yii;

class SettlementReportService
{
    public function run(int $storeId = 0, string $dateFrom = '', string $dateTo = '', int $limit = 500): array
    {
        $drafts = $this->draftRows($storeId, $dateFrom, $dateTo, max(1, $limit));
        $evidenceRows = (new SettlementPayoutEvidenceService())->evidenceRows(array_column($drafts, 'id'));
        $closeService = new SettlementCloseService();
        $result = $this->emptyResult();

        foreach ($drafts as $draft) {
            $result['draftsScanned']++;
            $draftId = (int)$draft['id'];
            $status = (string)$draft['draft_status'];
            $evidence = $evidenceRows[$draftId] ?? null;
            $storeKey = (int)$draft['store_id'];
            if (!isset($result['stores'][$storeKey])) {
                $result['stores'][$storeKey] = $this->emptyStoreRow($storeKey);
            }
            $store = &$result['stores'][$storeKey];

            $this->addDraftTotals($result['totals'], $draft);
            $this->addDraftTotals($store, $draft);
            $result['statusCounts'][$status] = ($result['statusCounts'][$status] ?? 0) + 1;
            $store['statusCounts'][$status] = ($store['statusCounts'][$status] ?? 0) + 1;

            if ($evidence) {
                $evidenceAmount = round((float)$evidence['amount'], 2);
                $result['totals']['evidence_amount'] += $evidenceAmount;
                $store['evidence_amount'] += $evidenceAmount;
            }

            if ($status === SettlementDraftService::DRAFT_STATUS_CLOSED) {
                $result['closedDrafts']++;
                $result['totals']['closed_net_amount'] += round((float)$draft['net_amount'], 2);
                $store['closed_drafts']++;
                $store['closed_net_amount'] += round((float)$draft['net_amount'], 2);
            } else {
                $reason = $closeService->blockReason($draft);
                $result['openDrafts']++;
                $result['openReasons'][$reason] = ($result['openReasons'][$reason] ?? 0) + 1;
                $store['openReasons'][$reason] = ($store['openReasons'][$reason] ?? 0) + 1;
            }
            unset($store);
        }

        $this->roundTotals($result['totals']);
        foreach ($result['stores'] as &$store) {
            $this->roundTotals($store);
        }
        unset($store);
        ksort($result['stores']);
        ksort($result['statusCounts']);
        ksort($result['openReasons']);

        return $result;
    }

    private function draftRows(int $storeId, string $dateFrom, string $dateTo, int $limit): array
    {
        $query = (new \yii\db\Query())
            ->from('{{%mall_settlement_draft}}')
            ->where(['status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit);
        if ($storeId > 0) {
            $query->andWhere(['store_id' => $storeId]);
        }
        if ($dateFrom !== '') {
            $from = strtotime($dateFrom . ' 00:00:00');
            if ($from !== false) {
                $query->andWhere(['>=', 'created_at', $from]);
            }
        }
        if ($dateTo !== '') {
            $to = strtotime($dateTo . ' 23:59:59');
            if ($to !== false) {
                $query->andWhere(['<=', 'created_at', $to]);
            }
        }

        return $query->all(Yii::$app->db);
    }

    private function emptyResult(): array
    {
        return [
            'draftsScanned' => 0,
            'closedDrafts' => 0,
            'openDrafts' => 0,
            'totals' => $this->emptyTotals(),
            'statusCounts' => [],
            'openReasons' => [],
            'stores' => [],
        ];
    }

    private function emptyStoreRow(int $storeId): array
    {
        return array_merge(['store_id' => $storeId, 'statusCounts' => [], 'openReasons' => []], $this->emptyTotals(), [
            'closed_drafts' => 0,
        ]);
    }

    private function emptyTotals(): array
    {
        return [
            'drafts' => 0,
            'orders' => 0,
            'order_amount' => 0.0,
            'shipment_fee_deducted' => 0.0,
            'net_amount' => 0.0,
            'closed_net_amount' => 0.0,
            'evidence_amount' => 0.0,
        ];
    }

    private function addDraftTotals(array &$totals, array $draft): void
    {
        $totals['drafts']++;
        $totals['orders'] += (int)$draft['order_count'];
        $totals['order_amount'] += round((float)$draft['order_amount'], 2);
        $totals['shipment_fee_deducted'] += round((float)$draft['shipment_fee_deducted'], 2);
        $totals['net_amount'] += round((float)$draft['net_amount'], 2);
    }

    private function roundTotals(array &$totals): void
    {
        foreach (['order_amount', 'shipment_fee_deducted', 'net_amount', 'closed_net_amount', 'evidence_amount'] as $key) {
            if (isset($totals[$key])) {
                $totals[$key] = round((float)$totals[$key], 2);
            }
        }
    }
}
