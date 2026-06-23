<?php

namespace console\controllers;

use common\services\mall\LanguageReviewService;
use yii\console\Controller;
use yii\console\ExitCode;

class LanguageReviewExportController extends Controller
{
    public $targets = 'en,mn';
    public $domains = 'ui,mail,notification,payment_error';
    public $limit = 2000;
    public $handoverDir = 'runtime/handover';
    public $csvPath = '';
    public $markdownPath = '';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'targets',
            'domains',
            'limit',
            'handoverDir',
            'csvPath',
            'markdownPath',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia language review export\n");
        $service = new LanguageReviewService();
        $result = $service->exportBundle([
            'targets' => $this->targets,
            'domains' => $this->domains,
            'limit' => $this->limit,
            'handoverDir' => $this->handoverDir,
            'csvPath' => $this->csvPath,
            'markdownPath' => $this->markdownPath,
        ]);

        $this->stdout("Rows: {$result['row_count']}\n");
        $this->stdout("CSV: {$result['csv_path']}\n");
        $this->stdout("Markdown: {$result['markdown_path']}\n");
        $this->stdout("Safety: review export only; no database writes, provider calls, or secrets.\n");

        return ExitCode::OK;
    }
}
