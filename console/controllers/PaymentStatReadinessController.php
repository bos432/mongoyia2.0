<?php

namespace console\controllers;

use common\services\mall\PaymentStatisticsService;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentStatReadinessController extends Controller
{
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $storeId = 0;
    public $startDate = '';
    public $endDate = '';

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
            'storeId',
            'startDate',
            'endDate',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia payment statistics readiness\n");
        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkSnapshot();
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
        $this->requireFileContains('Payment statistics service', 'common/services/mall/PaymentStatisticsService.php', [
            'MONGOYIA_PAYMENT_STATISTICS_V1',
            'dailyRows',
            'providerRows',
            'failureRows',
            'anomalyRows',
            'reconciliationRows',
        ]);
        $this->requireFileContains('Payment statistics backend controller', 'backend/modules/mall/controllers/PaymentStatController.php', [
            'PaymentStatisticsService',
            'requestedStoreId',
            'isMallPlatformOperator',
            'ForbiddenHttpException',
        ]);
        $this->requireFileContains('Payment statistics backend page', 'backend/modules/mall/views/payment-stat/index.php', [
            'data-mongoyia-payment-statistics',
            'data-mongoyia-payment-statistics-summary',
            'data-mongoyia-payment-statistics-anomaly',
            'data-mongoyia-payment-statistics-reconciliation',
        ]);
        $this->requireFileContains('Payment statistics permission migration', 'console/migrations/m260623_161000_mongoyia_payment_stat_permission.php', [
            '/mall/payment-stat/index',
            'grantToRoles',
            'clearAllPermission',
        ]);
    }

    private function checkSnapshot(): void
    {
        $this->section('Read-only snapshot');
        try {
            $snapshot = (new PaymentStatisticsService())->snapshot([
                'store_id' => (int)$this->storeId,
                'start_date' => (string)$this->startDate,
                'end_date' => (string)$this->endDate,
            ]);
            foreach (['summary', 'daily_rows', 'provider_rows', 'failure_rows', 'anomaly_rows', 'reconciliation_rows'] as $key) {
                if (!array_key_exists($key, $snapshot)) {
                    $this->fail("Snapshot missing {$key}.");
                    return;
                }
            }
            $this->ok('Payment statistics snapshot shape is ready.');
        } catch (\Throwable $e) {
            $this->fail('Payment statistics snapshot failed: ' . $e->getMessage());
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
            '# Mongoyia Payment Statistics Readiness',
            '',
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: read-only payment statistics from payment attempt audit rows.',
            '- Safety: this command does not call providers, create orders, write payment attempts, or mutate order/fund state.',
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

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function requireFileContains(string $label, string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->fail("{$label}: required file {$path} is missing.");
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("{$label}: missing marker {$needle}.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Required markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->addCheck($message, 'PASS', 'runtime snapshot', 'Read-only check passed.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-payment-stat-readiness-' . date('Ymd-His') . '.md';
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
