<?php

namespace common\services\mall;

class LogisticsProviderAdapterService
{
    public const VERSION = 'MONGOYIA_LOGISTICS_PROVIDER_ADAPTER_V1';
    public const PROVIDER_SIMULATED = 'simulated';
    public const PROVIDER_EXTERNAL_CONTRACT = 'external_contract';

    private const TRACKING_STATUS_MAP = [
        'created' => 'created',
        'picked_up' => 'in_transit',
        'departed' => 'in_transit',
        'arrived' => 'in_transit',
        'out_for_delivery' => 'out_for_delivery',
        'delivered' => 'delivered',
        'signed' => 'delivered',
        'exception' => 'exception',
        'delayed' => 'exception',
        'returned' => 'returned',
    ];

    public function providerDefinitions(): array
    {
        return [
            self::PROVIDER_SIMULATED => [
                'code' => self::PROVIDER_SIMULATED,
                'name' => 'Simulated Logistics Provider',
                'mode' => 'fixture',
                'enabled_for_live' => false,
                'network_policy' => 'simulated_provider_no_network_calls',
                'secret_policy' => 'provider_secret_never_logged',
                'supported_operations' => [
                    'create_shipment_preview',
                    'query_tracking',
                    'batch_tracking',
                    'tracking_status_map',
                ],
                'required_runtime_fields' => [
                    'order_sn',
                    'store_id',
                    'tracking_no',
                    'receiver_country',
                    'weight_kg',
                ],
            ],
            self::PROVIDER_EXTERNAL_CONTRACT => [
                'code' => self::PROVIDER_EXTERNAL_CONTRACT,
                'name' => 'Real Provider Contract',
                'mode' => 'disabled_until_provider_evidence',
                'enabled_for_live' => false,
                'network_policy' => 'real_provider_requires_backend_config_and_evidence',
                'secret_policy' => 'provider_secret_never_logged',
                'required_backend_config' => [
                    'api_base_url',
                    'app_id',
                    'api_secret',
                    'callback_secret',
                    'environment',
                    'enabled',
                ],
                'redacted_fields' => [
                    'api_secret',
                    'callback_secret',
                    'access_token',
                ],
            ],
        ];
    }

    public function readinessMatrix(): array
    {
        return [
            [
                'area' => 'adapter_contract',
                'status' => 'PASS',
                'notes' => 'Provider adapters expose create preview, single tracking, batch tracking, status normalization, and secret redaction policy.',
            ],
            [
                'area' => 'simulated_provider',
                'status' => 'PASS',
                'notes' => 'Simulated provider returns deterministic shipment and tracking payloads with zero network calls.',
            ],
            [
                'area' => 'real_provider_gate',
                'status' => 'PASS',
                'notes' => 'Real providers stay disabled until encrypted backend config and external provider evidence are accepted.',
            ],
            [
                'area' => 'runtime_mutation_boundary',
                'status' => 'PASS',
                'notes' => 'Preview and tracking helpers do not mutate orders, shipment rows, fund rows, stock, or provider credentials.',
            ],
        ];
    }

    public function createShipmentPreview(array $input): array
    {
        $normalized = $this->normalizeShipmentInput($input);
        $provider = $normalized['provider'];

        if ($provider !== self::PROVIDER_SIMULATED) {
            return [
                'provider' => $provider,
                'status' => 'blocked',
                'code' => 'real_provider_requires_backend_config_and_evidence',
                'network_calls' => 0,
                'mutates_business_data' => false,
                'notes' => 'Real provider create shipment is disabled until Phase 14 external evidence is accepted.',
            ];
        }

        $weight = max(0.01, (float)$normalized['weight_kg']);
        $baseFee = 8.00;
        $estimatedFee = round($baseFee + $weight * 3.50, 2);

        return [
            'provider' => self::PROVIDER_SIMULATED,
            'status' => 'ready',
            'label_no' => 'SIM-' . strtoupper(substr(sha1($normalized['order_sn'] . ':' . $normalized['tracking_no']), 0, 12)),
            'tracking_no' => $normalized['tracking_no'],
            'order_sn' => $normalized['order_sn'],
            'receiver_country' => $normalized['receiver_country'],
            'estimated_fee' => $estimatedFee,
            'currency' => 'USD',
            'network_calls' => 0,
            'mutates_business_data' => false,
            'secret_policy' => 'provider_secret_never_logged',
        ];
    }

