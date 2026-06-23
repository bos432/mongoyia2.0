<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServicePhase9AcceptanceController extends Controller
{
    public $baseUrl = 'https://demo2026.mongoyia.com';
    public $productId = 0;
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $runChildChecks = false;
    public $browserAccepted = false;
    public $appAccepted = false;
    public $browserEvidencePath = '';
    public $appEvidencePath = '';

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'productId',
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
            'runChildChecks',
            'browserAccepted',
            'appAccepted',
            'browserEvidencePath',
            'appEvidencePath',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia customer-service Phase 9 acceptance\n");

        $this->checkSourceCoverage();
        $this->checkManualAcceptanceInputs();
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
        $this->section('Phase 9 source coverage');

        $this->requireFileContains('Phase 9.1 translation service', 'common/services/mall/CustomerServiceTranslationService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_TRANSLATION_V1',
            'openai_compatible',
            'google_compatible',
            'messageMetadata',
            'provider_test',
        ]);
        $this->requireFileContains('Phase 9.1 translation runtime', 'frontend/modules/mall/controllers/ChatController.php', [
            'CustomerServiceTranslationService',
            'actionTranslate',
            'messageMetadata',
        ]);
        $this->requireFileContains('Phase 9.1 backend translation runtime', 'backend/modules/mall/controllers/KfController.php', [
            'CustomerServiceTranslationService',
            'actionTranslate',
            'messageMetadata',
        ]);
        $this->requireFileContains('Phase 9.1 translation config', 'backend/modules/mall/controllers/OperationalConfigController.php', [
            'actionSaveTranslation',
            'actionCheckTranslation',
            'actionTestTranslation',
        ]);
        $this->requireFileContains('Phase 9.1 translation migration', 'console/migrations/m260623_090100_mongoyia_customer_service_translation.php', [
            'original_content',
            'translated_content',
            'translation_status',
        ]);

        $this->requireFileContains('Phase 9.2 media service', 'common/services/mall/CustomerServiceMediaService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_MEDIA_V1',
            'validateUploadCandidate',
            'runtime/mongoyia-im-media',
            'viewFile',
        ]);
        $this->requireFileContains('Phase 9.2 frontend media runtime', 'frontend/modules/mall/controllers/ChatController.php', [
            'actionMediaUpload',
            'actionMediaView',
            'media_id',
        ]);
        $this->requireFileContains('Phase 9.2 backend media runtime', 'backend/modules/mall/controllers/KfController.php', [
            'actionMediaUpload',
            'actionMediaView',
            'media_id',
        ]);
        $this->requireFileContains('Phase 9.2 Python IM guard', 'deploy/im-backend/main.py', [
            'normalized_type not in (1, 2, 3, 4, 5)',
            '/mall/chat/media-view?',
            'media_preview_label',
        ]);
        $this->requireFileContains('Phase 9.2 PC/H5 media UI', 'web/resources/mall/default/views/chat/index.php', [
            'fileBtn',
            'videoBtn',
            'voiceBtn',
            'sendMedia',
            'MediaRecorder',
        ]);
        $this->requireFileContains('Phase 9.2 backend media UI', 'backend/modules/mall/views/kf/index.php', [
            'fileBtn',
            'videoBtn',
            'voiceBtn',
            'sendMedia',
            'MediaRecorder',
        ]);

        $this->requireFileContains('Phase 9.3 assistance service', 'common/services/mall/CustomerServiceAssistanceService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_ASSISTANCE_V1',
            'assistance_creates_ticket_only',
            'refund_approval',
            'compensation_approval',
        ]);
        $this->requireFileContains('Phase 9.3 assistance APIs', 'backend/modules/mall/controllers/KfController.php', [
            'actionAssistanceSearch',
            'actionAssistanceDetail',
            'actionAssistanceRequest',
        ]);
        $this->requireFileContains('Phase 9.3 assistance UI', 'backend/modules/mall/views/kf/index.php', [
            'data-mongoyia-customer-service-assistance="search"',
            'assistanceSearchUrl',
            'assistanceDetailUrl',
            'assistanceRequestUrl',
        ]);
        $this->requireFileContains('Phase 9.3 assistance permission', 'console/migrations/m260623_093000_mongoyia_customer_service_assistance_permission.php', [
            '/mall/kf/assistance-search',
            '/mall/kf/assistance-detail',
            '/mall/kf/assistance-request',
        ]);

        $this->requireFileContains('Phase 9.4 complaint loop service', 'common/services/mall/CustomerServiceComplaintLoopService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_LOOP_V1',
            'product_quality',
            'seller_proof_is_evidence_only',
            'platform_review_is_status_and_result_only',
        ]);
        $this->requireFileContains('Phase 9.4 complaint loop APIs', 'backend/modules/mall/controllers/KfController.php', [
            'actionComplaintLoopStep',
            'actionComplaintLinkAssistance',
        ]);
        $this->requireFileContains('Phase 9.4 complaint loop UI', 'backend/modules/mall/views/kf/ticket-view.php', [
            'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_LOOP_BACKEND_V1',
            'complaint-loop-step',
            'complaint-link-assistance',
        ]);
        $this->requireFileContains('Phase 9.4 complaint loop permission', 'console/migrations/m260623_094000_mongoyia_customer_service_complaint_loop_permission.php', [
            '/mall/kf/complaint-loop-step',
            '/mall/kf/complaint-link-assistance',
        ]);

        $this->requireFileContains('Phase 9.5 analytics service', 'common/services/mall/CustomerServiceAnalyticsService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_ANALYTICS_V1',
            'translation_failure_rate',
            'media_send_failure_rate',
            'aggregationPlan',
            'alertSignals',
        ]);
        $this->requireFileContains('Phase 9.5 analytics APIs', 'backend/modules/mall/controllers/KfController.php', [
            'actionAnalytics',
            'actionAnalyticsExport',
        ]);
        $this->requireFileContains('Phase 9.5 analytics UI', 'backend/modules/mall/views/kf/analytics.php', [
            'MONGOYIA_CUSTOMER_SERVICE_ANALYTICS_V1',
            'data-mongoyia-customer-service-analytics',
            'data-mongoyia-customer-service-analytics-export',
        ]);
        $this->requireFileContains('Phase 9.5 analytics permission', 'console/migrations/m260623_095000_mongoyia_customer_service_analytics_permission.php', [
            '/mall/kf/analytics',
            '/mall/kf/analytics-export',
        ]);

        $this->requireFileContains('Phase 9.6 uni-app package', 'apps/mongoyia-customer-chat-uniapp/package.json', [
            'dev:h5',
            '@dcloudio/uni-app',
        ]);
        $this->requireFileContains('Phase 9.6 uni-app chat page', 'apps/mongoyia-customer-chat-uniapp/pages/chat/index.vue', [
            'MONGOYIA_CUSTOMER_SERVICE_UNIAPP_CHAT_V1',
            'uni.connectSocket',
            'uploadAndSend',
            'uploadMedia',
            '/mall/chat/rating-submit',
        ]);
        $this->requireFileContains('Phase 9.6 token handoff', 'frontend/modules/mall/controllers/ChatController.php', [
            "'uid' => (int)\$product['user_id']",
            "'product_id' => \$gid",
            "'store_id' => (int)\$product['store_id']",
        ]);
    }

    private function checkManualAcceptanceInputs(): void
    {
        $this->section('Manual browser/app acceptance evidence');
        if ($this->browserAccepted) {
            $this->addCheck(
                'Browser role-flow acceptance',
                'PASS',
                $this->browserEvidencePath !== '' ? $this->browserEvidencePath : $this->baseUrl,
                'Buyer PC/H5, merchant service, platform service, translation, media, tickets, complaints, analytics, and refresh persistence were accepted externally.'
            );
        } else {
            $this->addCheck(
                'Browser role-flow acceptance',
                'PENDING',
                $this->baseUrl,
                'Run the browser checklist in the generated report and rerun with --browserAccepted=1 plus --browserEvidencePath=<report-or-screenshot-dir>.'
            );
        }

        if ($this->appAccepted) {
            $this->addCheck(
                'uni-app customer chat acceptance',
                'PASS',
                $this->appEvidencePath !== '' ? $this->appEvidencePath : 'apps/mongoyia-customer-chat-uniapp',
                'APP/H5 development client chat, WSS, media, translation display, and rating flow were accepted externally.'
            );
        } else {
            $this->addCheck(
                'uni-app customer chat acceptance',
                'PENDING',
                'apps/mongoyia-customer-chat-uniapp',
                'Run npm install and npm run dev:h5 or HBuilderX validation, then rerun with --appAccepted=1 plus --appEvidencePath=<report-or-screenshot-dir>.'
            );
        }
    }

    private function runChildChecks(): void
    {
        $this->section('Phase 9 child readiness commands');
        foreach ($this->childCommands() as $label => $route) {
            $params = ['interactive' => 0];
            if ($this->fixture) {
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
            'Phase 9.1 translation readiness' => 'customer-service-translation-test/run',
            'Phase 9.2 full-media readiness' => 'customer-service-media-test/run',
            'Phase 9.3 assistance readiness' => 'customer-service-assistance-test/run',
            'Phase 9.4 complaint-loop readiness' => 'customer-service-complaint-loop-test/run',
            'Phase 9.5 analytics readiness' => 'customer-service-analytics-test/run',
            'Phase 9.6 uni-app readiness' => 'customer-service-uniapp-test/run',
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
            '# Mongoyia Customer-Service Phase 9 Acceptance',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Product ID: ' . (int)$this->productId,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Evidence type: source coverage plus manual browser/app acceptance checklist.',
            '- Safety boundary: customer-service staff may create assistance, complaint, and approval workflow records but must not directly mutate orders, payments, funds, stock, refunds, settlement rows, or inventory.',
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
            '/www/server/php/83/bin/php yii customer-service-phase9-acceptance/run \\',
            '  --baseUrl=https://demo2026.mongoyia.com \\',
            '  --productId=<real_product_id> \\',
            '  --runChildChecks=1 \\',
            '  --fixture=1 \\',
            '  --strict=1 \\',
            '  --interactive=0',
            '```',
            '',
            'After browser and APP validation pass, rerun the same command with `--browserAccepted=1 --browserEvidencePath=<path>` and `--appAccepted=1 --appEvidencePath=<path>`.',
            '',
            '## Manual Browser Checklist',
            '',
            '1. Buyer PC/H5 opens a real product chat page, obtains a token, connects WSS, sends text, image, file, video, and voice messages.',
            '2. Buyer verifies translation display for zh-CN/en/mn and confirms failed translation still shows the original message without blocking send.',
            '3. Merchant service logs into backend, opens `/backend/mall/kf/index`, sees only own-store sessions, replies with text/media, inserts a quick reply, and refreshes to confirm persistence.',
            '4. Merchant service searches order/product context, opens details, and creates approval-only assistance requests for payment/logistics/refund or compensation advice.',
            '5. Platform service logs into backend, verifies cross-store session visibility, opens a complaint ticket, records category, user/service/seller/platform proof, conclusion, user feedback, and linked assistance.',
            '6. Platform service opens `/backend/mall/kf/analytics`, filters by store/language/channel/media/ticket/complaint, exports CSV, and confirms no fatal page or API errors.',
            '7. Refresh buyer chat, merchant workbench, ticket detail, and analytics pages; verify messages, media metadata, tickets, complaint loop data, ratings, and statistics remain reasonable.',
            '',
            '## Manual uni-app Checklist',
            '',
            '1. Run `npm install` and `npm run dev:h5` in `apps/mongoyia-customer-chat-uniapp`, or open the project with HBuilderX.',
            '2. Configure base HTTPS URL and WSS URL for the test server.',
            '3. Enter a real product ID and customer identity, fetch `/mall/chat/token`, connect WSS, and load history.',
            '4. Send text, image, file, video, and voice messages; preview or play returned media; verify translation display.',
            '5. Submit satisfaction rating, refresh the client, and confirm history/rating state is still reasonable.',
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

        $this->addCheck($label, 'PASS', $path, 'Required Phase 9 markers are present.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-phase9-acceptance-' . date('Ymd-His') . '.md';
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
