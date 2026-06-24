<?php

namespace console\controllers;

use common\services\mall\OperationalConfigService;
use common\services\mall\OperationalIdentityConfigService;
use yii\console\Controller;
use yii\console\ExitCode;

class IdentityConfigReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_IDENTITY_CONFIG_READINESS_V1';

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
        $this->stdout("Mongoyia identity config readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkProviderDefinitions();
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
        $this->requireFileContains('Encrypted identity config service', 'common/services/mall/OperationalIdentityConfigService.php', [
            'MONGOYIA_OPERATIONAL_IDENTITY_CONFIG_V1',
            'google',
            'facebook',
            'client_secret',
            'callbackUrls',
            'runtimeConfig',
        ]);
        $this->requireFileContains('Backend identity config actions', 'backend/modules/mall/controllers/OperationalConfigController.php', [
            'MONGOYIA_OPERATIONAL_CONFIG_BACKEND_POST_VERB_GUARD_V1',
            'OperationalIdentityConfigService',
            'actionIdentityConfig',
            'actionSaveIdentityConfig',
            'actionCheckIdentityConfig',
            "'save-identity-config'",
            "'check-identity-config'",
            "['post']",
        ]);
        $this->requireFileContains('Backend identity config page', 'backend/modules/mall/views/operational-config/identity-config.php', [
            'data-mongoyia-identity-config',
            'data-mongoyia-identity-provider-cards',
            'data-mongoyia-identity-callback-urls',
            '保存并检测',
        ]);
        $this->requireFileContains('Backend operations center identity entry', 'backend/modules/mall/views/operational-config/index.php', [
            '第三方登录配置',
            'data-mongoyia-identity-config-entry',
        ]);
        $this->requireFileContains('Frontend social auth boundary controller', 'frontend/controllers/SocialAuthController.php', [
            'MONGOYIA_SOCIAL_AUTH_BOUNDARY_V1',
            'MONGOYIA_SOCIAL_AUTH_UNBIND_POST_GUARD_V1',
            'OperationalIdentityConfigService',
            'VerbFilter',
            'actionRedirect',
            'actionCallback',
            'actionBind',
            'actionUnbind',
            "'unbind' => ['POST']",
            "post('provider', '')",
            'third_party_login_requires_provider_acceptance',
            'provider_secret_never_logged',
        ]);
        $this->requireFileContains('Identity config permission migration', 'console/migrations/m260623_162000_mongoyia_identity_config_permission.php', [
            '/mall/operational-config/identity-config*',
            '/mall/operational-config/save-identity-config*',
            '/mall/operational-config/check-identity-config*',
            'grantToRoles',
            'clearAllPermission',
        ]);
    }

    private function checkProviderDefinitions(): void
    {
        $this->section('Provider definitions');
        try {
            $service = new OperationalIdentityConfigService(new OperationalConfigService('codex-identity-test-master-key'));
            $providers = $service->providerDefinitions();
            foreach (['google', 'facebook'] as $provider) {
                if (empty($providers[$provider])) {
                    $this->fail("Provider definition missing: {$provider}");
                    continue;
                }
                foreach (['enabled', 'client_id', 'client_secret', 'auth_url', 'token_url', 'profile_url', 'redirect_path', 'scopes'] as $field) {
                    if (empty($providers[$provider]['fields'][$field])) {
                        $this->fail("Provider {$provider} missing field {$field}.");
                        continue 2;
                    }
                }
                if (empty($providers[$provider]['fields']['client_secret']['sensitive'])) {
                    $this->fail("Provider {$provider} client_secret must be sensitive.");
                    continue;
                }
                $urls = $service->callbackUrls($provider, 'test', 'https://demo2026.mongoyia.com');
                foreach (['callback', 'redirect', 'bind', 'unbind'] as $key) {
                    if (empty($urls[$key]) || strpos($urls[$key], 'provider=' . $provider) === false) {
                        $this->fail("Provider {$provider} callback URL {$key} is missing provider parameter.");
                        continue 2;
                    }
                }
                $this->ok("Provider {$provider} definition and callback URLs are ready.");
            }
        } catch (\Throwable $e) {
            $this->fail('Identity provider definition fixture failed: ' . $e->getMessage());
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
            '# Mongoyia Identity Config Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: Google/Facebook encrypted provider config, backend page, callback URL helpers, frontend route boundary, and permission migration.',
            '- Safety: this command does not call Google/Facebook, mutate users, write bindings, send notifications, or store provider secrets.',
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

        $this->addCheck($label, 'PASS', $path, 'Required identity config markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->addCheck($message, 'PASS', 'fixture', 'Provider definition check passed.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-identity-config-readiness-' . date('Ymd-His') . '.md';
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
