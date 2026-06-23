<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class AccountNotificationPhase12AcceptanceController extends Controller
{
    public const VERSION = 'MONGOYIA_ACCOUNT_NOTIFICATION_PHASE12_ACCEPTANCE_V1';

    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $runChildChecks = false;
    public $thirdPartyLoginAccepted = false;
    public $passwordRecoveryAccepted = false;
    public $notificationAccepted = false;
    public $languageReviewAccepted = false;
    public $browserAccepted = false;
    public $thirdPartyLoginEvidencePath = '';
    public $passwordRecoveryEvidencePath = '';
    public $notificationEvidencePath = '';
    public $languageReviewEvidencePath = '';
    public $browserEvidencePath = '';

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
            'thirdPartyLoginAccepted',
            'passwordRecoveryAccepted',
            'notificationAccepted',
            'languageReviewAccepted',
            'browserAccepted',
            'thirdPartyLoginEvidencePath',
            'passwordRecoveryEvidencePath',
            'notificationEvidencePath',
            'languageReviewEvidencePath',
            'browserEvidencePath',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia account/notification/language Phase 12 acceptance\n");

        $this->checkSourceCoverage();
        $this->checkManualAcceptanceInputs();
        if ($this->fixture) {
            $this->checkFixtureMatrix();
        }
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
        $this->section('Phase 12 source coverage');

        $this->requireFileContains('Phase 12 backlog registration', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'Account, notification, and language foundation completion',
            'account-notification-phase12-acceptance/run',
            'Facebook/Google login',
            'site/app notifications',
            'language review import/export',
        ]);
        $this->requireFileContains('Existing frontend password reset flow', 'frontend/controllers/SiteController.php', [
            'actionRequestPasswordReset',
            'PasswordResetRequestForm',
            'actionResetPassword',
            'ResetPasswordForm',
        ]);
        $this->requireFileContains('Password reset request form', 'frontend/models/PasswordResetRequestForm.php', [
            'sendEmail',
            'There is no user with this email address',
            'password_reset_token',
        ]);
        $this->requireFileContains('Password reset form', 'frontend/models/ResetPasswordForm.php', [
            'resetPassword',
            'findByPasswordResetToken',
            'removePasswordResetToken',
        ]);
        $this->requireFileContains('Existing OAuth2 foundation', 'frontend/controllers/Oauth2Controller.php', [
            'actionAuthorizeCode',
            'AuthCodeGrant',
            'validateAuthorizationRequest',
            'completeAuthorizationRequest',
        ]);
        $this->requireFileContains('OAuth server component', 'common/components/base/OauthSystem.php', [
            'AuthorizationServer',
            'AccessTokenRepository',
            'ClientRepository',
            'ScopeRepository',
        ]);
        $this->requireFileContains('Site message foundation', 'common/components/base/MessageSystem.php', [
            'sendMessageType',
            'MessageType::SEND_TARGET_ALL',
            'Message::STATUS_UNREAD',
            'updateMessageCount',
        ]);
        $this->requireFileContains('SMTP runtime foundation', 'common/components/mailer/SmtpMailer.php', [
            'OperationalMailConfigService',
            'runtimeConfig',
            'setTransport',
            'setHtmlBody',
        ]);
        $this->requireFileContains('Language message foundation', 'common/config/main.php', [
            "'i18n'",
            'PhpMessageSource',
            '@common/messages',
        ]);
        $this->requireDirectoryContains('English language package', 'common/messages/en', ['app.php']);
        $this->requireDirectoryContains('Mongolian language package', 'common/messages/mn', ['app.php']);
    }

    private function checkManualAcceptanceInputs(): void
    {
        $this->section('Phase 12 implementation and external evidence');
        $this->manualFlag(
            'Facebook/Google third-party login acceptance',
            $this->thirdPartyLoginAccepted,
            $this->thirdPartyLoginEvidencePath,
            'Facebook and Google login, callback, bind/unbind, conflict handling, and operation logs were accepted.',
            'Implement and validate Facebook/Google provider configuration, callbacks, account binding, unbinding, and safe error handling.'
        );
        $this->manualFlag(
            'Password recovery and security-code login acceptance',
            $this->passwordRecoveryAccepted,
            $this->passwordRecoveryEvidencePath,
            'Email/mobile recovery, verification/security-code login policies, backend switches, and operation logs were accepted.',
            'Extend the existing email reset foundation with mobile/email verification-code policy, backend switches, and operation logs.'
        );
        $this->manualFlag(
            'Site/app notification acceptance',
            $this->notificationAccepted,
            $this->notificationEvidencePath,
            'Order, logistics, payment, service-reply, and complaint notifications were accepted with send logs.',
            'Implement notification templates, event hooks, site/app delivery records, and provider test evidence.'
        );
        $this->manualFlag(
            'Mongolian/English language review import/export acceptance',
            $this->languageReviewAccepted,
            $this->languageReviewEvidencePath,
            'UI, mail, notification, and payment-error text export/import review workflow was accepted.',
            'Implement reviewer-safe language export/import for UI, mail, notification, and payment-error strings.'
        );
        $this->manualFlag(
            'Browser role-flow account/notification/language acceptance',
            $this->browserAccepted,
            $this->browserEvidencePath !== '' ? $this->browserEvidencePath : $this->baseUrl,
            'Buyer, merchant, and platform browser flows for login/recovery/notifications/language switching were accepted.',
            'Validate normal login, third-party login, password recovery, notification visibility, language switching, and readable error prompts in browser.'
        );
    }

    private function checkFixtureMatrix(): void
    {
        $this->section('Fixture matrix');

        $matrix = [
            'Existing email password reset foundation' => [
                'status' => 'PASS',
                'evidence' => 'frontend/controllers/SiteController.php',
                'notes' => 'Existing request/reset actions and forms are present; Phase 12 will harden policies and add mobile/security-code coverage.',
            ],
            'Existing OAuth2 server foundation' => [
                'status' => 'PASS',
                'evidence' => 'frontend/controllers/Oauth2Controller.php',
                'notes' => 'Internal OAuth2 grant foundation exists; Facebook/Google provider flow remains a Phase 12 implementation task.',
            ],
            'Existing site message foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/components/base/MessageSystem.php',
                'notes' => 'Unread site-message rows can be created; Phase 12 will add business event hooks and send logs.',
            ],
            'Existing SMTP foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/components/mailer/SmtpMailer.php',
                'notes' => 'SMTP runtime can read encrypted backend config; provider test evidence remains external.',
            ],
            'Language package foundation' => [
                'status' => is_dir($this->resolvePath('common/messages/mn')) ? 'PASS' : 'PENDING',
                'evidence' => 'common/messages',
                'notes' => 'Message packages exist; reviewer-safe export/import workflow remains a Phase 12 implementation task.',
            ],
        ];

        foreach ($matrix as $area => $row) {
            $this->addCheck($area, $row['status'], $row['evidence'], $row['notes']);
        }
    }

    private function runChildChecks(): void
    {
        $this->section('Phase 12 child readiness commands');
        foreach ($this->childCommands() as $label => $config) {
            $params = ['interactive' => 0];
            if ($this->fixture && !empty($config['fixture'])) {
                $params['fixture'] = 1;
            }

            try {
                $exitCode = Yii::$app->runAction($config['route'], $params);
                if ((int)$exitCode === ExitCode::OK) {
                    $this->addCheck($label, 'PASS', $config['route'], 'Child readiness command passed.');
                } else {
                    $this->addCheck($label, 'FAIL', $config['route'], 'Child readiness command returned exit code ' . (int)$exitCode . '.');
                }
            } catch (\Throwable $e) {
                $this->addCheck($label, 'FAIL', $config['route'], 'Child readiness command failed: ' . $e->getMessage());
            }
        }
    }

    private function childCommands(): array
    {
        return [
            'Operational mail config readiness' => ['route' => 'operational-config-mail-test/run', 'fixture' => true],
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
            '# Mongoyia Account, Notification, And Language Phase 12 Acceptance',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Scope: Facebook/Google login, password recovery/security-code policies, site/app notifications, and Mongolian/English review import/export.',
            '- Safety: this command does not call external identity providers, send real notifications, mutate users, write credentials, or store provider secrets.',
            '- Production boundary: provider credentials, mail/SMS/APP push routes, and human language signoff remain external evidence until accepted.',
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
            '/www/server/php/83/bin/php yii account-notification-phase12-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --runChildChecks=1 \\',
            '  --fixture=1 \\',
            '  --interactive=0',
            '```',
            '',
            'After Phase 12 implementation and evidence are complete, rerun with the accepted evidence flags and `--strict=1`.',
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

        $this->addCheck($label, 'PASS', $path, 'Required Phase 12 markers are present.');
    }

    private function requireDirectoryContains(string $label, string $path, array $files): void
    {
        $dir = $this->resolvePath($path);
        if (!is_dir($dir)) {
            $this->addCheck($label, 'PENDING', $path, 'Language package directory is missing and must be created during Phase 12 language review work.');
            return;
        }

        foreach ($files as $file) {
            if (!is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                $this->addCheck($label, 'PENDING', $path . '/' . $file, 'Required language package file is missing and must be added during Phase 12 language review work.');
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Language package exists.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-account-notification-phase12-acceptance-' . date('Ymd-His') . '.md';
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
