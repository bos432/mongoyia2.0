<?php

namespace common\services\mall;

use Yii;

class ImMediaUploadSkeletonService
{
    public const POLICY_VERSION = 'MONGOYIA_IM_MEDIA_UPLOAD_SKELETON_V1';

    public function status(): array
    {
        $flagEnabled = (bool)(Yii::$app->params['imFileVideoVoiceEnabled'] ?? false);
        $rules = $this->validationRules();

        return [
            'policyVersion' => self::POLICY_VERSION,
            'flagEnabled' => $flagEnabled,
            'implementationReady' => false,
            'enabled' => false,
            'validationHelperReady' => true,
            'storagePreflightReady' => true,
            'cleanupDryRunReady' => true,
            'enablementPreconditionReady' => true,
            'currentEnabledTypes' => [1, 2],
            'reservedTypes' => [3, 4, 5],
            'uploadUrl' => (string)(Yii::$app->params['chatMediaUploadUrl'] ?? '/mall/chat/media-upload'),
            'reservedMedia' => [
                $this->media('file', 3, '20 MB', '/attachment/chat-file/YYYY/MM/DD/', 'chat_file_smoke_', $rules['file']),
                $this->media('video', 4, '50 MB', '/attachment/chat-video/YYYY/MM/DD/', 'chat_video_smoke_', $rules['video']),
                $this->media('voice', 5, '10 MB', '/attachment/chat-voice/YYYY/MM/DD/', 'chat_voice_smoke_', $rules['voice']),
            ],
        ];
    }

    public function enablementPreconditionPlan(): array
    {
        $preconditions = [
            [
                'key' => 'live_php_upload_implementation',
                'satisfied' => false,
                'required_evidence' => 'reviewed PHP upload action with validation, storage write, audit metadata, and rollback cleanup',
            ],
            [
                'key' => 'python_payload_acceptance',
                'satisfied' => false,
                'required_evidence' => 'Python IM accepts and validates msg_type 3/4/5 payloads without weakening text/image rules',
            ],
            [
                'key' => 'wss_regression_evidence',
                'satisfied' => false,
                'required_evidence' => 'file/video/voice regression evidence over real WSS with merchant/product/store context',
            ],
            [
                'key' => 'cleanup_evidence',
                'satisfied' => false,
                'required_evidence' => 'uploaded files, messages, and fixture rows are cleanup-verifiable after acceptance',
            ],
        ];

        return [
            'policyVersion' => self::POLICY_VERSION,
            'enablementPreconditionReady' => true,
            'canExposeFrontendControls' => false,
            'canExposeBackendControls' => false,
            'canEnableTransport' => false,
            'permissionWriteEnabled' => false,
            'requiredControlMarkers' => [
                'frontend' => 'MONGOYIA_IM_MEDIA_UPLOAD_UI_V1',
                'backend' => 'MONGOYIA_IM_MEDIA_UPLOAD_KF_UI_V1',
            ],
            'futurePermissions' => [
                'frontend' => '/mall/chat/media-upload',
                'backend' => '/mall/kf/media-upload',
            ],
            'preconditions' => $preconditions,
            'pendingCount' => count(array_filter($preconditions, static function (array $row): bool {
                return empty($row['satisfied']);
            })),
        ];
    }

    public function storagePreflightPlan(string $date = ''): array
    {
        $date = $this->normalizeDate($date);
        $root = $this->storageRoot();
        $webRoot = $this->webRoot();
        $rows = [];
        foreach (['file', 'video', 'voice'] as $media) {
            $rows[] = [
                'media' => $media,
                'storage_root' => $root,
                'web_root' => $webRoot,
                'directory' => $root . DIRECTORY_SEPARATOR . $media . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $date),
                'directory_pattern' => 'runtime/mongoyia-im-media/' . $media . '/YYYY/MM/DD/',
                'filename_rule' => 'cleanup prefix + sha256 + original extension',
                'cleanup_prefix' => 'chat_' . $media . '_smoke_',
                'outside_web_root' => !$this->isPathInside($root, $webRoot),
                'would_create_directory' => false,
                'would_write_file' => false,
            ];
        }

