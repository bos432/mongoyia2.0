<?php

namespace common\services\mall;

class ProductInventoryPhase14Service
{
    public const VERSION = 'MONGOYIA_PRODUCT_INVENTORY_PHASE14_V1';
    public const SKU_APPLY_GATE = 'sku_generation_apply_requires_product_audit_and_inventory_acceptance';
    public const SHIPPING_TIMEOUT_APPLY_GATE = 'shipping_timeout_deposit_apply_requires_finance_acceptance';

    private const SHIPMENT_STATUS_UNSHIPPED = 60;
    private const SHIPMENT_STATUS_PREPARING = 70;
    private const SHIPMENT_STATUS_SHIPPING = 80;

    public function generateSkuCode(int $storeId, int $productId, string $attributeValue = '', int $sequence = 1): string
    {
        $hash = strtoupper(substr(sha1($storeId . ':' . $productId . ':' . trim($attributeValue)), 0, 6));
        return sprintf('MGY-S%04d-P%06d-%s-%03d', max(0, $storeId), max(0, $productId), $hash, max(1, $sequence));
    }

    public function inventoryLocationOptions(): array
    {
        return [
            'UB-WH-A' => 'Ulaanbaatar main warehouse A',
            'UB-WH-B' => 'Ulaanbaatar main warehouse B',
            'CN-BJ-01' => 'China Beijing cross-border warehouse',
            'CN-GZ-01' => 'China Guangzhou cross-border warehouse',
            'MN-STORE' => 'Merchant store self-ship location',
        ];
    }

    public function planSkuInventory(array $items, array $existingSkuCodes = []): array
    {
        $existing = array_fill_keys(array_map('strtoupper', $existingSkuCodes), true);
        $rows = [];
        $summary = [
            'items' => 0,
            'generated' => 0,
            'duplicates' => 0,
            'missing_location' => 0,
            'ready' => 0,
            'mutates_product' => false,
            'mutates_stock' => false,
        ];

        foreach ($items as $index => $item) {
            $summary['items']++;
            $storeId = (int)($item['store_id'] ?? 0);
            $productId = (int)($item['product_id'] ?? 0);
            $attributeValue = (string)($item['attribute_value'] ?? '');
            $sku = trim((string)($item['sku'] ?? ''));
            $generated = false;
            if ($sku === '') {
                $sku = $this->generateSkuCode($storeId, $productId, $attributeValue, $index + 1);
                $generated = true;
                $summary['generated']++;
            }

            $skuKey = strtoupper($sku);
            $duplicate = isset($existing[$skuKey]);
            $existing[$skuKey] = true;
            if ($duplicate) {
                $summary['duplicates']++;
            }

            $location = trim((string)($item['inventory_location'] ?? ''));
            if ($location === '') {
                $summary['missing_location']++;
            }

            $ready = !$duplicate && $location !== '';
            if ($ready) {
                $summary['ready']++;
            }

            $rows[] = [
                'store_id' => $storeId,
                'product_id' => $productId,
                'attribute_value' => $attributeValue,
                'sku' => $sku,
                'generated' => $generated,
                'duplicate' => $duplicate,
                'inventory_location' => $location,
                'stock' => (int)($item['stock'] ?? 0),
                'action' => $ready ? 'sku_inventory_ready_pending_apply' : 'manual_review_required',
                'apply_gate' => self::SKU_APPLY_GATE,
            ];
        }

        return [
            'version' => self::VERSION,
            'summary' => $summary,
            'rows' => $rows,
            'location_options' => $this->inventoryLocationOptions(),
            'safety' => [
                'dry_run_first' => true,
                'no_product_mutation' => true,
                'no_stock_mutation' => true,
            ],
        ];
    }

