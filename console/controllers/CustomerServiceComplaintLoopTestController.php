<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use common\services\mall\CustomerServiceComplaintLoopService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceComplaintLoopTestController extends Controller
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
        $this->stdout("Mongoyia customer-service complaint-loop check\n");

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
        $this->requireFileContains('@app/../common/services/mall/CustomerServiceAdvancedService.php', [
            'TICKET_STATUS_SELLER_PROOF',
            'TICKET_STATUS_PLATFORM_REVIEW',
            'TICKET_STATUS_REJECTED',
        ]);
        $this->requireFileContains('@app/../common/services/mall/CustomerServiceComplaintLoopService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_LOOP_V1',
            'product_quality',
            'service_attitude',
            'seller',
            'platform',
            'recordStep',
            'recordAssistanceLink',
            'linkAssistancePlan',
            'complaint_to_assistance_creates_ticket_only',
            'refund_mutation_allowed',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/KfController.php', [
            'CustomerServiceComplaintLoopService',
            'actionComplaintLoopStep',
            'actionComplaintLinkAssistance',
            'recordAssistanceLink',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_LOOP_BACKEND_V1',
            'complaint-loop-step',
            'complaint-link-assistance',
            '待商家举证',
            '待平台复核',
            '驳回投诉',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/kf/tickets.php', [
            '待商家举证',
            '待平台复核',
            '驳回',
        ]);
        $this->requireFileContains('@app/../console/migrations/m260623_094000_mongoyia_customer_service_complaint_loop_permission.php', [
            '/mall/kf/complaint-loop-step',
            '/mall/kf/complaint-link-assistance',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Fixture dry-run');
        $service = new CustomerServiceComplaintLoopService();
        foreach (['product_quality', 'service_attitude', 'logistics', 'payment', 'refund', 'other'] as $category) {
            if (!isset($service->categories()[$category])) {
                $this->fail("Missing complaint category {$category}.");
                return;
            }
        }
        foreach (['user', 'service', 'seller', 'platform'] as $role) {
            if (!isset($service->evidenceRoles()[$role])) {
                $this->fail("Missing complaint evidence role {$role}.");
                return;
            }
        }

        $advanced = new CustomerServiceAdvancedService();
        $transitions = $advanced->supportedTransitions();
        if (!in_array(CustomerServiceAdvancedService::TICKET_STATUS_SELLER_PROOF, $transitions[CustomerServiceAdvancedService::TICKET_STATUS_IN_PROGRESS] ?? [], true)) {
            $this->fail('In-progress complaints must be able to move to seller proof.');
            return;
        }
        if (!in_array(CustomerServiceAdvancedService::TICKET_STATUS_PLATFORM_REVIEW, $transitions[CustomerServiceAdvancedService::TICKET_STATUS_SELLER_PROOF] ?? [], true)) {
            $this->fail('Seller-proof complaints must be able to move to platform review.');
            return;
        }
        if (!in_array(CustomerServiceAdvancedService::TICKET_STATUS_REJECTED, $transitions[CustomerServiceAdvancedService::TICKET_STATUS_PLATFORM_REVIEW] ?? [], true)) {
            $this->fail('Platform-review complaints must be able to be rejected.');
            return;
        }
        $this->ok('Complaint status transitions include seller proof, platform review, and rejection.');

        $boundaries = $service->boundaries();
        foreach (['refund_mutation_allowed', 'compensation_mutation_allowed', 'order_mutation_allowed', 'payment_mutation_allowed', 'fund_mutation_allowed', 'stock_mutation_allowed'] as $key) {
            if (!array_key_exists($key, $boundaries) || $boundaries[$key]) {
                $this->fail("Boundary {$key} must be false.");
                return;
            }
        }
        $this->ok('Complaint loop boundaries forbid direct refund/compensation/order/payment/fund/stock mutation.');

        $plan = $service->dryRunPlan([
            'ticket_id' => 1,
            'store_id' => 1,
            'order_id' => 1,
            'product_id' => 1,
            'category' => 'product_quality',
        ]);
        if (($plan['version'] ?? '') !== CustomerServiceComplaintLoopService::VERSION || count($plan['rows'] ?? []) < 3) {
            $this->fail('Complaint loop dry-run plan is incomplete.');
            return;
        }
        $this->ok('Complaint loop dry-run plan is available.');

        $link = $service->linkAssistancePlan(['id' => 1, 'order_id' => 2, 'product_id' => 3, 'store_id' => 4], 'refund_suggestion');
        if (empty($link['creates_order_assist_ticket_only']) || !empty($link['direct_refund_or_compensation_allowed'])) {
            $this->fail('Complaint-to-assistance link must create an assistance ticket only.');
            return;
        }
        $this->ok('Complaint-to-assistance link is approval-only.');

        $record = $service->recordAssistanceLink(1, [
            'assistance_type' => 'refund_suggestion',
            'linked_ticket_id' => 2,
            'linked_ticket_sn' => 'CSO-DRYRUN',
        ], false);
        if (empty($record['dryRun']) || !empty($record['boundaries']['refund_mutation_allowed'])) {
            $this->fail('Complaint assistance-link record dry-run is incomplete.');
            return;
        }
        $this->ok('Complaint assistance-link record dry-run is available.');
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
