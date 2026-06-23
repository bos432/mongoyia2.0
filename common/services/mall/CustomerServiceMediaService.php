<?php

namespace common\services\mall;

use Yii;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\web\UploadedFile;

class CustomerServiceMediaService
{
    public const VERSION = 'MONGOYIA_CUSTOMER_SERVICE_MEDIA_V1';
    public const STORAGE_RELATIVE_ROOT = 'runtime/mongoyia-im-media';

    private $policy;

    public function __construct(?ImMediaUploadSkeletonService $policy = null)
    {
        $this->policy = $policy ?: new ImMediaUploadSkeletonService();
    }

    public function definitions(): array
    {
        return [
            'file' => [
                'msg_type' => 3,
                'max_bytes' => 20 * 1024 * 1024,
                'max_size' => '20 MB',
                'directory' => 'file',
                'cleanup_prefix' => 'chat_file_smoke_',
            ],
            'video' => [
                'msg_type' => 4,
                'max_bytes' => 50 * 1024 * 1024,
                'max_size' => '50 MB',
                'directory' => 'video',
                'cleanup_prefix' => 'chat_video_smoke_',
            ],
            'voice' => [
                'msg_type' => 5,
                'max_bytes' => 10 * 1024 * 1024,
                'max_size' => '10 MB',
                'max_duration_seconds' => 120,
                'directory' => 'voice',
                'cleanup_prefix' => 'chat_voice_smoke_',
            ],
        ];
    }

    public function upload(UploadedFile $file, string $media, array $options = []): array
    {
        if ($file->getHasError()) {
            throw new \RuntimeException('Upload failed. Please check the file.');
        }

        $bytes = (string)file_get_contents($file->tempName);
        $mime = FileHelper::getMimeType($file->tempName) ?: (string)$file->type;

        return $this->storeBytes($media, (string)$file->name, (string)$mime, $bytes, $options);
    }

    public function storeBytes(string $media, string $filename, string $mime, string $bytes, array $options = []): array
    {
        $media = $this->normalizeMedia($media);
        $definition = $this->definition($media);
        $duration = max(0, (int)($options['duration'] ?? 0));
        if ($media === 'voice' && $duration > (int)($definition['max_duration_seconds'] ?? 120)) {
            throw new \InvalidArgumentException('Voice duration cannot exceed 120 seconds.');
        }

        $validation = $this->policy->validateUploadCandidate($media, $filename, $mime, $bytes);
        if (empty($validation['policyAccepted'])) {
            throw new \InvalidArgumentException('Media upload rejected: ' . (string)($validation['reason'] ?? 'unknown'));
        }

        $date = date('Y/m/d');
        $extension = strtolower((string)($validation['extension'] ?? pathinfo($filename, PATHINFO_EXTENSION)));
        $hash = hash('sha256', $media . '|' . $filename . '|' . $bytes . '|' . microtime(true));
        $prefix = !empty($options['smoke']) ? 'chat_' . $media . '_smoke_' : 'chat_' . $media . '_';
        $basename = $prefix . date('ymd_His') . '_' . substr($hash, 0, 16) . '.' . $extension;
        $storageKey = $media . '/' . $date . '/' . $basename;
        $absolutePath = $this->absolutePath($storageKey);
        $storageRoot = $this->storageRoot();

        if (!$this->isPathInside($absolutePath, $storageRoot) || $this->isPathInside($absolutePath, $this->webRoot())) {
            throw new \RuntimeException('Media storage path is unsafe.');
        }

        FileHelper::createDirectory(dirname($absolutePath), 0775, true);
        if (file_put_contents($absolutePath, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('Could not save media file.');
        }

        $mediaId = $this->encodeMediaId([
            'v' => 1,
            'media' => $media,
            'key' => $storageKey,
            'name' => $this->safeDownloadName($filename),
            'size' => strlen($bytes),
            'mime' => (string)($validation['mime'] ?? $mime),
            'msg_type' => (int)$definition['msg_type'],
            'duration' => $duration,
        ]);

        return [
            'policyVersion' => self::VERSION,
            'media' => $media,
            'media_id' => $mediaId,
            'token' => $this->sign($mediaId),
            'msg_type' => (int)$definition['msg_type'],
            'url' => $this->buildViewUrl($mediaId),
            'name' => $this->safeDownloadName($filename),
            'size' => strlen($bytes),
            'mime' => (string)($validation['mime'] ?? $mime),
            'duration' => $duration,
            'thumbnail_url' => '',
            'storage_key' => $storageKey,
            'absolute_path' => $absolutePath,
        ];
    }

    public function viewFile(string $mediaId, string $token): array
    {
        if (!$this->verify($mediaId, $token)) {
            throw new \RuntimeException('Invalid media token.');
        }

        $payload = $this->decodeMediaId($mediaId);
        $key = (string)($payload['key'] ?? '');
        $path = $this->absolutePath($key);
        if ($key === '' || !$this->isPathInside($path, $this->storageRoot()) || !is_file($path)) {
            throw new \RuntimeException('Media file not found.');
        }

        return [
            'path' => $path,
            'name' => (string)($payload['name'] ?? basename($path)),
            'mime' => (string)($payload['mime'] ?? 'application/octet-stream'),
            'size' => (int)($payload['size'] ?? filesize($path)),
            'media' => (string)($payload['media'] ?? ''),
            'msg_type' => (int)($payload['msg_type'] ?? 0),
        ];
    }

    public function responseData(array $stored): array
    {
        return [
            'media_id' => (string)$stored['media_id'],
            'msg_type' => (int)$stored['msg_type'],
            'url' => (string)$stored['url'],
            'name' => (string)$stored['name'],
            'size' => (int)$stored['size'],
            'mime' => (string)$stored['mime'],
            'duration' => (int)$stored['duration'],
            'thumbnail_url' => (string)$stored['thumbnail_url'],
        ];
    }

    public function storageRoot(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::STORAGE_RELATIVE_ROOT);
    }

