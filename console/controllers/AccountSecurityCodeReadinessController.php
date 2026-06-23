<?php

namespace console\controllers;

use common\services\mall\AccountSecurityCodeService;
use yii\console\Controller;
use yii\console\ExitCode;

class AccountSecurityCodeReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_ACCOUNT_SECURITY_CODE_READINESS_V1';

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
        $this->stdout("Mongoyia account security-code runtime readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkRuntimeSchema();
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
        $this->requireFileContains('Security-code runtime service', 'common/services/mall/AccountSecurityCodeService.php', [
            'MONGOYIA_ACCOUNT_SECURITY_CODE_RUNTIME_V1',
            'security_code_hash_only_no_plaintext',
            'requestCode',
            'loginWithCode',
            'generatePasswordHash',
            'validatePassword',
            'DELIVERY_RESERVED',
            'mobile_delivery',
        ]);
        $this->requireFileContains('Security-code storage migration', 'console/migrations/m260623_166000_mongoyia_account_security_code.php', [
            'mall_account_security_code',
            'target_hash',
            'target_masked',
            'code_hash',
            'delivery_status',
            'verify_status',
            'idx_mall_account_security_code_target',
        ]);
        $this->requireFileContains('Frontend security-code runtime controller', 'frontend/controllers/AccountSecurityController.php', [
            'MONGOYIA_ACCOUNT_SECURITY_CODE_RUNTIME_V1',
            'AccountSecurityCodeService',
            'actionRequestCode',
            'actionLoginCode',
            'statusCodeForResult',
            'SECURITY_CODE_RUNTIME_GATE',
        ]);
        $this->requireFileContains('API security-code token handoff', 'api/controllers/SiteController.php', [
            'actionSecurityCodeRequest',
            'actionSecurityCodeLogin',
            'AccountSecurityCodeService',
            'accessTokenSystem->getAccessToken',
        ]);
        $this->requireFileContains('APP security-code login entry', 'apps/mongoyia-customer-chat-uniapp/src/pages/auth/login.vue', [
            'data-mongoyia-phase12-app-account-entry',
            '/api/site/security-code-request',
            '/api/site/security-code-login',
            'MONGOYIA_APP_SECURITY_CODE_CHANNEL_SELECTOR_V1',
            'codeChannel',
            'setCodeChannel',
            'requestSecurityCode',
            'submitCodeLogin',
        ]);
        $this->requireFileContains('Phase 12 aggregate wiring', 'console/controllers/AccountNotificationPhase12AcceptanceController.php', [
            'account-security-code-readiness/run',
            'Security-code delivery/storage runtime',
        ]);
    }

    private function checkRuntimeSchema(): void
    {
        $this->section('Runtime schema');
        try {
            $service = new AccountSecurityCodeService();
            $readiness = $service->runtimeReadiness();
            if (($readiness['version'] ?? '') !== AccountSecurityCodeService::VERSION) {
                $this->fail('Security-code runtime version marker is unavailable.');
                return;
            }
            if (empty($readiness['table_exists'])) {
                $this->fail('Security-code table is missing; run migrations first.');
                return;
            }

            $schema = \Yii::$app->db->schema->getTableSchema(AccountSecurityCodeService::TABLE, true);
            if (!$schema) {
                $this->fail('Security-code table schema cannot be loaded.');
                return;
            }

            foreach ([
                'store_id',
                'user_id',
                'channel',
                'purpose',
                'target_hash',
                'target_masked',
                'code_hash',
                'expires_at',
                'attempt_count',
                'max_attempts',
                'lock_minutes',
                'lock_until',
                'delivery_status',
                'verify_status',
                'error_summary',
                'consumed_at',
                'sent_at',
            ] as $column) {
                if (!isset($schema->columns[$column])) {
                    $this->fail("Security-code table column missing: {$column}.");
                    return;
                }
            }

            foreach (['code', 'plain_code', 'raw_code', 'target'] as $forbiddenColumn) {
                if (isset($schema->columns[$forbiddenColumn])) {
                    $this->fail("Forbidden plaintext security-code column exists: {$forbiddenColumn}.");
                    return;
                }
            }

            if (($readiness['mobile_delivery'] ?? '') !== 'reserved_until_sms_or_app_provider_evidence') {
                $this->warn('Mobile security-code delivery is not evidence-gated as expected.');
                return;
            }

            $this->ok('Security-code table and hash-only storage policy are ready.');
        } catch (\Throwable $e) {
            $this->fail('Security-code runtime fixture failed: ' . $e->getMessage());
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
            '# Mongoyia Account Security-Code Runtime Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: email security-code request/login runtime, hashed storage, attempt limits, lockouts, and mobile/SMS evidence gate.',
            '- Safety: this command does not send verification codes, mutate users, log users in, call SMS providers, or store plaintext codes.',
            '- Boundary: email delivery uses the configured mailer only when the frontend request-code endpoint is called by a user; mobile delivery remains reserved until SMS/APP provider evidence is accepted.',
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
            'git pull',
            '/www/server/php/83/bin/php yii migrate/up --interactive=0',
            '/www/server/php/83/bin/php yii account-security-code-readiness/run --fixture=1 --interactive=0',
            '```',
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

        $this->addCheck($label, 'PASS', $path, 'Required security-code markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->addCheck($message, 'PASS', 'fixture', 'Security-code runtime check passed.');
    }

    private function warn(string $message): void
    {
        $this->addCheck($message, 'WARN', 'readiness check', $message);
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
            . DIRECTORY_SEPARATOR . 'mongoyia-account-security-code-readiness-' . date('Ymd-His') . '.md';
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
