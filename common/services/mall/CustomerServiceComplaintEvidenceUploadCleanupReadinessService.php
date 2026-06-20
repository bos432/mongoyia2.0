<?php

namespace common\services\mall;

use Yii;

class CustomerServiceComplaintEvidenceUploadCleanupReadinessService
{
    public function run(): array
    {
        $storageRoot = Yii::getAlias('@runtime') . DIRECTORY_SEPARATOR . 'customer-service' . DIRECTORY_SEPARATOR . 'complaint-evidence';
        $webRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'web';
        $cleanupScopes = [
            $this->scope('fixture evidence directories', 'runtime/customer-service/complaint-evidence/fixture-*', 'delete generated fixture directories only'),
            $this->scope('temporary evidence directories', 'runtime/customer-service/complaint-evidence/tmp-*', 'delete generated temporary directories only'),
        ];
        $excludedScopes = [
            $this->scope('reviewed evidence storage', 'runtime/customer-service/complaint-evidence/{ticket_id}/{yyyymmdd}/sha256.ext', 'preserve uploaded evidence referenced by evidence_json'),
            $this->scope('public web files', 'web/uploads/*', 'never clean public uploads through complaint-evidence cleanup'),
            $this->scope('runtime handover evidence', 'runtime/handover/mongoyia-customer-service-complaint-evidence-*.md', 'preserve generated acceptance evidence reports'),
        ];

        return [
            'policyVersion' => 'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_CLEANUP_READINESS_V1',
            'storageRoot' => $storageRoot,
            'webRoot' => $webRoot,
            'storageRootInsideWeb' => $this->isInside($storageRoot, $webRoot),
            'cleanupScopes' => $cleanupScopes,
            'excludedScopes' => $excludedScopes,
            'cleanupPlan' => [
                'mode' => 'dry-run-first',
                'applyGuard' => 'COMPLAINT_EVIDENCE_CLEANUP_APPLY',
                'retentionHours' => 24,
                'businessRowsMutable' => false,
                'backendControlsEnabled' => false,
            ],
            'gateChecks' => $this->gateChecks($storageRoot, $webRoot, $cleanupScopes, $excludedScopes),
            'issues' => [],
        ];
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Customer Service Complaint Evidence Upload Cleanup Readiness',
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
            '## Cleanup Scope',
            '',
            '| Name | Pattern | Action |',
            '|---|---|---|',
        ]);
        foreach (($report['cleanupScopes'] ?? []) as $scope) {
            $lines[] = '| ' . $this->escapeCell((string)$scope['name'])
                . ' | `' . $this->escapeCell((string)$scope['pattern']) . '`'
                . ' | ' . $this->escapeCell((string)$scope['action'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Excluded Scope',
            '',
            '| Name | Pattern | Reason |',
            '|---|---|---|',
        ]);
        foreach (($report['excludedScopes'] ?? []) as $scope) {
            $lines[] = '| ' . $this->escapeCell((string)$scope['name'])
                . ' | `' . $this->escapeCell((string)$scope['pattern']) . '`'
                . ' | ' . $this->escapeCell((string)$scope['action'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Cleanup Plan Contract',
            '',
            '```json',
            json_encode($report['cleanupPlan'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '```',
            '',
            '## Signoff Checklist',
            '',
            '- Dry-run cleanup report reviewed before apply: PENDING',
            '- Retention window approved before backend upload enablement: PENDING',
            '- Scoped cleanup command coverage approved before backend upload enablement: PENDING',
            '- Backend upload controls remain disabled: PENDING',
            '',
            'This report is a read-only cleanup readiness gate. It does not upload files, create directories, delete files, write evidence_json, create tickets, append events, mutate ticket status, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.',
        ]);

        return $lines;
    }

    public function csvLines(array $report): array
    {
        $lines = ['type,name,pattern,action'];
        foreach (($report['cleanupScopes'] ?? []) as $scope) {
            $lines[] = implode(',', [
                'cleanup',
                $this->csvCell((string)$scope['name']),
                $this->csvCell((string)$scope['pattern']),
                $this->csvCell((string)$scope['action']),
            ]);
        }
        foreach (($report['excludedScopes'] ?? []) as $scope) {
            $lines[] = implode(',', [
                'excluded',
                $this->csvCell((string)$scope['name']),
                $this->csvCell((string)$scope['pattern']),
                $this->csvCell((string)$scope['action']),
            ]);
        }

        return $lines;
    }

    private function scope(string $name, string $pattern, string $action): array
    {
        return [
            'name' => $name,
            'pattern' => $pattern,
            'action' => $action,
        ];
    }

    private function gateChecks(string $storageRoot, string $webRoot, array $cleanupScopes, array $excludedScopes): array
    {
        return [
            [
                'key' => 'backend_upload_controls',
                'status' => 'disabled',
                'details' => 'backend complaint evidence upload and cleanup controls stay disabled until the real workflow lands',
            ],
            [
                'key' => 'storage_root_outside_web',
                'status' => $this->isInside($storageRoot, $webRoot) ? 'blocked' : 'ready',
                'details' => 'cleanup scope must remain outside public web root',
            ],
            [
                'key' => 'cleanup_scope_contract',
                'status' => $this->hasPattern($cleanupScopes, 'fixture-*') && $this->hasPattern($cleanupScopes, 'tmp-*') ? 'ready' : 'blocked',
                'details' => 'cleanup targets generated fixture-* and tmp-* evidence paths only',
            ],
            [
                'key' => 'cleanup_exclusion_contract',
                'status' => $this->hasPattern($excludedScopes, '{ticket_id}') && $this->hasPattern($excludedScopes, 'runtime/handover') ? 'ready' : 'blocked',
                'details' => 'reviewed ticket evidence and handover reports are outside cleanup scope',
            ],
            [
                'key' => 'apply_guard_contract',
                'status' => 'ready',
                'details' => 'future destructive cleanup must run dry-run first and require COMPLAINT_EVIDENCE_CLEANUP_APPLY',
            ],
            [
                'key' => 'business_data_contract',
                'status' => 'ready',
                'details' => 'cleanup readiness and future file cleanup must not mutate tickets, events, orders, payments, chats, funds, or statistics',
            ],
        ];
    }

    private function hasPattern(array $scopes, string $needle): bool
    {
        foreach ($scopes as $scope) {
            if (strpos((string)($scope['pattern'] ?? ''), $needle) !== false) {
                return true;
            }
        }

        return false;
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
