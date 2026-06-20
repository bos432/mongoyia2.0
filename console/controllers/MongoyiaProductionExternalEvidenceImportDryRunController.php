<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionExternalEvidenceImportDryRunService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionExternalEvidenceImportDryRunController extends Controller
{
    public $manifestPath = '';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'manifestPath',
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production external evidence import dry-run\n");
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
        $this->requireFileContains('common/services/mall/MongoyiaProductionExternalEvidenceImportDryRunService.php', [
            'class MongoyiaProductionExternalEvidenceImportDryRunService',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1',
            'production_external_evidence_import_dry_run_metadata_only',
            'evidence_import_executed',
            'production_final_no_go',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionExternalEvidenceImportDryRunController.php', [
            'class MongoyiaProductionExternalEvidenceImportDryRunController',
            'Mongoyia production external evidence import dry-run',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-external-evidence-import-dry-run.md', [
            'Mongoyia Production External Evidence Import Dry Run',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1',
            'mongoyia-production-external-evidence-import-dry-run/run',
            'evidence_import_executed=0',
            'production_final_no_go=1',
        ]);
        $this->requireFileContains('docs/mongoyia-production-evidence-summary.md', [
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1',
            'production external evidence import dry-run',
        ]);
        $this->requireFileContains('docs/mongoyia-production-go-live-gate.md', [
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1',
            'production external evidence import dry-run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionExternalEvidenceImportDryRunController.php',
            'MongoyiaProductionExternalEvidenceImportDryRunService.php',
            'docs/mongoyia-production-external-evidence-import-dry-run.md',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionExternalEvidenceImportDryRunPath',
            'mongoyia-production-external-evidence-import-dry-run-*.md',
            'Production external evidence import dry-run result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-external-evidence-import-dry-run/run',
            'mongoyia-production-external-evidence-import-dry-run-*.md',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = $this->service()->run($this->input());

            $this->assertReportBool($report, 'externalEvidenceInputValid', true, 'Production external evidence input is valid.');
            $this->assertReportBool($report, 'evidenceImportAllowed', false, 'Production external evidence import is not allowed.');
            $this->assertReportBool($report, 'evidenceImportExecuted', false, 'Production external evidence import is not executed.');
            $this->assertReportBool($report, 'productionGoLiveAllowed', false, 'Production go-live remains disallowed.');
            $this->assertReportBool($report, 'productionFinalNoGo', true, 'Production final NO-GO remains set.');
            $this->assertTotal($report, 'manifest_row_count', 13, 'Production external evidence manifest has thirteen rows.');
            $this->assertTotal($report, 'valid_manifest_row_count', 13, 'All production external evidence manifest rows are valid.');
            $this->assertTotal($report, 'required_evidence_count', 13, 'Production external evidence has thirteen required evidence keys.');
            $this->assertTotal($report, 'covered_required_evidence_count', 13, 'Production external evidence covers all required keys.');
            $this->assertTotal($report, 'precondition_count', 5, 'Production external evidence import dry-run has five preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 5, 'All production external evidence import preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 13, 'Production external evidence keeps thirteen external pending markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'Production external evidence import dry-run does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'Production external evidence import dry-run does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'Production external evidence import dry-run does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'Production external evidence import dry-run does not call external services.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'Production external evidence import dry-run does not write business rows.');
            $this->assertTotal($report, 'external_evidence_input_valid', 1, 'Production external evidence input-valid total is set.');
            $this->assertTotal($report, 'evidence_import_allowed', 0, 'Production external evidence import-allowed total is off.');
            $this->assertTotal($report, 'evidence_import_executed', 0, 'Production external evidence import-executed total is off.');
            $this->assertTotal($report, 'production_go_live_allowed', 0, 'Production go-live allowed total is off.');
            $this->assertTotal($report, 'production_final_no_go', 1, 'Production final NO-GO total is set.');
            $this->assertGateStatus($report, 'documentation', 'ready', 'Production external evidence documentation is ready.');
            $this->assertGateStatus($report, 'package_check_wiring', 'ready', 'Production external evidence package check wiring is ready.');
            $this->assertGateStatus($report, 'delivery_index_wiring', 'ready', 'Production external evidence delivery index wiring is ready.');
            $this->assertGateStatus($report, 'external_evidence_input_valid', 'ready', 'Production external evidence input gate is ready.');
            $this->assertGateStatus($report, 'evidence_import', 'disabled', 'Production external evidence import remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Production artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'Production external calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Production business mutation remains disabled.');
            $this->assertGateStatus($report, 'production_go_live', 'no-go', 'Production go-live remains NO-GO.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production External Evidence Import Dry Run',
                '- Result: PASS',
                'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1',
                '- Evidence import allowed: no',
                '- Evidence import executed: no',
                '- Production go-live allowed: no',
                '- Production final NO-GO: yes',
                '| evidence_import_executed | 0 |',
                'production_final_no_go=1 remains intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'evidence_key,source_ref,artifact_ref,artifact_sha256,redaction_status,owner_role,environment,reviewed_at,decision,notes',
                'prod_config_snapshot',
                'rollback_launch_approval',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production external evidence import dry-run generated read-only metadata evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production external evidence import dry-run fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'manifestPath' => (string)$this->manifestPath,
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-external-evidence-import-dry-run-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionExternalEvidenceImportDryRunService
    {
        return new MongoyiaProductionExternalEvidenceImportDryRunService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production external evidence import dry-run.');
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
