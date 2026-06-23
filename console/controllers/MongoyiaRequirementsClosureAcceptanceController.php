<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaRequirementsClosureAcceptanceController extends Controller
{
    public const VERSION = 'MONGOYIA_REQUIREMENTS_PHASE10_15_ACCEPTANCE_V1';

    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $runChildChecks = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
            'runChildChecks',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia Phase 10-15 requirements closure acceptance\n");

        $this->checkSourceCoverage();
        $this->runPhaseAcceptanceCommands();

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0))) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Aggregate source coverage');
        $this->requireFileContains('Phase 10-15 backlog registration', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'Phase 10-15 Remaining Requirements Closure',
            'mongoyia-requirements-closure-acceptance/run',
            'Production launch remains `NO-GO`',
        ]);

        foreach ($this->phaseCommands() as $phase => $config) {
            $this->requireFileContains($phase . ' acceptance command', $config['file'], [
                $config['version'],
                $config['route'],
                'Pending',
            ]);
        }
    }

    private function runPhaseAcceptanceCommands(): void
    {
        $this->section('Phase acceptance commands');
        foreach ($this->phaseCommands() as $phase => $config) {
            $route = $config['route'];
            $reportPath = $this->childReportPath($config['slug']);
            $params = [
                'interactive' => 0,
                'outputPath' => $reportPath,
            ];

            if (!empty($config['baseUrl'])) {
                $params['baseUrl'] = $this->baseUrl;
            }
            if (!empty($config['fixture']) && $this->fixture) {
                $params['fixture'] = 1;
            }
            if (!empty($config['runChildChecks']) && $this->runChildChecks) {
                $params['runChildChecks'] = 1;
            }

            try {
                $exitCode = Yii::$app->runAction($route, $params);
            } catch (\Throwable $e) {
                $this->addCheck($phase, 'FAIL', $route, 'Acceptance command failed before report generation: ' . $e->getMessage());
                continue;
            }

            $summary = $this->parseChildReport($reportPath);
            if ($summary === null) {
                $status = ((int)$exitCode === ExitCode::OK) ? 'WARN' : 'FAIL';
                $this->addCheck($phase, $status, $reportPath, 'Child report could not be parsed; inspect the generated command output.');
                continue;
            }

            $status = $summary['failures'] > 0 ? 'FAIL' : (($summary['warnings'] > 0 || $summary['pending'] > 0) ? 'PENDING' : 'PASS');
            $notes = 'Child result=' . $summary['result']
                . ', failures=' . $summary['failures']
                . ', warnings=' . $summary['warnings']
                . ', pending=' . $summary['pending']
                . '.';
            if ((int)$exitCode !== ExitCode::OK && $summary['failures'] === 0) {
                $notes .= ' Command exit code was ' . (int)$exitCode . '; inspect child report for details.';
            }
            $this->addCheck($phase, $status, $reportPath, $notes);
        }
    }

    private function phaseCommands(): array
    {
        return [
            'Phase 10 operational readiness' => [
                'slug' => 'phase10-operational',
                'route' => 'operational-config-phase10-acceptance/run',
                'file' => 'console/controllers/OperationalConfigPhase10AcceptanceController.php',
                'version' => 'OperationalConfigPhase10AcceptanceController',
                'baseUrl' => true,
                'fixture' => true,
                'runChildChecks' => true,
            ],
            'Phase 11 payment and merchant payment' => [
                'slug' => 'phase11-payment',
                'route' => 'payment-phase11-acceptance/run',
                'file' => 'console/controllers/PaymentPhase11AcceptanceController.php',
                'version' => 'MONGOYIA_PAYMENT_PHASE11_ACCEPTANCE_V1',
                'baseUrl' => true,
                'fixture' => true,
                'runChildChecks' => true,
            ],
            'Phase 12 account notification language' => [
                'slug' => 'phase12-account-notification',
                'route' => 'account-notification-phase12-acceptance/run',
                'file' => 'console/controllers/AccountNotificationPhase12AcceptanceController.php',
                'version' => 'AccountNotificationPhase12AcceptanceController',
                'baseUrl' => false,
                'fixture' => true,
                'runChildChecks' => true,
            ],
            'Phase 13 buyer seller APP' => [
                'slug' => 'phase13-app',
                'route' => 'app-phase13-acceptance/run',
                'file' => 'console/controllers/AppPhase13AcceptanceController.php',
                'version' => 'MONGOYIA_APP_PHASE13_ACCEPTANCE_V1',
                'baseUrl' => true,
                'fixture' => true,
                'runChildChecks' => false,
            ],
            'Phase 14 logistics product favorite review' => [
                'slug' => 'phase14-logistics-product',
                'route' => 'logistics-product-phase14-acceptance/run',
                'file' => 'console/controllers/LogisticsProductPhase14AcceptanceController.php',
                'version' => 'MONGOYIA_LOGISTICS_PRODUCT_PHASE14_ACCEPTANCE_V1',
                'baseUrl' => false,
                'fixture' => true,
                'runChildChecks' => false,
            ],
            'Phase 15 distributor support' => [
                'slug' => 'phase15-distributor-support',
                'route' => 'distribution-support-phase15-acceptance/run',
                'file' => 'console/controllers/DistributionSupportPhase15AcceptanceController.php',
                'version' => 'MONGOYIA_DISTRIBUTION_SUPPORT_PHASE15_ACCEPTANCE_V1',
                'baseUrl' => false,
                'fixture' => true,
                'runChildChecks' => false,
            ],
        ];
    }

    private function parseChildReport(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = (string)file_get_contents($path);
        $result = $this->matchReportValue($content, 'Result');
        $failures = $this->matchReportValue($content, 'Failures');
        $warnings = $this->matchReportValue($content, 'Warnings');
        $pending = $this->matchReportValue($content, 'Pending');

        if ($result === null || $failures === null || $warnings === null || $pending === null) {
            return null;
        }

        return [
            'result' => $result,
            'failures' => (int)$failures,
            'warnings' => (int)$warnings,
            'pending' => (int)$pending,
        ];
    }

    private function matchReportValue(string $content, string $name): ?string
    {
        if (preg_match('/^- ' . preg_quote($name, '/') . ':\s*(.+)$/mi', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Phase 10-15 Requirements Closure Acceptance',
            '',
            '- Generated at: ' . date('c'),
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Fixture mode: ' . ($this->fixture ? 'yes' : 'no'),
            '- Child readiness checks: ' . ($this->runChildChecks ? 'yes' : 'no'),
            '- Strict mode: ' . ($this->strict ? 'yes' : 'no'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '',
            '## Results',
            '',
            '| Status | Area | Evidence | Notes |',
            '| --- | --- | --- | --- |',
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
            'git pull',
            '/www/server/php/83/bin/php yii migrate/up --interactive=0',
            '/www/server/php/83/bin/php yii mongoyia-requirements-closure-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --fixture=1 \\',
            '  --runChildChecks=1 \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
            'This aggregate gate is read-only. It does not store provider secrets, call real payment/logistics/social providers by itself, change payment state, create withdrawals, approve reviews, or switch production traffic.',
            '',
            '## Acceptance Boundary',
            '',
            '- PASS means Phase 10-15 source coverage and accepted evidence gates are complete.',
            '- PENDING means code coverage exists but real browser/provider/production evidence still needs to be collected and accepted.',
            '- FAIL means a source marker, child command, or generated child report is missing or broken.',
            '- Production remains NO-GO until Phase 10 provider, operations, redacted export, browser, and owner signoff evidence are accepted.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function requireFileContains(string $label, string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck($label, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Required aggregate marker is present.');
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
        } elseif ($status === 'PENDING') {
            $this->pending++;
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
        if ($this->warnings > 0 || $this->pending > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function childReportPath(string $slug): string
    {
        $name = 'mongoyia-requirements-closure-' . $slug . '-' . date('Ymd-His') . '.md';
        return $this->resolvePath($this->handoverDir) . DIRECTORY_SEPARATOR . $name;
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-requirements-closure-acceptance-' . date('Ymd-His') . '.md';
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
