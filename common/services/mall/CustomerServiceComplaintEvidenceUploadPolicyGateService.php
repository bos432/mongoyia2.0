<?php

namespace common\services\mall;

class CustomerServiceComplaintEvidenceUploadPolicyGateService
{
    private const MAX_BYTES = 5242880;

    private const READY_MIMES = [
        'image/png' => ['extensions' => ['png'], 'status' => 'ready'],
        'image/jpeg' => ['extensions' => ['jpg', 'jpeg'], 'status' => 'ready'],
        'image/webp' => ['extensions' => ['webp'], 'status' => 'ready'],
    ];

    private const RESERVED_MIMES = [
        'application/pdf' => 'reserved_for_antivirus_policy',
        'video/mp4' => 'reserved_for_large_media_policy',
        'audio/mpeg' => 'reserved_for_voice_policy',
    ];

    public function run(): array
    {
        $samples = [
            ['name' => 'complaint-proof.png', 'mime' => 'image/png', 'bytes' => 204800],
            ['name' => 'receipt-photo.jpg', 'mime' => 'image/jpeg', 'bytes' => 2097152],
            ['name' => 'chat-screenshot.webp', 'mime' => 'image/webp', 'bytes' => 4718592],
            ['name' => 'oversized-proof.png', 'mime' => 'image/png', 'bytes' => self::MAX_BYTES + 1],
            ['name' => 'supplier-receipt.pdf', 'mime' => 'application/pdf', 'bytes' => 409600],
            ['name' => 'unboxing-video.mp4', 'mime' => 'video/mp4', 'bytes' => 1024000],
            ['name' => 'voice-note.mp3', 'mime' => 'audio/mpeg', 'bytes' => 102400],
            ['name' => 'script.php', 'mime' => 'application/x-php', 'bytes' => 1024],
            ['name' => '../proof.png', 'mime' => 'image/png', 'bytes' => 1024],
        ];

        $rows = [];
        foreach ($samples as $sample) {
            $rows[] = $this->evaluateSample($sample['name'], $sample['mime'], $sample['bytes']);
        }

        return [
            'policyVersion' => 'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_POLICY_GATE_V1',
            'maxBytes' => self::MAX_BYTES,
            'readyMimes' => array_keys(self::READY_MIMES),
            'reservedMimes' => array_keys(self::RESERVED_MIMES),
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'gateChecks' => [
                [
                    'key' => 'backend_upload_controls',
                    'status' => 'disabled',
                    'details' => 'backend complaint evidence upload/write controls remain disabled until transport, audit, storage, and cleanup land together',
                ],
                [
                    'key' => 'ready_image_policy',
                    'status' => 'ready',
                    'details' => 'png, jpg/jpeg, and webp images up to 5 MB are the first enablement set',
                ],
                [
                    'key' => 'reserved_document_media_policy',
                    'status' => 'reserved',
                    'details' => 'pdf, video, and audio remain reserved until antivirus, large-file, preview, and retention rules are implemented',
                ],
                [
                    'key' => 'audit_requirement',
                    'status' => 'ready',
                    'details' => 'future upload apply must append one customer-service event row and preserve ticket workflow status',
                ],
                [
                    'key' => 'cleanup_requirement',
                    'status' => 'reserved',
                    'details' => 'future upload implementation must add cleanup coverage for generated evidence files before backend controls are enabled',
                ],
            ],
            'issues' => [],
        ];
    }

    public function evaluateSample(string $name, string $mime, int $bytes): array
    {
        $name = trim($name);
        $mime = strtolower(trim($mime));
        $bytes = max(0, $bytes);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($name === '' || basename($name) !== $name || strpos($name, "\0") !== false) {
            return $this->row($name, $mime, $bytes, 'reject_path', 'filename must be a basename without traversal');
        }
        if ($bytes <= 0) {
            return $this->row($name, $mime, $bytes, 'reject_empty', 'file size must be greater than zero');
        }
        if ($bytes > self::MAX_BYTES) {
            return $this->row($name, $mime, $bytes, 'reject_size', 'file exceeds complaint evidence image limit');
        }
        if (isset(self::RESERVED_MIMES[$mime])) {
            return $this->row($name, $mime, $bytes, 'reject_reserved', self::RESERVED_MIMES[$mime]);
        }
        if (!isset(self::READY_MIMES[$mime])) {
            return $this->row($name, $mime, $bytes, 'reject_mime', 'mime type is not in the first enablement set');
        }
        if (!in_array($extension, self::READY_MIMES[$mime]['extensions'], true)) {
            return $this->row($name, $mime, $bytes, 'reject_extension', 'extension does not match ready mime policy');
        }

        return $this->row($name, $mime, $bytes, 'accept_policy', 'ready for future upload implementation');
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Customer Service Complaint Evidence Upload Policy Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Policy version: ' . (string)($report['policyVersion'] ?? ''),
            '- Max bytes: ' . (int)($report['maxBytes'] ?? 0),
            '',
            '## Totals',
            '',
            '| Item | Value |',
            '|---|---:|',
        ];

        foreach (($report['totals'] ?? []) as $key => $value) {
            $lines[] = '| ' . $this->escapeCell((string)$key) . ' | ' . (int)$value . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Gate Checks',
            '',
            '| Gate | Status | Details |',
            '|---|---|---|',
        ]);

        foreach (($report['gateChecks'] ?? []) as $check) {
            $lines[] = '| ' . $this->escapeCell((string)$check['key'])
                . ' | ' . $this->escapeCell((string)$check['status'])
                . ' | ' . $this->escapeCell((string)$check['details'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Sample Policy Matrix',
            '',
            '| File | MIME | Bytes | Decision | Reason |',
            '|---|---|---:|---|---|',
        ]);

        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = '| ' . $this->escapeCell((string)$row['name'])
                . ' | ' . $this->escapeCell((string)$row['mime'])
                . ' | ' . (int)$row['bytes']
                . ' | ' . $this->escapeCell((string)$row['decision'])
                . ' | ' . $this->escapeCell((string)$row['reason'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Complaint owner approved first enablement file types: PENDING',
            '- Storage root, retention, and cleanup rules approved: PENDING',
            '- Antivirus or malware scanning policy approved before documents/media: PENDING',
            '- Backend upload/write controls remain disabled: PENDING',
            '',
            'This report is a read-only policy gate. It does not upload files, write evidence_json, create tickets, append events, mutate ticket status, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['name,mime,bytes,decision,reason'];
        foreach (($report['rows'] ?? []) as $row) {
            $lines[] = implode(',', [
                $this->csvCell((string)$row['name']),
                $this->csvCell((string)$row['mime']),
                (int)$row['bytes'],
                $this->csvCell((string)$row['decision']),
                $this->csvCell((string)$row['reason']),
            ]);
        }

        return $lines;
    }

    private function row(string $name, string $mime, int $bytes, string $decision, string $reason): array
    {
        return [
            'name' => $name,
            'mime' => $mime,
            'bytes' => $bytes,
            'decision' => $decision,
            'reason' => $reason,
        ];
    }

    private function totals(array $rows): array
    {
        $totals = [
            'sample_count' => count($rows),
            'accept_policy_count' => 0,
            'reject_size_count' => 0,
            'reject_reserved_count' => 0,
            'reject_mime_count' => 0,
            'reject_path_count' => 0,
        ];
        foreach ($rows as $row) {
            $key = (string)$row['decision'] . '_count';
            if (isset($totals[$key])) {
                $totals[$key]++;
            }
        }

        return $totals;
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
