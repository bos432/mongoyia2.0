<?php

namespace common\services\mall;

class LogisticsTrackingSyncService
{
    public const VERSION = 'MONGOYIA_LOGISTICS_TRACKING_SYNC_V1';

    private const SHIPMENT_STATUS_SHIPPING = 80;
    private const SHIPMENT_STATUS_RECEIVED = 90;

    private const ABNORMAL_STATUS_RULES = [
        'exception' => 'manual_review_required',
        'returned' => 'manual_review_required',
        'manual_review' => 'manual_review_required',
    ];

    private $adapter;

    public function __construct(?LogisticsProviderAdapterService $adapter = null)
    {
        $this->adapter = $adapter ?: new LogisticsProviderAdapterService();
    }

    public function planSync(array $shipments): array
    {
        $rows = [];
        $summary = [
            'scanned' => 0,
            'planned' => 0,
            'idempotent_skips' => 0,
            'normal' => 0,
            'abnormal' => 0,
            'provider_blocked' => 0,
            'network_calls' => 0,
            'mutates_business_data' => false,
        ];

        foreach ($shipments as $shipment) {
            $summary['scanned']++;
            $row = $this->planRow($shipment);
            $rows[] = $row;
            $summary['network_calls'] += (int)($row['network_calls'] ?? 0);

            if (!empty($row['idempotent_skip'])) {
                $summary['idempotent_skips']++;
            } elseif (!empty($row['provider_blocked'])) {
                $summary['provider_blocked']++;
            } elseif (!empty($row['abnormal'])) {
                $summary['abnormal']++;
                $summary['planned']++;
            } else {
                $summary['normal']++;
                $summary['planned']++;
            }
        }

        return [
            'version' => self::VERSION,
            'status' => 'ok',
            'summary' => $summary,
            'rows' => $rows,
            'safety' => [
                'dry_run_first' => true,
                'provider_secret_never_logged' => true,
                'no_order_mutation' => true,
                'no_fund_mutation' => true,
                'no_stock_mutation' => true,
            ],
        ];
    }

    public function fixtureShipments(): array
    {
        return [
            [
                'order_id' => 140201,
                'order_sn' => 'P14-TRACK-DELIVERED',
                'provider' => LogisticsProviderAdapterService::PROVIDER_SIMULATED,
                'tracking_no' => 'SIM-TRACK-DELIVERED',
                'current_shipment_status' => self::SHIPMENT_STATUS_SHIPPING,
                'last_sync_key' => '',
            ],
            [
                'order_id' => 140202,
                'order_sn' => 'P14-TRACK-INTRANSIT',
                'provider' => LogisticsProviderAdapterService::PROVIDER_SIMULATED,
                'tracking_no' => 'SIM-TRACK-001',
                'current_shipment_status' => self::SHIPMENT_STATUS_SHIPPING,
                'last_sync_key' => '',
            ],
            [
                'order_id' => 140203,
                'order_sn' => 'P14-TRACK-EXCEPTION',
                'provider' => LogisticsProviderAdapterService::PROVIDER_SIMULATED,
                'tracking_no' => 'SIM-TRACK-EXCEPTION',
                'current_shipment_status' => self::SHIPMENT_STATUS_SHIPPING,
                'last_sync_key' => '',
            ],
            [
                'order_id' => 140204,
                'order_sn' => 'P14-TRACK-IDEMPOTENT',
                'provider' => LogisticsProviderAdapterService::PROVIDER_SIMULATED,
                'tracking_no' => 'SIM-TRACK-DELIVERED',
                'current_shipment_status' => self::SHIPMENT_STATUS_RECEIVED,
                'last_sync_key' => $this->syncKey('simulated', 'SIM-TRACK-DELIVERED', 'delivered', 3),
            ],
            [
                'order_id' => 140205,
                'order_sn' => 'P14-TRACK-REAL-BLOCKED',
                'provider' => LogisticsProviderAdapterService::PROVIDER_EXTERNAL_CONTRACT,
                'tracking_no' => 'REAL-TRACK-001',
                'current_shipment_status' => self::SHIPMENT_STATUS_SHIPPING,
                'last_sync_key' => '',
            ],
        ];
    }

