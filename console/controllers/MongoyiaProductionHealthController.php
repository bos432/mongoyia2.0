<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionHealthService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionHealthController extends Controller
{
    public $phpEnv = '.env';
    public $imEnv = '../../im后端/im后端/.env';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;
    public $skipConnectivity = false;

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
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production health\n");
        $this->checkFiles();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = $this->buildReport($this->input());
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
        $this->requireFileContains('common/services/mall/MongoyiaProductionHealthService.php', [
            'class MongoyiaProductionHealthService',
            'MONGOYIA_PRODUCTION_HEALTH_V1',
            'production_health_read_only_internal_checks',
            'This report runs internal read-only checks',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionHealthController.php', [
            'class MongoyiaProductionHealthController',
            'Mongoyia production health',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-readiness.md', [
            'Mongoyia Production Readiness',
            'MONGOYIA_PRODUCTION_HEALTH_V1',
            'mongoyia-production-health/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionHealthController.php',
            'MongoyiaProductionHealthService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionHealthPath',
            'mongoyia-production-health-*.md',
            'Production health result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-health/run',
            'mongoyia-production-health-*.md',
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
            $report = $this->buildReport($input);

            $this->assertReportValueIn($report, 'result', ['PASS', 'FAIL'], 'Production health returns a terminal PASS/FAIL result.');
            $this->assertTotal($report, 'health_row_count', 6, 'Production health has six internal check rows.');
            $this->assertTotal($report, 'dry_run_external_provider_call_count', 0, 'Production health does not call payment providers.');
            $this->assertTotal($report, 'dry_run_business_write_count', 0, 'Production health does not write business rows.');
            $this->assertRowExists($report, 'deployment_prod_profile', 'Deployment prod profile row exists.');
            $this->assertRowExists($report, 'generated_test_data_cleanup', 'Generated test-data cleanup row exists.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production Health Report',
                '- Result:',
                'MONGOYIA_PRODUCTION_HEALTH_V1',
                'ready_for_production_evidence_summary',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,exit_code,command,notes',
                'deployment_prod_profile',
                'generated_test_data_cleanup',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production health generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production health fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'phpEnv' => (string)$this->phpEnv,
            'imEnv' => (string)$this->imEnv,
            'strict' => (bool)$this->strict,
            'skipConnectivity' => (bool)$this->skipConnectivity,
        ];
    }

    private function buildReport(array $input): array
    {
        return $this->service()->run($input, $this->runSteps($input));
    }

    private function runSteps(array $input): array
    {
        $strictFlag = !empty($input['strict']) ? '1' : '0';
        $skipFlag = !empty($input['skipConnectivity']) ? '1' : '0';
        $steps = [
            [
                'key' => 'deployment_prod_profile',
                'name' => 'Deployment prod profile',
                'args' => [
                    'deploy-check/run',
                    '--profile=prod',
                    '--strict=' . $strictFlag,
                    '--skipConnectivity=' . $skipFlag,
                    '--phpEnv=' . (string)$input['phpEnv'],
                    '--imEnv=' . (string)$input['imEnv'],
                    '--interactive=0',
                ],
                'notes' => 'Prod-profile deploy configuration check.',
            ],
            [
                'key' => 'security_scan',
                'name' => 'Security scan',
                'args' => ['mongoyia-security-scan/run', '--interactive=0'],
                'notes' => 'Hardcoded secret and local-host scan.',
            ],
            [
                'key' => 'payment_audit',
                'name' => 'Payment audit',
                'args' => ['mongoyia-payment-audit/run', '--interactive=0'],
                'notes' => 'Read-only payment attempt and paid-order audit.',
            ],
            [
                'key' => 'order_integrity',
                'name' => 'Order integrity',
                'args' => ['mongoyia-order-integrity/run', '--interactive=0'],
                'notes' => 'Read-only parent/child order integrity check.',
            ],
            [
                'key' => 'translation_audit',
                'name' => 'Translation audit',
                'args' => ['mongoyia-translation-audit/run', '--interactive=0'],
                'notes' => 'Read-only product/category translation dirty-data audit.',
            ],
            [
                'key' => 'generated_test_data_cleanup',
                'name' => 'Generated test-data cleanup dry-run',
                'args' => ['mongoyia-test-cleanup/run', '--failOnPending=1', '--interactive=0'],
                'notes' => 'Dry-run cleanup verification for generated test records.',
            ],
        ];

        $rows = [];
        foreach ($steps as $step) {
            $result = $this->runYii($step['args']);
            $rows[] = [
                'key' => $step['key'],
                'name' => $step['name'],
                'exitCode' => $result['exitCode'],
                'command' => $result['command'],
                'output' => $this->redact($result['output']),
                'notes' => $step['notes'],
            ];
        }

        return $rows;
    }

    private function runYii(array $args): array
    {
        $yii = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'yii';
        $parts = array_merge([PHP_BINARY, $yii], $args);
        return $this->runProcess($parts, dirname(__DIR__, 2));
    }

    private function runProcess(array $parts, string $cwd): array
    {
        $command = implode(' ', array_map([$this, 'quoteArg'], $parts));
        $process = proc_open($command, [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);

        if (!is_resource($process)) {
            return [
                'exitCode' => 1,
                'output' => 'Failed to start process.',
                'command' => $this->redactCommand($parts),
            ];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        while (true) {
            $status = proc_get_status($process);
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $output .= $chunk;
            }
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($pipes[$index]);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
            fclose($pipes[$index]);
        }

        $exitCode = proc_close($process);
        return [
            'exitCode' => (int)$exitCode,
            'output' => $output,
            'command' => $this->redactCommand($parts),
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-health-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionHealthService
    {
        return new MongoyiaProductionHealthService();
    }

    private function recordReportIssues(array $report): void
    {
        foreach (($report['issues'] ?? []) as $issue) {
            $this->fail((string)$issue);
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production health.');
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

    private function quoteArg(string $arg): string
    {
        return escapeshellarg($arg);
    }

    private function redactCommand(array $parts): string
    {
        return implode(' ', array_map([$this, 'quoteArg'], array_map([$this, 'redact'], $parts)));
    }

    private function redact(string $text): string
    {
        $text = preg_replace('/(--[^=\s]*(?:password|secret|token|key)[^=\s]*=)[^\s]+/i', '$1***', $text);
        $text = preg_replace('/((?:password|secret|token|private_key|public_key)[\'"]?\s*[=:]\s*[\'"]?)[^\'"\s,]+/i', '$1***', $text);
        return (string)$text;
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
