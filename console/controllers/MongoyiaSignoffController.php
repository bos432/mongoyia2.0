<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaSignoffController extends Controller
{
    public $reportPath = '';
    public $outputPath = '';
    public $tester = '';
    public $notes = '';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'reportPath',
            'outputPath',
            'tester',
            'notes',
        ]);
    }

    public function actionRun()
    {
        $reportPath = $this->resolveReportPath();
        if ($reportPath === '' || !is_file($reportPath)) {
            $this->stderr("Acceptance report not found. Pass --reportPath=runtime/acceptance/mongoyia-acceptance-*.md.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $content = file_get_contents($reportPath);
        $summary = $this->section($content, 'Signoff Summary');
        if ($summary === '') {
            $this->stderr("Signoff Summary section not found in {$reportPath}.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $generatedData = $this->section($content, 'Generated Test Data');
        $outputPath = $this->resolveOutputPath($reportPath);
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Acceptance Signoff',
            '',
            '| Item | Value |',
            '|---|---|',
            '| Source report | ' . $this->relativePath($reportPath) . ' |',
            '| Generated at | ' . date('Y-m-d H:i:s') . ' |',
            '| Tester | ' . ($this->tester !== '' ? $this->tester : 'TBD') . ' |',
            '| Notes | ' . ($this->notes !== '' ? $this->notes : 'TBD') . ' |',
            '',
            '## Acceptance Summary',
            '',
            trim($summary),
            '',
        ];

        if ($generatedData !== '') {
            $lines[] = '## Generated Test Data';
            $lines[] = '';
            $lines[] = trim($generatedData);
            $lines[] = '';
        }

        $lines[] = '## Signoff';
        $lines[] = '';
        $lines[] = '| Role | Name | Result | Date |';
        $lines[] = '|---|---|---|---|';
        $lines[] = '| Tester |  |  |  |';
        $lines[] = '| Project owner |  |  |  |';
        $lines[] = '| Technical owner |  |  |  |';
        $lines[] = '';

        file_put_contents($outputPath, implode("\n", $lines));
        $this->stdout("Signoff file written to {$outputPath}\n");
        return ExitCode::OK;
    }

    private function resolveReportPath()
    {
        if ($this->reportPath !== '') {
            return $this->resolvePath($this->reportPath);
        }

        $files = glob($this->projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'acceptance' . DIRECTORY_SEPARATOR . 'mongoyia-acceptance-*.md');
        if (!$files) {
            return '';
        }

        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }

    private function resolveOutputPath(string $reportPath)
    {
        if ($this->outputPath !== '') {
            return $this->resolvePath($this->outputPath);
        }

        $name = basename($reportPath);
        $name = preg_replace('/^mongoyia-acceptance-/', 'mongoyia-signoff-', $name);
        return dirname($reportPath) . DIRECTORY_SEPARATOR . $name;
    }

    private function section(string $content, string $heading)
    {
        $pattern = '/^## ' . preg_quote($heading, '/') . '\R(?P<body>.*?)(?=^## |\z)/ms';
        if (!preg_match($pattern, $content, $matches)) {
            return '';
        }

        return trim($matches['body']);
    }

    private function resolvePath(string $path)
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function relativePath(string $path)
    {
        $root = rtrim($this->projectRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root) ? str_replace('\\', '/', substr($path, strlen($root))) : $path;
    }

    private function projectRoot()
    {
        return dirname(__DIR__, 2);
    }
}
