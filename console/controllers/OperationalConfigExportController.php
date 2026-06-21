<?php

namespace console\controllers;

use common\services\mall\OperationalConfigService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigExportController extends Controller
{
    public $outputPath = '';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['outputPath']);
    }

    public function actionRun()
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        try {
            $summary = (new OperationalConfigService())->summary();
            $result = 'PASS';
            $error = '';
        } catch (\Throwable $e) {
            $summary = ['rows' => [], 'latest_checks' => []];
            $result = 'FAIL';
            $error = $e->getMessage();
        }

        $lines = [
            '# Mongoyia Operational Config Redacted Export',
            '',
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Secret policy: redacted only; no private keys, Basic Auth, HMAC secrets, SMTP passwords, webhook secrets, or alert tokens are exported.',
            '',
        ];
        if ($error !== '') {
            $lines[] = '- Error: ' . $this->clean($error);
            $lines[] = '';
        }
        $lines[] = '## Config Rows';
        $lines[] = '';
        $lines[] = '| Category | Provider | Code | Environment | Enabled | Configured | Redacted Value | Last Check |';
        $lines[] = '|---|---|---|---|---|---|---|---|';
        foreach (($summary['rows'] ?? []) as $row) {
            $lines[] = '| ' . $this->cell($row['category'] ?? '') . ' | '
                . $this->cell($row['provider'] ?? '') . ' | '
                . $this->cell($row['code'] ?? '') . ' | '
                . $this->cell($row['environment'] ?? '') . ' | '
                . ((int)($row['is_enabled'] ?? 0) === 1 ? 'yes' : 'no') . ' | '
                . ((int)($row['configured'] ?? 0) === 1 ? 'yes' : 'no') . ' | '
                . $this->cell($row['redacted_value'] ?? '') . ' | '
                . $this->cell(($row['last_check_status'] ?? '') . ' ' . ($row['last_check_message'] ?? '')) . ' |';
        }
        $lines[] = '';
        $lines[] = '## Latest Checks';
        $lines[] = '';
        $lines[] = '| Time | Category | Provider | Key | Result | Message |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach (($summary['latest_checks'] ?? []) as $check) {
            $lines[] = '| ' . ((int)($check['checked_at'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int)$check['checked_at']) : '-') . ' | '
                . $this->cell($check['category'] ?? '') . ' | '
                . $this->cell($check['provider'] ?? '') . ' | '
                . $this->cell($check['check_key'] ?? '') . ' | '
                . $this->cell($check['result'] ?? '') . ' | '
                . $this->cell($check['message'] ?? '') . ' |';
        }
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines));
        $this->stdout("Redacted operational config export written to {$path}\n");
        return $result === 'PASS' ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function defaultPath(): string
    {
        return $this->resolvePath('runtime/handover/mongoyia-operational-config-redacted-export-' . date('Ymd-His') . '.md');
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function cell($value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $this->clean((string)$value));
    }

    private function clean(string $value): string
    {
        return str_replace(['PRIVATE KEY', 'Basic ', 'Bearer '], ['[redacted-key]', '[redacted-basic] ', '[redacted-bearer] '], $value);
    }
}
