<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionExternalEvidenceFinalAcceptanceGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionExternalEvidenceFinalAcceptanceGateController extends Controller
{
    public $reviewResultApplyGatePath = '';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'reviewResultApplyGatePath',
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production external evidence final acceptance gate\n");
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
        $this->requireFileContains('common/services/mall/MongoyiaProductionExternalEvidenceFinalAcceptanceGateService.php', [
            'class MongoyiaProductionExternalEvidenceFinalAcceptanceGateService',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1',
            'production_external_evidence_final_acceptance_gate_read_only',
            'evidence_accepted',
            'production_go_live_allowed',
        ]);
        $this->requireFileContains('common/services/mall/MongoyiaProductionExternalEvidenceReviewResultApplyGateService.php', [
            'class MongoyiaProductionExternalEvidenceReviewResultApplyGateService',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_RESULT_APPLY_GATE_V1',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionExternalEvidenceFinalAcceptanceGateController.php', [
            'class MongoyiaProductionExternalEvidenceFinalAcceptanceGateController',
            'Mongoyia production external evidence final acceptance gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-external-evidence-final-acceptance-gate.md', [
            'Mongoyia Production External Evidence Final Acceptance Gate',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1',
            'mongoyia-production-external-evidence-final-acceptance-gate/run',
            'evidence_accepted=0',
            'production_go_live_allowed=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionExternalEvidenceFinalAcceptanceGateController.php',
            'MongoyiaProductionExternalEvidenceFinalAcceptanceGateService.php',
            'docs/mongoyia-production-external-evidence-final-acceptance-gate.md',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionExternalEvidenceFinalAcceptanceGatePath',
            'mongoyia-production-external-evidence-final-acceptance-gate-*.md',
            'Production external evidence final acceptance gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-external-evidence-final-acceptance-gate/run',
            'mongoyia-production-external-evidence-final-acceptance-gate-*.md',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = $this->service()->run($this->input());

            $this->assertReportBool($report, 'finalAcceptanceMetadataValid', true, 'Production external evidence final acceptance metadata is valid.');
            $this->assertReportBool($report, 'finalAcceptanceReady', true, 'Production external evidence final acceptance preflight is ready.');
            $this->assertReportBool($report, 'evidenceAccepted', false, 'Production external evidence is not accepted.');
            $this->assertReportBool($report, 'finalAcceptanceExecuted', false, 'Production external evidence final acceptance is not executed.');
            $this->assertReportBool($report, 'productionGoLiveAllowed', false, 'Production go-live remains disallowed.');
            $this->assertReportBool($report, 'productionFinalNoGo', true, 'Production final NO-GO remains set.');
            $this->assertTotal($report, 'final_acceptance_row_count', 8, 'Production external evidence final acceptance gate has eight rows.');
            $this->assertTotal($report, 'valid_final_acceptance_row_count', 8, 'All production external evidence final acceptance rows are valid.');
            $this->assertTotal($report, 'ready_role_count', 8, 'All production external evidence final acceptance roles are ready in metadata.');
            $this->assertTotal($report, 'required_role_count', 8, 'Production external evidence final acceptance gate has eight required roles.');
            $this->assertTotal($report, 'precondition_count', 5, 'Production external evidence final acceptance gate has five preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 5, 'All production external evidence final acceptance preconditions pass.');
            $this->assertTotal($report, 'pending_external_count', 8, 'Production external evidence final acceptance keeps eight pending external markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'Production external evidence final acceptance does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'Production external evidence final acceptance does not import artifacts.');
            $this->assertTotal($report, 'artifact_hash_count', 0, 'Production external evidence final acceptance does not hash artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'Production external evidence final acceptance does not call external services.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'Production external evidence final acceptance does not write rows.');
            $this->assertTotal($report, 'final_acceptance_metadata_valid', 1, 'Production external evidence final acceptance metadata valid total is set.');
            $this->assertTotal($report, 'final_acceptance_ready', 1, 'Production external evidence final acceptance ready total is set.');
            $this->assertTotal($report, 'evidence_accepted', 0, 'Production external evidence accepted total is off.');
            $this->assertTotal($report, 'final_acceptance_executed', 0, 'Production external evidence final acceptance executed total is off.');
            $this->assertTotal($report, 'production_go_live_allowed', 0, 'Production go-live allowed total is off.');
            $this->assertTotal($report, 'production_final_no_go', 1, 'Production final NO-GO total is set.');
            $this->assertGateStatus($report, 'review_result_apply_gate_report', 'pass', 'Production external evidence review-result apply gate report is PASS.');
            $this->assertGateStatus($report, 'documentation', 'ready', 'Production external evidence final acceptance documentation is ready.');
            $this->assertGateStatus($report, 'package_check_wiring', 'ready', 'Production external evidence final acceptance package check wiring is ready.');
            $this->assertGateStatus($report, 'delivery_index_wiring', 'ready', 'Production external evidence final acceptance delivery index wiring is ready.');
            $this->assertGateStatus($report, 'final_acceptance_contract', 'ready', 'Production external evidence final acceptance contract is ready.');
            $this->assertGateStatus($report, 'final_acceptance_metadata', 'ready', 'Production external evidence final acceptance metadata gate is ready.');
            $this->assertGateStatus($report, 'evidence_acceptance', 'disabled', 'Production external evidence acceptance remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Production artifact access remains disabled.');
            $this->assertGateStatus($report, 'provider_calls', 'disabled', 'External service calls remain disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Business mutations remain disabled.');
            $this->assertGateStatus($report, 'production_go_live', 'no-go', 'Production go-live remains NO-GO.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production External Evidence Final Acceptance Gate',
                '- Result: PASS',
                'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_FINAL_ACCEPTANCE_GATE_V1',
                '- Evidence accepted: no',
                '- Final acceptance executed: no',
                '- Production go-live allowed: no',
                '| evidence_accepted | 0 |',
                'evidence_accepted=0 remains intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'acceptance_role,owner_ref,signoff_ref,ticket_ref,decision,acceptance_status,signed_at,notes',
                'business',
                'rollback',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production external evidence final acceptance gate generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production external evidence final acceptance gate fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'reviewResultApplyGatePath' => (string)$this->reviewResultApplyGatePath,
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-external-evidence-final-acceptance-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionExternalEvidenceFinalAcceptanceGateService
    {
        return new MongoyiaProductionExternalEvidenceFinalAcceptanceGateService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production external evidence final acceptance gate.');
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
