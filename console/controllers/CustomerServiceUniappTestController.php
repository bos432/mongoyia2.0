<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceUniappTestController extends Controller
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
        $this->stdout("Mongoyia customer-service uni-app check\n");

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
        $base = '@app/../apps/mongoyia-customer-chat-uniapp/';
        $this->requireFileContains($base . 'manifest.json', [
            '__UNI__MONGOYIA_CS',
            'Mongoyia客服',
        ]);
        $this->requireFileContains($base . 'pages.json', [
            'pages/chat/index',
        ]);
        $this->requireFileContains($base . 'pages/chat/index.vue', [
            'MONGOYIA_CUSTOMER_SERVICE_UNIAPP_CHAT_V1',
            'uni.connectSocket',
            'chat_history',
            'msg_type: 1',
            'chooseImage',
            'chooseFile',
            'chooseVideo',
            'getRecorderManager',
            'data-mongoyia-customer-service-uniapp-rating',
            '/mall/chat/rating-submit',
        ]);
        $this->requireFileContains($base . 'utils/api.js', [
            '/mall/chat/media-upload',
            'uni.uploadFile',
        ]);
        $this->requireFileContains('@app/../frontend/modules/mall/controllers/ChatController.php', [
            "'uid' => (int)\$product['user_id']",
            "'product_id' => \$gid",
            "'store_id' => (int)\$product['store_id']",
        ]);
        $this->requireFileContains($base . 'README.md', [
            'customer-service chat client',
            '/mall/chat/token',
            '/mall/chat/translate',
            '/mall/chat/media-upload',
            '/mall/chat/rating-submit',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Fixture dry-run');
        $base = Yii::getAlias('@app/../apps/mongoyia-customer-chat-uniapp');
        foreach (['package.json', 'manifest.json', 'pages.json'] as $file) {
            $json = json_decode((string)file_get_contents($base . '/' . $file), true);
            if (!is_array($json)) {
                $this->fail("Invalid JSON: {$file}");
                return;
            }
        }
        $this->ok('uni-app JSON files are parseable.');

        $package = json_decode((string)file_get_contents($base . '/package.json'), true);
        if (empty($package['scripts']['dev:h5']) || empty($package['dependencies']['@dcloudio/uni-app'])) {
            $this->fail('uni-app package is missing H5 dev script or uni dependency.');
            return;
        }
        $this->ok('uni-app package includes H5 dev script and uni dependency.');

        $page = (string)file_get_contents($base . '/pages/chat/index.vue');
        foreach (['messageType(message) === 2', 'messageType(message) === 3', 'messageType(message) === 4', 'messageType(message) === 5'] as $needle) {
            if (strpos($page, $needle) === false) {
                $this->fail("Chat page missing media render branch {$needle}.");
                return;
            }
        }
        $this->ok('Chat page renders image/file/video/voice branches.');
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
