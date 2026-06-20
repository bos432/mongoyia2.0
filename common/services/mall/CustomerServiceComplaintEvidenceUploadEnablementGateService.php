<?php

namespace common\services\mall;

class CustomerServiceComplaintEvidenceUploadEnablementGateService
{
    public function run(): array
    {
        $contracts = [
            $this->contract('permission_contract', '/mall/kf/complaint-evidence-upload', 'future backend upload must require an explicit permission before controls are enabled'),
            $this->contract('ui_control_contract', 'data-mongoyia-customer-service-complaint-evidence-upload="disabled"', 'backend view must expose a disabled marker until real upload handling lands'),
            $this->contract('audit_event_contract', 'customer-service-complaint-evidence-upload', 'future upload must append one audited customer-service event and preserve ticket status'),
            $this->contract('rollback_cleanup_contract', 'tmp -> reviewed storage -> evidence_json', 'future upload must delete tmp files on failure and only promote reviewed files after validation'),
        ];
        $preconditions = [
            $this->precondition('upload policy gate', 'customer-service-complaint-evidence-upload-policy-gate/run --fixture=1'),
            $this->precondition('upload implementation gate', 'customer-service-complaint-evidence-upload-implementation-gate/run --fixture=1'),
            $this->precondition('upload cleanup readiness', 'customer-service-complaint-evidence-upload-cleanup-readiness/run --fixture=1'),
            $this->precondition('audited evidence apply workflow', 'customer-service-complaint-evidence-apply-workflow/run --fixture=1'),
        ];

        return [
            'policyVersion' => 'MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_ENABLEMENT_GATE_V1',
            'backendRoute' => '/backend/mall/kf/complaint-evidence-upload',
            'permission' => '/mall/kf/complaint-evidence-upload',
            'uiMarker' => 'data-mongoyia-customer-service-complaint-evidence-upload="disabled"',
            'controlsEnabled' => false,
            'contracts' => $contracts,
            'preconditions' => $preconditions,
            'enablementPlan' => [
                'mode' => 'gate-only',
                'backendActionPresent' => false,
                'fileInputPresent' => false,
                'requiresCsrf' => true,
                'requiresStoreScope' => true,
                'preserveTicketStatus' => true,
                'auditEventRequired' => true,
                'cleanupRequired' => true,
            ],
            'gateChecks' => $this->gateChecks($contracts, $preconditions),
            'issues' => [],
        ];
    }

    public function markdownLines(array $report): array
    {
        $lines = [
            '# Mongoyia Customer Service Complaint Evidence Upload Enablement Gate',
            '',
            '- Result: ' . (empty($report['issues']) ? 'PASS' : 'WARN'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Policy version: ' . (string)($report['policyVersion'] ?? ''),
            '- Backend route: ' . (string)($report['backendRoute'] ?? ''),
            '- Permission: ' . (string)($report['permission'] ?? ''),
            '- UI marker: `' . (string)($report['uiMarker'] ?? '') . '`',
            '- Controls enabled: ' . (!empty($report['controlsEnabled']) ? 'yes' : 'no'),
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
            '## Enablement Contracts',
            '',
            '| Contract | Required Marker | Details |',
            '|---|---|---|',
        ]);
        foreach (($report['contracts'] ?? []) as $contract) {
            $lines[] = '| ' . $this->escapeCell((string)$contract['key'])
                . ' | `' . $this->escapeCell((string)$contract['marker']) . '`'
                . ' | ' . $this->escapeCell((string)$contract['details'])
                . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Required Preconditions',
            '',
            '| Name | Command |',
            '|---|---|',
        ]);
        foreach (($report['preconditions'] ?? []) as $precondition) {
            $lines[] = '| ' . $this->escapeCell((string)$precondition['name'])
                . ' | `' . $this->escapeCell((string)$precondition['command']) . '`'
                . ' |';
        }

        return array_merge($lines, [
            '',
            '## Enablement Plan Contract',
            '',
            '```json',
            json_encode($report['enablementPlan'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '```',
            '',
            '## Signoff Checklist',
            '',
            '- Upload permission and seller/platform scope approved: PENDING',
            '- Backend upload form/action reviewed with CSRF and disabled-by-default rollout: PENDING',
            '- Audit event metadata and unchanged ticket status verified: PENDING',
            '- Cleanup readiness and rollback behavior verified before enablement: PENDING',
            '',
            'This report is a read-only enablement gate. It does not upload files, create directories, delete files, write evidence_json, create tickets, append events, mutate ticket status, send IM messages, change orders, change payments, write fund logs, update statistics, or enable backend complaint evidence controls.',
        ]);
    }

    public function csvLines(array $report): array
    {
        $lines = ['type,name,marker_or_command,details'];
        foreach (($report['contracts'] ?? []) as $contract) {
            $lines[] = implode(',', [
                'contract',
                $this->csvCell((string)$contract['key']),
                $this->csvCell((string)$contract['marker']),
                $this->csvCell((string)$contract['details']),
            ]);
        }
        foreach (($report['preconditions'] ?? []) as $precondition) {
            $lines[] = implode(',', [
                'precondition',
                $this->csvCell((string)$precondition['name']),
                $this->csvCell((string)$precondition['command']),
                '',
            ]);
        }

        return $lines;
    }

    private function contract(string $key, string $marker, string $details): array
    {
        return [
            'key' => $key,
            'marker' => $marker,
            'details' => $details,
        ];
    }

    private function precondition(string $name, string $command): array
    {
        return [
            'name' => $name,
            'command' => $command,
        ];
    }

    private function gateChecks(array $contracts, array $preconditions): array
    {
        return [
            [
                'key' => 'backend_upload_controls',
                'status' => 'disabled',
                'details' => 'backend complaint evidence upload controls stay disabled until this gate is replaced by a tested upload workflow',
            ],
            [
                'key' => 'backend_action_contract',
                'status' => $this->hasContract($contracts, 'permission_contract') ? 'ready' : 'blocked',
                'details' => 'future POST action requires an explicit backend permission and store-scoped ticket validation',
            ],
            [
                'key' => 'ui_disabled_marker_contract',
                'status' => $this->hasContract($contracts, 'ui_control_contract') ? 'ready' : 'blocked',
                'details' => 'backend view must keep disabled upload marker until controls are intentionally enabled',
            ],
            [
                'key' => 'audit_event_contract',
                'status' => $this->hasContract($contracts, 'audit_event_contract') ? 'ready' : 'blocked',
                'details' => 'future upload records one audit event with source and file count while preserving ticket status',
            ],
            [
                'key' => 'rollback_cleanup_contract',
                'status' => $this->hasContract($contracts, 'rollback_cleanup_contract') ? 'ready' : 'blocked',
                'details' => 'future upload failure path deletes tmp files and leaves evidence_json unchanged',
            ],
            [
                'key' => 'precondition_chain',
                'status' => count($preconditions) >= 4 ? 'ready' : 'blocked',
                'details' => 'policy, implementation, cleanup readiness, and audited apply workflow gates must pass before enablement',
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
