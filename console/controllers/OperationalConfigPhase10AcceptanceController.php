<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class OperationalConfigPhase10AcceptanceController extends Controller
{
    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $runChildChecks = false;
    public $browserAccepted = false;
    public $providerEvidenceAccepted = false;
    public $productionEvidenceAccepted = false;
    public $redactedExportAccepted = false;
    public $browserEvidencePath = '';
    public $providerEvidencePath = '';
    public $productionEvidencePath = '';
    public $redactedExportPath = '';

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
            'browserAccepted',
            'providerEvidenceAccepted',
            'productionEvidenceAccepted',
            'redactedExportAccepted',
            'browserEvidencePath',
            'providerEvidencePath',
            'productionEvidencePath',
            'redactedExportPath',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia operational config Phase 10 acceptance\n");

        $this->checkSourceCoverage();
        $this->checkManualEvidenceInputs();
        if ($this->runChildChecks) {
            $this->runChildChecks();
        }

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
        $this->section('Phase 10 source coverage');

        $this->requireFileContains('Phase 10 backlog registration', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'Phase 10-15 Remaining Requirements Closure',
            'operational-config-phase10-acceptance/run',
            'Production launch remains `NO-GO`',
        ]);
        $this->requireFileContains('Operational config backend controller', 'backend/modules/mall/controllers/OperationalConfigController.php', [
            'MONGOYIA_OPERATIONAL_CONFIG_BACKEND_POST_VERB_GUARD_V1',
            'public function behaviors()',
            "'save-payment'",
            "'check-payment'",
            'actionSavePayment',
            'actionCheckPayment',
            'actionSaveMail',
            'actionTestMail',
            'actionSaveAlert',
            'actionTestAlert',
            'actionSaveLaunch',
            'actionSaveTranslation',
            'actionCheckTranslation',
            "'save-provider-evidence'",
            "'check-provider-evidence'",
            "['post']",
        ]);
        $this->requireFileContains('Operational config backend page', 'backend/modules/mall/views/operational-config/index.php', [
            'data-mongoyia-operational-phase10-readiness',
            'data-mongoyia-operational-payment-config',
            'data-mongoyia-operational-mail-config',
            'data-mongoyia-operational-ops-alert',
            'data-mongoyia-operational-launch-signoff',
            'OP_CONFIG_MASTER_KEY',
        ]);
        $this->requireFileContains('Encrypted operational config foundation', 'common/services/mall/OperationalConfigService.php', [
            'MONGOYIA_OPERATIONAL_CONFIG_FOUNDATION_V1',
            'OP_CONFIG_MASTER_KEY',
            'openssl_encrypt',
            'redactValue',
        ]);
        $this->requireFileContains('Payment operational config service', 'common/services/mall/OperationalPaymentConfigService.php', [
            'MONGOYIA_OPERATIONAL_PAYMENT_CONFIG_CENTER_V1',
            'qpay',
            'lianlian',
            'paypal',
            'runtimeConfig',
        ]);
        $this->requireFileContains('Mail operational config service', 'common/services/mall/OperationalMailConfigService.php', [
            'MONGOYIA_OPERATIONAL_MAIL_CONFIG_CENTER_V1',
            'sendTest',
            'runtimeConfig',
            'smtp_readiness',
        ]);
        $this->requireFileContains('Ops alert service', 'common/services/mall/OperationalOpsAlertService.php', [
            'MONGOYIA_OPERATIONAL_OPS_ALERT_CENTER_V1',
            'taskDefinitions',
            'sendTestAlert',
            'alert_readiness',
        ]);
        $this->requireFileContains('Provider evidence service', 'common/services/mall/OperationalProviderEvidenceService.php', [
            'MONGOYIA_OPERATIONAL_PROVIDER_EVIDENCE_V1',
            'provider_evidence',
            'redaction_confirmed',
            'looksSensitive',
        ]);
        $this->requireFileContains('Phase 10 readiness service', 'common/services/mall/OperationalPhase10ReadinessService.php', [
            'MONGOYIA_OPERATIONAL_PHASE10_READINESS_V1',
            'GO-READY',
            'mongoyia-operational-config-redacted-export-*.md',
            'mongoyia-production-go-live-gate-*.md',
        ]);
        $this->requireFileContains('Provider evidence backend page', 'backend/modules/mall/views/operational-config/index.php', [
            'data-mongoyia-operational-provider-evidence',
            '服务商证据验收',
            '保存证据并检测',
        ]);
        $this->requireFileContains('Launch signoff service', 'common/services/mall/OperationalLaunchSignoffService.php', [
            'MONGOYIA_OPERATIONAL_LAUNCH_SIGNOFF_CENTER_V1',
            '压测报告引用',
            '备份恢复确认',
            'GO，签核和证据引用已齐全',
        ]);
        $this->requireFileContains('Redacted export command', 'console/controllers/OperationalConfigExportController.php', [
            'Mongoyia Operational Config Redacted Export',
            'redacted only',
            'Secret policy',
        ]);
        $this->requireFileContains('Production go-live gate', 'common/services/mall/MongoyiaProductionGoLiveGateService.php', [
            'MONGOYIA_PRODUCTION_GO_LIVE_GATE_V1',
            'production_go_live_read_only_no_traffic_switch',
            'This gate is read-only',
        ]);
        $this->requireFileContains('Phase 11 PayPal runtime supersedes Phase 6 no-go child gates', 'console/controllers/PaymentPhase11AcceptanceController.php', [
            'MONGOYIA_PAYPAL_PHASE11_RUNTIME_SUPERSEDES_PHASE6_NOGO_V1',
            'PayPal final read-only go/no-go gate',
        ]);
    }

    private function checkManualEvidenceInputs(): void
    {
        $this->section('Manual provider/browser/production evidence');
        $this->manualFlag(
            'Backend operations browser acceptance',
            $this->browserAccepted,
            $this->browserEvidencePath !== '' ? $this->browserEvidencePath : $this->baseUrl . '/backend/mall/operational-config/index',
            'Platform admin saved and checked payment, SMTP, alert, launch signoff, translation, and redacted export flows in the browser.',
            'Open backend operations config center, save/check every card, export a redacted report, and rerun with --browserAccepted=1.'
        );
        $this->manualFlag(
            'Provider evidence acceptance',
            $this->providerEvidenceAccepted,
            $this->providerEvidencePath,
            'QPay, LianLian, PayPal, SMTP, translation provider, and alert-recipient evidence is recorded without exposing secrets.',
            'Record real or sandbox provider evidence references, then rerun with --providerEvidenceAccepted=1 and --providerEvidencePath=<path-or-ticket>.'
        );
        $this->manualFlag(
            'Production operations evidence acceptance',
            $this->productionEvidenceAccepted,
            $this->productionEvidencePath,
            'Scheduler, backup restore, load, security, business, rollback, and launch-window signoffs are recorded.',
            'Record production-style scheduler/backup/load/security/business evidence, then rerun with --productionEvidenceAccepted=1.'
        );
        $this->manualFlag(
            'Redacted export review acceptance',
            $this->redactedExportAccepted,
            $this->redactedExportPath,
            'A redacted operational configuration handover export was generated and reviewed with no secrets exposed.',
            'Run operational-config-export/run, review the Markdown export, then rerun with --redactedExportAccepted=1.'
        );
    }

    private function runChildChecks(): void
    {
        $this->section('Phase 10 child readiness commands');
        foreach ($this->childCommands() as $label => $config) {
            $route = $config['route'];
            $params = ['interactive' => 0];
            if ($this->fixture && !empty($config['fixture'])) {
                $params['fixture'] = 1;
            }

            try {
                $exitCode = Yii::$app->runAction($route, $params);
                if ((int)$exitCode === ExitCode::OK) {
                    $this->addCheck($label, 'PASS', $route, 'Child readiness command passed.');
                } else {
                    $this->addCheck($label, 'FAIL', $route, 'Child readiness command returned exit code ' . (int)$exitCode . '.');
                }
            } catch (\Throwable $e) {
                $this->addCheck($label, 'FAIL', $route, 'Child readiness command failed: ' . $e->getMessage());
            }
        }
    }

    private function childCommands(): array
    {
        return [
            'Phase 7 encrypted config foundation' => ['route' => 'operational-config-check/run', 'fixture' => true],
            'Phase 7 payment config center' => ['route' => 'operational-config-payment-test/run', 'fixture' => true],
            'Phase 7 PayPal runtime paths' => ['route' => 'operational-config-paypal-test/run', 'fixture' => true],
            'Phase 7 SMTP mail config center' => ['route' => 'operational-config-mail-test/run', 'fixture' => true],
            'Phase 7 operations alert center' => ['route' => 'operational-config-ops-alert-test/run', 'fixture' => true],
            'Phase 7 launch signoff center' => ['route' => 'operational-config-launch-test/run', 'fixture' => true],
            'Phase 10 provider evidence records' => ['route' => 'operational-config-provider-evidence-test/run', 'fixture' => true],
            'Phase 7 redacted export' => ['route' => 'operational-config-export/run', 'fixture' => false],
            'Production health evidence' => ['route' => 'mongoyia-production-health/run', 'fixture' => true],
            'Production monitor evidence' => ['route' => 'mongoyia-production-monitor/run', 'fixture' => true],
            'Production backup evidence' => ['route' => 'mongoyia-production-backup-verify-evidence/run', 'fixture' => true],
            'Production scheduled-check evidence' => ['route' => 'mongoyia-production-scheduled-check-evidence/run', 'fixture' => true],
            'Production load-test evidence' => ['route' => 'mongoyia-production-load-test-evidence/run', 'fixture' => true],
            'Production external evidence import dry-run' => ['route' => 'mongoyia-production-external-evidence-import-dry-run/run', 'fixture' => true],
            'Production external evidence review readiness' => ['route' => 'mongoyia-production-external-evidence-review-readiness/run', 'fixture' => true],
            'Production external evidence review-result apply gate' => ['route' => 'mongoyia-production-external-evidence-review-result-apply-gate/run', 'fixture' => true],
            'Production external evidence final acceptance gate' => ['route' => 'mongoyia-production-external-evidence-final-acceptance-gate/run', 'fixture' => true],
            'Production launch signoff readiness gate' => ['route' => 'mongoyia-production-launch-signoff-readiness-gate/run', 'fixture' => true],
            'Production evidence summary' => ['route' => 'mongoyia-production-evidence-summary/run', 'fixture' => true],
            'Production go-live gate' => ['route' => 'mongoyia-production-go-live-gate/run', 'fixture' => true],
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
            '# Mongoyia Operational Config Phase 10 Acceptance',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Scope: Phase 7.6 backend operations config acceptance plus production readiness evidence gates.',
            '- Secret policy: no API keys, Basic Auth, private keys, SMTP passwords, HMAC secrets, callback payloads, or alert tokens may appear in this report.',
            '- Launch policy: production remains NO-GO until every manual evidence flag is accepted and downstream production go-live gate is PASS.',
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

        $lines = array_merge($lines, [
            '',
            '## BaoTa Verification Command',
            '',
            '```bash',
            'cd /www/wwwroot/demo2026.mongoyia.com',
            'git pull --ff-only',
            'git rev-parse --short HEAD',
            '/www/server/php/83/bin/php yii migrate/up --interactive=0',
            '/www/server/php/83/bin/php yii cache/flush-all --interactive=0',
            '/etc/init.d/php-fpm-83 restart',
            '/www/server/php/83/bin/php yii operational-config-phase10-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --runChildChecks=1 \\',
            '  --fixture=1 \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
            'MONGOYIA_PHASE10_15_CHILD_DEPLOY_CACHE_REFRESH_V1: pull fast-forward changes, print the deployed commit, flush Yii cache, and restart PHP-FPM before collecting Phase 10 browser/provider evidence.',
            '',
            '## Browser Role-Flow Checklist',
            '',
            'Record screenshots, provider-side configuration screenshots, redacted export reports, scheduler/backup/load/security signoff references, or reviewer notes in non-secret evidence files, then pass those paths through the accepted evidence options after review.',
            '',
            '1. Platform admin opens `/backend/mall/operational-config/index` and confirms the Phase 10 NO-GO/GO readiness summary is visible.',
            '2. Payment cards show QPay, LianLian, and PayPal callback/return/cancel URL helpers and masked sensitive-field status.',
            '3. Save/check payment test-mode configs without exposing API keys, Basic Auth, private keys, HMAC secrets, or callback payloads in page text or export.',
            '4. Save SMTP config, run test-send, and confirm success/failure is recorded as a check row without exposing the SMTP password.',
            '5. Save translation-provider and alert recipients/triggers, run check/test actions where configured, and record unavailable-provider failures clearly.',
            '6. Save scheduled task evidence, backup evidence, load/security/business signoff references, launch window, rollback owner, and rollback plan.',
            '7. Run redacted export and confirm it contains only configured/unconfigured/masked values plus latest check results.',
            '8. Refresh the page and confirm saved non-secret state and latest check statuses remain visible.',
            '9. Safety check: confirm Phase 10 evidence did not store real secrets in screenshots/Markdown, switch production traffic, edit crontab/systemd, enable live payment, or mark production GO without all downstream gates.',
            '',
            '## Accepted Evidence Command',
            '',
            'After browser/provider/production evidence is reviewed, rerun with accepted evidence paths. Example:',
            '',
            '```bash',
            '/www/server/php/83/bin/php yii operational-config-phase10-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --runChildChecks=1 \\',
            '  --fixture=1 \\',
            '  --browserAccepted=1 --browserEvidencePath=runtime/handover/phase10-browser-evidence.md \\',
            '  --providerEvidenceAccepted=1 --providerEvidencePath=runtime/handover/phase10-provider-evidence.md \\',
            '  --productionEvidenceAccepted=1 --productionEvidencePath=runtime/handover/phase10-production-ops-evidence.md \\',
            '  --redactedExportAccepted=1 --redactedExportPath=runtime/handover/phase10-redacted-export-review.md \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function manualFlag(string $area, bool $accepted, string $evidence, string $passNotes, string $pendingNotes): void
    {
        if ($accepted) {
            $this->addCheck($area, 'PASS', $evidence !== '' ? $evidence : 'external evidence recorded', $passNotes);
            return;
        }

        $this->addCheck($area, 'PENDING', $evidence !== '' ? $evidence : 'pending external evidence', $pendingNotes);
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

        $this->addCheck($label, 'PASS', $path, 'Required Phase 10 markers are present.');
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

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-operational-config-phase10-acceptance-' . date('Ymd-His') . '.md';
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
