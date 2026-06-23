<?php

namespace console\controllers;

use common\services\mall\LogisticsProviderAdapterService;
use yii\console\Controller;
use yii\console\ExitCode;

class LogisticsProviderPhase14ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_LOGISTICS_PROVIDER_PHASE14_READINESS_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia Phase 14 logistics provider readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkSimulatedProvider();
        }

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Source coverage');
        $this->requireFileContains('Logistics provider adapter service', 'common/services/mall/LogisticsProviderAdapterService.php', [
            'MONGOYIA_LOGISTICS_PROVIDER_ADAPTER_V1',
            'PROVIDER_SIMULATED',
            'providerDefinitions',
            'createShipmentPreview',
            'queryTracking',
            'batchTracking',
            'TRACKING_STATUS_MAP',
            'simulated_provider_no_network_calls',
            'provider_secret_never_logged',
        ]);
        $this->requireFileContains('Phase 14 aggregate tracks logistics provider readiness', 'console/controllers/LogisticsProductPhase14AcceptanceController.php', [
            'Logistics provider adapter readiness',
            'logistics-provider-phase14-readiness/run',
        ]);
        $this->requireFileContains('Phase 14 backlog command list', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'logistics-provider-phase14-readiness/run',
            'Phase 14.1 logistics provider adapter contract',
        ]);
    }

    private function checkSimulatedProvider(): void
    {
        $this->section('Simulated provider fixture');
        try {
            $service = new LogisticsProviderAdapterService();
            $definitions = $service->providerDefinitions();
            if (empty($definitions[LogisticsProviderAdapterService::PROVIDER_SIMULATED])) {
                $this->fail('Simulated provider definition is missing.');
                return;
            }
            if (($definitions[LogisticsProviderAdapterService::PROVIDER_SIMULATED]['network_policy'] ?? '') !== 'simulated_provider_no_network_calls') {
                $this->fail('Simulated provider must not perform network calls.');
                return;
            }
            if (($definitions[LogisticsProviderAdapterService::PROVIDER_EXTERNAL_CONTRACT]['secret_policy'] ?? '') !== 'provider_secret_never_logged') {
                $this->fail('Real provider contract must keep secrets out of logs and reports.');
                return;
            }

            $preview = $service->createShipmentPreview([
                'provider' => LogisticsProviderAdapterService::PROVIDER_SIMULATED,
                'order_sn' => 'P14-SIM-ORDER',
                'store_id' => 1,
                'tracking_no' => 'SIM-TRACK-001',
                'receiver_country' => 'MN',
                'weight_kg' => 2.4,
            ]);
            if (($preview['status'] ?? '') !== 'ready' || (int)($preview['network_calls'] ?? -1) !== 0 || !empty($preview['mutates_business_data'])) {
                $this->fail('Simulated shipment preview must be ready, read-only, and offline.');
                return;
            }

            $tracking = $service->queryTracking(LogisticsProviderAdapterService::PROVIDER_SIMULATED, 'SIM-TRACK-DELIVERED');
            if (($tracking['normalized_status'] ?? '') !== 'delivered' || count($tracking['events'] ?? []) < 3) {
                $this->fail('Simulated delivered tracking fixture did not normalize correctly.');
                return;
            }

            $batch = $service->batchTracking([
                ['provider' => LogisticsProviderAdapterService::PROVIDER_SIMULATED, 'tracking_no' => 'SIM-TRACK-001'],
                ['provider' => LogisticsProviderAdapterService::PROVIDER_SIMULATED, 'tracking_no' => 'SIM-TRACK-EXCEPTION'],
            ]);
            if ((int)($batch['count'] ?? 0) !== 2 || (int)($batch['network_calls'] ?? -1) !== 0 || !empty($batch['mutates_business_data'])) {
                $this->fail('Batch tracking fixture must return two read-only offline rows.');
                return;
            }

            foreach ($service->readinessMatrix() as $row) {
                $this->addCheck((string)$row['area'], (string)$row['status'], 'LogisticsProviderAdapterService', (string)$row['notes']);
            }
        } catch (\Throwable $e) {
            $this->fail('Simulated provider fixture failed: ' . $e->getMessage());
        }
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Phase 14 Logistics Provider Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: logistics provider adapter contract, simulated provider, tracking status normalization, and real-provider evidence gate.',
            '- Safety: this command does not call logistics providers, mutate orders, write shipment rows, deduct funds, change stock, or store provider secrets.',
            '',
            '## Checks',
            '',
            '| Status | Area | Evidence | Notes |',
            '|---|---|---|---|',
        ];
        foreach ($this->checks as $check) {
            $lines[] = '| ' . $this->mdCell($check['status']) . ' | '
                . $this->mdCell($check['area']) . ' | `'
                . $this->mdCell($check['evidence']) . '` | '
                . $this->mdCell($check['notes']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## BaoTa Verification Command',
            '',
            '```bash',
            'cd /www/wwwroot/demo2026.mongoyia.com',
            'git pull',
            '/www/server/php/83/bin/php yii logistics-provider-phase14-readiness/run --fixture=1 --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function requireFileContains(string $label, string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck($label, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Required logistics provider markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function fail(string $message): void
    {
        $this->addCheck($message, 'FAIL', 'readiness check', $message);
    }

    private function addCheck(string $area, string $status, string $evidence, string $notes): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') {
            $this->failures++;
        } elseif ($status !== 'PASS') {
            $this->warnings++;
            $status = 'WARN';
        }

        $this->checks[] = [
            'area' => $area,
            'status' => $status,
            'evidence' => $evidence,
            'notes' => $notes,
        ];
        $this->stdout(str_pad($status, 8) . "{$area}\n");
    }

    private function result(): string
    {
        if ($this->failures > 0) {
            return 'FAIL';
        }
        if ($this->warnings > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-logistics-provider-phase14-readiness-' . date('Ymd-His') . '.md';
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }
}
