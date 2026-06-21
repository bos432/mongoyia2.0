<?php

namespace common\services\mall;

use Yii;
use yii\web\UploadedFile;

class CustomerServiceComplaintEvidenceService
{
    const SOURCE_UPLOAD = 'customer-service-complaint-evidence-upload';
    const SOURCE_DELETE = 'customer-service-complaint-evidence-delete';
    const MAX_BYTES = 5242880;

    private $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp'];
    private $allowedMimeTypes = ['image/png', 'image/jpeg', 'image/webp'];

    public function evidenceList(array $ticket): array
    {
        $evidence = $this->decodeEvidence((string)($ticket['evidence_json'] ?? ''));
        $files = [];
        foreach (($evidence['files'] ?? []) as $file) {
            if (!is_array($file) || !empty($file['deleted_at'])) {
                continue;
            }
            $files[] = $this->normalizeFileRow($file);
        }

        return $files;
    }

    public function upload(
        int $ticketId,
        UploadedFile $file,
        string $note,
        int $operatorId,
        string $operatorType,
        int $scopeStoreId
    ): array {
        $ticket = $this->ticketRow($ticketId, $scopeStoreId);
        $this->assertComplaintTicket($ticket);

        $this->validateUploadedFile($file);
        $sha256 = hash_file('sha256', $file->tempName);
        $extension = strtolower((string)$file->extension);
        $mime = $this->detectMime($file->tempName);
        $now = time();
        $relativePath = $this->relativePath($ticketId, $now, $sha256, $extension);
        $absolutePath = $this->storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create complaint evidence storage directory.');
        }
        if (!$file->saveAs($absolutePath, false)) {
            throw new \RuntimeException('Failed to save complaint evidence file.');
        }

        $evidence = $this->decodeEvidence((string)($ticket['evidence_json'] ?? ''));
        $row = [
            'id' => $this->newEvidenceId($sha256),
            'original_name' => basename((string)$file->name),
            'extension' => $extension,
            'mime' => $mime,
            'bytes' => (int)$file->size,
            'sha256' => $sha256,
            'relative_path' => $relativePath,
            'note' => trim($note),
            'uploaded_at' => $now,
            'uploaded_by' => $operatorId,
            'operator_type' => $this->normalizeOperatorType($operatorType),
            'reviewed_at' => 0,
        ];
        $evidence['files'][] = $row;
        $evidence['updated_at'] = $now;
        $evidence['updated_by'] = $operatorId;

        $eventId = $this->writeEvidenceAndEvent(
            $ticket,
            $evidence,
            $operatorId,
            $row['operator_type'],
            'Uploaded complaint evidence image: ' . $row['original_name'],
            self::SOURCE_UPLOAD,
            [
                'evidence_id' => $row['id'],
                'file_name' => $row['original_name'],
                'mime' => $row['mime'],
                'bytes' => $row['bytes'],
                'sha256' => $row['sha256'],
            ]
        );