    public function queryTracking(string $provider, string $trackingNo): array
    {
        $provider = $this->normalizeProvider($provider);
        $trackingNo = trim($trackingNo);
        if ($trackingNo === '') {
            throw new \InvalidArgumentException('Tracking number is required.');
        }

        if ($provider !== self::PROVIDER_SIMULATED) {
            return [
                'provider' => $provider,
                'tracking_no' => $trackingNo,
                'status' => 'blocked',
                'normalized_status' => 'manual_review',
                'code' => 'real_provider_requires_backend_config_and_evidence',
                'events' => [],
                'network_calls' => 0,
            ];
        }

        $events = $this->simulatedEvents($trackingNo);
        $last = end($events);
        $providerStatus = (string)($last['provider_status'] ?? 'created');

        return [
            'provider' => self::PROVIDER_SIMULATED,
            'tracking_no' => $trackingNo,
            'status' => 'ok',
            'provider_status' => $providerStatus,
            'normalized_status' => $this->normalizeTrackingStatus($providerStatus),
            'events' => $events,
            'network_calls' => 0,
            'secret_policy' => 'provider_secret_never_logged',
        ];
    }

    public function batchTracking(array $shipments): array
    {
        $rows = [];
        foreach ($shipments as $shipment) {
            $rows[] = $this->queryTracking(
                (string)($shipment['provider'] ?? self::PROVIDER_SIMULATED),
                (string)($shipment['tracking_no'] ?? '')
            );
        }

        return [
            'status' => 'ok',
            'count' => count($rows),
            'rows' => $rows,
            'network_calls' => 0,
            'mutates_business_data' => false,
        ];
    }

    public function normalizeTrackingStatus(string $providerStatus): string
    {
        $key = strtolower(trim($providerStatus));
        return self::TRACKING_STATUS_MAP[$key] ?? 'manual_review';
    }

    private function normalizeShipmentInput(array $input): array
    {
        $provider = $this->normalizeProvider((string)($input['provider'] ?? self::PROVIDER_SIMULATED));
        $required = ['order_sn', 'store_id', 'tracking_no', 'receiver_country', 'weight_kg'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input) || trim((string)$input[$field]) === '') {
                throw new \InvalidArgumentException("Shipment preview requires {$field}.");
            }
        }

        return [
            'provider' => $provider,
            'order_sn' => trim((string)$input['order_sn']),
            'store_id' => (int)$input['store_id'],
            'tracking_no' => trim((string)$input['tracking_no']),
            'receiver_country' => strtoupper(trim((string)$input['receiver_country'])),
            'weight_kg' => (float)$input['weight_kg'],
        ];
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        return $provider !== '' ? $provider : self::PROVIDER_SIMULATED;
    }

    private function simulatedEvents(string $trackingNo): array
    {
        $trackingUpper = strtoupper($trackingNo);
        $events = [
            [
                'time' => '2026-06-23 09:00:00',
                'provider_status' => 'created',
                'location' => 'Mongoyia Warehouse',
                'description' => 'Shipment label was created.',
            ],
            [
                'time' => '2026-06-23 12:30:00',
                'provider_status' => 'picked_up',
                'location' => 'Ulaanbaatar Hub',
                'description' => 'Package was picked up by carrier.',
            ],
        ];

        if (strpos($trackingUpper, 'DELIVERED') !== false) {
            $events[] = [
                'time' => '2026-06-24 15:10:00',
                'provider_status' => 'delivered',
                'location' => 'Recipient Address',
                'description' => 'Package was delivered and signed.',
            ];
            return $events;
        }

        if (strpos($trackingUpper, 'EXCEPTION') !== false) {
            $events[] = [
                'time' => '2026-06-24 08:20:00',
                'provider_status' => 'exception',
                'location' => 'Border Checkpoint',
                'description' => 'Package requires manual logistics review.',
            ];
            return $events;
        }

        $events[] = [
            'time' => '2026-06-24 10:00:00',
            'provider_status' => 'departed',
            'location' => 'International Linehaul',
            'description' => 'Package departed origin hub.',
        ];

        return $events;
    }
}
