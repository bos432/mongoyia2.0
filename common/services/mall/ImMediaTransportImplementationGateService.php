<?php

namespace common\services\mall;

class ImMediaTransportImplementationGateService
{
    public function run(): array
    {
        $futureMedia = [
            $this->media('file', 3, '20 MB', '/attachment/chat-file/YYYY/MM/DD/', 'chat_file_smoke_'),
            $this->media('video', 4, '50 MB', '/attachment/chat-video/YYYY/MM/DD/', 'chat_video_smoke_'),
            $this->media('voice', 5, '10 MB', '/attachment/chat-voice/YYYY/MM/DD/', 'chat_voice_smoke_'),
        ];
        $contracts = [
            $this->contract('php_upload_contract', 'size, extension, MIME, body-signature, filename-prefix, and storage-path guards for each media family'),
            $this->contract('python_payload_contract', 'Python IM validates msg_type 3/4/5 local attachment paths and rejects remote URLs, traversal, backslashes, controls, and oversized payloads'),
            $this->contract('ui_control_contract', 'frontend and backend customer-service UI expose stable file/video/voice markers only when backend and Python guards land'),
            $this->contract('regression_contract', 'im-regression covers valid media payloads, invalid media payloads, and rejected-message non-persistence'),
            $this->contract('cleanup_contract', 'mongoyia-test-cleanup removes generated chat_file_smoke_, chat_video_smoke_, and chat_voice_smoke_ samples'),
            $this->contract('rollout_contract', 'file/video/voice transport remains disabled until all gates pass in one reviewed increment'),
        ];

        return [
            'policyVersion' => 'MONGOYIA_IM_MEDIA_TRANSPORT_IMPLEMENTATION_GATE_V1',
            'currentEnabledTypes' => [1, 2],
            'reservedTypes' => [3, 4, 5],
            'controlsEnabled' => false,
            'futureMedia' => $futureMedia,
            'contracts' => $contracts,
            'gateChecks' => $this->gateChecks($futureMedia, $contracts),
            'issues' => [],
        ];
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia IM Media Transport Implementation Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Policy version: ' . (string)($report['policyVersion'] ?? ''),
            '- Current enabled msg types: `' . implode(', ', $report['currentEnabledTypes'] ?? []) . '`',
            '- Reserved msg types: `' . implode(', ', $report['reservedTypes'] ?? []) . '`',
            '- File/video/voice controls enabled: ' . (!empty($report['controlsEnabled']) ? 'yes' : 'no'),
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
            '## Future Media Families',
            '',
            '| Media | msg_type | Max size | Storage path | Cleanup prefix |',
            '|---|---:|---:|---|---|',
        ]);
        foreach (($report['futureMedia'] ?? []) as $media) {
            $lines[] = '| ' . $this->escapeCell((string)$media['media'])
                . ' | ' . (int)$media['msg_type']
                . ' | ' . $this->escapeCell((string)$media['max_size'])
                . ' | `' . $this->escapeCell((string)$media['storage_path']) . '`'
                . ' | `' . $this->escapeCell((string)$media['cleanup_prefix']) . '`'
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Implementation Contracts',
            '',
            '| Contract | Requirement |',
            '|---|---|',
        ]);
        foreach (($report['contracts'] ?? []) as $contract) {
            $lines[] = '| ' . $this->escapeCell((string)$contract['key'])
                . ' | ' . $this->escapeCell((string)$contract['requirement'])
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Signoff Checklist',
            '',
            '- PHP upload guards for file/video/voice reviewed: PENDING',
            '- Python IM payload rules and regression scripts reviewed: PENDING',
            '- Frontend and backend UI controls reviewed with disabled-by-default rollout: PENDING',
            '- Smoke upload cleanup and generated message cleanup verified: PENDING',
            '',
            'This report is a read-only transport implementation gate. It does not enable msg_type 3/4/5, upload files, create directories, delete files, write chat messages, mutate orders, change payments, write fund logs, or enable file/video/voice UI controls.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['type,name,value,details'];
        foreach (($report['futureMedia'] ?? []) as $media) {
            $lines[] = implode(',', [
                'media',
                $this->csvCell((string)$media['media']),
                (int)$media['msg_type'],
                $this->csvCell((string)$media['storage_path'] . ' ' . (string)$media['cleanup_prefix']),
            ]);
        }
        foreach (($report['contracts'] ?? []) as $contract) {
            $lines[] = implode(',', [
                'contract',
                $this->csvCell((string)$contract['key']),
                '',
                $this->csvCell((string)$contract['requirement']),
            ]);
        }

        return $lines;
    }

    private function media(string $media, int $msgType, string $maxSize, string $storagePath, string $cleanupPrefix): array
    {
        return [
            'media' => $media,
            'msg_type' => $msgType,
            'max_size' => $maxSize,
            'storage_path' => $storagePath,
            'cleanup_prefix' => $cleanupPrefix,
        ];
    }

    private function contract(string $key, string $requirement): array
    {
        return [
            'key' => $key,
            'requirement' => $requirement,
        ];
    }

    private function gateChecks(array $futureMedia, array $contracts): array
    {
        return [
            [
                'key' => 'runtime_boundary',
                'status' => 'disabled',
                'details' => 'current runtime remains text/image only; msg_type 3/4/5 must stay rejected',
            ],
            [
                'key' => 'future_media_contract',
                'status' => count($futureMedia) === 3 ? 'ready' : 'blocked',
                'details' => 'file, video, and voice media families have proposed msg types, storage paths, and cleanup prefixes',
            ],
            [
                'key' => 'php_upload_contract',
                'status' => $this->hasContract($contracts, 'php_upload_contract') ? 'ready' : 'blocked',
                'details' => 'future PHP upload endpoints must validate size, type, content signature, and storage path',
            ],
            [
                'key' => 'python_payload_contract',
                'status' => $this->hasContract($contracts, 'python_payload_contract') ? 'ready' : 'blocked',
                'details' => 'future Python IM payload validation must be updated before msg_type 3/4/5 is accepted',
            ],
            [
                'key' => 'ui_regression_cleanup_contract',
                'status' => $this->hasContract($contracts, 'ui_control_contract')
                    && $this->hasContract($contracts, 'regression_contract')
                    && $this->hasContract($contracts, 'cleanup_contract') ? 'ready' : 'blocked',
                'details' => 'future UI controls, regression scripts, and cleanup rules must land together',
            ],
            [
                'key' => 'rollout_contract',
                'status' => $this->hasContract($contracts, 'rollout_contract') ? 'ready' : 'blocked',
                'details' => 'file/video/voice transport remains disabled until one reviewed implementation increment satisfies every gate',
            ],
        ];
    }

    private function hasContract(array $contracts, string $key): bool
    {
        foreach ($contracts as $contract) {
            if ((string)($contract['key'] ?? '') === $key) {
                return true;
            }
        }

        return false;
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