    private function buildViewUrl(string $mediaId): string
    {
        $path = '/mall/chat/media-view';
        try {
            if (!Yii::$app instanceof \yii\console\Application && Yii::$app->has('urlManager')) {
                $path = Yii::$app->urlManager->createUrl(['/mall/chat/media-view']);
            }
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), 'mall.customer_service_media.url_fallback');
        }
        $separator = str_contains($path, '?') ? '&' : '?';
        return $path . $separator . http_build_query([
            'media_id' => $mediaId,
            'token' => $this->sign($mediaId),
        ]);
    }

    private function encodeMediaId(array $payload): string
    {
        return rtrim(strtr(base64_encode(Json::encode($payload)), '+/', '-_'), '=');
    }

    private function decodeMediaId(string $mediaId): array
    {
        $padding = (4 - strlen($mediaId) % 4) % 4;
        $json = base64_decode(strtr($mediaId, '-_', '+/') . str_repeat('=', $padding), true);
        $payload = $json !== false ? Json::decode($json, true) : null;
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid media id.');
        }

        return $payload;
    }

    private function sign(string $mediaId): string
    {
        return hash_hmac('sha256', $mediaId, $this->secret());
    }

    private function verify(string $mediaId, string $token): bool
    {
        return $mediaId !== '' && $token !== '' && hash_equals($this->sign($mediaId), $token);
    }

    private function secret(): string
    {
        $secret = (string)(Yii::$app->params['imAuthSecret'] ?? '');
        if ($secret === '' && function_exists('env')) {
            $secret = (string)env('IM_AUTH_SECRET', '');
        }
        return $secret !== '' ? $secret : hash('sha256', self::VERSION . '|' . (string)Yii::$app->id);
    }

    private function definition(string $media): array
    {
        $definitions = $this->definitions();
        if (!isset($definitions[$media])) {
            throw new \InvalidArgumentException('Unsupported media type: ' . $media);
        }

        return $definitions[$media];
    }

    private function normalizeMedia(string $media): string
    {
        $media = strtolower(trim($media));
        return in_array($media, ['file', 'video', 'voice'], true) ? $media : '';
    }

    private function safeDownloadName(string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        return $name !== '' ? mb_substr($name, 0, 120, 'UTF-8') : 'media';
    }

    private function absolutePath(string $storageKey): string
    {
        return $this->storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($storageKey, '/'));
    }

    private function webRoot(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'web';
    }

    private function isPathInside(string $path, string $root): bool
    {
        $path = rtrim($this->normalizePath($path), '/') . (is_dir($path) ? '/' : '');
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
