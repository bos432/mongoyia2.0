<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaPhase3ScheduledOpsController extends Controller
{
    public $strict = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['strict']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia Phase 3 scheduled ops readiness\n");
        $this->checkScript('console/shell/mongoyia-phase3-scheduled-ops.ps1', [
            'mongoyia-logistics-fee-reconciliation/run',
            'mongoyia-auto-receive/run',
            'ApplyAutoReceive',
            'mongoyia-test-cleanup/run',
        ]);
        $this->checkScript('console/shell/mongoyia-phase3-scheduled-ops.sh', [
            'mongoyia-logistics-fee-reconciliation/run',
            'mongoyia-auto-receive/run',
            'APPLY_AUTO_RECEIVE',
            'mongoyia-test-cleanup/run',
        ]);
        $this->checkDocs();

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkScript(string $relativePath, array $needles)
    {
        $this->section($relativePath);
        $path = $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            $this->fail("Missing {$relativePath}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("{$relativePath} missing {$needle}.");
                return;
            }
        }
        $this->ok("{$relativePath} contains scheduled ops markers.");
    }

    private function checkDocs()
    {
        $this->section('Docs');
        $this->requireFileContains('docs/mongoyia-delivery-status.md', ['mongoyia-phase3-scheduled-ops', 'ApplyAutoReceive']);
        $this->requireFileContains('docs/mongoyia-upgrade-backlog-20260618.md', ['Phase 3 scheduled operations', 'mongoyia-phase3-scheduled-ops']);
        $this->requireFileContains('docs/mongoyia-development-progress.md', ['Scheduled ops runbook added', 'mongoyia-phase3-scheduled-ops']);
    }

    private function requireFileContains(string $relativePath, array $needles)
    {
        $path = $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            $this->fail("Missing {$relativePath}.");
            return;
        }
        $content = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("{$relativePath} missing {$needle}.");
                return;
            }
        }
        $this->ok("{$relativePath} contains scheduled ops docs.");
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function section(string $name)
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message)
    {
        $this->stdout("OK   {$message}\n");
    }

    private function warn(string $message)
    {
        $this->warnings++;
        $this->stdout("WARN {$message}\n");
    }

    private function fail(string $message)
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
