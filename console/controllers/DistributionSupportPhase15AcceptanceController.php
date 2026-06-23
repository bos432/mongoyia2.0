<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class DistributionSupportPhase15AcceptanceController extends Controller
{
    public const VERSION = 'MONGOYIA_DISTRIBUTION_SUPPORT_PHASE15_ACCEPTANCE_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $trainingAccepted = false;
    public $promotionAccepted = false;
    public $downloadTrackingAccepted = false;
    public $payoutSignoffAccepted = false;
    public $browserAccepted = false;
    public $trainingEvidencePath = '';
    public $promotionEvidencePath = '';
    public $downloadTrackingEvidencePath = '';
    public $payoutSignoffEvidencePath = '';
    public $browserEvidencePath = '';

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
            'trainingAccepted',
            'promotionAccepted',
            'downloadTrackingAccepted',
            'payoutSignoffAccepted',
            'browserAccepted',
            'trainingEvidencePath',
            'promotionEvidencePath',
            'downloadTrackingEvidencePath',
            'payoutSignoffEvidencePath',
            'browserEvidencePath',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia Phase 15 distributor support acceptance\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkPlannedScopeMatrix();
        }
        $this->checkManualAcceptanceInputs();

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
        $this->section('Phase 15 source coverage');
        $this->requireFileContains('Phase 15 backlog registration', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'Distributor training and operations support center',
            'distribution-support-phase15-acceptance/run',
        ]);
        $this->requireFileContains('Existing distributor frontend center', 'web/resources/mall/default/views/user/distribution.php', [
            'Distribution Center',
            'Promotion Link',
            'Promotion Materials',
            'Invite Rewards',
            'Withdrawal Request',
        ]);
        $this->requireFileContains('Existing distributor backend operations page', 'backend/modules/mall/views/distribution-distributor/index.php', [
            '分销员运营',
            '推广素材',
            '风险记录',
            '邀请奖励',
        ]);
        $this->requireFileContains('Existing distributor profile/material/risk service', 'common/services/mall/DistributionProfileService.php', [
            'class DistributionProfileService',
            'MATERIAL_STATUS_ACTIVE',
            'materials',
            'risks',
        ]);
        $this->requireFileContains('Existing distributor analytics export evidence', 'console/controllers/MongoyiaDistributionAnalyticsExportController.php', [
            'Mongoyia distribution analytics export',
            'Signoff Checklist',
            'Real payout allowed by this report',
        ]);
        $this->requireFileContains('Existing distributor invite reward workflow', 'common/services/mall/DistributionInviteRewardWorkflowService.php', [
            'class DistributionInviteRewardWorkflowService',
            'ACTION_APPROVE',
            'ACTION_REJECT',
        ]);
        $this->requireFileContains('Existing withdrawal workflow', 'common/services/mall/DistributionWithdrawService.php', [
            'class DistributionWithdrawService',
            'WITHDRAW_STATUS_PENDING',
            'requestWithdraw',
        ]);
        $this->requireFileContains('Distributor support content service', 'common/services/mall/DistributionSupportContentService.php', [
            'MONGOYIA_DISTRIBUTION_SUPPORT_CONTENT_PHASE15_V1',
            'visibleForDistributor',
            'saveContent',
            'disableContent',
        ]);
        $this->requireFileContains('Distributor support content readiness', 'console/controllers/DistributionSupportContentPhase15ReadinessController.php', [
            'MONGOYIA_DISTRIBUTION_SUPPORT_CONTENT_PHASE15_READINESS_V1',
            'DistributionSupportContentService',
            'Distributor support content fixture',
        ]);
        $this->requireFileContains('Distributor support content migration', 'console/migrations/m260623_200000_mongoyia_distribution_support_content.php', [
            'mall_distribution_support_content',
            'content_type',
            'language',
            'support_url',
        ]);
        $this->requireFileContains('Distributor-facing support content UI', 'web/resources/mall/default/views/user/distribution.php', [
            'data-mongoyia-phase15-distributor-training',
            'Training & FAQ',
        ]);
        $this->requireFileContains('Backend support content UI', 'backend/modules/mall/views/distribution-distributor/index.php', [
            'data-mongoyia-phase15-support-content',
            '分销培训/FAQ/规则',
        ]);
        $this->requireFileContains('Distributor material phase 15 service', 'common/services/mall/DistributionMaterialPhase15Service.php', [
            'MONGOYIA_DISTRIBUTION_MATERIAL_PHASE15_V1',
            'MONGOYIA_DISTRIBUTION_MATERIAL_SAFE_URL_V1',
            'visibleMaterials',
            'saveMaterial',
            'cleanUrl',
            'recordAction',
        ]);
        $this->requireFileContains('Distributor material phase 15 readiness', 'console/controllers/DistributionMaterialPhase15ReadinessController.php', [
            'MONGOYIA_DISTRIBUTION_MATERIAL_PHASE15_READINESS_V1',
            'Distributor material fixture',
            'download_count',
        ]);
        $this->requireFileContains('Distributor material phase 15 migration', 'console/migrations/m260623_210000_mongoyia_distribution_material_phase15.php', [
            'mall_distribution_material_download_log',
            'asset_url',
            'qr_code_url',
            'copy_count',
        ]);
        $this->requireFileContains('Distributor-facing material tracking UI', 'web/resources/mall/default/views/user/distribution.php', [
            'data-mongoyia-phase15-promotion-materials',
            'distribution-material-track',
            'Download',
        ]);
        $this->requireFileContains('Backend material management UI', 'backend/modules/mall/views/distribution-distributor/index.php', [
            'data-mongoyia-phase15-material-management',
            'material-save',
            'material-disable',
        ]);
        $this->requireFileContains('Distributor signoff phase 15 service', 'common/services/mall/DistributionSignoffPhase15Service.php', [
            'MONGOYIA_DISTRIBUTION_SIGNOFF_PHASE15_V1',
            'saveEvidence',
            'reviewEvidence',
            'evidenceRows',
        ]);
        $this->requireFileContains('Distributor signoff phase 15 readiness', 'console/controllers/DistributionSignoffPhase15ReadinessController.php', [
            'MONGOYIA_DISTRIBUTION_SIGNOFF_PHASE15_READINESS_V1',
            'Distributor signoff fixture',
            'reviewEvidence',
        ]);
        $this->requireFileContains('Distributor signoff phase 15 migration', 'console/migrations/m260623_220000_mongoyia_distribution_signoff_evidence.php', [
            'mall_distribution_signoff_evidence',
            'evidence_type',
            'signoff_status',
        ]);
        $this->requireFileContains('Backend signoff evidence UI', 'backend/modules/mall/views/distribution-distributor/index.php', [
            'data-mongoyia-phase15-signoff-evidence',
            'signoff-evidence-save',
            'signoff-evidence-review',
        ]);
    }

    private function checkPlannedScopeMatrix(): void
    {
        $this->section('Phase 15 planned scope matrix');
        foreach ([
            'Training and FAQ content' => 'Multilingual distributor training, FAQ, platform rules, and customer-service entrance.',
            'Promotion material enhancement' => 'Multilingual material records, QR/link support, material status, and distributor-visible material catalog.',
            'Download tracking' => 'Material download/copy tracking with distributor, material, language, channel, and timestamp evidence.',
            'Payout and reward signoff evidence' => 'Offline withdrawal, commission rule, invite reward, and payout evidence signoff without direct fund mutation.',
            'Browser role-flow acceptance' => 'Distributor views tutorials, gets materials, generates link, checks performance, submits withdrawal, and platform reviews.',
        ] as $area => $notes) {
            $this->addCheck($area, 'PASS', 'planned Phase 15 scope', $notes);
        }
    }

    private function checkManualAcceptanceInputs(): void
    {
        $this->section('Phase 15 implementation and evidence gates');
        $this->manualFlag(
            'Training and FAQ acceptance',
            $this->trainingAccepted,
            $this->trainingEvidencePath,
            'Training/FAQ/support content was accepted.',
            'Implement multilingual distributor training, FAQ, platform rules, and customer-service entry.'
        );
        $this->manualFlag(
            'Promotion material acceptance',
            $this->promotionAccepted,
            $this->promotionEvidencePath,
            'Multilingual promotion materials were accepted.',
            'Implement multilingual materials, QR/link fields, material status, and distributor-facing display.'
        );
        $this->manualFlag(
            'Download tracking acceptance',
            $this->downloadTrackingAccepted,
            $this->downloadTrackingEvidencePath,
            'Download/copy tracking was accepted.',
            'Implement material download/copy tracking and read-only operations evidence.'
        );
        $this->manualFlag(
            'Payout and reward signoff acceptance',
            $this->payoutSignoffAccepted,
            $this->payoutSignoffEvidencePath,
            'Payout and invite reward signoff evidence was accepted.',
            'Implement commission rule, withdrawal, offline payout, and invite reward signoff evidence without direct real payout.'
        );
        $this->manualFlag(
            'Phase 15 browser role-flow acceptance',
            $this->browserAccepted,
            $this->browserEvidencePath,
            'Browser role-flow evidence was accepted.',
            'Validate distributor tutorial, FAQ, material acquisition, promotion link, performance view, withdrawal submission, and platform review in browser.'
        );
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Phase 15 Distributor Support Acceptance',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Scope: distributor training, FAQ/support content, multilingual promotion materials, material download tracking, and payout/invite reward signoff evidence.',
            '- Safety: this command is an evidence gate and does not approve commissions, create withdrawals, write fund logs, change payment state, or trigger real payouts.',
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
            '/www/server/php/83/bin/php yii distribution-support-content-phase15-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii distribution-material-phase15-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii distribution-signoff-phase15-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii distribution-support-phase15-acceptance/run --fixture=1 --interactive=0',
            '```',
            '',
            'MONGOYIA_PHASE10_15_CHILD_DEPLOY_CACHE_REFRESH_V1: pull fast-forward changes, print the deployed commit, flush Yii cache, and restart PHP-FPM before collecting Phase 15 distributor browser evidence.',
            '',
            '## Browser Role-Flow Checklist',
            '',
            'Record screenshots, report paths, or reviewer notes in a non-secret evidence file, then pass that path through `--browserEvidencePath` and the matching accepted flags after review.',
            '',
            '1. Platform backend: open `/backend/mall/distribution-distributor/index` as a platform operator and verify the support content, promotion material, and signoff evidence panels are visible.',
            '2. Platform backend: create or update test-only multilingual training/FAQ/support content and a test-only promotion material, then confirm the rows remain visible after refresh.',
            '3. Distributor frontend: open `/mall/user/distribution` as a distributor and verify Training & FAQ, promotion materials, promotion link, invite rewards, performance summary, withdrawal request entry, and customer-service entry are visible.',
            '4. Distributor frontend: open or copy a test material link through `/mall/user/distribution-material-track`, then refresh the backend material panel and confirm download/copy counts or log evidence are updated.',
            '5. Platform backend: add test-only commission rule, offline payout, or invite reward signoff evidence; review it as approve/reject with a note, then confirm the status summary updates after refresh.',
            '6. Safety check: confirm no real payout, payment state, fund log, commission approval, order mutation, or withdrawal approval was executed during this Phase 15 browser evidence run.',
            '',
            '## Accepted Evidence Command',
            '',
            'After browser evidence and reviewer notes are accepted, rerun with the reviewed evidence paths. Example:',
            '',
            '```bash',
            '/www/server/php/83/bin/php yii distribution-support-phase15-acceptance/run \\',
            '  --fixture=1 \\',
            '  --trainingAccepted=1 --trainingEvidencePath=runtime/handover/phase15-training-evidence.md \\',
            '  --promotionAccepted=1 --promotionEvidencePath=runtime/handover/phase15-promotion-evidence.md \\',
            '  --downloadTrackingAccepted=1 --downloadTrackingEvidencePath=runtime/handover/phase15-download-evidence.md \\',
            '  --payoutSignoffAccepted=1 --payoutSignoffEvidencePath=runtime/handover/phase15-signoff-evidence.md \\',
            '  --browserAccepted=1 --browserEvidencePath=runtime/handover/phase15-browser-evidence.md \\',
            '  --strict=1 --interactive=0',
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

        $this->addCheck($area, 'PENDING', $evidence !== '' ? $evidence : 'pending implementation/evidence', $pendingNotes);
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

        $this->addCheck($label, 'PASS', $path, 'Required Phase 15 markers are present.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-distribution-support-phase15-acceptance-' . date('Ymd-His') . '.md';
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
