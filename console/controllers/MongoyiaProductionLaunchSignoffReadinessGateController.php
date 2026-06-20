<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionLaunchSignoffReadinessGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionLaunchSignoffReadinessGateController extends Controller
{
    public $finalAcceptanceGatePath = '';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'finalAcceptanceGatePath',
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production launch signoff readiness gate\n");
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
        $this->requireFileContains('common/services/mall/MongoyiaProductionLaunchSignoffReadinessGateService.php', [
            'class MongoyiaProductionLaunchSignoffReadinessGateService',
            'MONGOYIA_PRODUCTION_LAUNCH_SIGNOFF_READINESS_GATE_V1',
            'production_launch_signoff_readiness_gate_read_only',
            'launch_signoff_accepted',
            'production_go_live_allowed',
        ]);
        $this->requireFileContains('common/services/mall/MongoyiaProductionExternalEvidenceFinalAcceptanceGateService.php', [
            'class MongoyiaProductionExternalEvidenceFinalAcceptanceGateService',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionLaunchSignoffReadinessGateController.php', [
            'class MongoyiaProductionLaunchSignoffReadinessGateController',
            'Mongoyia production launch signoff readiness gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-launch-signoff-readiness-gate.md', [
            'Mongoyia Production Launch Signoff Readiness Gate',
            'MONGOYIA_PRODUCTION_LAUNCH_SIGNOFF_READINESS_GATE_V1',
            'mongoyia-production-launch-signoff-readiness-gate/run',
            'launch_signoff_accepted=0',
            'production_go_live_allowed=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionLaunchSignoffReadinessGateController.php',
            'MongoyiaProductionLaunchSignoffReadinessGateService.php',
            'docs/mongoyia-production-launch-signoff-readiness-gate.md',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionLaunchSignoffReadinessGatePath',
            'mongoyia-production-launch-signoff-readiness-gate-*.md',
            'Production launch signoff readiness gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-launch-signoff-readiness-gate/run',
            'mongoyia-production-launch-signoff-readiness-gate-*.md',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = $this->service()->run($this->input());

            $this->assertReportBool($report, 'launchSignoffMetadataValid', true, 'Production launch signoff metadata is valid.');
            $this->assertReportBool($report, 'launchSignoffReady', true, 'Production launch signoff preflight is ready.');
            $this->assertReportBool($report, 'launchSignoffAccepted', false, 'Production launch signoff is not accepted.');
            $this->assertReportBool($report, 'launchApprovalExecuted', false, 'Production launch approval is not executed.');
            $this->assertReportBool($report, 'productionGoLiveAllowed', false, 'Production go-live remains disallowed.');
            $this->assertReportBool($report, 'productionFinalNoGo', true, 'Production final NO-GO remains set.');
            $this->assertTotal($report, 'launch_signoff_row_count', 8, 'Production launch signoff readiness gate has eight rows.');
            $this->assertTotal($report, 'valid_launch_signoff_row_count', 8, 'All production launch signoff rows are valid.');
            $this->assertTotal($report, 'ready_signoff_count', 8, 'All production launch signoff rows are ready in metadata.');
            $this->assertTotal($report, 'required_signoff_count', 8, 'Production launch signoff readiness gate has eight required signoffs.');
            $this->assertTotal($report, 'precondition_count', 5, 'Production launch signoff readiness gate has five preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 5, 'All production launch signoff readiness preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 8, 'Production launch signoff readiness keeps eight pending external markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'Production launch signoff readiness does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'Production launch signoff readiness does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'Production launch signoff readiness does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'Production launch signoff readiness does not call external services.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'Production launch signoff readiness does not write rows.');
            $this->assertTotal($report, 'launch_signoff_metadata_valid', 1, 'Production launch signoff metadata valid total is set.');
            $this->assertTotal($report, 'launch_signoff_ready', 1, 'Production launch signoff ready total is set.');
            $this->assertTotal($report, 'launch_signoff_accepted', 0, 'Production launch signoff accepted total is off.');
            $this->assertTotal($report, 'launch_approval_executed', 0, 'Production launch approval executed total is off.');
            $this->assertTotal($report, 'production_go_live_allowed', 0, 'Production go-live allowed total is off.');
            $this->assertTotal($report, 'production_final_no_go', 1, 'Production final NO-GO total is set.');
            $this->assertGateStatus($report, 'final_acceptance_gate_report', 'pass', 'Production external evidence final acceptance gate report is PASS.');
            $this->assertGateStatus($report, 'documentation', 'ready', 'Production launch signoff readiness documentation is ready.');
            $this->assertGateStatus($report, 'package_check_wiring', 'ready', 'Production launch signoff readiness package check wiring is ready.');
            $this->assertGateStatus($report, 'delivery_index_wiring', 'ready', 'Production launch signoff readiness delivery index wiring is ready.');
            $this->assertGateStatus($report, 'launch_signoff_contract', 'ready', 'Production launch signoff readiness contract is ready.');
            $this->assertGateStatus($report, 'launch_signoff_metadata', 'ready', 'Production launch signoff readiness metadata gate is ready.');
            $this->assertGateStatus($report, 'launch_signoff_acceptance', 'disabled', 'Production launch signoff acceptance remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Production artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'External service calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');
            $this->assertGateStatus($report, 'production_go_live', 'no-go', 'Production go-live remains NO-GO.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production Launch Signoff Readiness Gate',
                '- Result: PASS',
                'MONGOYIA_PRODUCTION_LAUNCH_SIGNOFF_READINESS_GATE_V1',
                '- Launch signoff accepted: no',
                '- Launch approval executed: no',
                '- Production go-live allowed: no',
                '| launch_signoff_accepted | 0 |',
                'launch_signoff_accepted=0 remains intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'signoff_key,owner_ref,ticket_ref,owner_role,decision,signoff_status,reviewed_at,notes',
                'business_launch',
                'launch_window',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production launch signoff readiness gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production launch signoff readiness gate fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'finalAcceptanceGatePath' => (string)$this->finalAcceptanceGatePath,
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-launch-signoff-readiness-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionLaunchSignoffReadinessGateService
    {
        return new MongoyiaProductionLaunchSignoffReadinessGateService(dirname(__DIR__, 2));
    }

    private function recordReportIssues(array $report): void
    {
        foreach (($report['issues'] ?? []) as $issue) {
            $this->warnings++;
            $this->stdout("WARN {$issue}\n");
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production launch signoff readiness gate.');
    }

    private function assertReportBool(array $report, string $key, bool $expected, string $message): void
    {
        $actual = (bool)($report[$key] ?? !$expected);
        if ($actual !== $expected) {
            $this->fail("{$message} Expected " . ($expected ? 'true' : 'false') . ', got ' . ($actual ? 'true' : 'false') . '.');
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

    private function assertGateStatus(array $report, string $key, string $expected, string $message): void
    {
        foreach (($report['gateChecks'] ?? []) as $check) {
            if ((string)$check['key'] !== $key) {
                continue;
            }
            if ((string)$check['status'] !== $expected) {
                $this->fail("{$message} Expected {$expected}, got {$check['status']}.");
                return;
            }
            $this->ok($message);
            return;
        }
        $this->fail("{$message} Gate {$key} missing.");
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

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
