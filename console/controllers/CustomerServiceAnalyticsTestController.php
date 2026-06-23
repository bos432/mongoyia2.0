<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAnalyticsService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceAnalyticsTestController extends Controller
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
        $this->stdout("Mongoyia customer-service analytics check\n");

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
        $this->requireFileContains('@app/../common/services/mall/CustomerServiceAnalyticsService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_ANALYTICS_V1',
            'translation_failure_rate',
            'media_send_failure_rate',
            'aggregationPlan',
            'alertSignals',
            'stat_overwrite_from_backend_allowed',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/KfController.php', [
            'CustomerServiceAnalyticsService',
            'actionAnalytics',
            'actionAnalyticsExport',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/kf/analytics.php', [
            'MONGOYIA_CUSTOMER_SERVICE_ANALYTICS_V1',
            'data-mongoyia-customer-service-analytics',
            'data-mongoyia-customer-service-analytics-export',
            'data-mongoyia-customer-service-analytics-aggregation',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/kf/tickets.php', [
            'data-mongoyia-customer-service-analytics-link',
        ]);
        $this->requireFileContains('@app/../console/migrations/m260623_095000_mongoyia_customer_service_analytics_permission.php', [
            '/mall/kf/analytics',
            '/mall/kf/analytics-export',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Fixture dry-run');
        $service = new CustomerServiceAnalyticsService();
        $dimensions = $service->dimensions();
        foreach (['staff', 'store', 'language', 'channel', 'hour', 'media', 'ticket', 'complaint'] as $key) {
            if (!isset($dimensions[$key])) {
                $this->fail("Missing analytics dimension {$key}.");
                return;
            }
        }
        $this->ok('Analytics dimensions cover staff/store/language/channel/hour/media/ticket/complaint.');

        $boundaries = $service->boundaries();
        foreach (['order_mutation_allowed', 'payment_mutation_allowed', 'fund_mutation_allowed', 'stock_mutation_allowed', 'stat_overwrite_from_backend_allowed'] as $key) {
            if (!array_key_exists($key, $boundaries) || $boundaries[$key]) {
                $this->fail("Boundary {$key} must be false.");
                return;
            }
        }
        $this->ok('Analytics boundaries remain read-only.');

        $plan = $service->aggregationPlan(0, ['date_from' => '20260601', 'date_to' => '20260630']);
        if (!array_key_exists('writes_business_rows', $plan) || $plan['writes_business_rows']) {
            $this->fail('Analytics aggregation plan must be dry-run/read-only by default.');
            return;
        }
        $this->ok('Analytics aggregation plan is dry-run/read-only.');

        $csv = $service->csvLines([
            'totals' => ['consultation_count' => 3],
            'kpis' => ['translation_failure_rate' => 0],
            'languageRows' => [['key' => 'en', 'label' => 'English', 'count' => 2, 'ratio' => 66.67]],
        ]);
        $joined = implode("\n", $csv);
        if (strpos($joined, 'section,key,label,value,extra') === false || strpos($joined, 'translation_failure_rate') === false || strpos($joined, 'English') === false) {
            $this->fail('Analytics CSV output is incomplete.');
            return;
        }
        $this->ok('Analytics CSV output includes totals, KPIs, and dimension rows.');
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
