<?php

namespace common\services\mall;

class ImMediaTransportPolicyGateService
{
    public function run(): array
    {
        $policies = [
            $this->policy(
                'file',
                3,
                20 * 1024 * 1024,
                ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'],
                [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/plain',
                    'text/csv',
                    'application/zip',
                ],
                ['pdf_magic', 'office_zip_container', 'zip_magic', 'text_utf8_or_ascii'],
                '/attachment/chat-file/YYYY/MM/DD/',
                'chat_file_smoke_',
                'generated sha256 basename with original extension'
            ),
            $this->policy(
                'video',
                4,
                50 * 1024 * 1024,
                ['mp4', 'webm'],
                ['video/mp4', 'video/webm'],
                ['mp4_ftyp_box', 'webm_ebml_header'],
                '/attachment/chat-video/YYYY/MM/DD/',
                'chat_video_smoke_',
                'generated sha256 basename with original extension'
            ),
            $this->policy(
                'voice',
                5,
                10 * 1024 * 1024,
                ['mp3', 'm4a', 'ogg', 'webm', 'wav'],
                ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/webm', 'audio/wav'],
                ['mp3_frame_or_id3', 'mp4_ftyp_box', 'ogg_header', 'webm_ebml_header', 'wav_riff_header'],
                '/attachment/chat-voice/YYYY/MM/DD/',
                'chat_voice_smoke_',
                'generated sha256 basename with original extension'
            ),
        ];

        $rollout = [
            $this->rollout('feature_flag', 'future IM_FILE_VIDEO_VOICE_ENABLED flag must default to false in every profile'),
            $this->rollout('permission_gate', 'frontend and customer-service backend controls appear only after upload and Python guards pass'),
            $this->rollout('cleanup_gate', 'generated chat_file_smoke_, chat_video_smoke_, and chat_voice_smoke_ rows/files must be removable by cleanup'),
            $this->rollout('rollback_gate', 'rollback disables controls and rejects msg_type 3/4/5 without touching old text/image messages'),
        ];

        return [
            'policyVersion' => 'MONGOYIA_IM_MEDIA_TRANSPORT_POLICY_GATE_V1',
            'transportEnabled' => false,
            'currentEnabledTypes' => [1, 2],
            'reservedTypes' => [3, 4, 5],
            'policies' => $policies,
            'rollout' => $rollout,
            'gateChecks' => $this->gateChecks($policies, $rollout),
            'issues' => [],
        ];
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia IM Media Transport Policy Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Policy version: ' . (string)($report['policyVersion'] ?? ''),
            '- Transport enabled: ' . (!empty($report['transportEnabled']) ? 'yes' : 'no'),
            '- Current enabled msg types: `' . implode(', ', $report['currentEnabledTypes'] ?? []) . '`',
            '- Reserved msg types: `' . implode(', ', $report['reservedTypes'] ?? []) . '`',
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
            '## Media Policy',
            '',
            '| Media | msg_type | Max bytes | Extensions | MIME allowlist | Signature rules | Storage path | Cleanup prefix |',
            '|---|---:|---:|---|---|---|---|---|',
        ]);
        foreach (($report['policies'] ?? []) as $policy) {
            $lines[] = '| ' . $this->escapeCell((string)$policy['media'])
                . ' | ' . (int)$policy['msg_type']
                . ' | ' . (int)$policy['max_bytes']
                . ' | `' . $this->escapeCell(implode(', ', $policy['extensions'] ?? [])) . '`'
                . ' | `' . $this->escapeCell(implode(', ', $policy['mime_allowlist'] ?? [])) . '`'
                . ' | `' . $this->escapeCell(implode(', ', $policy['signature_rules'] ?? [])) . '`'
                . ' | `' . $this->escapeCell((string)$policy['storage_path']) . '`'
                . ' | `' . $this->escapeCell((string)$policy['cleanup_prefix']) . '`'
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Rollout Rules',
            '',
            '| Rule | Requirement |',
            '|---|---|',
        ]);
        foreach (($report['rollout'] ?? []) as $rule) {
            $lines[] = '| ' . $this->escapeCell((string)$rule['key'])
                . ' | ' . $this->escapeCell((string)$rule['requirement'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- Business approval for allowed file/video/voice formats: PENDING',
            '- Security approval for MIME/signature/path rules: PENDING',
            '- Storage quota and retention owner confirmed: PENDING',
            '- WSS regression and cleanup evidence after implementation: PENDING',
            '',
            'This report is a read-only policy gate. It does not enable msg_type 3/4/5, upload files, create directories, delete files, write chat messages, mutate orders, change payments, write fund logs, or expose file/video/voice UI controls.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['type,name,value,details'];
        foreach (($report['policies'] ?? []) as $policy) {
            $lines[] = implode(',', [
                'policy',
                $this->csvCell((string)$policy['media']),
                (int)$policy['msg_type'],
                $this->csvCell(
                    'max_bytes=' . (int)$policy['max_bytes']
                    . '; extensions=' . implode('|', $policy['extensions'] ?? [])
                    . '; mime=' . implode('|', $policy['mime_allowlist'] ?? [])
                    . '; signatures=' . implode('|', $policy['signature_rules'] ?? [])
                    . '; storage=' . (string)$policy['storage_path']
                    . '; cleanup=' . (string)$policy['cleanup_prefix']
                ),
            ]);
        }
        foreach (($report['rollout'] ?? []) as $rule) {
            $lines[] = implode(',', [
                'rollout',
                $this->csvCell((string)$rule['key']),
                '',
                $this->csvCell((string)$rule['requirement']),
            ]);
        }

        return $lines;
    }

    private function policy(
        string $media,
        int $msgType,
        int $maxBytes,
        array $extensions,
        array $mimeAllowlist,
        array $signatureRules,
        string $storagePath,
        string $cleanupPrefix,
        string $filenameRule
    ): array {
        return [
            'media' => $media,
            'msg_type' => $msgType,
            'max_bytes' => $maxBytes,
            'extensions' => $extensions,
            'mime_allowlist' => $mimeAllowlist,
            'signature_rules' => $signatureRules,
            'storage_path' => $storagePath,
            'cleanup_prefix' => $cleanupPrefix,
            'filename_rule' => $filenameRule,
        ];
    }

    private function rollout(string $key, string $requirement): array
    {
        return [
            'key' => $key,
            'requirement' => $requirement,
        ];
    }

    private function gateChecks(array $policies, array $rollout): array
    {
        return [
            [
                'key' => 'runtime_boundary',
                'status' => 'disabled',
                'details' => 'current runtime remains text/image only and msg_type 3/4/5 must stay rejected',
            ],
            [
                'key' => 'policy_scope',
                'status' => count($policies) === 3 ? 'ready' : 'blocked',
                'details' => 'file, video, and voice policies are all defined',
            ],
            [
                'key' => 'size_limits',
                'status' => $this->hasExpectedSizeLimits($policies) ? 'ready' : 'blocked',
                'details' => 'file 20 MB, video 50 MB, and voice 10 MB limits are fixed before implementation',
            ],
            [
                'key' => 'type_and_signature_guards',
                'status' => $this->hasTypeAndSignatureGuards($policies) ? 'ready' : 'blocked',
                'details' => 'extension, MIME, and content-signature rules are required for every media family',
            ],
            [
                'key' => 'storage_cleanup_scope',
                'status' => $this->hasStorageAndCleanupScope($policies) ? 'ready' : 'blocked',
                'details' => 'storage paths and cleanup prefixes are scoped per media family',
            ],
            [
                'key' => 'rollout_and_rollback',
                'status' => count($rollout) === 4 ? 'ready' : 'blocked',
                'details' => 'feature flag, permission gate, cleanup gate, and rollback gate are documented',
            ],
        ];
    }

    private function hasExpectedSizeLimits(array $policies): bool
    {
        $expected = [
            'file' => 20 * 1024 * 1024,
            'video' => 50 * 1024 * 1024,
            'voice' => 10 * 1024 * 1024,
        ];
        foreach ($expected as $media => $maxBytes) {
            $policy = $this->findPolicy($policies, $media);
            if (!$policy || (int)$policy['max_bytes'] !== $maxBytes) {
                return false;
            }
        }

        return true;
    }

    private function hasTypeAndSignatureGuards(array $policies): bool
    {
        foreach (['file', 'video', 'voice'] as $media) {
            $policy = $this->findPolicy($policies, $media);
            if (!$policy || empty($policy['extensions']) || empty($policy['mime_allowlist']) || empty($policy['signature_rules'])) {
                return false;
            }
        }

        return true;
    }

    private function hasStorageAndCleanupScope(array $policies): bool
    {
        foreach (['file', 'video', 'voice'] as $media) {
            $policy = $this->findPolicy($policies, $media);
            if (!$policy) {
                return false;
            }
            if (strpos((string)$policy['storage_path'], '/attachment/chat-' . $media . '/') !== 0) {
                return false;
            }
            if ((string)$policy['cleanup_prefix'] !== 'chat_' . $media . '_smoke_') {
                return false;
            }
        }

        return true;
    }

    private function findPolicy(array $policies, string $media): ?array
    {
        foreach ($policies as $policy) {
            if ((string)($policy['media'] ?? '') === $media) {
                return $policy;
            }
        }

        return null;
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
