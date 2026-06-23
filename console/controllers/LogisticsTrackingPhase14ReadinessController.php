<?php

namespace console\controllers;

use common\services\mall\LogisticsTrackingSyncService;
use yii\console\Controller;
use yii\console\ExitCode;

class LogisticsTrackingPhase14ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_LOGISTICS_TRACKING_PHASE14_READINESS_V1';

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
        $this->stdout("Mongoyia Phase 14 logistics tracking sync readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkTrackingPlanFixture();
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
        $this->requireFileContains('Logistics tracking sync service', 'common/services/mall/LogisticsTrackingSyncService.php', [
            'MONGOYIA_LOGISTICS_TRACKING_SYNC_V1',
            'planSync',
            'fixtureShipments',
            'abnormalStatusRules',
            'mark_received_pending_apply',
            'manual_review_required',
            'skip_already_synced',
            'provider_evidence_required',
            'no_order_mutation',
        ]);
        $this->requireFileContains('Logistics provider adapter dependency', 'common/services/mall/LogisticsProviderAdapterService.php', [
            'MONGOYIA_LOGISTICS_PROVIDER_ADAPTER_V1',
            'queryTracking',
            'batchTracking',
        ]);
        $this->requireFileContains('Phase 14 aggregate tracks logistics tracking readiness', 'console/controllers/LogisticsProductPhase14AcceptanceController.php', [
            'Logistics tracking sync readiness',
            'logistics-tracking-phase14-readiness/run',
        ]);
        $this->requireFileContains('Phase 14 backlog command list', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'logistics-tracking-phase14-readiness/run',
            'Phase 14.2 tracking sync',
        ]);
    }

    private function checkTrackingPlanFixture(): void
    {
        $this->section('Tracking plan fixture');
        try {
            $service = new LogisticsTrackingSyncService();
            $plan = $service->planSync($service->fixtureShipments());
            $summary = $plan['summary'] ?? [];
            if (($plan['version'] ?? '') !== LogisticsTrackingSyncService::VERSION) {
                $this->fail('Tracking sync plan version marker is missing.');
                return;
            }
            if ((int)($summary['scanned'] ?? 0) !== 5) {
                $this->fail('Tracking sync fixture must scan five rows.');
                return;
            }
            if ((int)($summary['normal'] ?? 0) !== 2) {
                $this->fail('Tracking sync fixture must classify two normal planned rows.');
                return;
            }
            if ((int)($summary['abnormal'] ?? 0) !== 1) {
                $this->fail('Tracking sync fixture must classify one abnormal row.');
                return;
            }
            if ((int)($summary['idempotent_skips'] ?? 0) !== 1) {
                $this->fail('Tracking sync fixture must classify one idempotent skip.');
                return;
            }
            if ((int)($summary['provider_blocked'] ?? 0) !== 1) {
                $this->fail('Tracking sync fixture must classify one real-provider evidence gate.');
                return;
            }
            if ((int)($summary['network_calls'] ?? -1) !== 0 || !empty($summary['mutates_business_data'])) {
                $this->fail('Tracking sync fixture must be offline and read-only.');
                return;
            }

            $actions = array_column($plan['rows'] ?? [], 'action');
            foreach (['mark_received_pending_apply', 'keep_shipping', 'manual_review_required', 'skip_already_synced', 'provider_evidence_required'] as $action) {
                if (!in_array($action, $actions, true)) {
                    $this->fail("Tracking sync fixture missing action {$action}.");
                    return;
                }
            }

            if (empty($plan['safety']['dry_run_first']) || empty($plan['safety']['provider_secret_never_logged'])) {
                $this->fail('Tracking sync safety flags are incomplete.');
                return;
            }

            $this->addCheck('Tracking sync plan shape', 'PASS', 'LogisticsTrackingSyncService::planSync', 'Fixture returns normal, abnormal, idempotent, and provider-gated rows.');
            $this->addCheck('Abnormal status rules', 'PASS', 'LogisticsTrackingSyncService::abnormalStatusRules', 'Exception, returned, and manual-review statuses require human review.');
            $this->addCheck('Dry-run mutation boundary', 'PASS', 'fixture summary', 'No provider calls, order mutation, fund mutation, stock mutation, or secret logging.');
        } catch (\Throwable $e) {
            $this->fail('Tracking sync fixture failed: ' . $e->getMessage());
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
            '# Mongoyia Phase 14 Logistics Tracking Sync Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: dry-run tracking sync plan, abnormal status classification, idempotent skip, and real-provider evidence gate.',
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
            '/www/server/php/83/bin/php yii logistics-tracking-phase14-readiness/run --fixture=1 --interactive=0',
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

        $this->addCheck($label, 'PASS', $path, 'Required logistics tracking markers are present.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-logistics-tracking-phase14-readiness-' . date('Ymd-His') . '.md';
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