        return [
            'ticketId' => $ticketId,
            'evidenceId' => $row['id'],
            'eventId' => $eventId,
            'file' => $row,
        ];
    }

    public function delete(
        int $ticketId,
        string $evidenceId,
        int $operatorId,
        string $operatorType,
        int $scopeStoreId
    ): array {
        $ticket = $this->ticketRow($ticketId, $scopeStoreId);
        $this->assertComplaintTicket($ticket);

        $evidence = $this->decodeEvidence((string)($ticket['evidence_json'] ?? ''));
        $deleted = null;
        foreach (($evidence['files'] ?? []) as $index => $file) {
            if (!is_array($file) || (string)($file['id'] ?? '') !== $evidenceId || !empty($file['deleted_at'])) {
                continue;
            }
            if ((int)($file['reviewed_at'] ?? 0) > 0) {
                throw new \RuntimeException('Reviewed evidence cannot be deleted.');
            }
            $deleted = $this->normalizeFileRow($file);
            $evidence['files'][$index]['deleted_at'] = time();
            $evidence['files'][$index]['deleted_by'] = $operatorId;
            break;
        }
        if (!$deleted) {
            throw new \RuntimeException('Evidence file not found or already deleted.');
        }

        $eventId = $this->writeEvidenceAndEvent(
            $ticket,
            $evidence,
            $operatorId,
            $this->normalizeOperatorType($operatorType),
            'Deleted unreviewed complaint evidence image: ' . $deleted['original_name'],
            self::SOURCE_DELETE,
            [
                'evidence_id' => $deleted['id'],
                'file_name' => $deleted['original_name'],
                'sha256' => $deleted['sha256'],
            ]
        );

        $path = $this->absolutePathForRow($deleted);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }

        return [
            'ticketId' => $ticketId,
            'evidenceId' => $evidenceId,
            'eventId' => $eventId,
        ];
    }

    public function viewFile(int $ticketId, string $evidenceId, int $scopeStoreId): array
    {
        $ticket = $this->ticketRow($ticketId, $scopeStoreId);
        $this->assertComplaintTicket($ticket);

        foreach ($this->evidenceList($ticket) as $file) {
            if ((string)$file['id'] !== $evidenceId) {
                continue;
            }
            $path = $this->absolutePathForRow($file);
            if ($path === '' || !is_file($path)) {
                throw new \RuntimeException('Evidence file is missing from storage.');
            }

            return [
                'path' => $path,
                'mime' => $file['mime'],
                'name' => $file['original_name'],
            ];
        }

        throw new \RuntimeException('Evidence file not found.');
    }

    private function ticketRow(int $ticketId, int $scopeStoreId): array
    {
        if ($ticketId <= 0 || !$this->tableExists('{{%mall_customer_service_ticket}}')) {
            return [];
        }
        if ($scopeStoreId < 0) {
            return [];
        }

        $query = (new \yii\db\Query())
            ->from('{{%mall_customer_service_ticket}}')
            ->where(['id' => $ticketId, 'status' => 1]);
        if ($scopeStoreId > 0) {
            $query->andWhere(['store_id' => $scopeStoreId]);
        }

        return $query->one(Yii::$app->db) ?: [];
    }

    private function assertComplaintTicket(array $ticket): void
    {
        if (!$ticket) {
            throw new \RuntimeException('Complaint ticket not found or out of scope.');
        }
        if ((string)$ticket['ticket_type'] !== CustomerServiceAdvancedService::TICKET_TYPE_COMPLAINT) {
            throw new \RuntimeException('Only complaint tickets can upload evidence.');
        }
        if (!$this->tableExists('{{%mall_customer_service_event}}')) {
            throw new \RuntimeException('Customer-service event schema missing.');
        }
    }

    private function validateUploadedFile(UploadedFile $file): void
    {
        if ($file->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed. Please retry with a valid image.');
        }
        if ((int)$file->size <= 0 || (int)$file->size > self::MAX_BYTES) {
            throw new \RuntimeException('Image must be smaller than 5MB.');
        }

        $extension = strtolower((string)$file->extension);
        if (!in_array($extension, $this->allowedExtensions, true)) {
            throw new \RuntimeException('Only png, jpg, jpeg, and webp images are supported.');
        }

        $mime = $this->detectMime($file->tempName);
        if (!in_array($mime, $this->allowedMimeTypes, true)) {
            throw new \RuntimeException('Uploaded file is not a supported image.');
        }

        $imageInfo = @getimagesize($file->tempName);
        if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
            throw new \RuntimeException('Uploaded image body is invalid.');
        }
    }

    private function writeEvidenceAndEvent(
        array $ticket,
        array $evidence,
        int $operatorId,
        string $operatorType,
        string $content,
        string $source,
        array $metadata
    ): int {
        $now = time();
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode evidence metadata.');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            Yii::$app->db->createCommand()->update('{{%mall_customer_service_ticket}}', [
                'evidence_json' => $json,
                'updated_at' => $now,
                'updated_by' => $operatorId,
            ], [
                'id' => (int)$ticket['id'],
                'status' => 1,
            ])->execute();

            Yii::$app->db->createCommand()->insert('{{%mall_customer_service_event}}', [
                'ticket_id' => (int)$ticket['id'],
                'event_type' => CustomerServiceAdvancedService::EVENT_TYPE_NOTE,
                'from_status' => (string)$ticket['ticket_status'],
                'to_status' => (string)$ticket['ticket_status'],
                'operator_user_id' => $operatorId,
                'operator_type' => $operatorType,
                'content' => $content,
                'metadata_json' => json_encode(array_merge([
                    'source' => $source,
                    'preserve_ticket_status' => true,
                    'order_id' => (int)$ticket['order_id'],
                    'product_id' => (int)$ticket['product_id'],
                    'chat_uuid' => (string)$ticket['chat_uuid'],
                ], $metadata), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'remark' => 'backend complaint evidence image workflow',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $operatorId,
                'updated_by' => $operatorId,
            ])->execute();

            $eventId = (int)Yii::$app->db->getLastInsertID();
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $eventId;
    }

    private function decodeEvidence(string $json): array
    {
        $decoded = [];
        if (trim($json) !== '') {
            $decoded = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $decoded = [];
            }
        }

        if (!isset($decoded['files']) || !is_array($decoded['files'])) {
            $decoded['files'] = [];
        }
        $decoded['version'] = (int)($decoded['version'] ?? 1);
        $decoded['source'] = (string)($decoded['source'] ?? self::SOURCE_UPLOAD);

        return $decoded;
    }

    private function normalizeFileRow(array $file): array
    {
        return [
            'id' => (string)($file['id'] ?? ''),
            'original_name' => (string)($file['original_name'] ?? $file['name'] ?? ''),
            'extension' => (string)($file['extension'] ?? ''),
            'mime' => (string)($file['mime'] ?? ''),
            'bytes' => (int)($file['bytes'] ?? 0),
            'sha256' => (string)($file['sha256'] ?? ''),
            'relative_path' => (string)($file['relative_path'] ?? ''),
            'note' => (string)($file['note'] ?? ''),
            'uploaded_at' => (int)($file['uploaded_at'] ?? 0),
            'uploaded_by' => (int)($file['uploaded_by'] ?? 0),
            'operator_type' => (string)($file['operator_type'] ?? ''),
            'reviewed_at' => (int)($file['reviewed_at'] ?? 0),
        ];
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $path);
                finfo_close($finfo);
                return $mime;
            }
        }

        $info = @getimagesize($path);
        return is_array($info) ? (string)($info['mime'] ?? '') : '';
    }

    private function storageRoot(): string
    {
        return Yii::getAlias('@runtime') . DIRECTORY_SEPARATOR . 'customer-service' . DIRECTORY_SEPARATOR . 'evidence';
    }

    private function relativePath(int $ticketId, int $now, string $sha256, string $extension): string
    {
        return $ticketId . '/' . date('Ymd', $now) . '/' . $sha256 . '.' . $extension;
    }

    private function absolutePathForRow(array $file): string
    {
        $relative = str_replace(['\\', '..'], ['/', ''], (string)($file['relative_path'] ?? ''));
        $path = $this->storageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->storageRoot()), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (strpos($normalized, $root) !== 0) {
            return '';
        }

        return $path;
    }

    private function newEvidenceId(string $sha256): string
    {
        return 'ev-' . date('YmdHis') . '-' . substr($sha256, 0, 12);
    }

    private function normalizeOperatorType(string $operatorType): string
    {
        $operatorType = strtolower(trim($operatorType));
        $allowed = [
            CustomerServiceAdvancedService::OPERATOR_TYPE_PLATFORM,
            CustomerServiceAdvancedService::OPERATOR_TYPE_MERCHANT,
            CustomerServiceAdvancedService::OPERATOR_TYPE_SYSTEM,
        ];

        return in_array($operatorType, $allowed, true) ? $operatorType : CustomerServiceAdvancedService::OPERATOR_TYPE_SYSTEM;
    }

    private function tableExists(string $table): bool
    {
        return Yii::$app->db->schema->getTableSchema($table, true) !== null;
    }
}
