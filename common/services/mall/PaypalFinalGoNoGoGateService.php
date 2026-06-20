<?php

namespace common\services\mall;

class PaypalFinalGoNoGoGateService
{
    public const GATE_VERSION = 'MONGOYIA_PAYPAL_FINAL_GO_NO_GO_GATE_V1';

    private const MODE = 'paypal_final_go_no_go_read_only_no_enablement';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? rtrim($rootPath, DIRECTORY_SEPARATOR . '/\\') : dirname(__DIR__, 3);
    }

    public function run(): array
    {
        $liveGatePath = $this->latestHandoverFile('mongoyia-payment-provider-live-verification-enablement-gate-*.md');
        $liveGateResult = $this->readReportResult($liveGatePath);
        $liveGateReport = (new PaypalLiveVerificationEnablementGateService($this->rootPath))->run();
        $enablementAllowed = (bool)($liveGateReport['enablementAllowed'] ?? true);
        $pendingCount = (int)($liveGateReport['totals']['pending_precondition_count'] ?? -1);
        $runtimeEnabled = (bool)($liveGateReport['runtimeEnabled'] ?? true);
        $decision = $enablementAllowed ? 'GO' : 'NO-GO';

        $checks = [
            $this->check(
                'live_verification_enablement_gate_report',
                $liveGatePath !== '' && $liveGateResult === 'PASS' && empty($liveGateReport['issues']),
                'pass',
                'blocked',
                $liveGatePath !== '' ? $this->relativePath($liveGatePath) : 'Missing latest live verification enablement gate report.'
            ),
            $this->check(
                'enablement_allowed_state',
                !$enablementAllowed,
                'no-go',
                'go',
                $enablementAllowed ? 'paypal live verification enablement reports allowed.' : 'enablement_allowed=false; final decision must remain NO-GO.'
            ),
            $this->check(
                'pending_external_evidence_acceptance',
                $pendingCount === 2,
                'pending',
                'blocked',
                'pending_precondition_count=' . $pendingCount
            ),
            $this->check(
                'runtime_implementation_pending',
                $this->gateStatus($liveGateReport, 'live_provider_implementation') === 'pending',
                'pending',
                'blocked',
                $this->gateDetails($liveGateReport, 'live_provider_implementation')
            ),
            $this->check(
                'paypal_runtime_disabled',
                !$runtimeEnabled,
                'disabled',
                'blocked',
                $runtimeEnabled ? 'PAYPAL_ENABLED=true' : 'PAYPAL_ENABLED=false'
            ),
            $this->check(
                'paypal_ui_hidden',
                $this->gateStatus($liveGateReport, 'paypal_ui_hidden') === 'hidden',
                'hidden',
                'blocked',
                $this->gateDetails($liveGateReport, 'paypal_ui_hidden')
            ),
            $this->check(
                'provider_api_boundary',
                $this->gateStatus($liveGateReport, 'provider_api_boundary') === 'disabled',
                'disabled',
                'blocked',
                $this->gateDetails($liveGateReport, 'provider_api_boundary')
            ),
            $this->check(
                'provider_calls',
                $this->gateStatus($liveGateReport, 'provider_calls') === 'disabled',
                'disabled',
                'blocked',
                $this->gateDetails($liveGateReport, 'provider_calls')
            ),
            $this->check(
                'business_mutation',
                $this->gateStatus($liveGateReport, 'business_mutation') === 'disabled',
                'disabled',
                'blocked',
                $this->gateDetails($liveGateReport, 'business_mutation')
            ),
        ];

        $noGoReasons = $this->noGoReasons($liveGateReport, $enablementAllowed);
        $issues = [];
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'blocked') {
                $issues[] = (string)$check['key'] . ': ' . (string)$check['details'];
            }
        }
        if ($decision !== 'NO-GO') {
            $issues[] = 'final_decision: GO is not allowed by this rollout increment.';
        }

        return [
            'gateVersion' => self::GATE_VERSION,
            'sourceGateVersions' => [
                PaypalLiveVerificationEnablementGateService::GATE_VERSION,
            ],
            'mode' => self::MODE,
            'decision' => $decision,
            'goAllowed' => $decision === 'GO',
            'runtimeEnabled' => $runtimeEnabled,
            'enablementAllowed' => $enablementAllowed,
            'liveVerificationGatePath' => $this->relativePath($liveGatePath),
            'liveVerificationGateResult' => $liveGateResult,
            'noGoReasons' => $noGoReasons,
            'gateChecks' => $checks,
            'totals' => $this->totals($checks, $noGoReasons, $decision),
            'issues' => $issues,
        ];
    }

    private function noGoReasons(array $liveGateReport, bool $enablementAllowed): array
    {
        if ($enablementAllowed) {
            return [];
        }

        return [
            [
                'key' => 'real_sandbox_live_evidence_acceptance_pending',
                'status' => 'pending',
                'details' => $this->gateDetails($liveGateReport, 'sandbox_evidence'),
            ],
            [
                'key' => 'runtime_implementation_pending',
                'status' => 'pending',
                'details' => $this->gateDetails($liveGateReport, 'live_provider_implementation'),
            ],
        ];
    }

    private function totals(array $checks, array $noGoReasons, string $decision): array
    {
        $satisfied = 0;
        foreach ($checks as $check) {
            if ($check['satisfied'] ?? false) {
                $satisfied++;
            }
        }

        return [
            'gate_check_count' => count($checks),
            'satisfied_gate_check_count' => $satisfied,
            'no_go_reason_count' => count($noGoReasons),
            'final_decision_no_go' => $decision === 'NO-GO' ? 1 : 0,
            'go_allowed' => $decision === 'GO' ? 1 : 0,
            'dry_run_network_call_count' => 0,
            'dry_run_write_count' => 0,
        ];
    }

    private function check(string $key, bool $satisfied, string $okStatus, string $failStatus, string $details): array
    {
        return [
            'key' => $key,
            'satisfied' => $satisfied,
            'status' => $satisfied ? $okStatus : $failStatus,
            'details' => $details,
        ];
    }

    private function gateStatus(array $report, string $key): string
    {
        foreach (($report['gateChecks'] ?? []) as $check) {
            if ((string)($check['key'] ?? '') === $key) {
                return (string)($check['status'] ?? '');
            }
        }

        foreach (($report['preconditions'] ?? []) as $precondition) {
            if ((string)($precondition['key'] ?? '') === $key) {
                return (string)($precondition['status'] ?? '');
            }
        }

        return '';
    }

    private function gateDetails(array $report, string $key): string
    {
        foreach (($report['gateChecks'] ?? []) as $check) {
            if ((string)($check['key'] ?? '') === $key) {
                return (string)($check['details'] ?? '');
            }
        }

        foreach (($report['preconditions'] ?? []) as $precondition) {
            if ((string)($precondition['key'] ?? '') === $key) {
                return (string)($precondition['evidence'] ?? '');
            }
        }

        return 'Missing gate details for ' . $key . '.';
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia PayPal Final Go/No-Go Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Gate version: ' . (string)($report['gateVersion'] ?? ''),
            '- Mode: ' . (string)($report['mode'] ?? ''),
            '- Final decision: ' . (string)($report['decision'] ?? ''),
            '- Go allowed: ' . (($report['goAllowed'] ?? true) ? 'yes' : 'no'),
            '- Runtime enabled: ' . (($report['runtimeEnabled'] ?? true) ? 'yes' : 'no'),
            '- Enablement allowed: ' . (($report['enablementAllowed'] ?? true) ? 'yes' : 'no'),
            '- Source live verification gate: ' . (string)($report['liveVerificationGatePath'] ?? ''),
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
            '## No-Go Reasons',
            '',
            '| Key | Status | Details |',
            '|---|---|---|',
        ]);
        foreach (($report['noGoReasons'] ?? []) as $reason) {
            $lines[] = '| ' . $this->escapeCell((string)$reason['key'])
                . ' | ' . $this->escapeCell((string)$reason['status'])
                . ' | ' . $this->escapeCell((string)$reason['details'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Gate Checks',
            '',
            '| Gate | Status | Satisfied | Details |',
            '|---|---|---:|---|',
        ]);
        foreach (($report['gateChecks'] ?? []) as $check) {
            $lines[] = '| ' . $this->escapeCell((string)$check['key'])
                . ' | ' . $this->escapeCell((string)$check['status'])
                . ' | ' . (($check['satisfied'] ?? false) ? '1' : '0')
                . ' | ' . $this->escapeCell((string)$check['details'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- Final decision NO-GO is intentional for this increment.',
            '- This gate is read-only and cannot enable PayPal.',
            '- PayPal UI remains hidden and `PAYPAL_ENABLED=false` remains required.',
            '- Real sandbox/live evidence acceptance and runtime implementation remain pending.',
            '- No PayPal, QPay, or LianLian network call is made.',
            '- No `mall_payment_attempt` row is inserted, updated, or deleted.',
            '- No order, callback, chat, file, shipment, fund, ticket, statistic, signoff, or evidence row is created or updated.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['key,status,details'];
        foreach (($report['noGoReasons'] ?? []) as $reason) {
            $lines[] = implode(',', [
                $this->csvCell((string)$reason['key']),
                $this->csvCell((string)$reason['status']),
                $this->csvCell((string)$reason['details']),
            ]);
        }

        return $lines;
    }

    private function latestHandoverFile(string $pattern): string
    {
        $dir = $this->rootPath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'handover';
        $files = glob($dir . DIRECTORY_SEPARATOR . $pattern);
        if (!$files) {
            return '';
        }
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return (string)$files[0];
    }

    private function readReportResult(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return 'MISSING';
        }
        $content = (string)file_get_contents($path);
        if (preg_match('/^- Result:\s*([A-Z]+)/m', $content, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }

    private function relativePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        if (strpos($normalized, $root) === 0) {
            return substr($normalized, strlen($root));
        }

        return $normalized;
    }

    private function escapeCell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    private function csvCell(string $value): string
    {
        if (strpbrk($value, "\",\n\r") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
