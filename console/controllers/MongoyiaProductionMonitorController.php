<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionMonitorService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionMonitorController extends Controller
{
    public $phpEnv = '.env';
    public $imEnv = '../../im后端/im后端/.env';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;
    public $skipConnectivity = false;
    public $skipImPort = false;
    public $diskWarnPercent = 85;
    public $diskFailPercent = 95;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'phpEnv',
            'imEnv',
            'outputDir',
            'fixture',
            'strict',
            'skipConnectivity',
            'skipImPort',
            'diskWarnPercent',
            'diskFailPercent',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production monitor\n");
        $this->checkFiles();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = $this->service()->run($this->input());
            $this->recordReportIssues($report);
            $paths = $this->writeExport($report, false);
            $this->stdout("Markdown: {$paths['md']}\nCSV: {$paths['csv']}\n");
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles(): void
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/MongoyiaProductionMonitorService.php', [
            'class MongoyiaProductionMonitorService',
            'MONGOYIA_PRODUCTION_MONITOR_V1',
            'production_monitor_read_only_runtime_snapshot',
            'This report checks runtime',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionMonitorController.php', [
            'class MongoyiaProductionMonitorController',
            'Mongoyia production monitor',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-readiness.md', [
            'Mongoyia Production Readiness',
            'MONGOYIA_PRODUCTION_MONITOR_V1',
            'mongoyia-production-monitor/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionMonitorController.php',
            'MongoyiaProductionMonitorService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionMonitorPath',
            'mongoyia-production-monitor-*.md',
            'Production monitor result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-monitor/run',
            'mongoyia-production-monitor-*.md',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $input = $this->input();
            $input['strict'] = false;
            $input['skipConnectivity'] = true;
            $report = $this->service()->run($input);

            $this->assertReportValueIn($report, 'result', ['PASS', 'WARN', 'FAIL'], 'Production monitor returns a terminal PASS/WARN/FAIL result.');
            $this->assertTotal($report, 'monitor_row_count', 10, 'Production monitor has ten runtime rows.');
            $this->assertTotal($report, 'dry_run_external_provider_call_count', 0, 'Production monitor does not call payment providers.');
            $this->assertTotal($report, 'dry_run_business_write_count', 0, 'Production monitor does not write business rows.');
            $this->assertRowExists($report, 'php_cli', 'PHP CLI monitor row exists.');
            $this->assertRowExists($report, 'redis_port', 'Redis monitor row exists.');
            $this->assertRowExists($report, 'python_im_port', 'Python IM monitor row exists.');
            $this->assertRowExists($report, 'runtime_logs', 'Runtime logs monitor row exists.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production Monitor',
                '- Result:',
                'MONGOYIA_PRODUCTION_MONITOR_V1',
                'ready_for_production_evidence_summary',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,area,check,status,evidence,action',
                'php_cli',
                'runtime_logs',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production monitor generated read-only runtime evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production monitor fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'phpEnv' => (string)$this->phpEnv,
            'imEnv' => (string)$this->imEnv,
            'strict' => (bool)$this->strict,
            'skipConnectivity' => (bool)$this->skipConnectivity,
            'skipImPort' => (bool)$this->skipImPort,
            'diskWarnPercent' => (int)$this->diskWarnPercent,
            'diskFailPercent' => (int)$this->diskFailPercent,
        ];
    }

    private function writeExport(array $report, bool $fixture): array
    {
        $dir = (string)$this->outputDir !== ''
            ? Yii::getAlias((string)$this->outputDir)
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'handover';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stamp = date('Ymd-His') . ($fixture ? '-fixture-' . mt_rand(1000, 9999) : '');
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-monitor-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionMonitorService
    {
        return new MongoyiaProductionMonitorService(dirname(__DIR__, 2));
    }

    private function recordReportIssues(array $report): void
    {
        if (($report['result'] ?? '') === 'FAIL') {
            foreach (($report['issues'] ?? []) as $issue) {
                $this->fail((string)$issue);
            }
            return;
        }

        foreach (($report['issues'] ?? []) as $issue) {
            $this->warn((string)$issue);
        }
    }

    private function businessTableCounts(): array
    {
        $counts = [];
        foreach ([
            '{{%mall_order}}',
            '{{%mall_order_product}}',
            '{{%mall_payment_attempt}}',
            '{{%base_message}}',
            '{{%chat_message}}',
            '{{%base_attachment}}',
            '{{%base_fund_log}}',
            '{{%mall_customer_service_ticket}}',
            '{{%mall_customer_service_event}}',
            '{{%mall_customer_service_stat_daily}}',
        ] as $table) {
            if (Yii::$app->db->schema->getTableSchema($table, true) === null) {
                continue;
            }
            $counts[$table] = (int)(new \yii\db\Query())->from($table)->count('*', Yii::$app->db);
        }

        return $counts;
    }

    private function assertBusinessCountsUnchanged(array $before): void
    {
        foreach ($before as $table => $expected) {
            $actual = (int)(new \yii\db\Query())->from($table)->count('*', Yii::$app->db);
            if ($actual !== $expected) {
                $this->fail("Business table {$table} changed. Expected {$expected}, got {$actual}.");
                return;
            }
        }
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production monitor.');
    }

    private function assertReportValueIn(array $report, string $key, array $expectedValues, string $message): void
    {
        $actual = (string)($report[$key] ?? '');
        if (!in_array($actual, $expectedValues, true)) {
            $this->fail("{$message} Expected one of " . implode(', ', $expectedValues) . ", got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertTotal(array $report, string $key, int $expected, string $message): void
    {
        $actual = (int)($report['totals'][$key] ?? -1);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertRowExists(array $report, string $key, string $message): void
    {
        foreach (($report['rows'] ?? []) as $row) {
            if ((string)$row['key'] === $key) {
                $this->ok($message);
                return;
            }
        }
        $this->fail("{$message} Row {$key} missing.");
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $fullPath = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function assertFileContains(string $path, array $needles): void
    {
        if (!is_file($path)) {
            $this->fail("Missing export file {$path}.");
            return;
        }
        $content = (string)file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("Export file {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("Export file contains required markers: {$path}");
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function warn(string $message): void
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
