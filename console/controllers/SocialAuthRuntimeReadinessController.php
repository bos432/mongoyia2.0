<?php

namespace console\controllers;

use common\services\mall\SocialIdentityService;
use yii\console\Controller;
use yii\console\ExitCode;

class SocialAuthRuntimeReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_SOCIAL_AUTH_RUNTIME_READINESS_V1';

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
        $this->stdout("Mongoyia social auth runtime readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkFixtureMatrix();
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
        $this->requireFileContains('Social identity runtime service', 'common/services/mall/SocialIdentityService.php', [
            'MONGOYIA_SOCIAL_IDENTITY_RUNTIME_V1',
            'MONGOYIA_SOCIAL_AUTH_RETURN_URL_GUARD_V1',
            'BIND_POLICY_REQUIRE_EXISTING_SESSION',
            'authorizationUrl',
            'handleCallback',
            'bindIdentity',
            'safeReturnUrl',
            "strpos(\$returnUrl, '//') === 0",
            "preg_match('/^[a-z][a-z0-9+.-]*:/i'",
            "'return_url_policy' => self::RETURN_URL_GUARD_VERSION",
            'provider_secret_never_logged',
            'PROVIDER_RESPONSE_ERROR_POLICY',
            'provider_response_errors_are_sanitized',
            'decodeProviderJson',
        ]);
        $this->requireFileContains('Social auth frontend runtime controller', 'frontend/controllers/SocialAuthController.php', [
            'MONGOYIA_SOCIAL_AUTH_RUNTIME_V1',
            'MONGOYIA_SOCIAL_AUTH_UNBIND_POST_GUARD_V1',
            'SocialIdentityService',
            'VerbFilter',
            'actionRedirect',
            'actionCallback',
            'actionBind',
            'actionUnbind',
            "'unbind' => ['POST']",
            "post('provider', '')",
            'providerEnabled',
            'SOCIAL_AUTH_DISABLED',
            'SOCIAL_AUTH_UNAVAILABLE',
            'require_existing_session_before_first_login',
        ]);
        $this->requireFileContains('Social identity migration', 'console/migrations/m260623_165000_mongoyia_social_identity.php', [
            'mall_social_identity',
            'provider_user_id',
            'profile_json',
            'last_login_at',
        ]);
        $this->requireFileContains('APP social login entry', 'apps/mongoyia-customer-chat-uniapp/src/pages/auth/login.vue', [
            'data-mongoyia-phase12-social-login-entry',
            "socialLogin('google')",
            "socialLogin('facebook')",
            '/social-auth/redirect',
        ]);
    }

    private function checkFixtureMatrix(): void
    {
        $this->section('Fixture matrix');
        try {
            $service = new SocialIdentityService();
            $readiness = $service->runtimeReadiness();
            foreach (['google', 'facebook'] as $provider) {
                if (!in_array($provider, $readiness['providers'] ?? [], true)) {
                    $this->fail("Provider missing from social auth runtime: {$provider}.");
                    continue;
                }
                $this->ok("Provider {$provider} runtime definition is ready.");
            }
            if (empty($readiness['table_exists'])) {
                $this->fail('Social identity table is missing; run migrations before runtime acceptance.');
                return;
            }
            if (($readiness['bind_policy'] ?? '') !== SocialIdentityService::BIND_POLICY_REQUIRE_EXISTING_SESSION) {
                $this->fail('Social auth bind policy is not locked to existing-session binding.');
                return;
            }
            $this->ok('Social identity table and safe bind policy are ready.');
        } catch (\Throwable $e) {
            $this->fail('Social auth runtime fixture failed: ' . $e->getMessage());
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
            '# Mongoyia Social Auth Runtime Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: Google/Facebook OAuth redirect, callback, existing-session binding, unbinding, bound-user login, and redacted social identity storage.',
            '- Safety: this command does not call Google/Facebook, exchange codes, log users in, mutate users, or store provider tokens/secrets.',
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

        $this->addCheck($label, 'PASS', $path, 'Required social auth runtime markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->addCheck($message, 'PASS', 'fixture', 'Social auth runtime fixture check passed.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-social-auth-runtime-readiness-' . date('Ymd-His') . '.md';
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
