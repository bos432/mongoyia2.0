<?php

namespace console\controllers;

use common\services\mall\ImMediaTransportImplementationGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaImMediaTransportImplementationGateController extends Controller
{
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;
    public $imRoot = '../../im后端/im后端';

    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'outputDir',
            'fixture',
            'strict',
            'imRoot',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia IM media transport implementation gate\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new ImMediaTransportImplementationGateService())->run();
            $this->recordReportIssues($report);
            $paths = $this->writeExport($report, false);
            $this->stdout("Markdown: {$paths['md']}\nCSV: {$paths['csv']}\n");
        }

        $this->stdout("\nSummary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");
        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkFiles(): void
    {
        $this->section('Files');
        $this->requireFileContains('common/services/mall/ImMediaTransportImplementationGateService.php', [
            'class ImMediaTransportImplementationGateService',
            'MONGOYIA_IM_MEDIA_TRANSPORT_IMPLEMENTATION_GATE_V1',
            'Mongoyia IM Media Transport Implementation Gate',
            'python_payload_contract',
            'ui_regression_cleanup_contract',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaImMediaTransportImplementationGateController.php', [
            'class MongoyiaImMediaTransportImplementationGateController',
            'Rollback-clean fixture',
            'IM media transport implementation gate',
        ]);
        $this->requireFileContains('docs/mongoyia-im-media-contract.md', [
            'MONGOYIA_IM_MEDIA_TRANSPORT_IMPLEMENTATION_GATE_V1',
            'Transport Implementation Gate',
            'msg_type=3/4/5',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipImMediaTransportImplementationGate',
            'IM media transport implementation gate Phase 6 closure',
            'mongoyia-im-media-transport-implementation-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaImMediaTransportImplementationGateController.php',
            'ImMediaTransportImplementationGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'imMediaTransportImplementationGatePath',
            'mongoyia-im-media-transport-implementation-gate-*.md',
            'IM media transport implementation gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-im-media-transport-implementation-gate/run',
            'mongoyia-im-media-transport-implementation-gate-*.md',
        ]);
    }

    private function checkRuntimeBoundary(): void
    {
        $this->section('Runtime boundary');
        $this->requireFileContains('frontend/modules/mall/controllers/ChatController.php', [
            "['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp']",
            'getimagesize($file->tempName)',
            "'chat/' . date('Y/m/d')",
            "'chat_smoke_'",
        ]);
        $this->requireFileMissingMarkers('web/resources/mall/default/views/chat/index.php', [
            'id="fileInput"',
            'id="videoInput"',
            'id="voiceInput"',
            'msg_type: 3',
            'msg_type: 4',
            'msg_type: 5',
            'MONGOYIA_IM_FILE_CONTRACT_V1',
            'MONGOYIA_IM_VIDEO_CONTRACT_V1',
            'MONGOYIA_IM_VOICE_CONTRACT_V1',
        ]);
        $this->requireFileMissingMarkers('backend/modules/mall/views/kf/index.php', [
            'id="fileInput"',
            'id="videoInput"',
            'id="voiceInput"',
            'msg_type: 3',
            'msg_type: 4',
            'msg_type: 5',
            'MONGOYIA_IM_FILE_CONTRACT_V1',
            'MONGOYIA_IM_VIDEO_CONTRACT_V1',
            'MONGOYIA_IM_VOICE_CONTRACT_V1',
        ]);
        $this->requireAbsoluteFileContains($this->resolvePath((string)$this->imRoot) . DIRECTORY_SEPARATOR . 'main.py', [
            'def validate_chat_payload',
            'normalized_type not in (1, 2)',
            "normalized_content.startswith('/attachment/')",
            "'..' in normalized_content",
        ]);
        $this->requireAbsoluteFileContains($this->resolvePath((string)$this->imRoot) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'im-regression.py', [
            'check_payload_rejections',
            '"Invalid image message URL"',
            '"invalid payload was saved to chat history"',
            '"invalid msg_type"',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = (new ImMediaTransportImplementationGateService())->run();

            $this->assertFalse((bool)$report['controlsEnabled'], 'File/video/voice controls remain disabled.');
            $this->assertSameInt(2, count($report['currentEnabledTypes'] ?? []), 'Current runtime has two enabled msg types.');
            $this->assertSameInt(3, count($report['reservedTypes'] ?? []), 'Future runtime has three reserved msg types.');
            $this->assertSameInt(3, count($report['futureMedia'] ?? []), 'Future media contract has file, video, and voice families.');
            $this->assertSameInt(6, count($report['contracts'] ?? []), 'Implementation gate has six required contracts.');
            $this->assertGateStatus($report, 'runtime_boundary', 'disabled', 'Runtime boundary remains disabled for msg_type 3/4/5.');
            $this->assertGateStatus($report, 'future_media_contract', 'ready', 'Future media contract is ready.');
            $this->assertGateStatus($report, 'php_upload_contract', 'ready', 'PHP upload contract is ready.');
            $this->assertGateStatus($report, 'python_payload_contract', 'ready', 'Python payload contract is ready.');
            $this->assertGateStatus($report, 'ui_regression_cleanup_contract', 'ready', 'UI/regression/cleanup contract is ready.');
            $this->assertGateStatus($report, 'rollout_contract', 'ready', 'Rollout contract is ready.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia IM Media Transport Implementation Gate',
                '- Result: PASS',
                'Future Media Families',
                'python_payload_contract',
                'This report is a read-only transport implementation gate',
            ]);
            $this->assertFileContains($paths['csv'], [
                'type,name,value,details',
                'chat_file_smoke_',
                'python_payload_contract',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('IM media transport implementation gate fixture generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('IM media transport implementation gate fixture failed: ' . $e->getMessage());
        }
    }

    private function writeExport(array $report, bool $fixture): array
    {
        $dir = (string)$this->outputDir !== ''
            ? Yii::getAlias((string)$this->outputDir)
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'handover';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stamp = date('Ymd-His') . ($fixture ? '-fixture-' . mt_rand(1000, 9999) : '');
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-im-media-transport-implementation-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new ImMediaTransportImplementationGateService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function businessTableCounts(): array
    {
        $counts = [];
        foreach ([
            '{{%base_message}}',
            '{{%chat_message}}',
            '{{%mall_order}}',
            '{{%mall_order_product}}',
            '{{%mall_payment_attempt}}',
            '{{%base_fund_log}}',
        ] as $table) {
            if (Yii::$app->db->schema->getTableSchema($table, true) === null) {
                continue;
            }
            $counts[$table] = (int)(new \yii\db\Query())->from($table)->count('*', Yii::$app->db);
        }

        return $counts;
    }

    private function assertBusinessCountsUnchanged(array $before): void
    {
        foreach ($before as $table => $expected) {
            $actual = (int)(new \yii\db\Query())->from($table)->count('*', Yii::$app->db);
            if ($actual !== $expected) {
                $this->fail("Business table {$table} changed. Expected {$expected}, got {$actual}.");
                return;
            }
        }
        $this->ok('Messages, orders, payments, and funds were not mutated by IM media transport implementation gate.');
    }

    private function assertGateStatus(array $report, string $key, string $expected, string $message): void
    {
        foreach (($report['gateChecks'] ?? []) as $check) {
            if ((string)$check['key'] !== $key) {
                continue;
            }
            if ((string)$check['status'] !== $expected) {
                $this->fail("{$message} Expected {$expected}, got {$check['status']}.");
                return;
            }
            $this->ok($message);
            return;
        }
        $this->fail("{$message} Gate {$key} missing.");
    }

    private function assertFalse(bool $condition, string $message): void
    {
        if ($condition) {
            $this->fail($message);
            return;
        }
        $this->ok($message);
    }

    private function assertSameInt(int $expected, int $actual, string $message): void
    {
        if ($expected !== $actual) {
            $this->fail("{$message} Expected {$expected}, got {$actual}.");
            return;
        }
        $this->ok($message);
    }

    private function requireFileContains(string $path, array $needles): void
    {
        $fullPath = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $this->requireContentContains($path, (string)file_get_contents($fullPath), $needles);
    }

    private function requireAbsoluteFileContains(string $fullPath, array $needles): void
    {
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$fullPath}.");
            return;
        }
        $this->requireContentContains($this->displayPath($fullPath), (string)file_get_contents($fullPath), $needles);
    }

    private function requireContentContains(string $label, string $content, array $needles): void
    {
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->fail("File {$label} missing '{$needle}'.");
                return;
            }
        }
        $this->ok("File contains required markers: {$label}");
    }

    private function requireFileMissingMarkers(string $path, array $needles): void
    {
        $fullPath = Yii::getAlias('@app') . '/../' . $path;
        if (!is_file($fullPath)) {
            $this->fail("Missing file {$path}.");
            return;
        }
        $content = (string)file_get_contents($fullPath);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                $this->fail("File {$path} should not contain reserved marker '{$needle}'.");
                return;
            }
        }
        $this->ok("File keeps reserved media controls disabled: {$path}");
    }

    private function assertFileContains(string $path, array $needles): void
    {
        if (!is_file($path)) {
            $this->fail("Missing export file {$path}.");
            return;
        }
        $this->requireContentContains($path, (string)file_get_contents($path), $needles);
    }

    private function recordReportIssues(array $report): void
    {
        foreach (($report['issues'] ?? []) as $issue) {
            $this->warnings++;
            $this->stdout("WARN {$issue}\n");
        }
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function displayPath(string $path): string
    {
        $root = rtrim(dirname(__DIR__, 2), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $root)) {
            return str_replace('\\', '/', substr($path, strlen($root)));
        }

        return str_replace('\\', '/', $path);
    }

    private function section(string $label): void
    {
        $this->stdout("\n[{$label}]\n");
    }

    private function ok(string $message): void
    {
        $this->stdout("OK   {$message}\n");
    }

    private function fail(string $message): void
    {
        $this->failures++;
        $this->stdout("FAIL {$message}\n");
    }
}