    public function planShippingTimeout(array $orders, int $now = 0): array
    {
        $now = $now > 0 ? $now : time();
        $rows = [];
        $summary = [
            'orders' => 0,
            'timeout_pending_deduction' => 0,
            'blocked_insufficient_fund' => 0,
            'watching' => 0,
            'already_shipped' => 0,
            'planned_deduction_amount' => 0.0,
            'mutates_order' => false,
            'mutates_fund' => false,
        ];

        foreach ($orders as $order) {
            $summary['orders']++;
            $paidAt = (int)($order['paid_at'] ?? 0);
            $timeoutHours = max(0, (int)($order['shipment_timeout_hours'] ?? 0));
            $deductFee = round((float)($order['shipment_timeout_deduct_fee'] ?? 0), 2);
            $shipmentStatus = (int)($order['shipment_status'] ?? 0);
            $deadline = $paidAt > 0 && $timeoutHours > 0 ? $paidAt + $timeoutHours * 3600 : 0;
            $storeFund = round((float)($order['store_fund'] ?? 0), 2);

            $row = [
                'order_id' => (int)($order['order_id'] ?? 0),
                'order_sn' => (string)($order['order_sn'] ?? ''),
                'store_id' => (int)($order['store_id'] ?? 0),
                'shipment_status' => $shipmentStatus,
                'deadline_at' => $deadline,
                'deduct_fee' => $deductFee,
                'store_fund' => $storeFund,
                'action' => 'watch_until_deadline',
                'apply_gate' => self::SHIPPING_TIMEOUT_APPLY_GATE,
                'mutates_order' => false,
                'mutates_fund' => false,
                'notes' => '',
            ];

            if ($shipmentStatus >= self::SHIPMENT_STATUS_SHIPPING) {
                $row['action'] = 'already_shipped';
                $row['notes'] = 'Order is already shipped or received; timeout deduction is not planned.';
                $summary['already_shipped']++;
            } elseif ($deadline > 0 && $now > $deadline && $deductFee > 0) {
                if ($storeFund >= $deductFee) {
                    $row['action'] = 'deduct_deposit_pending_apply';
                    $row['notes'] = 'Shipping timeout is eligible for controlled deposit deduction after finance acceptance.';
                    $summary['timeout_pending_deduction']++;
                    $summary['planned_deduction_amount'] += $deductFee;
                } else {
                    $row['action'] = 'blocked_insufficient_fund';
                    $row['notes'] = 'Store fund is insufficient; finance review is required before any deduction.';
                    $summary['blocked_insufficient_fund']++;
                }
            } else {
                $row['notes'] = 'Order has not exceeded the configured shipping timeout.';
                $summary['watching']++;
            }

            $rows[] = $row;
        }

        $summary['planned_deduction_amount'] = round($summary['planned_deduction_amount'], 2);

        return [
            'version' => self::VERSION,
            'summary' => $summary,
            'rows' => $rows,
            'safety' => [
                'dry_run_first' => true,
                'no_order_mutation' => true,
                'no_fund_mutation' => true,
                'finance_acceptance_required' => true,
            ],
        ];
    }

    public function fixtureSkuItems(): array
    {
        return [
            ['store_id' => 1, 'product_id' => 101, 'attribute_value' => 'Black/L', 'sku' => '', 'inventory_location' => 'UB-WH-A', 'stock' => 20],
            ['store_id' => 1, 'product_id' => 102, 'attribute_value' => 'White/M', 'sku' => 'MGY-DUPLICATE', 'inventory_location' => 'CN-BJ-01', 'stock' => 8],
            ['store_id' => 2, 'product_id' => 201, 'attribute_value' => 'Default', 'sku' => '', 'inventory_location' => '', 'stock' => 5],
        ];
    }

    public function fixtureShippingOrders(int $now): array
    {
        return [
            [
                'order_id' => 14301,
                'order_sn' => 'P14-TIMEOUT-READY',
                'store_id' => 1,
                'paid_at' => $now - 96 * 3600,
                'shipment_status' => self::SHIPMENT_STATUS_UNSHIPPED,
                'shipment_timeout_hours' => 72,
                'shipment_timeout_deduct_fee' => 5.00,
                'store_fund' => 20.00,
            ],
            [
                'order_id' => 14302,
                'order_sn' => 'P14-TIMEOUT-BLOCKED',
                'store_id' => 1,
                'paid_at' => $now - 96 * 3600,
                'shipment_status' => self::SHIPMENT_STATUS_PREPARING,
                'shipment_timeout_hours' => 72,
                'shipment_timeout_deduct_fee' => 10.00,
                'store_fund' => 2.00,
            ],
            [
                'order_id' => 14303,
                'order_sn' => 'P14-TIMEOUT-WATCH',
                'store_id' => 1,
                'paid_at' => $now - 12 * 3600,
                'shipment_status' => self::SHIPMENT_STATUS_UNSHIPPED,
                'shipment_timeout_hours' => 72,
                'shipment_timeout_deduct_fee' => 5.00,
                'store_fund' => 20.00,
            ],
            [
                'order_id' => 14304,
                'order_sn' => 'P14-TIMEOUT-SHIPPED',
                'store_id' => 1,
                'paid_at' => $now - 96 * 3600,
                'shipment_status' => self::SHIPMENT_STATUS_SHIPPING,
                'shipment_timeout_hours' => 72,
                'shipment_timeout_deduct_fee' => 5.00,
                'store_fund' => 20.00,
            ],
        ];
    }
}
