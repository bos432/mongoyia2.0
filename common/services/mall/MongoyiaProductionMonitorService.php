<?php

namespace common\services\mall;

class MongoyiaProductionMonitorService
{
    public const MONITOR_VERSION = 'MONGOYIA_PRODUCTION_MONITOR_V1';

    private const MODE = 'production_monitor_read_only_runtime_snapshot';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $input = []): array
    {
        $phpEnv = (string)($input['phpEnv'] ?? '.env');
        $imEnv = (string)($input['imEnv'] ?? '../../im后端/im后端/.env');
        $diskWarnPercent = (int)($input['diskWarnPercent'] ?? 85);
        $diskFailPercent = (int)($input['diskFailPercent'] ?? 95);
        $skipConnectivity = (bool)($input['skipConnectivity'] ?? false);
        $skipImPort = (bool)($input['skipImPort'] ?? false);

        $rows = [
            $this->phpCliRow(),
            $this->databaseEnvRow($phpEnv),
            $this->redisRow($phpEnv, $skipConnectivity),
            $this->imRow($imEnv, $skipConnectivity, $skipImPort),
            $this->diskRow($diskWarnPercent, $diskFailPercent),
        ];

        foreach (['runtime', 'frontend/runtime', 'web/assets', 'web/attachment'] as $path) {
            $rows[] = $this->filesystemRow($path);
        }
        $rows[] = $this->logRow();

        $totals = $this->totals($rows);

        return [
            'monitorVersion' => self::MONITOR_VERSION,
            'mode' => self::MODE,
            'result' => $this->result($totals),
            'phpEnv' => $phpEnv,
            'imEnv' => $imEnv,
            'diskWarnPercent' => $diskWarnPercent,
            'diskFailPercent' => $diskFailPercent,
            'skipConnectivity' => $skipConnectivity,
            'skipImPort' => $skipImPort,
            'rows' => $rows,
            'totals' => $totals,
            'issues' => $this->issues($rows),
        ];
    }

    private function phpCliRow(): array
    {
        return $this->row(
            'php_cli',
            'Runtime',
            'PHP CLI',
            'PASS',
            PHP_VERSION . ' (' . PHP_BINARY . ')',
            'Keep PHP CLI available for console health and maintenance commands.'
        );
    }

    private function databaseEnvRow(string $phpEnv): array
    {
        $dbDsn = $this->envValue($phpEnv, 'DB_DSN');
        $dbUser = $this->envValue($phpEnv, 'DB_USERNAME');
        if ($dbDsn !== '' && $dbUser !== '') {
            return $this->row(
                'php_database_env',
                'Config',
                'PHP database env present',
                'PASS',
                'DB_DSN and DB_USERNAME exist',
                'Run deploy-check for credential validation.'
            );
        }

        return $this->row(
            'php_database_env',
            'Config',
            'PHP database env present',
            'FAIL',
            'Missing DB_DSN or DB_USERNAME',
            'Provision the real PHP .env before production launch.'
        );
    }

    private function redisRow(string $phpEnv, bool $skipConnectivity): array
    {
        $host = $this->envValue($phpEnv, 'REDIS_HOST');
        $port = $this->envValue($phpEnv, 'REDIS_PORT');
        if ($host === '' || $port === '') {
            return $this->row(
                'redis_port',
                'Connectivity',
                'Redis port',
                'WARN',
                'REDIS_HOST/REDIS_PORT missing',
                'Provision Redis env and verify the service manager.'
            );
        }
        if ($skipConnectivity) {
            return $this->row(
                'redis_port',
                'Connectivity',
                'Redis port',
                'WARN',
                $host . ':' . $port . ' configured; connectivity skipped',
                'Run without skipConnectivity on the real host.'
            );
        }

        return $this->socketRow(
            'redis_port',
            'Connectivity',
            'Redis port',
            $host,
            $port,
            'Monitor latency and memory in production.',
            'Start Redis or verify network/security group.'
        );
    }

    private function imRow(string $imEnv, bool $skipConnectivity, bool $skipImPort): array
    {
        if ($skipImPort) {
            return $this->row(
                'python_im_port',
                'Connectivity',
                'Python IM port',
                'WARN',
                'IM port check skipped',
                'Run IM WSS healthcheck through the real domain before production launch.'
            );
        }

        $host = $this->envValue($imEnv, 'IM_HOST');
        $port = $this->envValue($imEnv, 'IM_PORT');
        if ($host === '0.0.0.0') {
            $host = '127.0.0.1';
        }
        if ($host === '' || $port === '') {
            return $this->row(
                'python_im_port',
                'Connectivity',
                'Python IM port',
                'WARN',
                'IM_HOST/IM_PORT missing',
                'Provision Python IM .env and service manager config.'
            );
        }
        if ($skipConnectivity) {
            return $this->row(
                'python_im_port',
                'Connectivity',
                'Python IM port',
                'WARN',
                $host . ':' . $port . ' configured; connectivity skipped',
                'Run without skipConnectivity on the real host and also test public WSS.'
            );
        }

        return $this->socketRow(
            'python_im_port',
            'Connectivity',
            'Python IM port',
            $host,
            $port,
            'Also run IM WSS healthcheck through the real domain.',
            'Start IM process or verify supervisor/systemd.'
        );
    }

    private function diskRow(int $warnPercent, int $failPercent): array
    {
        $warnPercent = max(1, min(100, $warnPercent));
        $failPercent = max($warnPercent, min(100, $failPercent));
        $total = @disk_total_space($this->rootPath);
        $free = @disk_free_space($this->rootPath);
        if ($total === false || $free === false || (float)$total <= 0) {
            return $this->row(
                'project_disk_usage',
                'Capacity',
                'Project disk usage',
                'WARN',
                'Disk usage unavailable',
                'Enable OS-level disk alerts on the production host.'
            );
        }

        $usedPercent = round(((float)$total - (float)$free) / (float)$total * 100, 2);
        if ($usedPercent >= $failPercent) {
            return $this->row(
                'project_disk_usage',
                'Capacity',
                'Project disk usage',
                'FAIL',
                $usedPercent . '% used',
                'Free disk space before uploads/logs/backups fill the volume.'
            );
        }
        if ($usedPercent >= $warnPercent) {
            return $this->row(
                'project_disk_usage',
                'Capacity',
                'Project disk usage',
                'WARN',
                $usedPercent . '% used',
                'Plan cleanup or volume expansion.'
            );
        }

        return $this->row(
            'project_disk_usage',
            'Capacity',
            'Project disk usage',
            'PASS',
            $usedPercent . '% used',
            'Keep daily disk alerts enabled.'
        );
    }

    private function filesystemRow(string $path): array
    {
        $fullPath = $this->resolvePath($path);
        $key = 'filesystem_' . str_replace(['/', '\\', '-'], '_', $path);
        if (!file_exists($fullPath)) {
            return $this->row(
                $key,
                'Filesystem',
                $path,
                'WARN',
                'missing',
                'Create before production traffic.'
            );
        }
        if (!is_writable($fullPath)) {
            return $this->row(
                $key,
                'Filesystem',
                $path,
                'WARN',
                'exists but is not writable by current CLI user',
                'Keep writable by the PHP runtime user.'
            );
        }

        return $this->row(
            $key,
            'Filesystem',
            $path,
            'PASS',
            'exists and writable',
            'Keep writable by the PHP runtime user.'
        );
    }

    private function logRow(): array
    {
        $files = [];
        foreach (['frontend/runtime/logs', 'console/runtime/logs'] as $path) {
            $files = array_merge($files, $this->recentFiles($this->resolvePath($path)));
        }
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $files = array_slice($files, 0, 5);
        if ($files === []) {
            return $this->row(
                'runtime_logs',
                'Logs',
                'Recent runtime logs',
                'WARN',
                'No runtime log files found',
                'Verify log path, write permissions, and rotation.'
            );
        }

        return $this->row(
            'runtime_logs',
            'Logs',
            'Recent runtime logs',
            'PASS',
            implode(', ', array_map('basename', $files)),
            'Feed PHP and IM logs into alerting.'
        );
    }

    private function socketRow(
        string $key,
        string $area,
        string $check,
        string $host,
        string $port,
        string $passAction,
        string $warnAction
    ): array {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, (int)$port, $errno, $errstr, 1.0);
        if (is_resource($socket)) {
            fclose($socket);
            return $this->row($key, $area, $check, 'PASS', $host . ':' . $port . ' reachable', $passAction);
        }

        $evidence = $host . ':' . $port . ' not reachable';
        if ($errstr !== '') {
            $evidence .= ' (' . $errstr . ')';
        }

        return $this->row($key, $area, $check, 'WARN', $evidence, $warnAction);
    }

    private function row(string $key, string $area, string $check, string $status, string $evidence, string $action): array
    {
        return [
            'key' => $key,
            'area' => $area,
            'check' => $check,
            'status' => $status,
            'evidence' => $evidence,
            'action' => $action,
        ];
    }

    private function totals(array $rows): array
    {
        $totals = [
            'monitor_row_count' => count($rows),
            'pass_count' => 0,
            'warn_count' => 0,
            'fail_count' => 0,
            'dry_run_external_provider_call_count' => 0,
            'dry_run_business_write_count' => 0,
        ];

        foreach ($rows as $row) {
            if ((string)$row['status'] === 'PASS') {
                $totals['pass_count']++;
            } elseif ((string)$row['status'] === 'FAIL') {
                $totals['fail_count']++;
            } else {
                $totals['warn_count']++;
            }
        }

        $totals['ready_for_production_evidence_summary'] = $totals['fail_count'] === 0 ? 1 : 0;

        return $totals;
    }

    private function result(array $totals): string
    {
        if ((int)$totals['fail_count'] > 0) {
            return 'FAIL';
        }
        if ((int)$totals['warn_count'] > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function issues(array $rows): array
    {
        $issues = [];
        foreach ($rows as $row) {
            if ((string)$row['status'] === 'PASS') {
                continue;
            }
            $issues[] = (string)$row['key'] . ': ' . (string)$row['status'] . ' - ' . (string)$row['action'];
        }

        return $issues;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Production Monitor',
            '',
            '- Result: ' . (string)($report['result'] ?? ''),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Monitor version: ' . (string)($report['monitorVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- PHP env: ' . (string)($report['phpEnv'] ?? ''),
            '- IM env: ' . (string)($report['imEnv'] ?? ''),
            '- Disk warn percent: ' . (int)($report['diskWarnPercent'] ?? 0),
            '- Disk fail percent: ' . (int)($report['diskFailPercent'] ?? 0),
            '- Skip connectivity: ' . (($report['skipConnectivity'] ?? false) ? '1' : '0'),
            '- Skip IM port: ' . (($report['skipImPort'] ?? false) ? '1' : '0'),
            '',
            'This report checks runtime, env, Redis/IM connectivity options, disk capacity, writable paths, and recent logs. It does not call payment providers, trigger callbacks, create orders, connect to public WSS, restore databases, or mutate business data.',
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
        ];

        foreach (($report['totals'] ?? []) as $key => $value) {
            $lines[] = '| ' . $this->escapeCell((string)$key) . ' | ' . (int)$value . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Monitor Rows',
            '',
            '| Key | Area | Check | Status | Evidence | Action |',
            '|---|---|---|---:|---|---|',
        ]);

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['key'])
                . ' | ' . $this->escapeCell((string)$row['area'])
                . ' | ' . $this->escapeCell((string)$row['check'])
                . ' | ' . $this->escapeCell((string)$row['status'])
                . ' | ' . $this->escapeCell((string)$row['evidence'])
                . ' | ' . $this->escapeCell((string)$row['action'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Boundary',
            '',
            'Local fixture reports are expected to remain WARN or FAIL until the real host has production env files, reachable Redis and IM services, writable upload/runtime paths, recent logs, and sufficient disk capacity. A non-PASS report is a production blocker, not a launch approval.',
        ]);

        return $lines;
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,area,check,status,evidence,action'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = $this->csvRow([
                (string)$row['key'],
                (string)$row['area'],
                (string)$row['check'],
                (string)$row['status'],
                (string)$row['evidence'],
                (string)$row['action'],
            ]);
        }

        return $lines;
    }

    private function envValue(string $path, string $key): string
    {
        $fullPath = $this->resolvePath($path);
        if (!is_file($fullPath)) {
            return '';
        }

        $lines = file($fullPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }

        $value = '';
        foreach ($lines as $line) {
            if (!preg_match('/^\s*' . preg_quote($key, '/') . '\s*=(.*)$/', (string)$line, $matches)) {
                continue;
            }
            $value = trim((string)$matches[1]);
        }

        return trim($value, "\"'");
    }

    private function recentFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\UnexpectedValueException $e) {
            return [];
        }

        return $files;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0) {
            return $path;
        }

        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function escapeCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    private function csvRow(array $cells): string
    {
        return implode(',', array_map(static function ($cell) {
            return '"' . str_replace('"', '""', (string)$cell) . '"';
        }, $cells));
    }
}
