<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionLoadTestEvidenceService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionLoadTestEvidenceController extends Controller
{
    public $evidenceDir = 'runtime/handover';
    public $loadSmokePath = '';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;
    public $failOnPending = false;
    public $loadTestReference = '';
    public $browsingSignoff = 'PENDING';
    public $checkoutSignoff = 'PENDING';
    public $paymentCallbackSignoff = 'PENDING';
    public $imConcurrencySignoff = 'PENDING';
    public $dataStoreSignoff = 'PENDING';
    public $rollbackMonitoringSignoff = 'PENDING';
    public $peakUsers = '';
    public $durationMinutes = '';
    public $p95Ms = '';
    public $errorRate = '';
    public $tester = '';

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'evidenceDir',
            'loadSmokePath',
            'outputDir',
            'fixture',
            'strict',
            'failOnPending',
            'loadTestReference',
            'browsingSignoff',
            'checkoutSignoff',
            'paymentCallbackSignoff',
            'imConcurrencySignoff',
            'dataStoreSignoff',
            'rollbackMonitoringSignoff',
            'peakUsers',
            'durationMinutes',
            'p95Ms',
            'errorRate',
            'tester',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production load-test evidence\n");
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
        $this->requireFileContains('common/services/mall/MongoyiaProductionLoadTestEvidenceService.php', [
            'class MongoyiaProductionLoadTestEvidenceService',
            'MONGOYIA_PRODUCTION_LOAD_TEST_EVIDENCE_V1',
            'production_load_test_evidence_read_only_no_traffic',
            'This report is read-only',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionLoadTestEvidenceController.php', [
            'class MongoyiaProductionLoadTestEvidenceController',
            'Mongoyia production load-test evidence',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-load-test-evidence.md', [
            'Mongoyia Production Load-Test Evidence',
            'MONGOYIA_PRODUCTION_LOAD_TEST_EVIDENCE_V1',
            'mongoyia-production-load-test-evidence/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionLoadTestEvidenceController.php',
            'MongoyiaProductionLoadTestEvidenceService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionLoadTestEvidencePath',
            'mongoyia-production-load-test-evidence-*.md',
            'Production load-test evidence result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-load-test-evidence/run',
            'mongoyia-production-load-test-evidence-*.md',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = $this->service()->run($this->input());

            $this->assertReportValue($report, 'result', 'WARN', 'Production load-test evidence remains WARN while formal evidence is pending.');
            $this->assertTotal($report, 'evidence_row_count', 8, 'Production load-test evidence has eight rows.');
            $this->assertTotalAtLeast($report, 'pending_count', 1, 'Production load-test evidence keeps pending rows before owner signoff.');
            $this->assertTotal($report, 'ready_for_go_live_gate', 0, 'Production load-test evidence is not ready for go-live gate by default.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'Production load-test evidence does not generate traffic or call external services.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'Production load-test evidence does not write business rows.');
            $this->assertRowStatus($report, 'formal_load_test_report', 'PENDING', 'Formal load-test report remains pending.');
            $this->assertRowStatus($report, 'browsing_scenario', 'PENDING', 'Browsing scenario remains pending.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production Load-Test Evidence',
                '- Result: WARN',
                'MONGOYIA_PRODUCTION_LOAD_TEST_EVIDENCE_V1',
                'ready_for_go_live_gate',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,evidence,reference,notes',
                'formal_load_test_report',
                'rollback_monitoring_scenario',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production load-test evidence generated read-only pending evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production load-test evidence fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'evidenceDir' => (string)$this->evidenceDir,
            'loadSmokePath' => (string)$this->loadSmokePath,
            'loadTestReference' => (string)$this->loadTestReference,
            'browsingSignoff' => (string)$this->browsingSignoff,
            'checkoutSignoff' => (string)$this->checkoutSignoff,
            'paymentCallbackSignoff' => (string)$this->paymentCallbackSignoff,
            'imConcurrencySignoff' => (string)$this->imConcurrencySignoff,
            'dataStoreSignoff' => (string)$this->dataStoreSignoff,
            'rollbackMonitoringSignoff' => (string)$this->rollbackMonitoringSignoff,
            'peakUsers' => (string)$this->peakUsers,
            'durationMinutes' => (string)$this->durationMinutes,
            'p95Ms' => (string)$this->p95Ms,
            'errorRate' => (string)$this->errorRate,
            'tester' => (string)$this->tester,
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-load-test-evidence-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionLoadTestEvidenceService
    {
        return new MongoyiaProductionLoadTestEvidenceService(dirname(__DIR__, 2));
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
        if ($this->failOnPending && (int)($report['totals']['pending_count'] ?? 0) > 0) {
            $this->fail('Production load-test evidence has pending rows and failOnPending=1.');
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production load-test evidence.');
    }

    private function assertReportValue(array $report, string $key, string $expected, string $message): void
    {
        $actual = (string)($report[$key] ?? '');
        if ($actual !== $expected) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
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

    private function assertTotalAtLeast(array $report, string $key, int $minimum, string $message): void
    {
        $actual = (int)($report['totals'][$key] ?? -1);
        if ($actual < $minimum) {
            $this->fail("{$message} Expected at least {$minimum}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function assertRowStatus(array $report, string $key, string $expected, string $message): void
    {
        foreach (($report['rows'] ?? []) as $row) {
            if ((string)$row['key'] !== $key) {
                continue;
            }
            if ((string)$row['status'] !== $expected) {
                $this->fail("{$message} Expected {$expected}, got {$row['status']}.");
                return;
            }
            $this->ok($message);
            return;
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
