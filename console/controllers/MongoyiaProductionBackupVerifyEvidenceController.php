<?php

namespace console\controllers;

use common\services\mall\MongoyiaProductionBackupVerifyEvidenceService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaProductionBackupVerifyEvidenceController extends Controller
{
    public $evidenceDir = 'runtime/handover';
    public $backupVerifyPath = '';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;
    public $failOnPending = false;
    public $databaseChecksumSignoff = 'PENDING';
    public $uploadArchiveSignoff = 'PENDING';
    public $restoreDrillSignoff = 'PENDING';
    public $retentionSignoff = 'PENDING';
    public $rollbackOwnerSignoff = 'PENDING';
    public $restoreDrillReference = '';
    public $retentionReference = '';
    public $operator = '';

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'evidenceDir',
            'backupVerifyPath',
            'outputDir',
            'fixture',
            'strict',
            'failOnPending',
            'databaseChecksumSignoff',
            'uploadArchiveSignoff',
            'restoreDrillSignoff',
            'retentionSignoff',
            'rollbackOwnerSignoff',
            'restoreDrillReference',
            'retentionReference',
            'operator',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia production backup verify evidence\n");
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
        $this->requireFileContains('common/services/mall/MongoyiaProductionBackupVerifyEvidenceService.php', [
            'class MongoyiaProductionBackupVerifyEvidenceService',
            'MONGOYIA_PRODUCTION_BACKUP_VERIFY_EVIDENCE_V1',
            'production_backup_verify_evidence_read_only',
            'This report is read-only',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaProductionBackupVerifyEvidenceController.php', [
            'class MongoyiaProductionBackupVerifyEvidenceController',
            'Mongoyia production backup verify evidence',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-production-readiness.md', [
            'MONGOYIA_PRODUCTION_BACKUP_VERIFY_EVIDENCE_V1',
            'mongoyia-production-backup-verify-evidence/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaProductionBackupVerifyEvidenceController.php',
            'MongoyiaProductionBackupVerifyEvidenceService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'productionBackupVerifyEvidencePath',
            'mongoyia-production-backup-verify-evidence-*.md',
            'Production backup verify evidence result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-production-backup-verify-evidence/run',
            'mongoyia-production-backup-verify-evidence-*.md',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = $this->service()->run($this->input());

            $this->assertReportValue($report, 'result', 'WARN', 'Production backup verify evidence remains WARN while backup/restore signoffs are pending.');
            $this->assertTotal($report, 'evidence_row_count', 6, 'Production backup verify evidence has six rows.');
            $this->assertTotalAtLeast($report, 'pending_count', 1, 'Production backup verify evidence keeps pending rows before owner signoff.');
            $this->assertTotal($report, 'ready_for_production_evidence_summary', 0, 'Production backup verify evidence is not ready for evidence summary by default.');
            $this->assertTotal($report, 'dry_run_network_call_count', 0, 'Production backup verify evidence does not call external services.');
            $this->assertTotal($report, 'dry_run_write_count', 0, 'Production backup verify evidence does not write business rows.');
            $this->assertRowExists($report, 'backup_verify_report', 'Backup verify report row exists.');
            $this->assertRowStatus($report, 'restore_drill', 'PENDING', 'Restore drill remains pending.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia Production Backup Verify Evidence',
                '- Result: WARN',
                'MONGOYIA_PRODUCTION_BACKUP_VERIFY_EVIDENCE_V1',
                'ready_for_production_evidence_summary',
            ]);
            $this->assertFileContains($paths['csv'], [
                'key,status,evidence,reference,notes',
                'backup_verify_report',
                'rollback_owner_review',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('Production backup verify evidence generated read-only pending evidence.');
        } catch (\Throwable $e) {
            $this->fail('Production backup verify evidence fixture failed: ' . $e->getMessage());
        }
    }

    private function input(): array
    {
        return [
            'evidenceDir' => (string)$this->evidenceDir,
            'backupVerifyPath' => (string)$this->backupVerifyPath,
            'databaseChecksumSignoff' => (string)$this->databaseChecksumSignoff,
            'uploadArchiveSignoff' => (string)$this->uploadArchiveSignoff,
            'restoreDrillSignoff' => (string)$this->restoreDrillSignoff,
            'retentionSignoff' => (string)$this->retentionSignoff,
            'rollbackOwnerSignoff' => (string)$this->rollbackOwnerSignoff,
            'restoreDrillReference' => (string)$this->restoreDrillReference,
            'retentionReference' => (string)$this->retentionReference,
            'operator' => (string)$this->operator,
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-production-backup-verify-evidence-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = $this->service();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function service(): MongoyiaProductionBackupVerifyEvidenceService
    {
        return new MongoyiaProductionBackupVerifyEvidenceService(dirname(__DIR__, 2));
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
            $this->fail('Production backup verify evidence has pending rows and failOnPending=1.');
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
        $this->ok('Orders, payments, chats, files, funds, tickets, and statistics were not mutated by production backup verify evidence.');
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
