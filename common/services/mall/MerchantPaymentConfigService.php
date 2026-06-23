<?php

namespace common\services\mall;

class MerchantPaymentConfigService
{
    public const VERSION = 'MONGOYIA_MERCHANT_PAYMENT_CONFIG_V1';

    private $configService;
    private $paymentService;

    public function __construct(?OperationalConfigService $configService = null, ?OperationalPaymentConfigService $paymentService = null)
    {
        $this->configService = $configService ?: new OperationalConfigService();
        $this->paymentService = $paymentService ?: new OperationalPaymentConfigService($this->configService);
    }

    public function snapshot(string $environment = 'test', int $storeId = 0, string $baseUrl = ''): array
    {
        $storeId = $this->normalizeStoreId($storeId);
        $permission = $this->permissionSnapshot($storeId);
        $payment = $storeId > 0
            ? $this->paymentService->snapshot($environment, $baseUrl, $storeId)
            : [
                'version' => OperationalPaymentConfigService::VERSION,
                'store_id' => 0,
                'environment' => $environment,
                'providers' => [],
            ];

        return [
            'version' => self::VERSION,
            'store_id' => $storeId,
            'environment' => $environment,
            'permission' => $permission,
            'payment' => $payment,
            'live_enablement_policy' => 'merchant_live_enablement_requires_phase10_provider_and_production_evidence',
        ];
    }

    public function savePermission(int $storeId, array $input): array
    {
        $storeId = $this->normalizeStoreId($storeId);
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('Merchant store_id is required.');
        }

        $allowed = !empty($input['allowed']) ? '1' : '0';
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 200, 'UTF-8');

        $this->configService->save([
            'store_id' => $storeId,
            'category' => 'merchant_payment',
            'provider' => 'permission',
            'code' => 'allowed',
            'label' => '商家独立支付配置权限',
            'environment' => 'default',
            'is_enabled' => (int)$allowed,
            'is_sensitive' => 0,
            'value' => $allowed,
            'remark' => $note,
            'metadata' => [
                'version' => self::VERSION,
                'note' => $note,
                'platform_controlled' => 1,
            ],
        ]);

        $result = $allowed === '1' ? 'PASS' : 'PENDING';
        $message = $allowed === '1'
            ? '商家独立支付配置权限已开启；仅测试模式可配置，正式启用仍需证据门。'
            : '商家独立支付配置权限未开启。';

        $this->configService->recordCheck([
            'store_id' => $storeId,
            'category' => 'merchant_payment',
            'provider' => 'permission',
            'check_key' => 'allowed',
            'result' => $result,
            'message' => $message,
            'details' => [
                'version' => self::VERSION,
                'allowed' => (int)$allowed,
                'live_enablement_blocked' => 1,
            ],
        ]);

        return [
            'result' => $result,
            'message' => $message,
            'details' => ['store_id' => $storeId, 'allowed' => (int)$allowed],
        ];
    }

    public function saveProvider(int $storeId, string $provider, string $environment, array $input): array
    {
        $storeId = $this->normalizeStoreId($storeId);
        $this->assertConfigAllowed($storeId);
        $environment = $this->normalizeEnvironment($environment);

        if ($environment === 'live' && !empty($input['enabled'])) {
            throw new \InvalidArgumentException('商家正式支付启用需要 Phase 10 服务商/生产证据和 Phase 11 浏览器验收通过后再开放。');
        }

        return $this->paymentService->saveProvider($provider, $environment, $input, $storeId);
    }

    public function checkProvider(int $storeId, string $provider, string $environment = 'test', bool $persist = true): array
    {
        $storeId = $this->normalizeStoreId($storeId);
        if (!$this->isAllowed($storeId)) {
            $result = [
                'result' => 'FAIL',
                'message' => '平台未开启该商家的独立支付配置权限。',
                'details' => [
                    'version' => self::VERSION,
                    'store_id' => $storeId,
                    'allowed' => 0,
                ],
            ];
            if ($persist) {
                $this->configService->recordCheck([
                    'store_id' => $storeId,
                    'category' => 'payment',
                    'provider' => $provider,
                    'check_key' => 'readiness',
                    'result' => $result['result'],
                    'message' => $result['message'],
                    'details' => $result['details'],
                ]);
            }
            return $result;
        }

        return $this->paymentService->checkProvider($provider, $environment, $persist, $storeId);
    }

    public function isAllowed(int $storeId): bool
    {
        $storeId = $this->normalizeStoreId($storeId);
        if ($storeId <= 0) {
            return false;
        }

        return (string)$this->configService->getValue('merchant_payment', 'permission', 'allowed', 'default', $storeId, '0') === '1';
    }

    private function permissionSnapshot(int $storeId): array
    {
        $allowed = $this->isAllowed($storeId);
        return [
            'allowed' => $allowed ? 1 : 0,
            'status' => $allowed ? 'PASS' : 'PENDING',
            'message' => $allowed
                ? '平台已允许该商家维护独立支付资料。'
                : '平台尚未允许该商家维护独立支付资料。',
        ];
    }

    private function assertConfigAllowed(int $storeId): void
    {
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('Merchant store_id is required.');
        }
        if (!$this->isAllowed($storeId)) {
            throw new \RuntimeException('平台未开启该商家的独立支付配置权限。');
        }
    }

    private function normalizeStoreId(int $storeId): int
    {
        return max(0, $storeId);
    }

    private function normalizeEnvironment(string $environment): string
    {
        return in_array($environment, ['test', 'live'], true) ? $environment : 'test';
    }
}
