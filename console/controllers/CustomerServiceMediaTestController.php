<?php

namespace console\controllers;

use common\services\mall\CustomerServiceMediaService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceMediaTestController extends Controller
{
    public $fixture = false;

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['fixture']);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia customer-service media check\n");

        $this->checkMarkers();
        if ($this->fixture && $this->failures === 0) {
            $this->runFixture();
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        return $this->failures > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkMarkers(): void
    {
        $this->section('Source markers');
        $this->requireFileContains('@app/../common/services/mall/CustomerServiceMediaService.php', [
            'MONGOYIA_CUSTOMER_SERVICE_MEDIA_V1',
            'msg_type',
            'media_id',
            'viewFile',
            'validateUploadCandidate',
            'runtime/mongoyia-im-media',
        ]);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/ChatController.php', [
            'CustomerServiceMediaService',
            'actionMediaUpload',
            'actionMediaView',
            'media_id',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/controllers/KfController.php', [
            'CustomerServiceMediaService',
            'actionMediaUpload',
            'actionMediaView',
            'media_id',
        ]);
        $this->requireFileContains('@app/../deploy/im-backend/main.py', [
            'MAX_MEDIA_MESSAGE_LENGTH',
            'normalized_type not in (1, 2, 3, 4, 5)',
            'media_preview_label',
            '/mall/chat/media-view?',
            'media_id=',
            'token=',
        ]);
        $this->requireFileContains('@app/../web/resources/mall/default/views/chat/index.php', [
            'mediaUploadUrl',
            'fileBtn',
            'videoBtn',
            'voiceBtn',
            'sendMedia',
            'MediaRecorder',
        ]);
        $this->requireFileContains('@app/../backend/modules/mall/views/kf/index.php', [
            'mediaUploadUrl',
            'fileBtn',
            'videoBtn',
            'voiceBtn',
            'sendMedia',
            'MediaRecorder',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Fixture upload/view');
        $service = new CustomerServiceMediaService();
        $stored = null;
        try {
            $stored = $service->storeBytes('file', 'codex-fixture.pdf', 'application/pdf', "%PDF-1.4\ncodex fixture\n", [
                'smoke' => true,
            ]);
            if ((int)($stored['msg_type'] ?? 0) !== 3 || empty($stored['media_id']) || empty($stored['token'])) {
                $this->fail('PDF fixture did not return msg_type=3 media_id/token.');
            } else {
                $this->ok('PDF fixture returns msg_type=3 media_id/token.');
            }
            if (empty($stored['absolute_path']) || !is_file($stored['absolute_path'])) {
                $this->fail('PDF fixture file was not written to non-public storage.');
            } else {
                $this->ok('PDF fixture file was written to non-public storage.');
            }

            $view = $service->viewFile((string)$stored['media_id'], (string)$stored['token']);
            if (($view['path'] ?? '') !== ($stored['absolute_path'] ?? '') || ($view['mime'] ?? '') !== 'application/pdf') {
                $this->fail('Signed media view did not resolve the stored PDF fixture.');
            } else {
                $this->ok('Signed media view resolves the stored PDF fixture.');
            }

            try {
                $service->viewFile((string)$stored['media_id'], 'bad-token');
                $this->fail('Invalid media token should be rejected.');
            } catch (\Throwable $e) {
                $this->ok('Invalid media token is rejected.');
            }

            try {
                $service->storeBytes('file', 'bad.exe', 'application/octet-stream', "MZbad", ['smoke' => true]);
                $this->fail('Invalid file extension/signature should be rejected.');
            } catch (\Throwable $e) {
                $this->ok('Invalid file extension/signature is rejected.');
            }
        } catch (\Throwable $e) {
            $this->fail('Customer-service media fixture failed: ' . $e->getMessage());
        } finally {
            $this->cleanupFixture($service, $stored);
        }
    }

    private function cleanupFixture(CustomerServiceMediaService $service, ?array $stored): void
    {
        if (!$stored || empty($stored['absolute_path'])) {
            return;
        }

        $path = (string)$stored['absolute_path'];
        $root = realpath($service->storageRoot());
        $real = realpath($path);
        if ($root && $real && str_starts_with(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $root), '/') . '/') && is_file($real)) {
            @unlink($real);
            $this->ok('Customer-service media fixture file cleaned up.');
        }
    }

    private function requireFileContains(string $alias, array $needles): void
    {
        $path = Yii::getAlias($alias);
        if (!is_file($path)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$path} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$path}");
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stderr("FAIL {$message}\n");
    }
}
