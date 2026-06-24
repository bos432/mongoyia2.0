<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PaymentPhase11AcceptanceController extends Controller
{
    public const VERSION = 'MONGOYIA_PAYMENT_PHASE11_ACCEPTANCE_V1';
    public const PROVIDER_AFTERFILL_POLICY_VERSION = 'MONGOYIA_PHASE11_PAYMENT_PROVIDER_AFTERFILL_POLICY_V1';
    public const CHILD_CHECKS_VERSION = 'MONGOYIA_PAYMENT_PHASE11_CHILD_CHECKS_V1';

    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $allowExternalAfterfill = true;
    public $runChildChecks = false;
    public $sandboxAccepted = false;
    public $merchantConfigAccepted = false;
    public $statsAccepted = false;
    public $callbackAuditAccepted = false;
    public $browserAccepted = false;
    public $sandboxEvidencePath = '';
    public $merchantConfigEvidencePath = '';
    public $statsEvidencePath = '';
    public $callbackAuditEvidencePath = '';
    public $browserEvidencePath = '';

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
            'sandboxAccepted',
            'merchantConfigAccepted',
            'statsAccepted',
            'callbackAuditAccepted',
            'browserAccepted',
            'sandboxEvidencePath',
            'merchantConfigEvidencePath',
            'statsEvidencePath',
            'callbackAuditEvidencePath',
            'browserEvidencePath',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia payment Phase 11 acceptance\n");

        $this->checkSourceCoverage();
        $this->checkManualAcceptanceInputs();
        if ($this->runChildChecks) {
            $this->runChildChecks();
        }

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
        $this->section('Phase 11 source coverage');

        $this->requireFileContains('Phase 11 backlog registration', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'Payment and merchant multi-merchant completion',
            'payment-phase11-acceptance/run',
            'merchant-owned encrypted payment configuration',
        ]);
        $this->requireFileContains('Operational payment config center', 'common/services/mall/OperationalPaymentConfigService.php', [
            'MONGOYIA_OPERATIONAL_PAYMENT_CONFIG_CENTER_V1',
            'qpay',
            'lianlian',
            'paypal',
            'saveProvider',
            'runtimeConfig',
            '正式支付启用前必须通过必填配置检测',
        ]);
        $this->requireFileContains('Frontend payment runtime routes', 'frontend/modules/mall/controllers/PaymentController.php', [
            'public function actionQpay',
            'public function actionQpayres',
            'public function actionLianlian',
            'public function actionPaypal',
            'public function actionPaypalReturn',
            'public function actionPaypalCancel',
            'public function actionPaypalWebhook',
        ]);
        $this->requireFileContains('Frontend payment channel selector', 'frontend/modules/mall/controllers/PaymentController.php', [
            'MONGOYIA_PAYMENT_CHANNEL_SELECTOR_V1',
            'MONGOYIA_MERCHANT_PAYMENT_RUNTIME_SCOPE_V1',
            'paymentChannels',
            'paymentProviderConfigForOrder',
            'paymentProviderStoreId',
            'singleOrderStoreId',
            'MerchantPaymentConfigService',
            "'provider' => 'qpay'",
            "'provider' => 'lianlian'",
            "'provider' => 'paypal'",
            'isPaypalConfigReady',
        ]);
        $this->requireFileContains('Frontend payment dynamic channel UI', 'web/resources/mall/default/views/payment/index.php', [
            'data-mongoyia-phase11-payment-channel-list',
            'data-mongoyia-phase11-payment-channel',
            'data-mongoyia-phase11-payment-store-id',
            'data-mongoyia-phase11-payment-no-channel',
            'Pay with {provider}',
        ]);
        $this->requireFileContains('Frontend payment dynamic channel CSS', 'web/resources/mall/default/css/style.css', [
            'MONGOYIA_PAYMENT_CHANNEL_LIST_UI_V1',
            '.payment-channel-list',
            'overflow-wrap: anywhere',
        ]);
        $this->requireFileContains('Payment callback safety guards', 'frontend/modules/mall/controllers/PaymentController.php', [
            'assertPaidAmountMatches',
            'paymentCallbackLockName',
            'PaymentAttempt::RESULT_IGNORED',
            'Duplicate PayPal webhook ignored',
            'Duplicate paid callback ignored',
            'paymentGatewayResponse',
        ]);
        $this->requireFileContains('Backend order refund POST guard', 'backend/modules/mall/controllers/OrderController.php', [
            'MONGOYIA_BACKEND_ORDER_REFUND_POST_GUARD_V1',
            "'edit-status'] = ['post']",
            "post('id', 0)",
            "post('status', null)",
            'markRefunded',
        ]);
        $this->requireFileContains('Backend order refund UI posts CSRF forms', 'backend/modules/mall/views/order/index.php', [
            'data-mongoyia-order-refund-post-guard',
            'csrfToken',
            "Url::to(['edit-status'])",
            'PAYMENT_STATUS_REFUND',
        ]);
        $this->requireFileNotContains('Backend order refund UI has no URL-id status link', 'backend/modules/mall/views/order/index.php', [
            "Html::buttonModal(['edit-status', 'id' =>",
        ]);
        $this->requireFileContains('Payment attempt audit model', 'common/models/mall/PaymentAttempt.php', [
            'mall_payment_attempt',
            'RESULT_PENDING',
            'RESULT_SUCCESS',
            'RESULT_FAILED',
            'RESULT_IGNORED',
            'payload_hash',
            'createForOrder',
        ]);
        $this->requireFileContains('Payment audit backend isolation', 'backend/modules/mall/controllers/PaymentAttemptController.php', [
            'PaymentAttempt',
            'isMallPlatformOperator',
            'getStoreId',
            'getAgentStoreIds',
        ]);
        $this->requireFileContains('Merchant payment config service', 'common/services/mall/MerchantPaymentConfigService.php', [
            'MONGOYIA_MERCHANT_PAYMENT_CONFIG_V1',
            'savePermission',
            'saveProvider',
            '商家正式支付启用需要 Phase 10',
            'merchant_live_enablement_requires_phase10_provider_and_production_evidence',
        ]);
        $this->requireFileContains('Merchant payment backend actions', 'backend/modules/mall/controllers/OperationalConfigController.php', [
            'MONGOYIA_OPERATIONAL_CONFIG_BACKEND_POST_VERB_GUARD_V1',
            'actionMerchantPayment',
            'actionSaveMerchantPaymentPermission',
            'actionSaveMerchantPayment',
            'actionCheckMerchantPayment',
            'merchantPaymentStoreId',
            "'save-merchant-payment-permission'",
            "'save-merchant-payment'",
            "'check-merchant-payment'",
            "['post']",
        ]);
        $this->requireFileContains('Merchant payment backend UI', 'backend/modules/mall/views/operational-config/merchant-payment.php', [
            'data-mongoyia-merchant-payment-config',
            'data-mongoyia-merchant-payment-permission',
            'data-mongoyia-merchant-payment-provider-cards',
            '正式启用被证据门阻断',
        ]);
        $this->requireFileContains('Merchant payment permission migration', 'console/migrations/m260623_160000_mongoyia_merchant_payment_config_permission.php', [
            '/mall/operational-config/merchant-payment*',
            'grantToRoles',
            'clearAllPermission',
        ]);
        $this->requireFileContains('Payment statistics service', 'common/services/mall/PaymentStatisticsService.php', [
            'MONGOYIA_PAYMENT_STATISTICS_V1',
            'dailyRows',
            'providerRows',
            'failureRows',
            'anomalyRows',
            'reconciliationRows',
        ]);
        $this->requireFileContains('Payment statistics backend UI', 'backend/modules/mall/views/payment-stat/index.php', [
            'data-mongoyia-payment-statistics',
            'data-mongoyia-payment-statistics-summary',
            'data-mongoyia-payment-statistics-anomaly',
            'data-mongoyia-payment-statistics-reconciliation',
        ]);
        $this->requireFileContains('Payment statistics readiness command', 'console/controllers/PaymentStatReadinessController.php', [
            'Mongoyia payment statistics readiness',
            'PaymentStatisticsService',
            'payment-stat-readiness',
        ]);
        $this->requireFileContains('Payment callback regression readiness command', 'console/controllers/PaymentCallbackRegressionReadinessController.php', [
            'MONGOYIA_PAYMENT_CALLBACK_REGRESSION_READINESS_V1',
            'Disabled provider/channel guard',
            'Failure status callback guard',
            'Duplicate callback/idempotency guard',
            'Amount mismatch guard',
            'Signature/HMAC guard',
            'Audit row coverage',
        ]);
        $this->requireFileContains('Phase 10 production readiness boundary', 'console/controllers/OperationalConfigPhase10AcceptanceController.php', [
            'Production launch remains `NO-GO`',
            'providerEvidenceAccepted',
            'redactedExportAccepted',
        ]);
        $this->requireFileContains('Phase 11 PayPal runtime supersedes Phase 6 no-go gate', 'console/controllers/PaymentPhase11AcceptanceController.php', [
            'MONGOYIA_PAYPAL_PHASE11_RUNTIME_SUPERSEDES_PHASE6_NOGO_V1',
            'PayPal final read-only go/no-go gate',
        ]);
        $this->requireFileContains('Phase 11 payment provider afterfill policy', 'console/controllers/PaymentPhase11AcceptanceController.php', [
            'MONGOYIA_PHASE11_PAYMENT_PROVIDER_AFTERFILL_POLICY_V1',
            'allowExternalAfterfill',
            'AFTERFILL',
            'Afterfill pending',
        ]);
        $this->requireFileContains('Phase 11 child readiness wiring', 'console/controllers/PaymentPhase11AcceptanceController.php', [
            'MONGOYIA_PAYMENT_PHASE11_CHILD_CHECKS_V1',
            'runChildChecks',
            'childCommands',
            'operational-config-payment-test/run',
            'operational-config-paypal-test/run',
            'mall-payment-test/run',
            'mongoyia-payment-callback-readiness/run',
            'payment-stat-readiness/run',
            'payment-callback-regression-readiness/run',
        ]);
    }

    private function checkManualAcceptanceInputs(): void
    {
        $this->section('Phase 11 implementation and external evidence');
        $this->manualFlag(
            'QPay/LianLian/PayPal sandbox flow acceptance',
            $this->sandboxAccepted,
            $this->sandboxEvidencePath,
            'Sandbox create, return/cancel, webhook/callback, duplicate callback, amount mismatch, and disabled-channel cases were accepted.',
            'Complete QPay, LianLian, and PayPal sandbox provider evidence through backend afterfill before accepting this gate.',
            true
        );
        $this->manualFlag(
            'Merchant encrypted payment configuration acceptance',
            $this->merchantConfigAccepted,
            $this->merchantConfigEvidencePath,
            'Platform-controlled merchant-owned payment configuration was accepted with encrypted sensitive fields and redacted display.',
            'Implement and validate merchant-owned encrypted payment configuration before accepting this gate.'
        );
        $this->manualFlag(
            'Payment statistics acceptance',
            $this->statsAccepted,
            $this->statsEvidencePath,
            'Daily amount, method distribution, failed reason, callback anomaly, and reconciliation-difference statistics were accepted.',
            'Implement and validate payment statistics before accepting this gate.'
        );
        $this->manualFlag(
            'Callback and audit coverage acceptance',
            $this->callbackAuditAccepted,
            $this->callbackAuditEvidencePath,
            'Failed callback, duplicate callback, amount mismatch, signature failure, provider-close, and audit-list review were accepted.',
            'Run callback/audit regression evidence before accepting this gate.'
        );
        $this->manualFlag(
            'Browser role-flow payment acceptance',
            $this->browserAccepted,
            $this->browserEvidencePath !== '' ? $this->browserEvidencePath : $this->baseUrl,
            'Platform admin, merchant, and buyer browser payment role-flow was accepted without obvious page/API errors.',
            'After implementation, validate payment config, buyer checkout, callback audit, merchant isolation, and stats pages in the browser.'
        );
    }

    private function runChildChecks(): void
    {
        $this->section('Phase 11 child readiness commands');
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
            'Operational payment config center' => ['route' => 'operational-config-payment-test/run', 'fixture' => true],
            'PayPal runtime paths' => ['route' => 'operational-config-paypal-test/run', 'fixture' => true],
            'Base mall payment regression' => ['route' => 'mall-payment-test/run', 'fixture' => false],
            'Payment callback readiness' => ['route' => 'mongoyia-payment-callback-readiness/run', 'fixture' => false],
            'Payment statistics readiness' => ['route' => 'payment-stat-readiness/run', 'fixture' => true],
            'Payment callback regression readiness' => ['route' => 'payment-callback-regression-readiness/run', 'fixture' => true],
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
            '# Mongoyia Payment Phase 11 Acceptance',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Afterfill pending: ' . $this->afterfillPending,
            '- Child readiness checks: ' . ($this->runChildChecks ? 'yes' : 'no'),
            '- Scope: QPay, LianLian, PayPal sandbox flow, live enablement guard, merchant encrypted payment configuration, payment statistics, and callback/audit coverage.',
            '- Safety boundary: this acceptance command is read-only and must not enable live payment, call payment providers, mutate orders, mutate funds, or store secrets.',
            '- External afterfill policy: QPay, LianLian, and PayPal sandbox provider material may remain AFTERFILL during development acceptance; live payment stays blocked until accepted evidence exists.',
            '- Production boundary: Phase 10 production readiness remains NO-GO until real provider and operations evidence are accepted.',
            '- MONGOYIA_PAYPAL_PHASE11_RUNTIME_SUPERSEDES_PHASE6_NOGO_V1: Phase 11 PayPal Orders/Webhook runtime acceptance supersedes the old Phase 6 PayPal final read-only go/no-go gate as a child check; production live enablement still remains blocked by Phase 10 evidence and explicit accepted flags.',
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
            '/www/server/php/83/bin/php yii payment-callback-regression-readiness/run --fixture=1 --strict=1 --interactive=0',
            '/www/server/php/83/bin/php yii payment-phase11-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --runChildChecks=1 \\',
            '  --fixture=1 \\',
            '  --allowExternalAfterfill=1 \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
            'MONGOYIA_PHASE10_15_CHILD_DEPLOY_CACHE_REFRESH_V1: pull fast-forward changes, print the deployed commit, flush Yii cache, and restart PHP-FPM before collecting Phase 11 payment browser/callback evidence.',
            '',
            '## Browser Role-Flow Checklist',
            '',
            'Record screenshots, provider sandbox console results, callback/audit reports, statistics reports, or reviewer notes in non-secret evidence files, then pass those paths through the accepted evidence options after review.',
            '',
            '1. Platform admin opens payment configuration, confirms QPay, LianLian, and PayPal test/live readiness and live-enable protection.',
            '2. Platform admin grants or denies merchant independent payment-configuration permission and verifies sensitive values are encrypted and redacted.',
            '3. Merchant with permission saves test payment configuration, runs checks, and cannot view raw secrets after save.',
            '4. Buyer creates an order and sees only enabled payment channels; disabled channels are unavailable.',
            '5. Buyer completes at least one sandbox payment path, plus cancel/failure paths where provider evidence is available.',
            '6. Platform admin reviews callback/payment audit rows for success, duplicate, failure, signature error, amount mismatch, and disabled-channel cases.',
            '7. Platform admin opens payment statistics and verifies daily amount, payment-method distribution, failure reason, callback anomaly, and reconciliation-difference rows.',
            '8. Merchant opens scoped payment audit/statistics pages and cannot see another store payment data.',
            '9. Safety check: confirm evidence did not enable live payment, store provider secrets in reports, alter funds outside provider sandbox/test records, or bypass Phase 10 production NO-GO gates.',
            '',
            '## Accepted Evidence Command',
            '',
            'After Phase 11 implementation and external evidence are reviewed, rerun with accepted evidence paths. Example:',
            '',
            '```bash',
            '/www/server/php/83/bin/php yii payment-phase11-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --runChildChecks=1 \\',
            '  --fixture=1 \\',
            '  --allowExternalAfterfill=1 \\',
            '  --sandboxAccepted=1 --sandboxEvidencePath=runtime/handover/phase11-sandbox-payment-evidence.md \\',
            '  --merchantConfigAccepted=1 --merchantConfigEvidencePath=runtime/handover/phase11-merchant-payment-config-evidence.md \\',
            '  --statsAccepted=1 --statsEvidencePath=runtime/handover/phase11-payment-statistics-evidence.md \\',
            '  --callbackAuditAccepted=1 --callbackAuditEvidencePath=runtime/handover/phase11-callback-audit-evidence.md \\',
            '  --browserAccepted=1 --browserEvidencePath=runtime/handover/phase11-browser-evidence.md \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function manualFlag(string $area, bool $accepted, string $evidence, string $passNotes, string $pendingNotes, bool $externalAfterfill = false): void
    {
        if ($accepted) {
            $this->addCheck($area, 'PASS', $evidence !== '' ? $evidence : 'external evidence recorded', $passNotes);
            return;
        }

        if ($externalAfterfill && $this->allowExternalAfterfill) {
            $this->addCheck($area, 'AFTERFILL', $evidence !== '' ? $evidence : 'backend afterfill pending', $pendingNotes);
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

        $this->addCheck($label, 'PASS', $path, 'Required Phase 11 markers are present.');
    }

    private function requireFileNotContains(string $label, string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                $this->addCheck($label, 'FAIL', $path, "Forbidden marker {$needle} is still present.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Forbidden Phase 11 markers are absent.');
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

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-payment-phase11-acceptance-' . date('Ymd-His') . '.md';
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
