<?php

namespace console\controllers;

use common\services\mall\LanguageReviewService;
use yii\console\Controller;
use yii\console\ExitCode;

class LanguageReviewPhase12ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_LANGUAGE_REVIEW_PHASE12_READINESS_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;

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
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia language review Phase 12 readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkFixtureMatrix();
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
        $this->requireFileContains('Language review service', 'common/services/mall/LanguageReviewService.php', [
            'MONGOYIA_LANGUAGE_REVIEW_V1',
            'DOMAIN_UI',
            'DOMAIN_MAIL',
            'DOMAIN_NOTIFICATION',
            'DOMAIN_PAYMENT_ERROR',
            'exportBundle',
            'importCsv',
            'reviewed_translation',
            'review_status',
        ]);
        $this->requireFileContains('Language review export command', 'console/controllers/LanguageReviewExportController.php', [
            'Mongoyia language review export',
            'targets',
            'domains',
            'csv_path',
        ]);
        $this->requireFileContains('Language review import command', 'console/controllers/LanguageReviewImportController.php', [
            'Mongoyia language review import',
            'inputPath',
            'apply',
            'dry-run',
        ]);
        $this->requireFileContains('English app language package', 'common/messages/en/app.php', [
            'Security-code login is disabled',
            'Order status notification',
            'Payment callback verification failed',
        ]);
        $this->requireFileContains('Mongolian app language package', 'common/messages/mn/app.php', [
            'Email',
            'Check your email for further instructions.',
        ]);
    }

    private function checkFixtureMatrix(): void
    {
        $this->section('Fixture matrix');
        try {
            $service = new LanguageReviewService();
            $domains = array_keys($service->supportedDomains());
            foreach ([LanguageReviewService::DOMAIN_UI, LanguageReviewService::DOMAIN_MAIL, LanguageReviewService::DOMAIN_NOTIFICATION, LanguageReviewService::DOMAIN_PAYMENT_ERROR] as $domain) {
                if (!in_array($domain, $domains, true)) {
                    $this->fail("Language review domain missing: {$domain}.");
                    continue;
                }
                $this->ok("Language review domain {$domain} is ready.");
            }

            $rows = $service->exportRows([
                'targets' => 'en,mn',
                'domains' => 'mail,notification,payment_error',
                'limit' => 40,
            ]);
            if (count($rows) <= 0) {
                $this->fail('Language review export fixture returned no rows.');
                return;
            }
            $this->ok('Language review export fixture produced ' . count($rows) . ' rows.');

            $samplePath = $this->sampleImportCsv($rows[0]);
            $result = $service->importCsv($samplePath, false);
            if ((int)$result['planned_count'] !== 1 || (int)$result['apply'] !== 0) {
                $this->fail('Language review import dry-run fixture did not plan exactly one row.');
                return;
            }
            $this->ok('Language review import dry-run fixture is ready.');
        } catch (\Throwable $e) {
            $this->fail('Language review fixture matrix failed: ' . $e->getMessage());
        }
    }

    private function sampleImportCsv(array $row): string
    {
        $dir = $this->resolvePath($this->handoverDir);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'mongoyia-language-review-import-fixture-' . date('Ymd-His') . '.csv';
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['domain', 'category', 'source', 'target_language', 'current_translation', 'reviewed_translation', 'review_status', 'reviewer', 'notes']);
        fputcsv($handle, [
            $row['domain'],
            $row['category'],
            $row['source'],
            $row['target_language'],
            $row['current_translation'],
            $row['current_translation'] !== '' ? $row['current_translation'] : '[reviewed] ' . $row['source'],
            'approved',
            'codex-fixture',
            'dry-run fixture only',
        ]);
        fclose($handle);

        return $path;
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Language Review Phase 12 Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: reviewer-safe export/import for UI, mail, notification, and payment-error strings.',
            '- Safety: readiness uses export/dry-run import only; no provider calls, no database writes, no secrets, and no apply writes unless the import command is explicitly run with `--apply=1`.',
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
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck($label, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Required language review markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->addCheck($message, 'PASS', 'fixture', 'Language review fixture check passed.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-language-review-phase12-readiness-' . date('Ymd-His') . '.md';
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
