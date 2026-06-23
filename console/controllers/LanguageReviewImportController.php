<?php

namespace console\controllers;

use common\services\mall\LanguageReviewService;
use yii\console\Controller;
use yii\console\ExitCode;

class LanguageReviewImportController extends Controller
{
    public $inputPath = '';
    public $apply = false;
    public $outputPath = '';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'inputPath',
            'apply',
            'outputPath',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia language review import\n");
        if (trim((string)$this->inputPath) === '') {
            $this->stderr("FAIL inputPath is required.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $service = new LanguageReviewService();
            $result = $service->importCsv((string)$this->inputPath, (bool)$this->apply);
            $report = $service->writeImportReport($result, (string)$this->outputPath);
        } catch (\Throwable $e) {
            $this->stderr("FAIL " . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Mode: " . ((bool)$this->apply ? 'apply' : 'dry-run') . "\n");
        $this->stdout("Planned rows: {$result['planned_count']}\n");
        $this->stdout("Skipped rows: {$result['skipped_count']}\n");
        $this->stdout("Written files: " . implode(', ', $result['written_files'] ?: ['none']) . "\n");
        $this->stdout("Report: {$report}\n");

        return ExitCode::OK;
    }
}
