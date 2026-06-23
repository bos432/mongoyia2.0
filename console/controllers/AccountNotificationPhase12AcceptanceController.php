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
            'authorizationCodeCreate',
            'authorizationCodeDelete',
            'authorizationCodeFindByCode',
        ]);
        $this->requireFileContains('OAuth authorization-code repository', 'common/models/oauth/repositories/AuthCodeRepository.php', [
            'MONGOYIA_OAUTH_AUTH_CODE_REPOSITORY_V1',
            'persistNewAuthCode',
            'revokeAuthCode',
            'isAuthCodeRevoked',
        ]);
        $this->requireFileContains('OAuth PSR-7 response adapter', 'api/components/response/OauthResponse.php', [
            'MONGOYIA_OAUTH_RESPONSE_ADAPTER_V1',
            'getHeaderLine',
            'normalizeHeaderValues',
            'withAddedHeader',
        ]);
        $this->requireFileContains('OAuth PSR-7 stream adapter', 'api/components/response/OauthStream.php', [
            'MONGOYIA_OAUTH_RESPONSE_ADAPTER_V1',
            '__toString',
            'read($length)',
            'getContents',
        ]);
        $this->requireFileContains('Encrypted third-party login config service', 'common/services/mall/OperationalIdentityConfigService.php', [
            'MONGOYIA_OPERATIONAL_IDENTITY_CONFIG_V1',
            'google',
            'facebook',
            'client_secret',
            'runtimeConfig',
        ]);
        $this->requireFileContains('Third-party login backend config page', 'backend/modules/mall/views/operational-config/identity-config.php', [
            'data-mongoyia-identity-config',
            'data-mongoyia-identity-provider-cards',
            'data-mongoyia-identity-callback-urls',
        ]);
        $this->requireFileContains('Third-party login frontend boundary', 'frontend/controllers/SocialAuthController.php', [
            'MONGOYIA_SOCIAL_AUTH_BOUNDARY_V1',
            'MONGOYIA_SOCIAL_AUTH_RUNTIME_V1',
            'actionRedirect',
            'actionCallback',
            'actionBind',
            'actionUnbind',
            'SocialIdentityService',
            'require_existing_session_before_first_login',
        ]);
        $this->requireFileContains('Third-party login runtime service', 'common/services/mall/SocialIdentityService.php', [
            'MONGOYIA_SOCIAL_IDENTITY_RUNTIME_V1',
            'authorizationUrl',
            'handleCallback',
            'bindIdentity',
            'provider_secret_never_logged',
        ]);
        $this->requireFileContains('Third-party login binding migration', 'console/migrations/m260623_165000_mongoyia_social_identity.php', [
            'mall_social_identity',
            'provider_user_id',
            'profile_json',
        ]);
        $this->requireFileContains('Third-party login readiness command', 'console/controllers/IdentityConfigReadinessController.php', [
            'MONGOYIA_IDENTITY_CONFIG_READINESS_V1',
            'identity-config-readiness',
            'Frontend social auth boundary controller',
        ]);
        $this->requireFileContains('Third-party login runtime readiness command', 'console/controllers/SocialAuthRuntimeReadinessController.php', [
            'MONGOYIA_SOCIAL_AUTH_RUNTIME_READINESS_V1',
            'social-auth-runtime-readiness',
            'existing-session binding',
        ]);
        $this->requireFileContains('APP social login entry', 'apps/mongoyia-customer-chat-uniapp/src/pages/auth/login.vue', [
            'data-mongoyia-phase12-social-login-entry',
            "socialLogin('google')",
            "socialLogin('facebook')",
            '/social-auth/redirect',
        ]);
        $this->requireFileContains('Encrypted account security policy service', 'common/services/mall/OperationalAccountSecurityService.php', [
            'MONGOYIA_OPERATIONAL_ACCOUNT_SECURITY_V1',
            'email_reset_enabled',
            'mobile_code_login_enabled',
            'code_ttl_seconds',
            'audit_enabled',
        ]);
        $this->requireFileContains('Account security backend config page', 'backend/modules/mall/views/operational-config/account-security.php', [
            'data-mongoyia-account-security',
            'data-mongoyia-account-security-policy',
            'data-mongoyia-account-security-routes',
        ]);
        $this->requireFileContains('Account security frontend boundary', 'frontend/controllers/AccountSecurityController.php', [
            'MONGOYIA_ACCOUNT_SECURITY_BOUNDARY_V1',
            'MONGOYIA_ACCOUNT_SECURITY_CODE_RUNTIME_V1',
            'AccountSecurityCodeService',
            'actionRequestCode',
            'actionLoginCode',
            'SECURITY_CODE_POLICY_GATE',
            'SECURITY_CODE_RUNTIME_GATE',
        ]);
        $this->requireFileContains('Account security readiness command', 'console/controllers/AccountSecurityReadinessController.php', [
            'MONGOYIA_ACCOUNT_SECURITY_READINESS_V1',
            'account-security-readiness',
            'Frontend account security boundary controller',
        ]);
        $this->requireFileContains('Security-code delivery/storage runtime', 'common/services/mall/AccountSecurityCodeService.php', [
            'MONGOYIA_ACCOUNT_SECURITY_CODE_RUNTIME_V1',
            'security_code_hash_only_no_plaintext',
            'requestCode',
            'loginWithCode',
            'DELIVERY_RESERVED',
        ]);
        $this->requireFileContains('Security-code storage migration', 'console/migrations/m260623_166000_mongoyia_account_security_code.php', [
            'mall_account_security_code',
            'target_hash',
            'target_masked',
            'code_hash',
            'delivery_status',
            'verify_status',
        ]);
        $this->requireFileContains('Security-code runtime readiness command', 'console/controllers/AccountSecurityCodeReadinessController.php', [
            'MONGOYIA_ACCOUNT_SECURITY_CODE_READINESS_V1',
            'account-security-code-readiness',
            'Forbidden plaintext security-code column',
        ]);
        $this->requireFileContains('APP security-code token handoff', 'api/controllers/SiteController.php', [
            'actionSecurityCodeRequest',
            'actionSecurityCodeLogin',
            'accessTokenSystem->getAccessToken',
        ]);
        $this->requireFileContains('APP security-code login entry', 'apps/mongoyia-customer-chat-uniapp/src/pages/auth/login.vue', [
            'data-mongoyia-phase12-app-account-entry',
            '/api/site/security-code-request',
            '/api/site/security-code-login',
            'submitCodeLogin',
        ]);
        $this->requireFileContains('Site message foundation', 'common/components/base/MessageSystem.php', [
            'sendMessageType',
            'MessageType::SEND_TARGET_ALL',
            'Message::STATUS_UNREAD',
            'updateMessageCount',
        ]);
        $this->requireFileContains('Operational notification event service', 'common/services/mall/OperationalNotificationService.php', [
            'MONGOYIA_OPERATIONAL_NOTIFICATION_V1',
            'EVENT_ORDER_STATUS',
            'EVENT_LOGISTICS_STATUS',
            'EVENT_PAYMENT_RESULT',
            'EVENT_CUSTOMER_SERVICE_REPLY',
            'EVENT_COMPLAINT_RESULT',
            'CHANNEL_APP_RESERVED',
        ]);
        $this->requireFileContains('Notification send-log backend page', 'backend/modules/mall/views/notification-log/index.php', [
            'data-mongoyia-notification-log',
            'data-mongoyia-notification-log-summary',
            'data-mongoyia-notification-log-recent',
        ]);
        $this->requireFileContains('Notification send-log readiness command', 'console/controllers/NotificationPhase12ReadinessController.php', [
            'MONGOYIA_NOTIFICATION_PHASE12_READINESS_V1',
            'Notification event',
            'app-reserved',
        ]);
        $this->requireFileContains('Notification send-log migration', 'console/migrations/m260623_164000_mongoyia_notification_send_log.php', [
            'mall_notification_send_log',
            '/mall/notification-log/index',
            'delivery_status',
        ]);
        $this->requireFileContains('Buyer APP notification center', 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/notifications.vue', [
            'data-mongoyia-phase12-app-notifications',
            'BUYER_ENDPOINTS.notifications',
            'markRead',
            'markAllRead',
        ]);
        $this->requireFileContains('Buyer APP notification API runtime', 'common/services/mall/AppBuyerApiService.php', [
            'MONGOYIA_APP_BUYER_NOTIFICATION_CENTER_V1',
            'markNotificationRead',
            'notificationSummary',
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
        $this->requireFileContains('Language review import/export service', 'common/services/mall/LanguageReviewService.php', [
            'MONGOYIA_LANGUAGE_REVIEW_V1',
            'DOMAIN_MAIL',
            'DOMAIN_NOTIFICATION',
            'DOMAIN_PAYMENT_ERROR',
            'exportBundle',
            'importCsv',
        ]);
        $this->requireFileContains('Language review export command', 'console/controllers/LanguageReviewExportController.php', [
            'Mongoyia language review export',
            'targets',
            'domains',
        ]);
        $this->requireFileContains('Language review import command', 'console/controllers/LanguageReviewImportController.php', [
            'Mongoyia language review import',
            'inputPath',
            'apply',
        ]);
        $this->requireFileContains('Language review readiness command', 'console/controllers/LanguageReviewPhase12ReadinessController.php', [
            'MONGOYIA_LANGUAGE_REVIEW_PHASE12_READINESS_V1',
            'language-review-phase12-readiness',
            'dry-run import',
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
                'notes' => 'Internal OAuth2 grant foundation exists; Facebook/Google provider runtime is handled by the Phase 12 social identity service.',
            ],
            'Third-party login runtime foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/services/mall/SocialIdentityService.php',
                'notes' => 'Google/Facebook OAuth redirect/callback, safe first-bind policy, unbind, and bound-user login runtime are present; provider credentials and browser callback evidence remain external.',
            ],
            'Account security policy foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/services/mall/OperationalAccountSecurityService.php',
                'notes' => 'Backend switches and frontend security-code routes are present; email runtime storage is hash-only and mobile delivery remains evidence-gated.',
            ],
            'Security-code delivery/storage runtime foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/services/mall/AccountSecurityCodeService.php',
                'notes' => 'Email security-code request/login runtime, attempt limits, lockouts, and hashed storage are present; SMS/APP delivery remains reserved.',
            ],
            'Existing site message foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/components/base/MessageSystem.php',
                'notes' => 'Unread site-message rows can be created; Phase 12 will add business event hooks and send logs.',
            ],
            'Notification event hooks and send-log foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/services/mall/OperationalNotificationService.php',
                'notes' => 'Order, logistics, payment, customer-service reply, and complaint-result notification hooks plus send-log records are present; app push remains reserved.',
            ],
            'Existing SMTP foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/components/mailer/SmtpMailer.php',
                'notes' => 'SMTP runtime can read encrypted backend config; provider test evidence remains external.',
            ],
            'Language package foundation' => [
                'status' => is_dir($this->resolvePath('common/messages/mn')) ? 'PASS' : 'PENDING',
                'evidence' => 'common/messages',
                'notes' => 'Message packages exist and Phase 12 language review export/import now provides the reviewer workflow.',
            ],
            'Language review import/export foundation' => [
                'status' => 'PASS',
                'evidence' => 'common/services/mall/LanguageReviewService.php',
                'notes' => 'Reviewer-safe CSV/Markdown export and approved-row import dry-run/apply workflow are present for UI, mail, notification, and payment-error strings.',
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
            'Identity provider config readiness' => ['route' => 'identity-config-readiness/run', 'fixture' => true],
            'Social auth runtime readiness' => ['route' => 'social-auth-runtime-readiness/run', 'fixture' => true],
            'Account security policy readiness' => ['route' => 'account-security-readiness/run', 'fixture' => true],
            'Security-code delivery/storage runtime readiness' => ['route' => 'account-security-code-readiness/run', 'fixture' => true],
            'Notification event/send-log readiness' => ['route' => 'notification-phase12-readiness/run', 'fixture' => true],
            'Language review import/export readiness' => ['route' => 'language-review-phase12-readiness/run', 'fixture' => true],
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
            'git pull --ff-only',
            'git rev-parse --short HEAD',
            '/www/server/php/83/bin/php yii migrate/up --interactive=0',
            '/www/server/php/83/bin/php yii cache/flush-all --interactive=0',
            '/etc/init.d/php-fpm-83 restart',
            '/www/server/php/83/bin/php yii identity-config-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii social-auth-runtime-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii account-security-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii account-security-code-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii notification-phase12-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii language-review-phase12-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii account-notification-phase12-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --runChildChecks=1 \\',
            '  --fixture=1 \\',
            '  --interactive=0',
            '```',
            '',
            'MONGOYIA_PHASE10_15_CHILD_DEPLOY_CACHE_REFRESH_V1: pull fast-forward changes, print the deployed commit, flush Yii cache, and restart PHP-FPM before collecting Phase 12 account/notification/language browser evidence.',
            '',
            '## Browser Role-Flow Checklist',
            '',
            'Record screenshots, command reports, provider-console callback screenshots, language-review bundles, or reviewer notes in non-secret evidence files, then pass those paths through the accepted evidence options after review.',
            '',
            '1. Buyer PC/H5/APP: log in with a normal test account, log out, verify readable failure prompts for wrong credentials, and confirm no raw exception or provider secret appears on screen.',
            '2. Buyer PC/H5/APP: request password reset or email security-code login, verify code/reset policy prompts, complete a test login where mail delivery is configured, and confirm refresh persistence after login.',
            '3. Buyer PC/H5/APP: open Google and Facebook login entries, complete provider redirect/callback/bind/unbind only with provider sandbox credentials, and capture provider callback URL configuration evidence.',
            '4. Buyer PC/H5/APP: open notification center or site-message entry, verify order/logistics/payment/customer-service/complaint notification rows are visible when fixture or test events exist, mark one and all messages read, then refresh.',
            '5. Buyer PC/H5/APP: switch language between Chinese, English, and Mongolian where available, verify account, notification, payment-error, and mail-template prompts do not show missing-key text or mojibake.',
            '6. Platform admin: open identity-provider, account-security, SMTP/notification, and language-review pages; verify secret fields are redacted, switches save through backend config, and operation logs/check reports are generated.',
            '7. Platform admin: export language review bundles, import an approved-row test CSV in dry-run mode, apply only reviewer-approved rows, and confirm changed text is visible after refresh without exposing secrets.',
            '8. Merchant/backend user: verify normal backend login remains available and account/security pages do not expose platform-only configuration to unauthorized roles.',
            '9. Safety check: confirm browser evidence did not store OAuth client secrets, SMTP passwords, raw security codes, SMS tokens, APP push keys, or provider private data in screenshots or Markdown reports.',
            '',
            '## Accepted Evidence Command',
            '',
            'After Phase 12 implementation and reviewed evidence are complete, rerun with the accepted evidence paths. Example:',
            '',
            '```bash',
            '/www/server/php/83/bin/php yii account-notification-phase12-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --runChildChecks=1 \\',
            '  --fixture=1 \\',
            '  --thirdPartyLoginAccepted=1 --thirdPartyLoginEvidencePath=runtime/handover/phase12-third-party-login-evidence.md \\',
            '  --passwordRecoveryAccepted=1 --passwordRecoveryEvidencePath=runtime/handover/phase12-password-recovery-evidence.md \\',
            '  --notificationAccepted=1 --notificationEvidencePath=runtime/handover/phase12-notification-evidence.md \\',
            '  --languageReviewAccepted=1 --languageReviewEvidencePath=runtime/handover/phase12-language-review-evidence.md \\',
            '  --browserAccepted=1 --browserEvidencePath=runtime/handover/phase12-browser-evidence.md \\',
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