    public function abnormalStatusRules(): array
    {
        return self::ABNORMAL_STATUS_RULES;
    }

    private function planRow(array $shipment): array
    {
        $provider = (string)($shipment['provider'] ?? LogisticsProviderAdapterService::PROVIDER_SIMULATED);
        $trackingNo = (string)($shipment['tracking_no'] ?? '');
        $tracking = $this->adapter->queryTracking($provider, $trackingNo);
        $providerStatus = (string)($tracking['provider_status'] ?? $tracking['normalized_status'] ?? 'manual_review');
        $normalizedStatus = (string)($tracking['normalized_status'] ?? 'manual_review');
        $syncKey = $this->syncKey($provider, $trackingNo, $providerStatus, count($tracking['events'] ?? []));

        $row = [
            'order_id' => (int)($shipment['order_id'] ?? 0),
            'order_sn' => (string)($shipment['order_sn'] ?? ''),
            'provider' => $provider,
            'tracking_no' => $trackingNo,
            'provider_status' => $providerStatus,
            'normalized_status' => $normalizedStatus,
            'sync_key' => $syncKey,
            'last_sync_key' => (string)($shipment['last_sync_key'] ?? ''),
            'current_shipment_status' => (int)($shipment['current_shipment_status'] ?? 0),
            'target_shipment_status' => null,
            'action' => 'manual_review_required',
            'abnormal' => false,
            'provider_blocked' => false,
            'idempotent_skip' => false,
            'network_calls' => (int)($tracking['network_calls'] ?? 0),
            'mutates_business_data' => false,
            'notes' => '',
        ];

        if (($tracking['status'] ?? '') === 'blocked') {
            $row['provider_blocked'] = true;
            $row['action'] = 'provider_evidence_required';
            $row['notes'] = 'Real provider tracking remains blocked until backend encrypted config and external evidence are accepted.';
            return $row;
        }

        if ($row['last_sync_key'] !== '' && $row['last_sync_key'] === $syncKey) {
            $row['idempotent_skip'] = true;
            $row['action'] = 'skip_already_synced';
            $row['notes'] = 'Tracking payload matches last sync key; no repeat update should be planned.';
            return $row;
        }

        if (isset(self::ABNORMAL_STATUS_RULES[$normalizedStatus])) {
            $row['abnormal'] = true;
            $row['action'] = self::ABNORMAL_STATUS_RULES[$normalizedStatus];
            $row['notes'] = 'Abnormal logistics status must be reviewed before any order status change.';
            return $row;
        }

        if ($normalizedStatus === 'delivered') {
            $row['target_shipment_status'] = self::SHIPMENT_STATUS_RECEIVED;
            $row['action'] = 'mark_received_pending_apply';
            $row['notes'] = 'Delivered tracking can be used as evidence for a controlled receive-status update.';
            return $row;
        }

        if (in_array($normalizedStatus, ['in_transit', 'out_for_delivery', 'created'], true)) {
            $row['target_shipment_status'] = self::SHIPMENT_STATUS_SHIPPING;
            $row['action'] = 'keep_shipping';
            $row['notes'] = 'Tracking is active; keep or move the order to shipping in a controlled apply step.';
            return $row;
        }

        $row['abnormal'] = true;
        $row['notes'] = 'Unknown tracking status requires manual review.';
        return $row;
    }

    private function syncKey(string $provider, string $trackingNo, string $providerStatus, int $eventCount): string
    {
        return sha1(strtolower(trim($provider)) . ':' . strtoupper(trim($trackingNo)) . ':' . strtolower(trim($providerStatus)) . ':' . $eventCount);
    }
}
