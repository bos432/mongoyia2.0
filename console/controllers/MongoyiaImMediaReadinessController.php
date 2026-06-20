<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class MongoyiaImMediaReadinessController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $imRoot = '../../im后端/im后端';
    public $productId = 102;
    public $strict = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $infos = 0;
    private $cleanupFiles = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'handoverDir',
            'outputPath',
            'imRoot',
            'productId',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->stdout("Mongoyia IM media readiness\n");

        $this->checkPhpUploadGuard();
        $this->checkFrontendChatUi();
        $this->checkBackendKfUi();
        $this->checkReservedMediaUiBoundaries();
        $this->checkMediaContract();
        $this->checkImPayloadGuard();
        $this->checkHttpChatPage();
        $this->checkHttpUploadSamples();

        $result = $this->result();
        $path = $this->writeReport($result);
        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->infos} info.\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkPhpUploadGuard(): void
    {
        $this->requireFileMarkers('PHP chat upload guard', 'frontend/modules/mall/controllers/ChatController.php', [
            'public function actionUpload()',
            "UploadedFile::getInstanceByName('file')",
            '$file->getHasError()',
            '$file->size > 5 * 1024 * 1024',
            "['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp']",
            'getimagesize($file->tempName)',
            "'chat/' . date('Y/m/d')",
            "'chat_smoke_'",
            "Yii::getAlias('@attachment')",
            "Yii::getAlias('@attachmentUrl')",
        ]);

        $this->requireFileMarkers('Chat route/env upload config', 'common/config/params.php', [
            "'chatUploadUrl' => env('CHAT_UPLOAD_URL', '/mall/chat/upload')",
            "'imWebsocketUrl' => env('IM_WEBSOCKET_URL'",
            "'imAuthSecret' => env('IM_AUTH_SECRET'",
        ]);
    }

    private function checkFrontendChatUi(): void
    {
        $this->requireFileMarkers('Frontend chat image UI', 'web/resources/mall/default/views/chat/index.php', [
            'data-mongoyia-mobile-ui="chat"',
            'id="imageBtn"',
            'id="imageInput" accept="image/*"',
            'uploadUrl:',
            'async function sendImage(file)',
            'file.size > 5 * 1024 * 1024',
            "formData.append('file', file)",
            'normalizeUploadUrl(result)',
            "msg_type: 2",
            "value.startsWith('/attachment/')",
            'parsed.host === window.location.host',
        ]);
    }

    private function checkBackendKfUi(): void
    {
        $this->requireFileMarkers('Backend customer-service image UI', 'backend/modules/mall/views/kf/index.php', [
            'id="imageBtn"',
            'id="imageInput" accept="image/*"',
            'uploadUrl:',
            'async function sendImage(file)',
            'file.size > 5 * 1024 * 1024',
            "formData.append('file', file)",
            'normalizeUploadUrl(result)',
            "msg_type: 2",
            "value.startsWith('/attachment/')",
            'parsed.host === window.location.host',
        ]);
    }

    private function checkReservedMediaUiBoundaries(): void
    {
        $reservedMarkers = [
            'id="fileInput"',
            'id="videoInput"',
            'id="voiceInput"',
            'id="fileBtn"',
            'id="videoBtn"',
            'id="voiceBtn"',
            'sendFile(',
            'sendVideo(',
            'sendVoice(',
            'msg_type: 3',
            'msg_type: 4',
            'msg_type: 5',
        ];

        $this->requireFileMissingMarkers('Frontend reserved media controls', 'web/resources/mall/default/views/chat/index.php', $reservedMarkers);
        $this->requireFileMissingMarkers('Backend reserved media controls', 'backend/modules/mall/views/kf/index.php', $reservedMarkers);
    }

    private function checkMediaContract(): void
    {
        $this->requireFileMarkers('Versioned future media contract', 'docs/mongoyia-im-media-contract.md', [
            '# Mongoyia IM Media Contract',
            'Contract version: 2026-06-19-im-media-v1',
            'MONGOYIA_IM_FILE_CONTRACT_V1',
            'MONGOYIA_IM_VIDEO_CONTRACT_V1',
            'MONGOYIA_IM_VOICE_CONTRACT_V1',
            '`msg_type=3`',
            '`msg_type=4`',
            '`msg_type=5`',
            '`chat_file_smoke_`',
            '`chat_video_smoke_`',
            '`chat_voice_smoke_`',
            'No runtime enablement',
        ]);
    }

    private function checkImPayloadGuard(): void
    {
        $root = rtrim($this->resolvePath($this->imRoot), DIRECTORY_SEPARATOR);
        $main = $root . DIRECTORY_SEPARATOR . 'main.py';
        $regression = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'im-regression.py';

        $this->requireAbsoluteFileMarkers('Python IM payload guard', $main, [
            'MAX_TEXT_MESSAGE_LENGTH',
            'MAX_IMAGE_MESSAGE_LENGTH',
            'def validate_chat_payload',
            'normalized_type not in (1, 2)',
            "normalized_content.startswith('/attachment/')",
            "'..' in normalized_content",
            'any(ord(ch) < 32 for ch in normalized_content)',
            'max_size=2 ** 20',
        ]);

        $this->requireAbsoluteFileMarkers('Python IM media regression coverage', $regression, [
            'check_payload_rejections',
            'valid_image = f"/attachment/chat/regression_',
            '"javascript:alert(1)"',
            '"https://evil.example.com/chat.png"',
            '"Invalid image message URL"',
            '"invalid payload was saved to chat history"',
        ]);
    }

    private function checkHttpChatPage(): void
    {
        $path = '/mall/chat/index?gid=' . (int)$this->productId;
        $response = $this->get($this->absoluteUrl($path));
        if ((int)$response['status'] < 200 || (int)$response['status'] >= 400) {
            $this->addCheck('HTTP chat page media config', 'FAIL', $path, 'Expected HTTP 2xx/3xx, got ' . (int)$response['status'] . '.');
            return;
        }

        $required = [
            'data-mongoyia-mobile-ui="chat"',
            'uploadUrl',
            'imageInput',
            'accept="image/*"',
            'msg_type: 2',
            'normalizeImageUrl',
        ];
        foreach ($required as $marker) {
            if (stripos($response['body'], $marker) === false) {
                $this->addCheck('HTTP chat page media config', 'FAIL', $path, "Missing marker `{$marker}`.");
                return;
            }
        }

        $this->addCheck('HTTP chat page media config', 'PASS', $path, 'Chat page exposes image-only upload controls and URL normalization markers.');
    }

    private function checkHttpUploadSamples(): void
    {
        $uploadPath = (string)(Yii::$app->params['chatUploadUrl'] ?? '/mall/chat/upload');
        $url = $this->absoluteUrl($uploadPath . (str_contains($uploadPath, '?') ? '&' : '?') . 'lang=en');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        $valid = $this->postMultipart($url, ['smoke' => '1'], [
            'field' => 'file',
            'name' => 'mongoyia-chat-smoke.png',
            'type' => 'image/png',
            'content' => $png ?: '',
        ]);
        $validJson = $this->jsonBody($valid['body']);
        $uploadedUrl = $this->extractUploadUrl($validJson);
        if ((int)$valid['status'] >= 200 && (int)$valid['status'] < 400 && (int)($validJson['code'] ?? 0) === 200 && $uploadedUrl !== '') {
            $cleanupNote = $this->cleanupSmokeUpload($uploadedUrl);
            $this->addCheck('HTTP valid chat image upload', 'PASS', $uploadPath, '1x1 PNG upload accepted; ' . $cleanupNote);
        } else {
            $this->addCheck('HTTP valid chat image upload', 'FAIL', $uploadPath, 'Expected JSON code 200 for 1x1 PNG; got HTTP ' . (int)$valid['status'] . ' body ' . $this->shortBody($valid['body']) . '.');
        }

        $dangerous = $this->postMultipart($url, ['smoke' => '1'], [
            'field' => 'file',
            'name' => 'mongoyia-chat-smoke.php',
            'type' => 'application/x-php',
            'content' => '<?php echo 1; ?>',
        ]);
        $this->expectRejectedUpload('HTTP dangerous extension rejection', $uploadPath, $dangerous);

        $fakeImage = $this->postMultipart($url, ['smoke' => '1'], [
            'field' => 'file',
            'name' => 'mongoyia-chat-smoke.jpg',
            'type' => 'image/jpeg',
            'content' => 'not a real image',
        ]);
        $this->expectRejectedUpload('HTTP invalid image body rejection', $uploadPath, $fakeImage);

        foreach ($this->reservedMediaUploadSamples() as $sample) {
            $response = $this->postMultipart($url, ['smoke' => '1'], [
                'field' => 'file',
                'name' => $sample['name'],
                'type' => $sample['type'],
                'content' => $sample['content'],
            ]);
            $this->expectRejectedUpload($sample['label'], $uploadPath, $response);
        }
    }

    private function expectRejectedUpload(string $label, string $uploadPath, array $response): void
    {
        $json = $this->jsonBody($response['body']);
        $code = (int)($json['code'] ?? 0);
        $uploadedUrl = $this->extractUploadUrl($json);
        if ($uploadedUrl === '' && ($code >= 400 || (int)$response['status'] >= 400)) {
            $this->addCheck($label, 'PASS', $uploadPath, 'Rejected with HTTP ' . (int)$response['status'] . ' and JSON code ' . ($code ?: 'none') . '.');
            return;
        }

        if ($uploadedUrl !== '') {
            $this->cleanupSmokeUpload($uploadedUrl);
        }
        $this->addCheck($label, 'FAIL', $uploadPath, 'Unexpected upload acceptance; HTTP ' . (int)$response['status'] . ' body ' . $this->shortBody($response['body']) . '.');
    }

    private function requireFileMarkers(string $label, string $path, array $markers): void
    {
        $this->requireAbsoluteFileMarkers($label, $this->resolvePath($path), $markers, $path);
    }

    private function requireFileMissingMarkers(string $label, string $path, array $markers): void
    {
        $fullPath = $this->resolvePath($path);
        if (!is_file($fullPath)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($fullPath);
        foreach ($markers as $marker) {
            if (strpos($content, $marker) !== false) {
                $this->addCheck($label, 'FAIL', $path, "Reserved media marker `{$marker}` is present before file/video/voice rules are implemented.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'File/video/voice controls are not advertised before explicit Phase 6 transport rules are implemented.');
    }

    private function requireAbsoluteFileMarkers(string $label, string $fullPath, array $markers, string $displayPath = ''): void
    {
        $displayPath = $displayPath !== '' ? $displayPath : $this->displayPath($fullPath);
        if (!is_file($fullPath)) {
            $this->addCheck($label, 'FAIL', $displayPath, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($fullPath);
        foreach ($markers as $marker) {
            if (strpos($content, $marker) === false) {
                $this->addCheck($label, 'FAIL', $displayPath, "Missing marker `{$marker}`.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $displayPath, 'Required media/upload markers are present.');
    }

    private function get(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => "User-Agent: MongoyiaImMediaReadiness/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        return [
            'status' => $this->httpStatus($http_response_header ?? []),
            'body' => is_string($body) ? $body : '',
        ];
    }

    private function postMultipart(string $url, array $fields, array $file): array
    {
        $boundary = '----MongoyiaImMedia' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $this->multipartName((string)$name) . '"' . "\r\n\r\n";
            $body .= (string)$value . "\r\n";
        }

        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $this->multipartName((string)$file['field']) . '"; filename="' . $this->multipartName((string)$file['name']) . '"' . "\r\n";
        $body .= 'Content-Type: ' . (string)$file['type'] . "\r\n\r\n";
        $body .= (string)$file['content'] . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'User-Agent: MongoyiaImMediaReadiness/1.0',
                    'Content-Type: multipart/form-data; boundary=' . $boundary,
                    'Content-Length: ' . strlen($body),
                ]) . "\r\n",
                'content' => $body,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        return [
            'status' => $this->httpStatus($http_response_header ?? []),
            'body' => is_string($response) ? $response : '',
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

    private function jsonBody(string $body): array
    {
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }

    private function extractUploadUrl(array $json): string
    {
        if (isset($json['url']) && is_string($json['url'])) {
            return trim($json['url']);
        }
        if (isset($json['data']) && is_string($json['data'])) {
            return trim($json['data']);
        }
        if (isset($json['data']['url']) && is_string($json['data']['url'])) {
            return trim($json['data']['url']);
        }

        return '';
    }

    private function reservedMediaUploadSamples(): array
    {
        return [
            [
                'label' => 'HTTP reserved file/PDF rejection',
                'name' => 'mongoyia-chat-smoke.pdf',
                'type' => 'application/pdf',
                'content' => "%PDF-1.4\n% Mongoyia reserved media readiness\n",
            ],
            [
                'label' => 'HTTP reserved video/MP4 rejection',
                'name' => 'mongoyia-chat-smoke.mp4',
                'type' => 'video/mp4',
                'content' => "\x00\x00\x00\x18ftypmp42mongoyia",
            ],
            [
                'label' => 'HTTP reserved voice/MP3 rejection',
                'name' => 'mongoyia-chat-smoke.mp3',
                'type' => 'audio/mpeg',
                'content' => "ID3\x03\x00\x00\x00\x00\x00\x21mongoyia",
            ],
        ];
    }

    private function futureMediaContracts(): array
    {
        return [
            [
                'media' => 'File',
                'msg_type' => 'msg_type=3',
                'max_size' => '20 MB',
                'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'],
                'mime' => ['application/pdf', 'application/zip', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.*'],
                'storage_path' => '/attachment/chat-file/YYYY/MM/DD/',
                'ui_marker' => 'MONGOYIA_IM_FILE_CONTRACT_V1',
                'cleanup_prefix' => 'chat_file_smoke_',
            ],
            [
                'media' => 'Video',
                'msg_type' => 'msg_type=4',
                'max_size' => '50 MB',
                'extensions' => ['mp4', 'webm', 'mov'],
                'mime' => ['video/mp4', 'video/webm', 'video/quicktime'],
                'storage_path' => '/attachment/chat-video/YYYY/MM/DD/',
                'ui_marker' => 'MONGOYIA_IM_VIDEO_CONTRACT_V1',
                'cleanup_prefix' => 'chat_video_smoke_',
            ],
            [
                'media' => 'Voice',
                'msg_type' => 'msg_type=5',
                'max_size' => '10 MB',
                'extensions' => ['mp3', 'm4a', 'wav', 'ogg', 'webm'],
                'mime' => ['audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/ogg', 'audio/webm'],
                'storage_path' => '/attachment/chat-voice/YYYY/MM/DD/',
                'ui_marker' => 'MONGOYIA_IM_VOICE_CONTRACT_V1',
                'cleanup_prefix' => 'chat_voice_smoke_',
            ],
        ];
    }

    private function cleanupSmokeUpload(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/attachment/chat/')) {
            return 'upload URL is outside local chat attachment cleanup scope';
        }

        $name = basename($path);
        if (!str_starts_with($name, 'chat_smoke_')) {
            return 'uploaded file is not a chat_smoke_ file; left in place';
        }

        $fullPath = $this->projectRoot() . DIRECTORY_SEPARATOR . 'web' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $real = realpath($fullPath);
        $attachmentRoot = realpath($this->projectRoot() . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'attachment' . DIRECTORY_SEPARATOR . 'chat');
        if ($real === false || $attachmentRoot === false || !str_starts_with($real, $attachmentRoot . DIRECTORY_SEPARATOR)) {
            return 'local uploaded file was not found for cleanup';
        }

        if (@unlink($real)) {
            $this->cleanupFiles[] = $this->displayPath($real);
            return 'smoke upload removed from ' . $this->displayPath($real);
        }

        $this->addCheck('HTTP valid chat image upload cleanup', 'WARN', $this->displayPath($real), 'Could not remove uploaded smoke image; cleanup command should remove chat_smoke_ files.');
        return 'smoke upload cleanup failed';
    }

    private function absoluteUrl(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    private function addCheck(string $area, string $status, string $evidence, string $notes): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') {
            $this->failures++;
        } elseif ($status === 'INFO') {
            $this->infos++;
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

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia IM Media Readiness',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Product ID: ' . (int)$this->productId,
            '- Python IM root: ' . $this->displayPath($this->resolvePath($this->imRoot)),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Scope: image-only upload boundary and IM media payload readiness; no WSS connection, no payment provider call, no order mutation.',
            '',
            '## Summary',
            '',
            '| Item | Value |',
            '|---|---:|',
            '| Failures | ' . $this->failures . ' |',
            '| Warnings | ' . $this->warnings . ' |',
            '| Info | ' . $this->infos . ' |',
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
            '## Media Policy',
            '',
            '| Media | Current State | Gate |',
            '|---|---|---|',
            '| Text | Enabled | WebSocket `msg_type=1`, bounded by Python `IM_MAX_TEXT_MESSAGE_LENGTH`. |',
            '| Image | Enabled | `/mall/chat/upload`, 5 MB PHP guard, image extension allowlist, `getimagesize`, WebSocket `msg_type=2` with same-origin `/attachment/` URL normalization. |',
            '| File | Reserved / rejected | No frontend/backend control; PDF sample must be rejected by `/mall/chat/upload`; no WebSocket message type assigned. |',
            '| Video | Reserved / rejected | No frontend/backend control; MP4 sample must be rejected by `/mall/chat/upload`; no WebSocket message type assigned. |',
            '| Voice | Reserved / rejected | No frontend/backend control; MP3 sample must be rejected by `/mall/chat/upload`; no WebSocket message type assigned. |',
        ]);

        $lines = array_merge($lines, [
            '',
            '## Versioned Future Media Contract',
            '',
            '- Contract doc: `docs/mongoyia-im-media-contract.md`',
            '- Contract version: `2026-06-19-im-media-v1`',
            '- No runtime enablement: these rows are gates for future implementation and must stay rejected until backend, Python payload rules, UI controls, regression scripts, and cleanup are implemented together.',
            '',
            '| Media | Proposed msg_type | Max size | Extensions | MIME allowlist | Storage path | UI marker | Cleanup prefix |',
            '|---|---:|---:|---|---|---|---|---|',
        ]);

        foreach ($this->futureMediaContracts() as $contract) {
            $lines[] = '| ' . $this->mdCell($contract['media']) . ' | `'
                . $this->mdCell($contract['msg_type']) . '` | '
                . $this->mdCell($contract['max_size']) . ' | `'
                . $this->mdCell(implode(', ', $contract['extensions'])) . '` | `'
                . $this->mdCell(implode(', ', $contract['mime'])) . '` | `'
                . $this->mdCell($contract['storage_path']) . '` | `'
                . $this->mdCell($contract['ui_marker']) . '` | `'
                . $this->mdCell($contract['cleanup_prefix']) . '` |';
        }

        $lines = array_merge($lines, [
            '',
            '## Cleaned Smoke Uploads',
            '',
        ]);
        if ($this->cleanupFiles) {
            foreach ($this->cleanupFiles as $file) {
                $lines[] = '- `' . $this->mdCell($file) . '`';
            }
        } else {
            $lines[] = '- none';
        }

        $lines = array_merge($lines, [
            '',
            '## Boundaries',
            '',
            '- Current IM media support is image-only through `/mall/chat/upload` and WebSocket `msg_type=2` image URLs.',
            '- File, video, and voice message transport remain reserved Phase 6 work until explicit upload limits, payload rules, UI controls, regression scripts, and cleanup are added.',
            '- The command rejects dangerous extensions, invalid image bodies, reserved PDF file samples, reserved MP4 video samples, and reserved MP3 voice samples through HTTP checks, then removes the accepted `chat_smoke_` PNG from local attachments.',
            '- Public WSS, reverse-proxy, and concurrency evidence still belongs to `mongoyia-im-wss-evidence` and the Python IM regression scripts.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-im-media-readiness-' . date('Ymd-His') . '.md';
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function displayPath(string $path): string
    {
        $root = rtrim($this->projectRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $root)) {
            return str_replace('\\', '/', substr($path, strlen($root)));
        }

        return str_replace('\\', '/', $path);
    }

    private function multipartName(string $value): string
    {
        return str_replace(["\r", "\n", '"'], ['', '', ''], $value);
    }

    private function shortBody(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body));
        if (strlen($body) > 180) {
            return substr($body, 0, 180) . '...';
        }

        return $body;
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
