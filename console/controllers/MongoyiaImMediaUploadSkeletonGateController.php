<?php

namespace console\controllers;

use common\services\mall\ImMediaUploadSkeletonService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaImMediaUploadSkeletonGateController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $outputDir = '';
    public $fixture = false;
    public $strict = false;
    public $imRoot = '../../im后端/im后端';

    private $failures = 0;
    private $warnings = 0;
    private $checks = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'outputDir',
            'fixture',
            'strict',
            'imRoot',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia IM media upload skeleton gate\n");
        $this->checkFiles();
        $this->checkRuntimeBoundary();

        if ($this->fixture) {
            $this->runFixture();
        } else {
            $report = $this->report();
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
        $this->requireFileContains('common/services/mall/ImMediaUploadSkeletonService.php', [
            'class ImMediaUploadSkeletonService',
            'MONGOYIA_IM_MEDIA_UPLOAD_SKELETON_V1',
            "'implementationReady' => false",
            "'enabled' => false",
            "'validationHelperReady' => true",
            "'storagePreflightReady' => true",
            "'cleanupDryRunReady' => true",
            "'enablementPreconditionReady' => true",
            'validateUploadCandidate',
            'storagePreflightPlan',
            'storageDryRunForCandidate',
            'cleanupDryRunPlan',
            'enablementPreconditionPlan',
            'canExposeFrontendControls',
            'MONGOYIA_IM_MEDIA_UPLOAD_UI_V1',
            'storageRootOutsideWebRoot',
            'dryRunOnly',
            'policyAccepted',
            'invalid_signature',
            'chatMediaUploadUrl',
        ]);
        $this->requireFileContains('frontend/modules/mall/controllers/ChatController.php', [
            'ImMediaUploadSkeletonService',
            "'media-upload'",
            'public function actionMediaUpload()',
            'mediaTransportDisabled',
        ]);
        $this->requireFileContains('common/config/params.php', [
            "'chatMediaUploadUrl' => env('CHAT_MEDIA_UPLOAD_URL', '/mall/chat/media-upload')",
            "'imFileVideoVoiceEnabled' => env_bool('IM_FILE_VIDEO_VOICE_ENABLED', false)",
        ]);
        $this->requireFileContains('.env.example', [
            'CHAT_MEDIA_UPLOAD_URL=/mall/chat/media-upload',
            'IM_FILE_VIDEO_VOICE_ENABLED=false',
        ]);
        $this->requireFileContains('.env.test.example', [
            'CHAT_MEDIA_UPLOAD_URL=/mall/chat/media-upload',
            'IM_FILE_VIDEO_VOICE_ENABLED=false',
        ]);
        $this->requireFileContains('docs/mongoyia-im-media-contract.md', [
            'MONGOYIA_IM_MEDIA_UPLOAD_SKELETON_V1',
            'Upload Skeleton Gate',
            'validation helper',
            'storage preflight',
            'cleanup dry-run',
            'enablement precondition',
            'CHAT_MEDIA_UPLOAD_URL',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaAcceptanceController.php', [
            'skipImMediaUploadSkeletonGate',
            'IM media upload skeleton gate Phase 6 closure',
            'mongoyia-im-media-upload-skeleton-gate/run',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaPackageCheckController.php', [
            'MongoyiaImMediaUploadSkeletonGateController.php',
            'ImMediaUploadSkeletonService.php',
        ]);
        $this->requireFileContains('console/controllers/MongoyiaDeliveryIndexController.php', [
            'imMediaUploadSkeletonGatePath',
            'mongoyia-im-media-upload-skeleton-gate-*.md',
            'IM media upload skeleton gate result',
        ]);
        $this->requireFileContains('docs/mongoyia-package-index.md', [
            'mongoyia-im-media-upload-skeleton-gate/run',
            'mongoyia-im-media-upload-skeleton-gate-*.md',
        ]);
    }

    private function checkRuntimeBoundary(): void
    {
        $this->section('Runtime boundary');
        $this->requireFileMissingMarkers('Frontend reserved media controls', 'web/resources/mall/default/views/chat/index.php', [
            'id="fileInput"',
            'id="videoInput"',
            'id="voiceInput"',
            'msg_type: 3',
            'msg_type: 4',
            'msg_type: 5',
            'MONGOYIA_IM_MEDIA_UPLOAD_UI_V1',
        ]);
        $this->requireFileMissingMarkers('Backend reserved media controls', 'backend/modules/mall/views/kf/index.php', [
            'id="fileInput"',
            'id="videoInput"',
            'id="voiceInput"',
            'msg_type: 3',
            'msg_type: 4',
            'msg_type: 5',
            'MONGOYIA_IM_MEDIA_UPLOAD_KF_UI_V1',
        ]);
        $this->requireAbsoluteFileContains($this->resolvePath((string)$this->imRoot) . DIRECTORY_SEPARATOR . 'main.py', [
            'def validate_chat_payload',
            'normalized_type not in (1, 2)',
        ]);
    }

    private function runFixture(): void
    {
        $this->section('Rollback-clean fixture');
        try {
            $before = $this->businessTableCounts();
            $service = new ImMediaUploadSkeletonService();
            $status = $service->status();

            $this->assertFalse((bool)$status['enabled'], 'Media upload skeleton remains disabled.');
            $this->assertFalse((bool)$status['implementationReady'], 'Media upload skeleton implementation is not marked ready.');
            $this->assertSameInt(2, count($status['currentEnabledTypes'] ?? []), 'Current runtime has two enabled msg types.');
            $this->assertSameInt(3, count($status['reservedTypes'] ?? []), 'Future runtime has three reserved msg types.');
            $this->assertSameInt(3, count($status['reservedMedia'] ?? []), 'Skeleton exposes file, video, and voice reserved policy rows.');
            $this->assertFalse(empty($status['validationHelperReady']), 'Upload validation helper is present but still behind disabled transport gate.');
            $this->assertFalse(empty($status['storagePreflightReady']), 'Storage preflight helper is present but still behind disabled transport gate.');
            $this->assertFalse(empty($status['cleanupDryRunReady']), 'Cleanup dry-run helper is present but still behind disabled transport gate.');
            $this->assertFalse(empty($status['enablementPreconditionReady']), 'Enablement precondition helper is present but still keeps UI and permissions disabled.');
            $this->checkValidationHelpers($service);
            $this->checkStoragePreflight($service);
            $this->checkCleanupDryRun($service);
            $this->checkEnablementPreconditions($service);

            foreach (['file', 'video', 'voice'] as $media) {
                $this->assertDisabledResponse($service->disabledResponse($media, 'disabled'), $media);
                $this->checkHttpDisabledEndpoint($media);
            }

            $this->assertBusinessCountsUnchanged($before);
            $report = $this->report();
            $paths = $this->writeExport($report, true);
            $this->assertFileContains($paths['md'], [
                '# Mongoyia IM Media Upload Skeleton Gate',
                '- Result: PASS',
                'MONGOYIA_IM_MEDIA_UPLOAD_SKELETON_V1',
                'Disabled HTTP Endpoint Checks',
                '| validation | file-valid | policy_pass |',
                '| storage | file | outside_web_root |',
                '| cleanup | file | dry_run_only |',
                '| precondition | live_php_upload_implementation | pending |',
            ]);
            $this->assertFileContains($paths['csv'], [
                'type,name,value,details',
                'http,file,403',
                'validation,file-valid,policy_pass',
                'storage,file,outside_web_root',
                'cleanup,file,dry_run_only',
                'precondition,live_php_upload_implementation,pending',
                'service,enabled,false',
            ]);
            $this->ok('IM media upload skeleton gate fixture generated read-only evidence.');
        } catch (\Throwable $e) {
            $this->fail('IM media upload skeleton gate fixture failed: ' . $e->getMessage());
        }
    }

    private function checkHttpDisabledEndpoint(string $media): void
    {
        $path = (string)(Yii::$app->params['chatMediaUploadUrl'] ?? '/mall/chat/media-upload');
        $response = $this->postForm($this->baseUrl . '/' . ltrim($path, '/'), ['media' => $media, 'smoke' => '1']);
        $json = json_decode($response['body'], true);
        if (!is_array($json)) {
            $this->fail("Media {$media} disabled endpoint did not return JSON. HTTP {$response['status']}.");
            return;
        }
        if ((int)($json['code'] ?? 0) !== 403 || !empty($json['data']['enabled'])) {
            $this->fail("Media {$media} disabled endpoint expected code 403 and enabled=false.");
            return;
        }
        if ((string)($json['data']['policyVersion'] ?? '') !== ImMediaUploadSkeletonService::POLICY_VERSION) {
            $this->fail("Media {$media} disabled endpoint missing policy version.");
            return;
        }

        $this->checks[] = [
            'type' => 'http',
            'name' => $media,
            'value' => '403',
            'details' => 'disabled endpoint returned JSON code 403 and enabled=false',
        ];
        $this->ok("Media {$media} upload skeleton endpoint is disabled.");
    }

    private function checkValidationHelpers(ImMediaUploadSkeletonService $service): void
    {
        $this->assertPolicyAccepted(
            $service,
            'file-valid',
            'file',
            'manual.pdf',
            'application/pdf',
            "%PDF-1.4\n% Mongoyia smoke\n"
        );
        $this->assertPolicyAccepted(
            $service,
            'video-valid',
            'video',
            'clip.mp4',
            'video/mp4',
            "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00"
        );
        $this->assertPolicyAccepted(
            $service,
            'voice-valid',
            'voice',
            'voice.wav',
            'audio/wav',
            "RIFF\x24\x00\x00\x00WAVEfmt "
        );

        $this->assertPolicyRejected(
            $service,
            'file-bad-extension',
            'file',
            'manual.exe',
            'application/pdf',
            "%PDF-1.4\n",
            'invalid_extension'
        );
        $this->assertPolicyRejected(
            $service,
            'video-bad-mime',
            'video',
            'clip.mp4',
            'application/octet-stream',
            "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00",
            'invalid_mime'
        );
        $this->assertPolicyRejected(
            $service,
            'voice-bad-signature',
            'voice',
            'voice.wav',
            'audio/wav',
            'not a wav file',
            'invalid_signature'
        );
    }

    private function assertPolicyAccepted(
        ImMediaUploadSkeletonService $service,
        string $name,
        string $media,
        string $filename,
        string $mime,
        string $bytes
    ): void {
        $result = $service->validateUploadCandidate($media, $filename, $mime, $bytes);
        if (empty($result['policyAccepted']) || (string)($result['reason'] ?? '') !== 'policy_pass') {
            $this->fail("Upload validation {$name} expected policy_pass, got " . (string)($result['reason'] ?? 'unknown') . '.');
            return;
        }
        if (!empty($result['transportEnabled']) || !empty($result['implementationReady'])) {
            $this->fail("Upload validation {$name} must not enable transport.");
            return;
        }

        $this->checks[] = [
            'type' => 'validation',
            'name' => $name,
            'value' => 'policy_pass',
            'details' => 'extension, MIME, and signature accepted by disabled-by-default helper',
        ];
        $this->ok("Upload validation {$name} accepts allowed policy sample without enabling transport.");
    }

    private function assertPolicyRejected(
        ImMediaUploadSkeletonService $service,
        string $name,
        string $media,
        string $filename,
        string $mime,
        string $bytes,
        string $expectedReason
    ): void {
        $result = $service->validateUploadCandidate($media, $filename, $mime, $bytes);
        if (!empty($result['policyAccepted']) || (string)($result['reason'] ?? '') !== $expectedReason) {
            $this->fail("Upload validation {$name} expected {$expectedReason}, got " . (string)($result['reason'] ?? 'unknown') . '.');
            return;
        }
        if (!empty($result['transportEnabled']) || !empty($result['implementationReady'])) {
            $this->fail("Upload validation {$name} must not enable transport.");
            return;
        }

        $this->checks[] = [
            'type' => 'validation',
            'name' => $name,
            'value' => $expectedReason,
            'details' => 'invalid sample rejected by disabled-by-default helper',
        ];
        $this->ok("Upload validation {$name} rejects sample with {$expectedReason}.");
    }

    private function checkStoragePreflight(ImMediaUploadSkeletonService $service): void
    {
        $plan = $service->storagePreflightPlan('2026/06/19');
        if (empty($plan['storageRootOutsideWebRoot']) || !empty($plan['writeEnabled']) || !empty($plan['createDirectories'])) {
            $this->fail('Storage preflight expected outside-web-root dry-run plan with write/create disabled.');
            return;
        }
        foreach (($plan['media'] ?? []) as $row) {
            $media = (string)($row['media'] ?? '');
            if ($media === '' || empty($row['outside_web_root']) || !empty($row['would_create_directory']) || !empty($row['would_write_file'])) {
                $this->fail("Storage preflight row for {$media} is not a dry-run outside-web-root plan.");
                return;
            }
            $this->checks[] = [
                'type' => 'storage',
                'name' => $media,
                'value' => 'outside_web_root',
                'details' => 'storage preflight is outside web root and does not create directories or write files',
            ];
        }

        $this->assertStorageCandidateAccepted(
            $service,
            'file',
            'manual.pdf',
            'application/pdf',
            "%PDF-1.4\n% Mongoyia storage smoke\n"
        );
        $this->assertStorageCandidateRejected(
            $service,
            'file',
            '../manual.pdf',
            'application/pdf',
            "%PDF-1.4\n",
            'unsafe_filename'
        );
        $this->ok('Storage preflight remains dry-run and outside the public web root.');
    }

    private function assertStorageCandidateAccepted(
        ImMediaUploadSkeletonService $service,
        string $media,
        string $filename,
        string $mime,
        string $bytes
    ): void {
        $result = $service->storageDryRunForCandidate($media, $filename, $mime, $bytes, '2026/06/19');
        if (empty($result['pathAccepted']) || empty($result['insideStorageRoot']) || empty($result['outsideWebRoot'])) {
            $this->fail("Storage dry-run for {$media} expected an accepted path inside storage root and outside web root.");
            return;
        }
        if (!empty($result['writeEnabled']) || !empty($result['createDirectories']) || !empty($result['wouldWriteFile'])) {
            $this->fail("Storage dry-run for {$media} must not write files or create directories.");
            return;
        }
        $this->checks[] = [
            'type' => 'storage-path',
            'name' => $media,
            'value' => 'path_accepted',
            'details' => 'candidate storage key is scoped inside storage root and outside web root',
        ];
    }

    private function assertStorageCandidateRejected(
        ImMediaUploadSkeletonService $service,
        string $media,
        string $filename,
        string $mime,
        string $bytes,
        string $expectedReason
    ): void {
        $result = $service->storageDryRunForCandidate($media, $filename, $mime, $bytes, '2026/06/19');
        if (!empty($result['pathAccepted']) || (string)($result['reason'] ?? '') !== $expectedReason) {
            $this->fail("Storage dry-run for {$media} expected {$expectedReason}, got " . (string)($result['reason'] ?? 'unknown') . '.');
            return;
        }
        if (!empty($result['writeEnabled']) || !empty($result['createDirectories']) || !empty($result['wouldWriteFile'])) {
            $this->fail("Rejected storage dry-run for {$media} must not write files or create directories.");
            return;
        }
        $this->checks[] = [
            'type' => 'storage-path',
            'name' => $media . '-unsafe',
            'value' => $expectedReason,
            'details' => 'unsafe candidate is rejected before any storage write',
        ];
    }

    private function checkCleanupDryRun(ImMediaUploadSkeletonService $service): void
    {
        $plan = $service->cleanupDryRunPlan('2026/06/19');
        if (empty($plan['dryRunOnly']) || !empty($plan['deleteEnabled']) || (string)($plan['applyGuard'] ?? '') !== 'IM_MEDIA_UPLOAD_CLEANUP_APPLY') {
            $this->fail('Cleanup dry-run expected dryRunOnly=true, deleteEnabled=false, and explicit apply guard.');
            return;
        }
        foreach (($plan['media'] ?? []) as $row) {
            $media = (string)($row['media'] ?? '');
            if ($media === '' || !empty($row['would_delete_files']) || (string)($row['requires_apply_token'] ?? '') !== 'IM_MEDIA_UPLOAD_CLEANUP_APPLY') {
                $this->fail("Cleanup dry-run row for {$media} is not scoped behind the apply guard.");
                return;
            }
            $this->checks[] = [
                'type' => 'cleanup',
                'name' => $media,
                'value' => 'dry_run_only',
                'details' => 'cleanup plan is prefix-scoped and does not delete files',
            ];
        }
        $this->ok('Cleanup plan remains dry-run only and guarded by explicit apply token.');
    }

    private function checkEnablementPreconditions(ImMediaUploadSkeletonService $service): void
    {
        $plan = $service->enablementPreconditionPlan();
        if (empty($plan['enablementPreconditionReady'])
            || !empty($plan['canExposeFrontendControls'])
            || !empty($plan['canExposeBackendControls'])
            || !empty($plan['canEnableTransport'])
            || !empty($plan['permissionWriteEnabled'])
        ) {
            $this->fail('Enablement precondition plan must keep controls, transport, and write permissions disabled.');
            return;
        }
        if ((int)($plan['pendingCount'] ?? 0) !== 4 || count($plan['preconditions'] ?? []) !== 4) {
            $this->fail('Enablement precondition plan must require all four live-upload evidence items.');
            return;
        }
        foreach (($plan['preconditions'] ?? []) as $row) {
            $key = (string)($row['key'] ?? '');
            if ($key === '' || !empty($row['satisfied'])) {
                $this->fail("Enablement precondition {$key} must remain pending until live upload is reviewed.");
                return;
            }
            $this->checks[] = [
                'type' => 'precondition',
                'name' => $key,
                'value' => 'pending',
                'details' => (string)($row['required_evidence'] ?? ''),
            ];
        }
        $this->checks[] = [
            'type' => 'permission',
            'name' => 'media-upload-controls',
            'value' => 'disabled',
            'details' => 'frontend and backend controls require all live-upload preconditions before exposure',
        ];
        $this->ok('Enablement preconditions keep file/video/voice UI, permissions, and transport disabled.');
    }

    private function assertDisabledResponse(array $response, string $media): void
    {
        if ((int)($response['code'] ?? 0) !== 403) {
            $this->fail("Media {$media} service response expected code 403.");
            return;
        }
        if (!isset($response['data']['enabled']) || (bool)$response['data']['enabled'] !== false) {
            $this->fail("Media {$media} service response expected enabled=false.");
            return;
        }
        if ((string)($response['data']['media'] ?? '') !== $media) {
            $this->fail("Media {$media} service response media mismatch.");
            return;
        }
        $this->ok("Media {$media} service disabled response is stable.");
    }

    private function report(): array
    {
        $status = (new ImMediaUploadSkeletonService())->status();

        return [
            'result' => $this->failures > 0 ? 'FAIL' : ($this->warnings > 0 ? 'WARN' : 'PASS'),
            'generatedAt' => date('Y-m-d H:i:s'),
            'baseUrl' => $this->baseUrl,
            'status' => $status,
            'checks' => $this->checks,
        ];
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
        $base = $dir . DIRECTORY_SEPARATOR . 'mongoyia-im-media-upload-skeleton-gate-' . $stamp;
        $md = $base . '.md';
        $csv = $base . '.csv';
        file_put_contents($md, implode("\n", $this->markdownLines($report)) . "\n");
        file_put_contents($csv, implode("\n", $this->csvLines($report)) . "\n");

        return ['md' => $md, 'csv' => $csv];
    }

    private function markdownLines(array $report): array
    {
        $status = $report['status'] ?? [];
        $lines = [
            '# Mongoyia IM Media Upload Skeleton Gate',
            '',
            '- Result: ' . (string)($report['result'] ?? 'FAIL'),
            '- Generated at: ' . (string)($report['generatedAt'] ?? ''),
            '- Base URL: ' . (string)($report['baseUrl'] ?? ''),
            '- Policy version: ' . (string)($status['policyVersion'] ?? ''),
            '- Upload URL: `' . (string)($status['uploadUrl'] ?? '') . '`',
            '- Feature flag enabled: ' . (!empty($status['flagEnabled']) ? 'yes' : 'no'),
            '- Implementation ready: ' . (!empty($status['implementationReady']) ? 'yes' : 'no'),
            '- Runtime enabled: ' . (!empty($status['enabled']) ? 'yes' : 'no'),
            '',
            '## Reserved Media',
            '',
            '| Media | msg_type | Max size | Storage path | Cleanup prefix |',
            '|---|---:|---:|---|---|',
        ];
        foreach (($status['reservedMedia'] ?? []) as $media) {
            $lines[] = '| ' . $this->escapeCell((string)$media['media'])
                . ' | ' . (int)$media['msg_type']
                . ' | ' . $this->escapeCell((string)$media['max_size'])
                . ' | `' . $this->escapeCell((string)$media['storage_path']) . '`'
                . ' | `' . $this->escapeCell((string)$media['cleanup_prefix']) . '`'
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Disabled HTTP Endpoint Checks',
            '',
            '| Type | Name | Value | Details |',
            '|---|---|---|---|',
        ]);
        foreach (($report['checks'] ?? []) as $check) {
            $lines[] = '| ' . $this->escapeCell((string)$check['type'])
                . ' | ' . $this->escapeCell((string)$check['name'])
                . ' | ' . $this->escapeCell((string)$check['value'])
                . ' | ' . $this->escapeCell((string)$check['details'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            'This report is a read-only upload skeleton gate. It does not enable msg_type 3/4/5, save uploaded files, create upload directories, write chat messages, mutate orders, change payments, write fund logs, or expose file/video/voice UI controls.',
        ]);
    }

    private function csvLines(array $report): array
    {
        $status = $report['status'] ?? [];
        $lines = ['type,name,value,details'];
        $lines[] = 'service,policyVersion,' . $this->csvCell((string)($status['policyVersion'] ?? '')) . ',upload skeleton policy version';
        $lines[] = 'service,enabled,' . (!empty($status['enabled']) ? 'true' : 'false') . ',runtime upload skeleton enabled state';
        $lines[] = 'service,implementationReady,' . (!empty($status['implementationReady']) ? 'true' : 'false') . ',implementation readiness guard';
        foreach (($report['checks'] ?? []) as $check) {
            $lines[] = implode(',', [
                $this->csvCell((string)$check['type']),
                $this->csvCell((string)$check['name']),
                $this->csvCell((string)$check['value']),
                $this->csvCell((string)$check['details']),
            ]);
        }

        return $lines;
    }

    private function postForm(string $url, array $fields): array
    {
        $content = http_build_query($fields);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'User-Agent: MongoyiaImMediaUploadSkeletonGate/1.0',
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($content),
                ]) . "\r\n",
                'content' => $content,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);

        return [
            'status' => $this->httpStatus($http_response_header ?? []),
            'body' => is_string($body) ? $body : '',
        ];
    }

    private function httpStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$header, $matches)) {
                return (int)$matches[1];
            }
        }

        return 0;
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
        $this->ok('Messages, orders, payments, and funds were not mutated by IM media upload skeleton gate.');
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
        $fullPath = $this->resolvePath($path);
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

    private function requireFileMissingMarkers(string $label, string $path, array $needles): void
    {
        $fullPath = $this->resolvePath($path);
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

    private function escapeCell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    private function csvCell(string $value): string
    {
        if (strpbrk($value, "\",\n\r") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
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
