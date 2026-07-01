<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MiniProgramCompatReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_MINI_PROGRAM_COMPAT_READINESS_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $strict = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'handoverDir',
            'outputPath',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia mini-program compatibility readiness\n");

        $this->checkFrontendChat();
        $this->checkThemeChat();
        $this->checkBacklogRegistration();

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFrontendChat(): void
    {
        $this->section('Frontend chat WebView compatibility');
        $path = 'frontend/modules/mall/views/chat/index.php';
        $this->requireFileContains($path, [
            'MONGOYIA_MINI_PROGRAM_CHAT_QUERY_COMPAT_V1',
            'buildChatQuery',
            'encodeURIComponent',
            'MONGOYIA_MINI_PROGRAM_CHAT_FORMDATA_GUARD_V1',
            'createChatFormData',
            '!window.FormData',
            '!window.WebSocket',
        ]);
        $this->requireFileNotContains($path, [
            'URLSearchParams',
            'new URL(',
            'new FormData(',
            'new Blob(',
            'new File(',
            'new MediaRecorder(',
        ]);
    }

    private function checkThemeChat(): void
    {
        $this->section('Theme chat WebView compatibility');
        $path = 'web/resources/mall/default/views/chat/index.php';
        $this->requireFileContains($path, [
            'MONGOYIA_CHAT_WEBVIEW_FORMDATA_GUARD_V1',
            'createChatFormData',
            '!window.FormData',
            'MONGOYIA_CHAT_WEBVIEW_URL_NORMALIZER_COMPAT_V1',
            'parseSameOriginUrl',
            'window.URL',
            'window.location.protocol',
            '!window.MediaRecorder || !window.Blob || !window.File',
            'new window.MediaRecorder',
            'new window.Blob',
            'new window.File',
        ]);
        $this->requireFileNotContains($path, [
            'URLSearchParams',
            'new URL(',
            'new FormData(',
            'new Blob(',
            'new File(',
            'new MediaRecorder(',
        ]);
    }

    private function checkBacklogRegistration(): void
    {
        $this->section('Backlog registration');
        $this->requireFileContains('docs/mongoyia-upgrade-backlog-20260618.md', [
            self::VERSION,
            'mini-program-compat-readiness/run',
            'MONGOYIA_MINI_PROGRAM_CHAT_QUERY_COMPAT_V1',
        ]);
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($path, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck($path, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }

        $this->addCheck($path, 'PASS', $path, 'Required compatibility markers are present.');
    }

    private function requireFileNotContains(string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($path, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                $this->addCheck($path, 'FAIL', $path, "Forbidden unguarded API marker remains: {$needle}.");
                return;
            }
        }

        $this->addCheck($path, 'PASS', $path, 'Forbidden unguarded API markers are absent.');
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Mini-Program Compatibility Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: 0',
            '- Afterfill pending: 0',
            '- Scope: mini-program/H5 customer-service chat entry compatibility for query building, upload/rating forms, URL normalization, and voice recording fallback.',
            '- Safety: this command is read-only and does not call external providers, create orders, change payment state, mutate funds, approve reviews, or switch production GO.',
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

        $lines = array_merge($lines, [
            '',
            '## BaoTa Verification Command',
            '',
            '```bash',
            'cd /www/wwwroot/demo2026.mongoyia.com',
            'git pull --ff-only',
            '/www/server/php/83/bin/php yii mini-program-compat-readiness/run --strict=1 --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
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
            . DIRECTORY_SEPARATOR . 'mini-program-compat-readiness-' . date('Ymd-His') . '.md';
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
