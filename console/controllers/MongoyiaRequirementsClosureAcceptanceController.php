<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaRequirementsClosureAcceptanceController extends Controller
{
    public const VERSION = 'MONGOYIA_REQUIREMENTS_PHASE10_15_ACCEPTANCE_V1';
    public const EXTERNAL_AFTERFILL_POLICY_VERSION = 'MONGOYIA_PHASE10_15_EXTERNAL_AFTERFILL_POLICY_V1';

    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $allowExternalAfterfill = true;
    public $runChildChecks = false;
    public $phase10BrowserAccepted = false;
    public $phase10ProviderEvidenceAccepted = false;
    public $phase10ProductionEvidenceAccepted = false;
    public $phase10RedactedExportAccepted = false;
    public $phase10BrowserEvidencePath = '';
    public $phase10ProviderEvidencePath = '';
    public $phase10ProductionEvidencePath = '';
    public $phase10RedactedExportPath = '';
    public $phase11SandboxAccepted = false;
    public $phase11MerchantConfigAccepted = false;
    public $phase11StatsAccepted = false;
    public $phase11CallbackAuditAccepted = false;
    public $phase11BrowserAccepted = false;
    public $phase11SandboxEvidencePath = '';
    public $phase11MerchantConfigEvidencePath = '';
    public $phase11StatsEvidencePath = '';
    public $phase11CallbackAuditEvidencePath = '';
    public $phase11BrowserEvidencePath = '';
    public $phase12ThirdPartyLoginAccepted = false;
    public $phase12PasswordRecoveryAccepted = false;
    public $phase12NotificationAccepted = false;
    public $phase12LanguageReviewAccepted = false;
    public $phase12BrowserAccepted = false;
    public $phase12ThirdPartyLoginEvidencePath = '';
    public $phase12PasswordRecoveryEvidencePath = '';
    public $phase12NotificationEvidencePath = '';
    public $phase12LanguageReviewEvidencePath = '';
    public $phase12BrowserEvidencePath = '';
    public $phase13BuyerApiAccepted = false;
    public $phase13SellerApiAccepted = false;
    public $phase13BrowserAccepted = false;
    public $phase13AppAccepted = false;
    public $phase13BuyerEvidencePath = '';
    public $phase13SellerEvidencePath = '';
    public $phase13BrowserEvidencePath = '';
    public $phase13AppEvidencePath = '';
    public $phase14ProviderAdapterAccepted = false;
    public $phase14TrackingSyncAccepted = false;
    public $phase14SkuInventoryAccepted = false;
    public $phase14SearchVideoAccepted = false;
    public $phase14FavoriteReviewAccepted = false;
    public $phase14BrowserAccepted = false;
    public $phase14ProviderEvidencePath = '';
    public $phase14TrackingEvidencePath = '';
    public $phase14SkuInventoryEvidencePath = '';
    public $phase14SearchVideoEvidencePath = '';
    public $phase14FavoriteReviewEvidencePath = '';
    public $phase14BrowserEvidencePath = '';
    public $phase15TrainingAccepted = false;
    public $phase15PromotionAccepted = false;
    public $phase15DownloadTrackingAccepted = false;
    public $phase15PayoutSignoffAccepted = false;
    public $phase15BrowserAccepted = false;
    public $phase15TrainingEvidencePath = '';
    public $phase15PromotionEvidencePath = '';
    public $phase15DownloadTrackingEvidencePath = '';
    public $phase15PayoutSignoffEvidencePath = '';
    public $phase15BrowserEvidencePath = '';

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;
    private $afterfillPending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
            'allowExternalAfterfill',
            'runChildChecks',
            'phase10BrowserAccepted',
            'phase10ProviderEvidenceAccepted',
            'phase10ProductionEvidenceAccepted',
            'phase10RedactedExportAccepted',
            'phase10BrowserEvidencePath',
            'phase10ProviderEvidencePath',
            'phase10ProductionEvidencePath',
            'phase10RedactedExportPath',
            'phase11SandboxAccepted',
            'phase11MerchantConfigAccepted',
            'phase11StatsAccepted',
            'phase11CallbackAuditAccepted',
            'phase11BrowserAccepted',
            'phase11SandboxEvidencePath',
            'phase11MerchantConfigEvidencePath',
            'phase11StatsEvidencePath',
            'phase11CallbackAuditEvidencePath',
            'phase11BrowserEvidencePath',
            'phase12ThirdPartyLoginAccepted',
            'phase12PasswordRecoveryAccepted',
            'phase12NotificationAccepted',
            'phase12LanguageReviewAccepted',
            'phase12BrowserAccepted',
            'phase12ThirdPartyLoginEvidencePath',
            'phase12PasswordRecoveryEvidencePath',
            'phase12NotificationEvidencePath',
            'phase12LanguageReviewEvidencePath',
            'phase12BrowserEvidencePath',
            'phase13BuyerApiAccepted',
            'phase13SellerApiAccepted',
            'phase13BrowserAccepted',
            'phase13AppAccepted',
            'phase13BuyerEvidencePath',
            'phase13SellerEvidencePath',
            'phase13BrowserEvidencePath',
            'phase13AppEvidencePath',
            'phase14ProviderAdapterAccepted',
            'phase14TrackingSyncAccepted',
            'phase14SkuInventoryAccepted',
            'phase14SearchVideoAccepted',
            'phase14FavoriteReviewAccepted',
            'phase14BrowserAccepted',
            'phase14ProviderEvidencePath',
            'phase14TrackingEvidencePath',
            'phase14SkuInventoryEvidencePath',
            'phase14SearchVideoEvidencePath',
            'phase14FavoriteReviewEvidencePath',
            'phase14BrowserEvidencePath',
            'phase15TrainingAccepted',
            'phase15PromotionAccepted',
            'phase15DownloadTrackingAccepted',
            'phase15PayoutSignoffAccepted',
            'phase15BrowserAccepted',
            'phase15TrainingEvidencePath',
            'phase15PromotionEvidencePath',
            'phase15DownloadTrackingEvidencePath',
            'phase15PayoutSignoffEvidencePath',
            'phase15BrowserEvidencePath',
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
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending, {$this->afterfillPending} afterfill pending.\n");

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
            $needles = array_merge([
                $config['version'],
                $config['route'],
                'Pending',
                'MONGOYIA_PHASE10_15_CHILD_DEPLOY_CACHE_REFRESH_V1',
            ], $config['requiredMarkers'] ?? []);
            $this->requireFileContains($phase . ' acceptance command', $config['file'], $needles);
        }
        $this->requireFileContains('Phase 10-15 external afterfill policy', 'console/controllers/MongoyiaRequirementsClosureAcceptanceController.php', [
            'MONGOYIA_PHASE10_15_EXTERNAL_AFTERFILL_POLICY_V1',
            'allowExternalAfterfill',
            'Afterfill pending',
            'AFTERFILL',
        ]);
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
            if (!empty($config['allowExternalAfterfill'])) {
                $params['allowExternalAfterfill'] = $this->allowExternalAfterfill ? 1 : 0;
            }
            foreach (($config['passthrough'] ?? []) as $localName => $childName) {
                $value = $this->{$localName};
                if ($value === '' || $value === false || $value === null) {
                    continue;
                }
                $params[$childName] = $value;
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

            $status = $summary['failures'] > 0 ? 'FAIL' : (($summary['warnings'] > 0 || $summary['pending'] > 0) ? 'PENDING' : (($summary['afterfillPending'] ?? 0) > 0 ? 'AFTERFILL' : 'PASS'));
            $notes = 'Child result=' . $summary['result']
                . ', failures=' . $summary['failures']
                . ', warnings=' . $summary['warnings']
                . ', pending=' . $summary['pending']
                . ', afterfillPending=' . ($summary['afterfillPending'] ?? 0)
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
                'allowExternalAfterfill' => true,
                'passthrough' => [
                    'phase10BrowserAccepted' => 'browserAccepted',
                    'phase10ProviderEvidenceAccepted' => 'providerEvidenceAccepted',
                    'phase10ProductionEvidenceAccepted' => 'productionEvidenceAccepted',
                    'phase10RedactedExportAccepted' => 'redactedExportAccepted',
                    'phase10BrowserEvidencePath' => 'browserEvidencePath',
                    'phase10ProviderEvidencePath' => 'providerEvidencePath',
                    'phase10ProductionEvidencePath' => 'productionEvidencePath',
                    'phase10RedactedExportPath' => 'redactedExportPath',
                ],
            ],
            'Phase 11 payment and merchant payment' => [
                'slug' => 'phase11-payment',
                'route' => 'payment-phase11-acceptance/run',
                'file' => 'console/controllers/PaymentPhase11AcceptanceController.php',
                'version' => 'MONGOYIA_PAYMENT_PHASE11_ACCEPTANCE_V1',
                'baseUrl' => true,
                'fixture' => true,
                'runChildChecks' => true,
                'allowExternalAfterfill' => true,
                'requiredMarkers' => [
                    'MONGOYIA_PHASE11_PAYMENT_PROVIDER_AFTERFILL_POLICY_V1',
                    'Afterfill pending',
                ],
                'passthrough' => [
                    'phase11SandboxAccepted' => 'sandboxAccepted',
                    'phase11MerchantConfigAccepted' => 'merchantConfigAccepted',
                    'phase11StatsAccepted' => 'statsAccepted',
                    'phase11CallbackAuditAccepted' => 'callbackAuditAccepted',
                    'phase11BrowserAccepted' => 'browserAccepted',
                    'phase11SandboxEvidencePath' => 'sandboxEvidencePath',
                    'phase11MerchantConfigEvidencePath' => 'merchantConfigEvidencePath',
                    'phase11StatsEvidencePath' => 'statsEvidencePath',
                    'phase11CallbackAuditEvidencePath' => 'callbackAuditEvidencePath',
                    'phase11BrowserEvidencePath' => 'browserEvidencePath',
                ],
            ],
            'Phase 12 account notification language' => [
                'slug' => 'phase12-account-notification',
                'route' => 'account-notification-phase12-acceptance/run',
                'file' => 'console/controllers/AccountNotificationPhase12AcceptanceController.php',
                'version' => 'AccountNotificationPhase12AcceptanceController',
                'baseUrl' => true,
                'fixture' => true,
                'runChildChecks' => true,
                'allowExternalAfterfill' => true,
                'requiredMarkers' => [
                    'MONGOYIA_PHASE12_ACCOUNT_PROVIDER_AFTERFILL_POLICY_V1',
                    'Afterfill pending',
                ],
                'passthrough' => [
                    'phase12ThirdPartyLoginAccepted' => 'thirdPartyLoginAccepted',
                    'phase12PasswordRecoveryAccepted' => 'passwordRecoveryAccepted',
                    'phase12NotificationAccepted' => 'notificationAccepted',
                    'phase12LanguageReviewAccepted' => 'languageReviewAccepted',
                    'phase12BrowserAccepted' => 'browserAccepted',
                    'phase12ThirdPartyLoginEvidencePath' => 'thirdPartyLoginEvidencePath',
                    'phase12PasswordRecoveryEvidencePath' => 'passwordRecoveryEvidencePath',
                    'phase12NotificationEvidencePath' => 'notificationEvidencePath',
                    'phase12LanguageReviewEvidencePath' => 'languageReviewEvidencePath',
                    'phase12BrowserEvidencePath' => 'browserEvidencePath',
                ],
            ],
            'Phase 13 buyer seller APP' => [
                'slug' => 'phase13-app',
                'route' => 'app-phase13-acceptance/run',
                'file' => 'console/controllers/AppPhase13AcceptanceController.php',
                'version' => 'MONGOYIA_APP_PHASE13_ACCEPTANCE_V1',
                'baseUrl' => true,
                'fixture' => true,
                'runChildChecks' => true,
                'requiredMarkers' => [
                    'MONGOYIA_APP_PHASE13_CHILD_CHECKS_V1',
                    'runChildChecks',
                    'MONGOYIA_PHASE13_DEPLOYED_ASSET_FRESHNESS_V1',
                    'checkDeployedAssetFreshness',
                    'MONGOYIA_CART_LINK_NORMALIZER_V1',
                    'MONGOYIA_CART_CHECKOUT_URL_PARAMS_V1',
                    'MONGOYIA_CART_AJAX_POST_GUARD_V1',
                    'MONGOYIA_CART_CHECKOUT_POST_COUPON_GUARD_V1',
                    'MONGOYIA_PHASE13_DEPLOYED_PRODUCT_CART_LINKS_V1',
                    'checkDeployedProductCartLinks',
                    'MONGOYIA_PHASE13_DEPLOYED_CART_ROUTE_V1',
                    'checkDeployedCartRoute',
                ],
                'passthrough' => [
                    'phase13BuyerApiAccepted' => 'buyerApiAccepted',
                    'phase13SellerApiAccepted' => 'sellerApiAccepted',
                    'phase13BrowserAccepted' => 'browserAccepted',
                    'phase13AppAccepted' => 'appAccepted',
                    'phase13BuyerEvidencePath' => 'buyerEvidencePath',
                    'phase13SellerEvidencePath' => 'sellerEvidencePath',
                    'phase13BrowserEvidencePath' => 'browserEvidencePath',
                    'phase13AppEvidencePath' => 'appEvidencePath',
                ],
            ],
            'Phase 14 logistics product favorite review' => [
                'slug' => 'phase14-logistics-product',
                'route' => 'logistics-product-phase14-acceptance/run',
                'file' => 'console/controllers/LogisticsProductPhase14AcceptanceController.php',
                'version' => 'MONGOYIA_LOGISTICS_PRODUCT_PHASE14_ACCEPTANCE_V1',
                'baseUrl' => false,
                'fixture' => true,
                'runChildChecks' => true,
                'allowExternalAfterfill' => true,
                'requiredMarkers' => [
                    'MONGOYIA_LOGISTICS_PRODUCT_PHASE14_CHILD_CHECKS_V1',
                    'runChildChecks',
                    'MONGOYIA_PHASE14_LOGISTICS_PROVIDER_AFTERFILL_POLICY_V1',
                    'Afterfill pending',
                ],
                'passthrough' => [
                    'phase14ProviderAdapterAccepted' => 'providerAdapterAccepted',
                    'phase14TrackingSyncAccepted' => 'trackingSyncAccepted',
                    'phase14SkuInventoryAccepted' => 'skuInventoryAccepted',
                    'phase14SearchVideoAccepted' => 'searchVideoAccepted',
                    'phase14FavoriteReviewAccepted' => 'favoriteReviewAccepted',
                    'phase14BrowserAccepted' => 'browserAccepted',
                    'phase14ProviderEvidencePath' => 'providerEvidencePath',
                    'phase14TrackingEvidencePath' => 'trackingEvidencePath',
                    'phase14SkuInventoryEvidencePath' => 'skuInventoryEvidencePath',
                    'phase14SearchVideoEvidencePath' => 'searchVideoEvidencePath',
                    'phase14FavoriteReviewEvidencePath' => 'favoriteReviewEvidencePath',
                    'phase14BrowserEvidencePath' => 'browserEvidencePath',
                ],
            ],
            'Phase 15 distributor support' => [
                'slug' => 'phase15-distributor-support',
                'route' => 'distribution-support-phase15-acceptance/run',
                'file' => 'console/controllers/DistributionSupportPhase15AcceptanceController.php',
                'version' => 'MONGOYIA_DISTRIBUTION_SUPPORT_PHASE15_ACCEPTANCE_V1',
                'baseUrl' => false,
                'fixture' => true,
                'runChildChecks' => true,
                'requiredMarkers' => [
                    'MONGOYIA_DISTRIBUTION_SUPPORT_PHASE15_CHILD_CHECKS_V1',
                    'runChildChecks',
                ],
                'passthrough' => [
                    'phase15TrainingAccepted' => 'trainingAccepted',
                    'phase15PromotionAccepted' => 'promotionAccepted',
                    'phase15DownloadTrackingAccepted' => 'downloadTrackingAccepted',
                    'phase15PayoutSignoffAccepted' => 'payoutSignoffAccepted',
                    'phase15BrowserAccepted' => 'browserAccepted',
                    'phase15TrainingEvidencePath' => 'trainingEvidencePath',
                    'phase15PromotionEvidencePath' => 'promotionEvidencePath',
                    'phase15DownloadTrackingEvidencePath' => 'downloadTrackingEvidencePath',
                    'phase15PayoutSignoffEvidencePath' => 'payoutSignoffEvidencePath',
                    'phase15BrowserEvidencePath' => 'browserEvidencePath',
                ],
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
        $afterfillPending = $this->matchReportValue($content, 'Afterfill pending');

        if ($result === null || $failures === null || $warnings === null || $pending === null) {
            return null;
        }

        return [
            'result' => $result,
            'failures' => (int)$failures,
            'warnings' => (int)$warnings,
            'pending' => (int)$pending,
            'afterfillPending' => $afterfillPending === null ? 0 : (int)$afterfillPending,
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
            '- External afterfill policy: ' . ($this->allowExternalAfterfill ? 'enabled' : 'disabled'),
            '- Evidence flag passthrough: supported for Phase 10 through Phase 15 child acceptance commands.',
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Afterfill pending: ' . $this->afterfillPending,
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
            'git pull --ff-only',
            'git rev-parse --short HEAD',
            '/www/server/php/83/bin/php yii migrate/up --interactive=0',
            '/www/server/php/83/bin/php yii cache/flush-all --interactive=0',
            '/etc/init.d/php-fpm-83 restart',
            '/www/server/php/83/bin/php yii mongoyia-requirements-closure-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --fixture=1 \\',
            '  --runChildChecks=1 \\',
            '  --allowExternalAfterfill=1 \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
            'This aggregate gate is read-only. It does not store provider secrets, call real payment/logistics/social providers by itself, change payment state, create withdrawals, approve reviews, or switch production traffic.',
            'MONGOYIA_PHASE10_15_DEPLOY_CACHE_REFRESH_V1: the BaoTa verification command pulls only fast-forward changes, prints the deployed commit, flushes Yii cache, and restarts PHP-FPM before browser-facing acceptance probes so stale PHP/opcache/page output cannot be mistaken for a business-flow failure.',
            '',
            '## Accepted Evidence Passthrough',
            '',
            'After browser, provider, APP package, logistics, language review, and distributor evidence is collected, pass the accepted flags to this aggregate command with phase-prefixed options such as `--phase10BrowserAccepted=1`, `--phase11SandboxAccepted=1`, `--phase12ThirdPartyLoginAccepted=1`, `--phase13AppAccepted=1`, `--phase14BrowserAccepted=1`, and `--phase15TrainingAccepted=1`.',
            'Use matching `*EvidencePath` options for Markdown reports, tickets, or signed evidence references. Never pass raw secrets, provider credentials, callback payloads, private keys, SMTP passwords, Basic Auth values, or HMAC secrets as evidence-path values.',
            '',
            '## Acceptance Boundary',
            '',
            '- PASS means Phase 10-15 source coverage and accepted evidence gates are complete.',
            '- PENDING means code coverage exists but real browser/provider/production evidence still needs to be collected and accepted.',
            '- AFTERFILL means external provider or production operations material is intentionally left for backend afterfill and does not block development acceptance.',
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
        } elseif ($status === 'AFTERFILL') {
            $this->afterfillPending++;
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
        if ($this->warnings > 0 || $this->pending > 0 || $this->afterfillPending > 0) {
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
