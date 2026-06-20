<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionGoLiveGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionGoLiveGateController extends Controller
{
    public $evidenceDir = 'runtime/handover';
    public $evidenceSummaryPath = '';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;
    public $failOnPending = false;
    public $businessSignoff = 'PENDING';
    public $paymentProductionSignoff = 'PENDING';
    public $settlementSignoff = 'PENDING';
    public $monitoringAlertSignoff = 'PENDING';
    public $backupRestoreDrillSignoff = 'PENDING';
    public $rollbackOwnerSignoff = 'PENDING';
    public $securitySignoff = 'PENDING';
    public $launchWindowSignoff = 'PENDING';
    public $approverReference = '';
    public $changeTicket = '';
    public $paymentProductionReference = '';
    public $settlementReference = '';
    public $monitoringAlertReference = '';
    public $backupRestoreDrillReference = '';
    public $rollbackOwnerReference = '';
    public $securityReference = '';
    public $launchWindowReference = '';

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'evidenceDir',
            'evidenceSummaryPath',
            'outputDir',
            'fixture',
            'strict',
            'failOnPending',
            'businessSignoff',
            'paymentProductionSignoff',
            'settlementSignoff',
            'monitoringAlertSignoff',
            'backupRestoreDrillSignoff',
            'rollbackOwnerSignoff',
            'securitySignoff',
            'launchWindowSignoff',
            'approverReference',
            'changeTicket',
            'paymentProductionReference',
            'settlementReference',
            'monitoringAlertReference',
            'backupRestoreDrillReference',
            'rollbackOwnerReference',
            'securityReference',
            'launchWindowReference',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production go-live gate\n");
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
        $this->requireFileContains('common/services/mall/MongoyiaProductionGoLiveGateService.php', [
            'class MongoyiaProductionGoLiveGateService',
            'MONGOYIA_PRODUCTION_GO_LIVE_GATE_V1',
            'production_go_live_read_only_no_traffic_switch',
            'This gate is read-only',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionGoLiveGateController.php', [
            'class MongoyiaProductionGoLiveGateController',
            'Mongoyia production go-live gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-go-live-gate.md', [
            'Mongoyia Production Go-Live Gate',
            'MONGOYIA_PRODUCTION_GO_LIVE_GATE_V1',
            'mongoyia-production-go-live-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionGoLiveGateController.php',
            'MongoyiaProductionGoLiveGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionGoLiveGatePath',
            'mongoyia-production-go-live-gate-*.md',
            'Production go-live gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-go-live-gate/run',
            'mongoyia-production-go-live-gate-*.md',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = $this->service()->run($this->input());

            $this->assertReportValueIn($report, 'result', ['WARN', 'FAIL'], 'Production go-live gate remains non-PASS while production signoffs are pending.');
            $this->assertReportValue($report, 'decision', 'NO-GO', 'Production final decision remains NO-GO.');
            $this->assertTotal($report, 'gate_row_count', 15, 'Production go-live gate has fifteen rows.');
            $this->assertTotalAtLeast($report, 'pending_count', 1, 'Production go-live gate keeps pending rows before owner signoff.');
            $this->assertTotal($report, 'go_allowed', 0, 'Production GO is not allowed by default.');
            $this->assertTotal($report, 'final_decision_no_go', 1, 'Production final decision no-go flag is set.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'Production go-live gate does not call external services.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'Production go-live gate does not write business rows.');
            $this->assertRowStatus($report, 'paypal_final_no_go_boundary', 'PASS', 'PayPal no-go boundary is indexed as PASS evidence.');
            $this->assertRowStatus($report, 'production_external_evidence_review_result_apply', 'PASS', 'Production external evidence review-result apply gate is indexed as PASS evidence.');
            $this->assertRowStatus($report, 'production_external_evidence_final_acceptance', 'PASS', 'Production external evidence final acceptance gate is indexed as PASS evidence.');
            $this->assertRowStatus($report, 'production_launch_signoff_readiness', 'PASS', 'Production launch signoff readiness gate is indexed as PASS evidence.');
            $this->assertRowStatus($report, 'business_launch_approval', 'PENDING', 'Business launch approval remains pending.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production Go-Live Gate',
                '- Result:',
                '- Final decision: NO-GO',
                '- Go allowed: no',
                'MONGOYIA_PRODUCTION_GO_LIVE_GATE_V1',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,evidence,reference,notes',
                'business_launch_approval',
                'production_external_evidence_review_result_apply',
                'production_external_evidence_final_acceptance',
                'production_launch_signoff_readiness',
                'paypal_final_no_go_boundary',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production go-live gate generated read-only NO-GO evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production go-live gate fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'evidenceDir' => (string)$this->evidenceDir,
            'evidenceSummaryPath' => (string)$this->evidenceSummaryPath,
            'businessSignoff' => (string)$this->businessSignoff,
            'paymentProductionSignoff' => (string)$this->paymentProductionSignoff,
            'settlementSignoff' => (string)$this->settlementSignoff,
            'monitoringAlertSignoff' => (string)$this->monitoringAlertSignoff,
            'backupRestoreDrillSignoff' => (string)$this->backupRestoreDrillSignoff,
            'rollbackOwnerSignoff' => (string)$this->rollbackOwnerSignoff,
            'securitySignoff' => (string)$this->securitySignoff,
            'launchWindowSignoff' => (string)$this->launchWindowSignoff,
            'approverReference' => (string)$this->approverReference,
            'changeTicket' => (string)$this->changeTicket,
            'paymentProductionReference' => (string)$this->paymentProductionReference,
            'settlementReference' => (string)$this->settlementReference,
            'monitoringAlertReference' => (string)$this->monitoringAlertReference,
            'backupRestoreDrillReference' => (string)$this->backupRestoreDrillReference,
            'rollbackOwnerReference' => (string)$this->rollbackOwnerReference,
            'securityReference' => (string)$this->securityReference,
            'launchWindowReference' => (string)$this->launchWindowReference,
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-go-live-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionGoLiveGateService
    {
        return new MongoyiaProductionGoLiveGateService(dirname(__DIR__, 2));
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
            $this->fail('Production go-live gate has pending rows and failOnPending=1.');
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production go-live gate.');
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
