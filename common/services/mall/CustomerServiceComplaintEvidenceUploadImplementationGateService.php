<?php

namespace common\services\mall;

use Yii;

class CustomerServiceComplaintEvidenceUploadImplementationGateService
{
    public function run(): array
    {
        $storageRoot = Yii::getAlias('@runtime') . DIRECTORY_SEPARATOR . 'customer-service' . DIRECTORY_SEPARATOR . 'complaint-evidence';
        $webRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'web';
        $sampleFiles = [
            $this->sampleFile('proof.png', 'image/png', 204800, '9b2c1f0c4f0a2d3d8d7d6c5b4a3928172635445566778899aabbccddeeff0011'),
            $this->sampleFile('receipt.jpg', 'image/jpeg', 1048576, '7a2c1f0c4f0a2d3d8d7d6c5b4a3928172635445566778899aabbccddeeff0022'),
        ];
        $evidenceJson = $this->evidenceJson($sampleFiles);
        $auditMetadata = $this->auditMetadata($sampleFiles);

        return [
            'policyVersion' => 'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_IMPLEMENTATION_GATE_V1',
            'storageRoot' => $storageRoot,
            'webRoot' => $webRoot,
            'storageRootInsideWeb' => $this->isInside($storageRoot, $webRoot),
            'sampleFiles' => $sampleFiles,
            'evidenceJson' => $evidenceJson,
            'auditMetadata' => $auditMetadata,
            'cleanupPatterns' => [
                'runtime/customer-service/complaint-evidence/fixture-*',
                'runtime/customer-service/complaint-evidence/tmp-*',
            ],
            'gateChecks' => $this->gateChecks($storageRoot, $webRoot, $evidenceJson, $auditMetadata),
            'issues' => [],
        ];
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Customer Service Complaint Evidence Upload Implementation Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Policy version: ' . (string)($report['policyVersion'] ?? ''),
            '- Storage root: ' . $this->escapeCell((string)($report['storageRoot'] ?? '')),
            '- Web root: ' . $this->escapeCell((string)($report['webRoot'] ?? '')),
            '- Storage inside web root: ' . (!empty($report['storageRootInsideWeb']) ? 'yes' : 'no'),
            '',
            '## Gate Checks',
            '',
            '| Gate | Status | Details |',
            '|---|---|---|',
        ];

        foreach (($report['gateChecks'] ?? []) as $check) {
            $lines[] = '| ' . $this->escapeCell((string)$check['key'])
                . ' | ' . $this->escapeCell((string)$check['status'])
                . ' | ' . $this->escapeCell((string)$check['details'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Planned Evidence Files',
            '',
            '| Name | MIME | Bytes | Storage key | SHA256 |',
            '|---|---|---:|---|---|',
        ]);
        foreach (($report['sampleFiles'] ?? []) as $file) {
            $lines[] = '| ' . $this->escapeCell((string)$file['name'])
                . ' | ' . $this->escapeCell((string)$file['mime'])
                . ' | ' . (int)$file['bytes']
                . ' | ' . $this->escapeCell((string)$file['storage_key'])
                . ' | ' . $this->escapeCell((string)$file['sha256'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Evidence JSON Contract',
            '',
            '```json',
            json_encode(json_decode((string)($report['evidenceJson'] ?? '{}'), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '```',
            '',
            '## Audit Metadata Contract',
            '',
            '```json',
            json_encode(json_decode((string)($report['auditMetadata'] ?? '{}'), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '```',
            '',
            '## Cleanup Patterns',
            '',
        ]);
        foreach (($report['cleanupPatterns'] ?? []) as $pattern) {
            $lines[] = '- `' . $pattern . '`';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Storage root and retention window approved: PENDING',
            '- Cleanup command coverage approved before backend enablement: PENDING',
            '- Audit event metadata approved before backend enablement: PENDING',
            '- Backend upload controls remain disabled: PENDING',
            '',
            'This report is a read-only implementation gate. It does not upload files, create directories, write evidence_json, create tickets, append events, mutate ticket status, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['name,mime,bytes,storage_key,sha256'];
        foreach (($report['sampleFiles'] ?? []) as $file) {
            $lines[] = implode(',', [
                $this->csvCell((string)$file['name']),
                $this->csvCell((string)$file['mime']),
                (int)$file['bytes'],
                $this->csvCell((string)$file['storage_key']),
                $this->csvCell((string)$file['sha256']),
            ]);
        }

        return $lines;
    }

    private function sampleFile(string $name, string $mime, int $bytes, string $sha256): array
    {
        return [
            'name' => $name,
            'mime' => $mime,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'storage_key' => 'customer-service/complaint-evidence/{ticket_id}/{yyyymmdd}/' . $sha256 . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION)),
        ];
    }

    private function evidenceJson(array $files): string
    {
        return json_encode([
            'version' => 1,
            'source' => 'customer-service-complaint-evidence-upload',
            'files' => $files,
            'uploaded_at' => '{unix_timestamp}',
            'uploaded_by' => '{operator_user_id}',
        ], JSON_UNESCAPED_SLASHES);
    }

    private function auditMetadata(array $files): string
    {
        return json_encode([
            'source' => 'customer-service-complaint-evidence-upload',
            'file_count' => count($files),
            'storage_root' => '@runtime/customer-service/complaint-evidence',
            'preserve_ticket_status' => true,
            'cleanup_required' => true,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function gateChecks(string $storageRoot, string $webRoot, string $evidenceJson, string $auditMetadata): array
    {
        return [
            [
                'key' => 'backend_upload_controls',
                'status' => 'disabled',
                'details' => 'backend complaint evidence upload controls stay disabled until this implementation gate is replaced by a tested upload workflow',
            ],
            [
                'key' => 'storage_root_outside_web',
                'status' => $this->isInside($storageRoot, $webRoot) ? 'blocked' : 'ready',
                'details' => 'planned storage root must stay outside the public web root',
            ],
            [
                'key' => 'storage_key_contract',
                'status' => 'ready',
                'details' => 'storage keys include ticket id, date, sha256, and extension without trusting the original file path',
            ],
            [
                'key' => 'evidence_json_contract',
                'status' => $this->validJsonObject($evidenceJson) ? 'ready' : 'blocked',
                'details' => 'evidence_json stores source, version, files, uploaded_at, and uploaded_by fields',
            ],
            [
                'key' => 'audit_event_contract',
                'status' => $this->validJsonObject($auditMetadata) ? 'ready' : 'blocked',
                'details' => 'future apply must append one customer-service event row and preserve ticket workflow status',
            ],
            [
                'key' => 'cleanup_contract',
                'status' => 'ready',
                'details' => 'future cleanup must target only generated complaint-evidence fixture/tmp paths',
            ],
        ];
    }

    private function validJsonObject(string $json): bool
    {
        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    private function isInside(string $path, string $root): bool
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($path . DIRECTORY_SEPARATOR, $root) === 0;
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
}