        return [
            'policyVersion' => self::POLICY_VERSION,
            'storagePreflightReady' => true,
            'writeEnabled' => false,
            'createDirectories' => false,
            'storageRoot' => $root,
            'webRoot' => $webRoot,
            'storageRootOutsideWebRoot' => !$this->isPathInside($root, $webRoot),
            'date' => $date,
            'media' => $rows,
        ];
    }

    public function validationRules(): array
    {
        return [
            'file' => [
                'max_bytes' => 20 * 1024 * 1024,
                'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'],
                'mime_allowlist' => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/plain',
                    'text/csv',
                    'application/zip',
                ],
                'signature_rules' => ['pdf_magic', 'office_zip_container', 'zip_magic', 'text_utf8_or_ascii'],
            ],
            'video' => [
                'max_bytes' => 50 * 1024 * 1024,
                'extensions' => ['mp4', 'webm'],
                'mime_allowlist' => ['video/mp4', 'video/webm'],
                'signature_rules' => ['mp4_ftyp_box', 'webm_ebml_header'],
            ],
            'voice' => [
                'max_bytes' => 10 * 1024 * 1024,
                'extensions' => ['mp3', 'm4a', 'ogg', 'webm', 'wav'],
                'mime_allowlist' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/webm', 'audio/wav'],
                'signature_rules' => ['mp3_frame_or_id3', 'mp4_ftyp_box', 'ogg_header', 'webm_ebml_header', 'wav_riff_header'],
            ],
        ];
    }

    public function validateUploadCandidate(string $media, string $filename, string $mime, string $bytes): array
    {
        $media = $this->normalizeMedia($media);
        $rules = $this->validationRules();
        $rule = $rules[$media] ?? null;
        $filename = trim($filename);
        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $normalizedMime = strtolower(trim(explode(';', $mime)[0]));
        $size = strlen($bytes);

        $base = [
            'policyVersion' => self::POLICY_VERSION,
            'media' => $media,
            'filename' => $filename,
            'extension' => $extension,
            'mime' => $normalizedMime,
            'size' => $size,
            'policyAccepted' => false,
            'transportEnabled' => false,
            'implementationReady' => false,
        ];

        if ($rule === null) {
            return $base + ['reason' => 'unsupported_media'];
        }
        if ($filename === '' || preg_match('/[\\\\\/\x00]/', $filename) || strpos($filename, '..') !== false) {
            return $base + ['reason' => 'unsafe_filename'];
        }
        if (!in_array($extension, $rule['extensions'], true)) {
            return $base + ['reason' => 'invalid_extension'];
        }
        if (!in_array($normalizedMime, $rule['mime_allowlist'], true)) {
            return $base + ['reason' => 'invalid_mime'];
        }
        if ($size <= 0) {
            return $base + ['reason' => 'empty_body'];
        }
        if ($size > (int)$rule['max_bytes']) {
            return $base + ['reason' => 'too_large'];
        }
        if (!$this->matchesSignature($media, $extension, $bytes)) {
            return $base + ['reason' => 'invalid_signature'];
        }

        return array_merge($base, [
            'policyAccepted' => true,
            'reason' => 'policy_pass',
        ]);
    }

    public function storageDryRunForCandidate(string $media, string $filename, string $mime, string $bytes, string $date = ''): array
    {
        $validation = $this->validateUploadCandidate($media, $filename, $mime, $bytes);
        $date = $this->normalizeDate($date);
        $storageRoot = $this->storageRoot();
        $webRoot = $this->webRoot();
        $extension = (string)($validation['extension'] ?? strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)));
        $normalizedMedia = (string)($validation['media'] ?? $this->normalizeMedia($media));
        $prefix = 'chat_' . $normalizedMedia . '_smoke_';
        $basename = $prefix . substr(hash('sha256', $normalizedMedia . '|' . $filename . '|' . $bytes), 0, 32) . ($extension !== '' ? '.' . $extension : '');
        $relativeKey = $normalizedMedia . '/' . $date . '/' . $basename;
        $absolutePath = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeKey);
        $directory = dirname($absolutePath);
        $insideStorageRoot = $this->isPathInside($absolutePath, $storageRoot);
        $outsideWebRoot = !$this->isPathInside($absolutePath, $webRoot);

        return array_merge($validation, [
            'storageDryRun' => true,
            'writeEnabled' => false,
            'createDirectories' => false,
            'wouldWriteFile' => false,
            'storageRoot' => $storageRoot,
            'webRoot' => $webRoot,
            'directory' => $directory,
            'storageKey' => $relativeKey,
            'absolutePath' => $absolutePath,
            'insideStorageRoot' => $insideStorageRoot,
            'outsideWebRoot' => $outsideWebRoot,
            'pathAccepted' => !empty($validation['policyAccepted']) && $insideStorageRoot && $outsideWebRoot,
        ]);
    }

    public function cleanupDryRunPlan(string $date = ''): array
    {
        $date = $this->normalizeDate($date);
        $root = $this->storageRoot();
        $rows = [];
        foreach (['file', 'video', 'voice'] as $media) {
            $rows[] = [
                'media' => $media,
                'directory' => $root . DIRECTORY_SEPARATOR . $media . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $date),
                'cleanup_prefix' => 'chat_' . $media . '_smoke_',
                'scope' => 'generated fixture/tmp media only',
                'would_delete_files' => false,
                'requires_apply_token' => 'IM_MEDIA_UPLOAD_CLEANUP_APPLY',
            ];
        }

        return [
            'policyVersion' => self::POLICY_VERSION,
            'cleanupDryRunReady' => true,
            'dryRunOnly' => true,
            'deleteEnabled' => false,
            'applyGuard' => 'IM_MEDIA_UPLOAD_CLEANUP_APPLY',
            'storageRoot' => $root,
            'date' => $date,
            'media' => $rows,
        ];
    }

    public function disabledResponse(string $media, string $message): array
    {
        $status = $this->status();

        return [
            'code' => 403,
            'msg' => $message,
            'data' => [
                'enabled' => false,
                'policyVersion' => $status['policyVersion'],
                'media' => $this->normalizeMedia($media),
                'flagEnabled' => (bool)$status['flagEnabled'],
                'implementationReady' => (bool)$status['implementationReady'],
                'reservedTypes' => $status['reservedTypes'],
                'uploadUrl' => $status['uploadUrl'],
            ],
        ];
    }

    private function media(string $media, int $msgType, string $maxSize, string $storagePath, string $cleanupPrefix, array $rule): array
    {
        return [
            'media' => $media,
            'msg_type' => $msgType,
            'max_size' => $maxSize,
            'max_bytes' => (int)($rule['max_bytes'] ?? 0),
            'extensions' => $rule['extensions'] ?? [],
            'mime_allowlist' => $rule['mime_allowlist'] ?? [],
            'signature_rules' => $rule['signature_rules'] ?? [],
            'storage_path' => $storagePath,
            'cleanup_prefix' => $cleanupPrefix,
        ];
    }

    private function normalizeMedia(string $media): string
    {
        $media = strtolower(trim($media));
        return in_array($media, ['file', 'video', 'voice'], true) ? $media : 'unknown';
    }

    private function matchesSignature(string $media, string $extension, string $bytes): bool
    {
        if ($media === 'file') {
            if ($extension === 'pdf') {
                return strncmp($bytes, '%PDF-', 5) === 0;
            }
            if (in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'zip'], true)) {
                return $this->hasZipMagic($bytes);
            }
            if (in_array($extension, ['txt', 'csv'], true)) {
                return $this->looksLikeText($bytes);
            }
        }
        if ($media === 'video') {
            if ($extension === 'mp4') {
                return $this->hasMp4FtypBox($bytes);
            }
            if ($extension === 'webm') {
                return $this->hasWebmHeader($bytes);
            }
        }
        if ($media === 'voice') {
            if ($extension === 'mp3') {
                return strncmp($bytes, 'ID3', 3) === 0 || (strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0);
            }
            if ($extension === 'm4a') {
                return $this->hasMp4FtypBox($bytes);
            }
            if ($extension === 'ogg') {
                return strncmp($bytes, 'OggS', 4) === 0;
            }
            if ($extension === 'webm') {
                return $this->hasWebmHeader($bytes);
            }
            if ($extension === 'wav') {
                return strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WAVE';
            }
        }

        return false;
    }

    private function hasZipMagic(string $bytes): bool
    {
        return strncmp($bytes, "PK\x03\x04", 4) === 0
            || strncmp($bytes, "PK\x05\x06", 4) === 0
            || strncmp($bytes, "PK\x07\x08", 4) === 0;
    }

    private function hasMp4FtypBox(string $bytes): bool
    {
        return strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp';
    }

    private function hasWebmHeader(string $bytes): bool
    {
        return strlen($bytes) >= 4 && substr($bytes, 0, 4) === "\x1A\x45\xDF\xA3";
    }

    private function looksLikeText(string $bytes): bool
    {
        return !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $bytes);
    }

    private function storageRoot(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'mongoyia-im-media';
    }

    private function webRoot(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'web';
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $date)) {
            return date('Y/m/d');
        }

        return $date;
    }

    private function isPathInside(string $path, string $root): bool
    {
        $path = $this->normalizePath($path);
        $root = rtrim($this->normalizePath($root), '/') . '/';

        return str_starts_with($path, $root);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $prefix = preg_match('/^[A-Za-z]:/', $path) ? '' : '/';

        return strtolower($prefix . implode('/', $parts));
    }
}
