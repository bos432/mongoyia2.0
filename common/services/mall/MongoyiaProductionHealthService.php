<?php

namespace common\services\mall;

class MongoyiaProductionHealthService
{
    public const HEALTH_VERSION = 'MONGOYIA_PRODUCTION_HEALTH_V1';

    private const MODE = 'production_health_read_only_internal_checks';

    public function run(array $input, array $steps): array
    {
        $rows = [];
        foreach ($steps as $step) {
            $rows[] = [
                'key' => (string)$step['key'],
                'name' => (string)$step['name'],
                'status' => ((int)$step['exitCode'] === 0) ? 'PASS' : 'FAIL',
                'exitCode' => (int)$step['exitCode'],
                'command' => (string)$step['command'],
                'output' => (string)$step['output'],
                'notes' => (string)($step['notes'] ?? ''),
            ];
        }

        $totals = $this->totals($rows);

        return [
            'healthVersion' => self::HEALTH_VERSION,
            'mode' => self::MODE,
            'result' => $totals['fail_count'] > 0 ? 'FAIL' : 'PASS',
            'phpEnv' => (string)($input['phpEnv'] ?? ''),
            'imEnv' => (string)($input['imEnv'] ?? ''),
            'strict' => (bool)($input['strict'] ?? false),
            'skipConnectivity' => (bool)($input['skipConnectivity'] ?? false),
            'rows' => $rows,
            'totals' => $totals,
            'issues' => $this->issues($rows),
        ];
    }

    private function totals(array $rows): array
    {
        $totals = [
            'health_row_count' => count($rows),
            'pass_count' => 0,
            'fail_count' => 0,
            'warning_line_count' => 0,
            'failure_line_count' => 0,
            'dry_run_external_provider_call_count' => 0,
            'dry_run_business_write_count' => 0,
        ];

        foreach ($rows as $row) {
            if ((string)$row['status'] === 'PASS') {
                $totals['pass_count']++;
            } else {
                $totals['fail_count']++;
            }
            $totals['warning_line_count'] += preg_match_all('/^WARN\b/m', (string)$row['output']);
            $totals['failure_line_count'] += preg_match_all('/^FAIL\b/m', (string)$row['output']);
        }

        $totals['ready_for_production_evidence_summary'] = $totals['fail_count'] === 0 ? 1 : 0;

        return $totals;
    }

    private function issues(array $rows): array
    {
        $issues = [];
        foreach ($rows as $row) {
            if ((string)$row['status'] === 'PASS') {
                continue;
            }
            $issues[] = (string)$row['key'] . ': exit ' . (int)$row['exitCode'];
        }

        return $issues;
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Production Health Report',
            '',
            '- Result: ' . (string)($report['result'] ?? ''),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Health version: ' . (string)($report['healthVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- PHP env: ' . (string)($report['phpEnv'] ?? ''),
            '- IM env: ' . (string)($report['imEnv'] ?? ''),
            '- Strict deploy-check: ' . (($report['strict'] ?? false) ? '1' : '0'),
            '- Skip connectivity: ' . (($report['skipConnectivity'] ?? false) ? '1' : '0'),
            '',
            'This report runs internal read-only checks. It does not restore databases, create orders, trigger payment callbacks, call payment providers, connect to IM when connectivity is skipped, or mutate business data.',
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
            '## Health Steps',
            '',
            '| Key | Status | Exit Code | Command | Notes |',
            '|---|---|---:|---|---|',
        ]);

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['key'])
                . ' | ' . $this->escapeCell((string)$row['status'])
                . ' | ' . (int)$row['exitCode']
                . ' | ' . $this->escapeCell((string)$row['command'])
                . ' | ' . $this->escapeCell((string)$row['notes'])
                . ' |';
        }

        foreach (($report['rows'] ?? []) as $row) {
            $lines = array_merge($lines, [
                '',
                '### ' . (string)$row['name'],
                '',
                '- Status: ' . (string)$row['status'],
                '- Exit code: ' . (int)$row['exitCode'],
                '',
                '```text',
                (string)$row['command'],
                '```',
                '',
                'Output:',
                '',
                '```text',
                trim((string)$row['output']),
                '```',
            ]);
        }

        $lines = array_merge($lines, [
            '',
            '## Boundary',
            '',
            'Local fixture reports are expected to remain FAIL until prod-style HTTPS/WSS, IM secret, payment credential, PHP upload limit, and callback HMAC inputs are configured. A FAIL report is a production blocker, not a launch approval.',
        ]);

        return $lines;
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,status,exit_code,command,notes'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = $this->csvRow([
                (string)$row['key'],
                (string)$row['status'],
                (string)$row['exitCode'],
                (string)$row['command'],
                (string)$row['notes'],
            ]);
        }

        return $lines;
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
