<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class TestStationWafDiagnosticsController extends Controller
{
    public const VERSION = 'MONGOYIA_TEST_STATION_WAF_DIAGNOSTICS_V1';

    public $domain = 'demo2026.mongoyia.com';
    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $maxLogBytes = 524288;
    public $maxMatches = 60;
    public $strict = false;

    private $checks = [];
    private $matches = [];
    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'domain',
            'baseUrl',
            'handoverDir',
            'outputPath',
            'maxLogBytes',
            'maxMatches',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->domain = trim((string)$this->domain);
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia test-station WAF diagnostics\n");

        $this->checkSourceCoverage();
        $this->inspectConfigFiles();
        $this->inspectLogFiles();
        $this->inspectRuntimeLogs();

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), " . count($this->matches) . " evidence line(s).\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Source coverage');
        $this->requireFileContains('docs/mongoyia-optimization-remediation-plan-20260702.md', [
            self::VERSION,
            'test-station-waf-diagnostics/run',
            '444/WAF',
        ]);
        $this->requireFileContains('docs/mongoyia-deployment-guide-20260702.md', [
            self::VERSION,
            'test-station-waf-diagnostics/run',
            'HTTP 444',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaRequirementsClosureAcceptanceController.php', [
            self::VERSION,
            'Test-station WAF diagnostics',
            'test-station-waf-diagnostics/run',
        ]);
    }

    private function inspectConfigFiles(): void
    {
        $this->section('Nginx/BaoTa/WAF config markers');
        $files = $this->candidateConfigFiles();
        $found = 0;
        foreach ($files as $label => $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $found++;
            $content = $this->readTail($path);
            $hits = $this->findConfigHits($content);
            if (!$hits) {
                $this->addCheck('Config ' . $label, 'PASS', $path, 'Readable and no obvious 444/deny/WAF marker was found in the inspected tail.');
                continue;
            }
            foreach ($hits as $line) {
                $this->recordMatch('CONFIG', $label, $path, $line);
            }
            $this->addCheck('Config ' . $label, 'WARN', $path, 'Found 444/deny/WAF/security markers; inspect the report evidence lines before changing rules.');
        }

        if ($found === 0) {
            $this->addCheck('Config file discovery', 'WARN', 'no readable BaoTa/Nginx config candidates', 'Run this command on the BaoTa server user that can read Nginx and panel configuration files.');
        }
    }

    private function inspectLogFiles(): void
    {
        $this->section('Nginx/BaoTa/WAF log evidence');
        $files = $this->candidateLogFiles();
        $found = 0;
        $hitCount = 0;
        foreach ($files as $label => $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $found++;
            $content = $this->readTail($path);
            $hits = $this->findLogHits($content);
            foreach ($hits as $line) {
                $hitCount++;
                $this->recordMatch('LOG', $label, $path, $line);
            }
            if ($hits) {
                $this->addCheck('Log ' . $label, 'WARN', $path, 'Found backend/login/444/WAF-related evidence in the inspected tail.');
            } else {
                $this->addCheck('Log ' . $label, 'PASS', $path, 'Readable and no backend/login/444/WAF hit was found in the inspected tail.');
            }
        }

        if ($found === 0) {
            $this->addCheck('Log file discovery', 'WARN', 'no readable BaoTa/Nginx log candidates', 'Run this command on the BaoTa server or pass readable logs into the expected paths.');
        } elseif ($hitCount === 0) {
            $this->addCheck('HTTP 444 evidence lines', 'PASS', '0', 'No 444/backend-login evidence lines were found in readable log tails.');
        }
    }

    private function inspectRuntimeLogs(): void
    {
        $this->section('Yii runtime log context');
        $paths = [
            'runtime/logs/app.log',
            'runtime/logs/console.log',
        ];
        foreach ($paths as $path) {
            $full = $this->resolvePath($path);
            if (!is_file($full) || !is_readable($full)) {
                $this->addCheck('Runtime log ' . $path, 'WARN', $path, 'Runtime log is not readable in this environment.');
                continue;
            }
            $content = $this->readTail($full);
            $hits = $this->findLogHits($content);
            foreach ($hits as $line) {
                $this->recordMatch('YII', $path, $full, $line);
            }
            if ($hits) {
                $this->addCheck('Runtime log ' . $path, 'WARN', $path, 'Found backend/login/444-related application log context.');
            } else {
                $this->addCheck('Runtime log ' . $path, 'PASS', $path, 'No backend/login/444-related application log context in the inspected tail.');
            }
        }
    }

    private function candidateConfigFiles(): array
    {
        $domain = $this->domain;
        return [
            'site nginx vhost' => '/www/server/panel/vhost/nginx/' . $domain . '.conf',
            'site rewrite' => '/www/server/panel/vhost/rewrite/' . $domain . '.conf',
            'nginx main config' => '/www/server/nginx/conf/nginx.conf',
            'nginx http config' => '/www/server/nginx/conf/nginx.conf',
            'BaoTa WAF site config' => '/www/server/panel/plugin/btwaf/site.json',
            'BaoTa WAF config' => '/www/server/panel/plugin/btwaf/config.json',
            'Nginx WAF config' => '/www/server/nginx/conf/waf/config.lua',
            'Nginx WAF init' => '/www/server/nginx/conf/waf/init.lua',
            'project user ini' => dirname(__DIR__, 2) . '/.user.ini',
        ];
    }

    private function candidateLogFiles(): array
    {
        $domain = $this->domain;
        return [
            'site access log' => '/www/wwwlogs/' . $domain . '.log',
            'site error log' => '/www/wwwlogs/' . $domain . '.error.log',
            'site access underscore log' => '/www/wwwlogs/' . str_replace('.', '_', $domain) . '.log',
            'nginx access log' => '/www/server/nginx/logs/access.log',
            'nginx error log' => '/www/server/nginx/logs/error.log',
            'BaoTa panel error log' => '/www/server/panel/logs/error.log',
            'BaoTa panel task log' => '/www/server/panel/logs/task.log',
            'BaoTa WAF log' => '/www/server/panel/plugin/btwaf/logs/' . $domain . '.log',
            'BaoTa WAF total log' => '/www/server/panel/plugin/btwaf/logs/total.log',
        ];
    }

    private function findConfigHits(string $content): array
    {
        return $this->filterLines($content, [
            '/\breturn\s+444\b/i',
            '/\bdeny\s+all\b/i',
            '/\bdeny\s+\d{1,3}(?:\.\d{1,3}){3}\b/i',
            '/\binclude\b.*(?:waf|btwaf|firewall|security)/i',
            '/(?:waf|btwaf|firewall|security|cc_defense|cc\s*rule)/i',
            '/backend\/site\/login|backend\/site\/info|\/backend\//i',
        ]);
    }

    private function findLogHits(string $content): array
    {
        return $this->filterLines($content, [
            '/\s444\s/',
            '/backend\/site\/login|backend\/site\/info|\/backend\//i',
            '/(?:waf|btwaf|firewall|security|cc_defense|forbidden|denied|blocked|rule)/i',
        ]);
    }

    private function filterLines(string $content, array $patterns): array
    {
        $hits = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $hits[] = $this->sanitizeLine($line);
                    break;
                }
            }
            if (count($hits) >= (int)$this->maxMatches) {
                break;
            }
        }
        return $hits;
    }

    private function readTail(string $path): string
    {
        $max = max(4096, (int)$this->maxLogBytes);
        $size = @filesize($path);
        if ($size === false || $size <= $max) {
            return (string)@file_get_contents($path);
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return '';
        }
        fseek($handle, -$max, SEEK_END);
        $data = (string)stream_get_contents($handle);
        fclose($handle);
        return $data;
    }

    private function sanitizeLine(string $line): string
    {
        $line = preg_replace('/(password|passwd|pwd|secret|token|key|authorization|cookie|set-cookie)=([^&\s;]+)/i', '$1=[redacted]', $line);
        $line = preg_replace('/(Authorization|Cookie|Set-Cookie):\s*[^,\s]+/i', '$1: [redacted]', $line);
        if (strlen($line) > 500) {
            $line = substr($line, 0, 500) . '...';
        }
        return $line;
    }

    private function recordMatch(string $type, string $label, string $path, string $line): void
    {
        if (count($this->matches) >= (int)$this->maxMatches) {
            return;
        }
        $this->matches[] = [
            'type' => $type,
            'label' => $label,
            'path' => $path,
            'line' => $line,
        ];
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Test-Station WAF Diagnostics',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Domain: ' . $this->domain,
            '- Base URL: ' . $this->baseUrl,
            '- Max log bytes per file: ' . (int)$this->maxLogBytes,
            '- Max evidence lines: ' . (int)$this->maxMatches,
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Evidence lines: ' . count($this->matches),
            '- Scope: read-only BaoTa/Nginx/WAF configuration and recent log evidence for HTTP 444/backend validation blockers.',
            '- Safety: this command does not edit Nginx/WAF rules, disable security, create orders, submit payments, approve reviews/withdrawals, call providers, or switch production GO.',
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

        $lines[] = '';
        $lines[] = '## Evidence Lines';
        $lines[] = '';
        if (!$this->matches) {
            $lines[] = 'No evidence lines were collected from readable candidate files.';
        } else {
            $lines[] = '| Type | Source | Path | Redacted line |';
            $lines[] = '|---|---|---|---|';
            foreach ($this->matches as $match) {
                $lines[] = '| ' . $this->mdCell($match['type']) . ' | '
                    . $this->mdCell($match['label']) . ' | `'
                    . $this->mdCell($match['path']) . '` | '
                    . $this->mdCell($match['line']) . ' |';
            }
        }

        $lines = array_merge($lines, [
            '',
            '## BaoTa Verification Command',
            '',
            '```bash',
            'cd /www/wwwroot/demo2026.mongoyia.com',
            'git pull --ff-only',
            '/www/server/php/83/bin/php yii test-station-waf-diagnostics/run \\',
            '  --domain=demo2026.mongoyia.com \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --interactive=0',
            '```',
            '',
            'If this report finds HTTP 444 or WAF rule evidence, keep CSRF/login/permission protections enabled and add only a minimal validation whitelist for the test station, accepted validation IP, or specific read-only acceptance paths.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck('Source marker ' . $path, 'FAIL', $path, 'Required file is missing.');
            return;
        }
        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck('Source marker ' . $path, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }
        $this->addCheck('Source marker ' . $path, 'PASS', $path, 'Required WAF diagnostics markers are present.');
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
            . DIRECTORY_SEPARATOR . 'test-station-waf-diagnostics-' . date('Ymd-His') . '.md';
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
