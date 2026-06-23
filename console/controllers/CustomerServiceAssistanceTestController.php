<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceAssistanceService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceAssistanceTestController extends Controller
{
    public $fixture = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['fixture']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service assistance check\n");

        $this->checkMarkers();
        if ($this->fixture && $this->failures === 0) {
            $this->runFixture();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkMarkers(): void
    {
        $this->section('Source markers');
        $this->requireFileContains('@app/../common/services/mall/CustomerServiceAssistanceService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_ASSISTANCE_V1',
            'searchOrders',
            'searchProducts',
            'orderDetail',
            'productDetail',
            'createAssistanceRequest',
            'order_mutation_allowed',
            'refund_mutation_allowed',
            'approval_required_for_high_risk',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/KfController.php', [
            'CustomerServiceAssistanceService',
            'actionAssistanceSearch',
            'actionAssistanceDetail',
            'actionAssistanceRequest',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/kf/index.php', [
            'data-mongoyia-customer-service-assistance="search"',
            'assistanceSearchUrl',
            'assistanceDetailUrl',
            'assistanceRequestUrl',
            'createAssistanceRequest',
            '订单/商品查询',
            '协助处理单',
        ]);
        $this->requireFileContains('@app/../common/services/mall/CustomerServiceTicketCreateService.php', [
            'assistance_type',
            'risk_action',
            'approval_required',
        ]);
        $this->requireFileContains('@app/../console/migrations/m260623_093000_mongoyia_customer_service_assistance_permission.php', [
            '/mall/kf/assistance-search',
            '/mall/kf/assistance-detail',
            '/mall/kf/assistance-request',
            'Phase 9 customer-service order/product assistance APIs',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Fixture dry-run');
        $service = new CustomerServiceAssistanceService();
        $types = $service->assistanceTypes();
        foreach (['payment_guidance', 'logistics_query', 'refund_suggestion', 'compensation_suggestion'] as $type) {
            if (!isset($types[$type])) {
                $this->fail("Missing assistance type {$type}.");
            }
        }

        $boundaries = $service->boundaries();
        foreach (['order_mutation_allowed', 'payment_mutation_allowed', 'fund_mutation_allowed', 'stock_mutation_allowed', 'refund_mutation_allowed'] as $key) {
            if (!array_key_exists($key, $boundaries) || $boundaries[$key]) {
                $this->fail("Boundary {$key} must be false.");
                return;
            }
        }
        $this->ok('Assistance boundaries forbid direct order/payment/fund/stock/refund mutation.');

        $orders = $service->searchOrders(['q' => ''], 0, 1);
        $products = $service->searchProducts(['q' => ''], 0, 1);
        $this->ok('Order/product search methods execute in the current schema.');

        $context = [
            'store_id' => (int)($orders[0]['store_id'] ?? $products[0]['store_id'] ?? 1),
            'product_id' => (int)($products[0]['id'] ?? 0),
            'order_id' => (int)($orders[0]['id'] ?? 0),
            'order_sn' => (string)($orders[0]['sn'] ?? 'CS-ASSIST-DRYRUN'),
            'customer_user_id' => (int)($orders[0]['user_id'] ?? 0),
            'customer_uuid' => 'customer-service-assistance-dry-run',
            'merchant_user_id' => 0,
            'platform_user_id' => 1,
            'chat_uuid' => 'customer-service-assistance-dry-run',
            'content' => 'Dry-run assistance request. No order/payment/fund/stock mutation.',
        ];
        $result = $service->createAssistanceRequest(
            $context,
            'refund_suggestion',
            false,
            1,
            CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
            0
        );
        if (empty($result['approvalRequired']) || (string)($result['riskAction'] ?? '') !== 'refund_approval') {
            $this->fail('Refund assistance dry-run must require approval and use refund_approval risk action.');
            return;
        }
        if (!empty($result['created'])) {
            $this->fail('Dry-run assistance must not create a ticket.');
            return;
        }
        $this->ok('Refund assistance dry-run requires approval and does not create a ticket.');
    }

    private function requireFileContains(string $alias, array $needles): void
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
