<?php

namespace console\controllers;

use common\services\mall\ImMediaTransportPolicyGateService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaImMediaTransportPolicyGateController extends Controller
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
        $this->stdout("Mongoyia IM media transport policy gate\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = (new ImMediaTransportPolicyGateService())->run();
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
        $this->requireFileContains('common/services/mall/ImMediaTransportPolicyGateService.php', [
            'class ImMediaTransportPolicyGateService',
            'MONGOYIA_IM_MEDIA_TRANSPORT_POLICY_GATE_V1',
            'Mongoyia IM Media Transport Policy Gate',
            'type_and_signature_guards',
            'rollout_and_rollback',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaImMediaTransportPolicyGateController.php', [
            'class MongoyiaImMediaTransportPolicyGateController',
            'Mongoyia IM media transport policy gate',
            'Rollback-clean fixture',
        ]);
        $this->requireFileContains('docs/mongoyia-im-media-contract.md', [
            'MONGOYIA_IM_MEDIA_TRANSPORT_POLICY_GATE_V1',
            'Transport Policy Gate',
            'msg_type=3/4/5',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipImMediaTransportPolicyGate',
            'IM media transport policy gate Phase 6 closure',
            'mongoyia-im-media-transport-policy-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaImMediaTransportPolicyGateController.php',
            'ImMediaTransportPolicyGateService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'imMediaTransportPolicyGatePath',
            'mongoyia-im-media-transport-policy-gate-*.md',
            'IM media transport policy gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-im-media-transport-policy-gate/run',
            'mongoyia-im-media-transport-policy-gate-*.md',
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
            '"invalid payload was saved to chat history"',
            '"invalid msg_type"',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $businessCounts = $this->businessTableCounts();
            $report = (new ImMediaTransportPolicyGateService())->run();

            $this->assertFalse((bool)$report['transportEnabled'], 'File/video/voice transport remains disabled.');
            $this->assertSameInt(2, count($report['currentEnabledTypes'] ?? []), 'Current runtime has two enabled msg types.');
            $this->assertSameInt(3, count($report['reservedTypes'] ?? []), 'Future runtime has three reserved msg types.');
            $this->assertSameInt(3, count($report['policies'] ?? []), 'Policy gate has file, video, and voice policies.');
            $this->assertPolicy($report, 'file', 3, 20 * 1024 * 1024, 'pdf', 'application/pdf', 'pdf_magic', 'chat_file_smoke_');
            $this->assertPolicy($report, 'video', 4, 50 * 1024 * 1024, 'mp4', 'video/mp4', 'mp4_ftyp_box', 'chat_video_smoke_');
            $this->assertPolicy($report, 'voice', 5, 10 * 1024 * 1024, 'mp3', 'audio/mpeg', 'mp3_frame_or_id3', 'chat_voice_smoke_');
            $this->assertGateStatus($report, 'runtime_boundary', 'disabled', 'Runtime boundary remains disabled for msg_type 3/4/5.');
            $this->assertGateStatus($report, 'policy_scope', 'ready', 'Policy scope is ready.');
            $this->assertGateStatus($report, 'size_limits', 'ready', 'Size limits are ready.');
            $this->assertGateStatus($report, 'type_and_signature_guards', 'ready', 'Type and signature guards are ready.');
            $this->assertGateStatus($report, 'storage_cleanup_scope', 'ready', 'Storage and cleanup scope is ready.');
            $this->assertGateStatus($report, 'rollout_and_rollback', 'ready', 'Rollout and rollback gates are ready.');

            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia IM Media Transport Policy Gate',
                '- Result: PASS',
                'MONGOYIA_IM_MEDIA_TRANSPORT_POLICY_GATE_V1',
                'Media Policy',
                'This report is a read-only policy gate',
            ]);
            $this->assertFileContains($paths['csv'], [
                'type,name,value,details',
                'chat_file_smoke_',
                'feature_flag',
            ]);
            $this->assertBusinessCountsUnchanged($businessCounts);
            $this->ok('IM media transport policy gate fixture generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('IM media transport policy gate fixture failed: ' . $e->getMessage());
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-im-media-transport-policy-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        $service = new ImMediaTransportPolicyGateService();
        file_put_contents($md, implode("\n", $service->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $service->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function assertPolicy(
        array $report,
        string $media,
        int $msgType,
        int $maxBytes,
        string $extension,
        string $mime,
        string $signature,
        string $cleanupPrefix
    ): void {
        foreach (($report['policies'] ?? []) as $policy) {
            if ((string)($policy['media'] ?? '') !== $media) {
                continue;
            }
            if ((int)$policy['msg_type'] !== $msgType) {
                $this->fail("{$media} policy msg_type mismatch.");
                return;
            }
            if ((int)$policy['max_bytes'] !== $maxBytes) {
                $this->fail("{$media} policy max size mismatch.");
                return;
            }
            if (!in_array($extension, $policy['extensions'] ?? [], true)) {
                $this->fail("{$media} policy missing extension {$extension}.");
                return;
            }
            if (!in_array($mime, $policy['mime_allowlist'] ?? [], true)) {
                $this->fail("{$media} policy missing MIME {$mime}.");
                return;
            }
            if (!in_array($signature, $policy['signature_rules'] ?? [], true)) {
                $this->fail("{$media} policy missing signature rule {$signature}.");
                return;
            }
            if ((string)$policy['cleanup_prefix'] !== $cleanupPrefix) {
                $this->fail("{$media} policy cleanup prefix mismatch.");
                return;
            }
            $this->ok("{$media} policy is ready.");
            return;
        }
        $this->fail("Missing {$media} policy.");
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
        $this->ok('Messages, orders, payments, and funds were not mutated by IM media transport policy gate.');
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
