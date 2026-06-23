<?php

namespace console\controllers;

use common\services\mall\OperationalAccountSecurityService;
use common\services\mall\OperationalConfigService;
use yii\console\Controller;
use yii\console\ExitCode;

class AccountSecurityReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_ACCOUNT_SECURITY_READINESS_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia account security readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkPolicyDefinitions();
        }

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Source coverage');
        $this->requireFileContains('Encrypted account security policy service', 'common/services/mall/OperationalAccountSecurityService.php', [
            'MONGOYIA_OPERATIONAL_ACCOUNT_SECURITY_V1',
            'email_reset_enabled',
            'mobile_code_login_enabled',
            'code_ttl_seconds',
            'audit_enabled',
            'codeLoginEnabled',
        ]);
        $this->requireFileContains('Backend account security actions', 'backend/modules/mall/controllers/OperationalConfigController.php', [
            'OperationalAccountSecurityService',
            'actionAccountSecurity',
            'actionSaveAccountSecurity',
            'actionCheckAccountSecurity',
        ]);
        $this->requireFileContains('Backend account security page', 'backend/modules/mall/views/operational-config/account-security.php', [
            'data-mongoyia-account-security',
            'data-mongoyia-account-security-policy',
            'data-mongoyia-account-security-routes',
            '保存并检测',
        ]);
        $this->requireFileContains('Backend operations center account security entry', 'backend/modules/mall/views/operational-config/index.php', [
            '账号安全策略',
            'data-mongoyia-account-security-entry',
        ]);
        $this->requireFileContains('Frontend account security boundary controller', 'frontend/controllers/AccountSecurityController.php', [
            'MONGOYIA_ACCOUNT_SECURITY_BOUNDARY_V1',
            'MONGOYIA_ACCOUNT_SECURITY_CODE_RUNTIME_V1',
            'SECURITY_CODE_POLICY_GATE',
            'SECURITY_CODE_RUNTIME_GATE',
            'AccountSecurityCodeService',
            'actionRequestCode',
            'actionLoginCode',
            'SECURITY_CODE_LOGIN_DISABLED',
            'SECURITY_CODE_DELIVERY_RESERVED',
        ]);
        $this->requireFileContains('Account security permission migration', 'console/migrations/m260623_163000_mongoyia_account_security_permission.php', [
            '/mall/operational-config/account-security*',
            '/mall/operational-config/save-account-security*',
            '/mall/operational-config/check-account-security*',
            'grantToRoles',
            'clearPermissionCache',
        ]);
    }

    private function checkPolicyDefinitions(): void
    {
        $this->section('Policy definitions');
        try {
            $service = new OperationalAccountSecurityService(new OperationalConfigService('codex-account-security-test-master-key'));
            $definitions = $service->fieldDefinitions();
            foreach ([
                'email_reset_enabled',
                'mobile_reset_enabled',
                'email_code_login_enabled',
                'mobile_code_login_enabled',
                'code_length',
                'code_ttl_seconds',
                'max_attempts',
                'lock_minutes',
                'allowed_channels',
                'audit_enabled',
            ] as $field) {
                if (empty($definitions[$field])) {
                    $this->fail("Policy field missing: {$field}.");
                    return;
                }
            }

            $check = $service->check(false);
            if (!in_array($check['result'] ?? '', ['PASS', 'WARN'], true)) {
                $this->fail('Default account-security policy check did not pass: ' . ($check['message'] ?? 'unknown error'));
                return;
            }

            $emailEnabled = $service->codeLoginEnabled('email') ? '1' : '0';
            $mobileEnabled = $service->codeLoginEnabled('mobile') ? '1' : '0';
            $this->ok("Policy definitions are ready; default code-login flags email={$emailEnabled}, mobile={$mobileEnabled}.");
        } catch (\Throwable $e) {
            $this->fail('Account security policy fixture failed: ' . $e->getMessage());
        }
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Account Security Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: encrypted account-security policy, backend switches, frontend security-code routes, and permission migration.',
            '- Safety: this command does not send verification codes, mutate users, log users in, call SMS/mail providers, or store provider secrets.',
            '- Boundary: email security-code runtime is controlled by backend policy and mailer configuration; mobile/SMS delivery remains reserved until provider evidence is accepted.',
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

        $this->addCheck($label, 'PASS', $path, 'Required account-security markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->addCheck($message, 'PASS', 'fixture', 'Policy definition check passed.');
    }

    private function fail(string $message): void
    {
        $this->addCheck($message, 'FAIL', 'readiness check', $message);
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
            . DIRECTORY_SEPARATOR . 'mongoyia-account-security-readiness-' . date('Ymd-His') . '.md';
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
