<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaRiskRegisterController extends Controller
{
    public $reportPath = '';
    public $outputPath = '';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'reportPath',
            'outputPath',
        ]);
    }

    public function actionRun()
    {
        $reportPath = $this->reportPath !== ''
            ? $this->resolvePath($this->reportPath)
            : $this->latestFile('mongoyia-acceptance-*.md');
        if ($reportPath === '' || !is_file($reportPath)) {
            $this->stderr("Acceptance report not found. Pass --reportPath=runtime/acceptance/mongoyia-acceptance-*.md.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $content = file_get_contents($reportPath);
        $outputPath = $this->outputPath !== ''
            ? $this->resolvePath($this->outputPath)
            : $this->defaultOutputPath($reportPath);
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $risks = $this->riskRows($content);
        $lines = [
            '# Mongoyia Risk Register',
            '',
            '| Item | Value |',
            '|---|---|',
            '| Source report | ' . $this->relativePath($reportPath) . ' |',
            '| Generated at | ' . date('Y-m-d H:i:s') . ' |',
            '| Total extracted warning/failure rows | ' . count($risks) . ' |',
            '',
            '## Acceptance Risks',
            '',
            '| Area | Severity | Status | Source | Next action |',
            '|---|---|---|---|---|',
        ];

        if ($risks) {
            foreach ($risks as $risk) {
                $lines[] = '| ' . $risk['area'] . ' | ' . $risk['severity'] . ' | ' . $risk['status'] . ' | `' . $this->escapeCell($risk['source']) . '` | ' . $risk['action'] . ' |';
            }
        } else {
            $lines[] = '| Acceptance | Info | Clear | No warnings or failures extracted. | None. |';
        }

        $lines = array_merge($lines, [
            '',
            '## Production Scope Risks',
            '',
            '| Area | Severity | Status | Next action |',
            '|---|---|---|---|',
            '| Payment providers | High | Pending production confirmation | Confirm production QPay/LianLian credentials, callback signatures, callback IP ranges, and settlement rules. |',
            '| Security/operations | High | Pending production hardening | Configure TLS/WSS, WAF/CDN policy, monitoring, alerting, log rotation, queue/process supervision, and backup restore drills. |',
            '| Finance/reconciliation | High | Pending production process | Implement or verify platform/seller settlement, payment reconciliation, refund reconciliation, and accounting exports. |',
            '| Translation/content | Medium | Pending manual QA | Run full Mongolian/English language review and replace images that contain embedded Chinese text. |',
            '| IM/load | Medium | Pending load validation | Run long-duration IM concurrency and reconnect testing beyond the lightweight acceptance regression. |',
            '| Business signoff | Medium | Pending owner approval | Confirm zero-price products, seller operations, customer-service identity policy, refund/after-sales process, and production launch checklist. |',
            '',
        ]);

        file_put_contents($outputPath, implode("\n", $lines));
        $this->stdout("Risk register written to {$outputPath}\n");
        return ExitCode::OK;
    }

    private function riskRows(string $content)
    {
        $rows = [];
        foreach ($this->extractWarnFailLines($content) as $line) {
            $rows[] = [
                'area' => $this->riskArea($line),
                'severity' => str_starts_with($line, 'FAIL') ? 'High' : $this->riskSeverity($line),
                'status' => $this->riskStatus($line),
                'source' => $line,
                'action' => $this->riskAction($line),
            ];
        }

        return $rows;
    }

    private function extractWarnFailLines(string $content)
    {
        $lines = [];
        if (preg_match('/^### Warning \/ Failure Extract\R(?P<body>.*?)(?=^## |\z)/ms', $content, $matches)) {
            foreach (preg_split('/\R/', $matches['body']) as $line) {
                $line = trim($line);
                if (preg_match('/^- `(?P<risk>(WARN|FAIL)\b.*?)`$/', $line, $riskMatch)) {
                    $lines[] = $riskMatch['risk'];
                }
            }
        }

        if (!$lines) {
            foreach (preg_split('/\R/', $content) as $line) {
                $line = trim($line);
                if (preg_match('/^(WARN|FAIL)\b/', $line)) {
                    $lines[] = $line;
                }
            }
        }

        return array_values(array_unique($lines));
    }

    private function riskArea(string $line)
    {
        if (preg_match('/IM_|WebSocket|IM /i', $line)) {
            return 'IM/config';
        }
        if (preg_match('/QPAY|LianLian|LIANLIAN|HMAC|payment|Paid parent/i', $line)) {
            return 'Payment';
        }
        if (preg_match('/Product .*non-positive price|zero/i', $line)) {
            return 'Catalog';
        }
        if (preg_match('/Legacy parent orders|order product/i', $line)) {
            return 'Order legacy data';
        }
        if (preg_match('/upload_max_filesize|WEB_BASE_URL|localhost|STORE_PLATFORM_DOMAIN/i', $line)) {
            return 'Environment';
        }

        return 'Acceptance';
    }

    private function riskSeverity(string $line)
    {
        if (preg_match('/HMAC|Paid parent|Legacy parent|non-positive price|upload_max_filesize/i', $line)) {
            return 'Medium';
        }

        return 'Low';
    }

    private function riskStatus(string $line)
    {
        if (preg_match('/local|localhost|placeholder|empty|skipped|IM_AUTH_SECRET|WEB_BASE_URL/i', $line)) {
            return 'Expected locally, must fix for test/prod';
        }
        if (preg_match('/non-positive price|Legacy parent|Paid parent/i', $line)) {
            return 'Known data/business risk';
        }

        return 'Review required';
    }

    private function riskAction(string $line)
    {
        if (preg_match('/IM_AUTH_SECRET/i', $line)) {
            return 'Set a long shared PHP/Python IM secret in test/prod `.env`.';
        }
        if (preg_match('/IM_WEBSOCKET_URL|localhost/i', $line)) {
            return 'Use real HTTPS/WSS test/prod domains and reverse proxy paths.';
        }
        if (preg_match('/upload_max_filesize/i', $line)) {
            return 'Set PHP upload and post limits to at least `6M` on test/prod.';
        }
        if (preg_match('/QPAY|LIANLIAN|LianLian|HMAC|timestamp/i', $line)) {
            return 'Configure sandbox/provider credentials, callback HMAC secrets, and callback max-age.';
        }
        if (preg_match('/non-positive price/i', $line)) {
            return 'Confirm product pricing or deactivate products before business signoff.';
        }
        if (preg_match('/Legacy parent orders/i', $line)) {
            return 'Plan historical order migration, archival, or documented exception.';
        }
        if (preg_match('/Paid parent orders/i', $line)) {
            return 'Backfill or document historical payment audit coverage.';
        }

        return 'Review the source warning and assign an owner.';
    }

    private function defaultOutputPath(string $reportPath)
    {
        $name = basename($reportPath);
        $name = preg_replace('/^mongoyia-acceptance-/', 'mongoyia-risk-register-', $name);
        return dirname($reportPath) . DIRECTORY_SEPARATOR . $name;
    }

    private function latestFile(string $pattern)
    {
        $files = glob($this->projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'acceptance' . DIRECTORY_SEPARATOR . $pattern);
        if (!$files) {
            return '';
        }
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }

    private function escapeCell(string $value)
    {
        return str_replace('|', '\\|', $value);
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
