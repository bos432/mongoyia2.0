<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionExternalEvidenceReviewReadinessService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionExternalEvidenceReviewReadinessController extends Controller
{
    public $importDryRunPath = '';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'importDryRunPath',
            'outputDir',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production external evidence review readiness\n");
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
        $this->requireFileContains('common/services/mall/MongoyiaProductionExternalEvidenceReviewReadinessService.php', [
            'class MongoyiaProductionExternalEvidenceReviewReadinessService',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_READINESS_V1',
            'production_external_evidence_review_readiness_read_only',
            'review_accepted',
            'production_go_live_allowed',
        ]);
        $this->requireFileContains('common/services/mall/MongoyiaProductionExternalEvidenceImportDryRunService.php', [
            'class MongoyiaProductionExternalEvidenceImportDryRunService',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_IMPORT_DRY_RUN_V1',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionExternalEvidenceReviewReadinessController.php', [
            'class MongoyiaProductionExternalEvidenceReviewReadinessController',
            'Mongoyia production external evidence review readiness',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-external-evidence-review-readiness.md', [
            'Mongoyia Production External Evidence Review Readiness',
            'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_READINESS_V1',
            'mongoyia-production-external-evidence-review-readiness/run',
            'review_accepted=0',
            'production_go_live_allowed=0',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionExternalEvidenceReviewReadinessController.php',
            'MongoyiaProductionExternalEvidenceReviewReadinessService.php',
            'docs/mongoyia-production-external-evidence-review-readiness.md',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionExternalEvidenceReviewReadinessPath',
            'mongoyia-production-external-evidence-review-readiness-*.md',
            'Production external evidence review readiness result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-external-evidence-review-readiness/run',
            'mongoyia-production-external-evidence-review-readiness-*.md',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = $this->service()->run($this->input());

            $this->assertReportBool($report, 'reviewInputValid', true, 'Production external evidence review input is valid.');
            $this->assertReportBool($report, 'reviewAccepted', false, 'Production external evidence review is not accepted.');
            $this->assertReportBool($report, 'productionGoLiveAllowed', false, 'Production go-live remains disallowed.');
            $this->assertReportBool($report, 'productionFinalNoGo', true, 'Production final NO-GO remains set.');
            $this->assertTotal($report, 'reviewer_row_count', 7, 'Production external evidence review has seven reviewer rows.');
            $this->assertTotal($report, 'valid_reviewer_row_count', 7, 'All production external evidence reviewer rows are valid.');
            $this->assertTotal($report, 'required_role_count', 7, 'Production external evidence review has seven required roles.');
            $this->assertTotal($report, 'covered_required_role_count', 7, 'Production external evidence review covers all required roles.');
            $this->assertTotal($report, 'precondition_count', 4, 'Production external evidence review has four preconditions.');
            $this->assertTotal($report, 'satisfied_precondition_count', 4, 'All production external evidence review preconditions pass.');
            $this->assertTotal($report, 'pending_signoff_count', 7, 'Production external evidence review keeps seven pending signoff markers.');
            $this->assertTotal($report, 'artifact_read_count', 0, 'Production external evidence review does not read artifacts.');
            $this->assertTotal($report, 'artifact_import_count', 0, 'Production external evidence review does not import artifacts.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'Production external evidence review does not call external services.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'Production external evidence review does not write business rows.');
            $this->assertTotal($report, 'review_input_valid', 1, 'Production external evidence review input-valid total is set.');
            $this->assertTotal($report, 'review_accepted', 0, 'Production external evidence review-accepted total is off.');
            $this->assertTotal($report, 'production_go_live_allowed', 0, 'Production go-live allowed total is off.');
            $this->assertTotal($report, 'production_final_no_go', 1, 'Production final NO-GO total is set.');
            $this->assertGateStatus($report, 'import_dry_run_report', 'pass', 'Production external evidence import dry-run report is PASS.');
            $this->assertGateStatus($report, 'documentation', 'ready', 'Production external evidence review documentation is ready.');
            $this->assertGateStatus($report, 'package_check_wiring', 'ready', 'Production external evidence review package check wiring is ready.');
            $this->assertGateStatus($report, 'delivery_index_wiring', 'ready', 'Production external evidence review delivery index wiring is ready.');
            $this->assertGateStatus($report, 'review_input_valid', 'ready', 'Production external evidence review input gate is ready.');
            $this->assertGateStatus($report, 'review_acceptance', 'disabled', 'Production external evidence review acceptance remains disabled.');
            $this->assertGateStatus($report, 'artifact_access', 'disabled', 'Production artifact access remains disabled.');
            $this->assertGateStatus($report, 'business_mutation', 'disabled', 'Production business mutation remains disabled.');
            $this->assertGateStatus($report, 'production_go_live', 'no-go', 'Production go-live remains NO-GO.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production External Evidence Review Readiness',
                '- Result: PASS',
                'MONGOYIA_PRODUCTION_EXTERNAL_EVIDENCE_REVIEW_READINESS_V1',
                '- Review accepted: no',
                '- Production go-live allowed: no',
                '- Production final NO-GO: yes',
                '| review_accepted | 0 |',
                'review_accepted=0 remains intentional',
            ]);
            $this->assertFileContains($paths['csv'], [
                'review_role,reviewer_ref,signoff_ref,review_status,reviewed_at,notes',
                'business',
                'language',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production external evidence review readiness generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production external evidence review readiness fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'importDryRunPath' => (string)$this->importDryRunPath,
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-external-evidence-review-readiness-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionExternalEvidenceReviewReadinessService
    {
        return new MongoyiaProductionExternalEvidenceReviewReadinessService(dirname(__DIR__, 2));
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production external evidence review readiness.');
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
