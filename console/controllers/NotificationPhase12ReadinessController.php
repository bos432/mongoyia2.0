<?php

namespace console\controllers;

use common\services\mall\OperationalNotificationService;
use yii\console\Controller;
use yii\console\ExitCode;

class NotificationPhase12ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_NOTIFICATION_PHASE12_READINESS_V1';

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
        $this->stdout("Mongoyia notification Phase 12 readiness\n");

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
        $this->requireFileContains('Notification service foundation', 'common/services/mall/OperationalNotificationService.php', [
            'MONGOYIA_OPERATIONAL_NOTIFICATION_V1',
            'EVENT_ORDER_STATUS',
            'EVENT_LOGISTICS_STATUS',
            'EVENT_PAYMENT_RESULT',
            'EVENT_CUSTOMER_SERVICE_REPLY',
            'EVENT_COMPLAINT_RESULT',
            'CHANNEL_APP_RESERVED',
            'notifyComplaintResult',
            'dispatch',
        ]);
        $this->requireFileContains('Notification log backend controller', 'backend/modules/mall/controllers/NotificationLogController.php', [
            'OperationalNotificationService',
            'actionIndex',
            'requestedStoreId',
            'ForbiddenHttpException',
        ]);
        $this->requireFileContains('Notification log backend page', 'backend/modules/mall/views/notification-log/index.php', [
            'data-mongoyia-notification-log',
            'data-mongoyia-notification-log-summary',
            'data-mongoyia-notification-log-recent',
            '通知发送日志',
        ]);
        $this->requireFileContains('Operational center notification log entry', 'backend/modules/mall/views/operational-config/index.php', [
            '通知日志',
            'data-mongoyia-notification-log-entry',
        ]);
        $this->requireFileContains('Notification send-log migration', 'console/migrations/m260623_164000_mongoyia_notification_send_log.php', [
            'mall_notification_send_log',
            'event_key',
            'delivery_status',
            '/mall/notification-log/index',
            'grantToRoles',
        ]);
    }

    private function checkFixtureMatrix(): void
    {
        $this->section('Fixture matrix');
        try {
            $service = new OperationalNotificationService();
            $events = $service->eventDefinitions();
            foreach ([
                OperationalNotificationService::EVENT_ORDER_STATUS,
                OperationalNotificationService::EVENT_LOGISTICS_STATUS,
                OperationalNotificationService::EVENT_PAYMENT_RESULT,
                OperationalNotificationService::EVENT_CUSTOMER_SERVICE_REPLY,
                OperationalNotificationService::EVENT_COMPLAINT_RESULT,
            ] as $event) {
                if (empty($events[$event])) {
                    $this->fail("Notification event definition missing: {$event}.");
                    continue;
                }
                if (empty($events[$event]['default_channels'])) {
                    $this->fail("Notification event {$event} has no default channels.");
                    continue;
                }
                $this->ok("Notification event {$event} definition is ready.");
            }

            $dryRun = $service->notifyOrderStatus(1, [
                'order_id' => 1,
                'order_sn' => 'PHASE12-NOTIFY-DRY-RUN',
                'status_label' => 'dry-run',
            ], [
                'dry_run' => true,
                'channels' => [
                    OperationalNotificationService::CHANNEL_SITE,
                    OperationalNotificationService::CHANNEL_APP_RESERVED,
                ],
            ]);
            if (empty($dryRun['success']) || count($dryRun['results'] ?? []) !== 2) {
                $this->fail('Notification dry-run dispatch did not return both site and app-reserved results.');
                return;
            }
            $this->ok('Notification dry-run dispatch covers site and app-reserved channels.');
        } catch (\Throwable $e) {
            $this->fail('Notification fixture matrix failed: ' . $e->getMessage());
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
            '# Mongoyia Notification Phase 12 Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: order/logistics/payment/customer-service/complaint notification event hooks, site-message delivery, app-reserved channel, send-log table, and backend log page.',
            '- Safety: this command does not send real SMS, call APP push providers, mutate orders, mutate payments, or store provider secrets.',
            '- Boundary: APP push/SMS/email provider delivery remains external evidence until Phase 12 manual acceptance.',
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

        $this->addCheck($label, 'PASS', $path, 'Required notification markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->addCheck($message, 'PASS', 'fixture', 'Notification fixture check passed.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-notification-phase12-readiness-' . date('Ymd-His') . '.md';
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
