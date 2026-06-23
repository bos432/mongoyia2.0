<?php

namespace common\services\mall;

class OperationalPhase10ReadinessService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_PHASE10_READINESS_V1';

    private $rootPath;

    public function __construct(string $rootPath = '')
    {
        $this->rootPath = $rootPath !== '' ? $rootPath : dirname(__DIR__, 3);
    }

    public function snapshot(string $environment = 'test'): array
    {
        $providerEvidence = (new OperationalProviderEvidenceService())->snapshot($environment);
        $providerRows = [];
        foreach (($providerEvidence['providers'] ?? []) as $provider) {
            $latest = $provider['latest_check'] ?? [];
            $providerRows[] = [
                'name' => $provider['label'] ?? $provider['provider'],
                'result' => $latest['result'] ?? 'PENDING',
                'message' => $latest['message'] ?? '等待服务商证据。',
            ];
        }

        $providerResult = $this->rollupResult(array_column($providerRows, 'result'));
        $launch = (new OperationalLaunchSignoffService())->snapshot();
        $launchReadiness = $launch['readiness'] ?? [];
        $redactedExport = $this->latestReport('runtime/handover', 'mongoyia-operational-config-redacted-export-*.md');
        $goLiveGate = $this->latestReport('runtime/handover', 'mongoyia-production-go-live-gate-*.md');

        $rows = [
            [
                'key' => 'provider_evidence',
                'name' => '服务商证据',
                'result' => $providerResult,
                'evidence' => 'provider_evidence readiness rows',
                'message' => $providerResult === 'PASS' ? '服务商证据已齐全。' : '仍有服务商证据缺失或疑似包含敏感信息。',
            ],
            [
                'key' => 'launch_signoff',
                'name' => '上线签核',
                'result' => $launchReadiness['result'] ?? 'PENDING',
                'evidence' => 'operational launch signoff',
                'message' => $launchReadiness['message'] ?? '等待上线签核。',
            ],
            [
                'key' => 'redacted_export',
                'name' => '脱敏配置导出',
                'result' => $redactedExport['result'],
                'evidence' => $redactedExport['path'],
                'message' => $redactedExport['message'],
            ],
            [
                'key' => 'production_go_live_gate',
                'name' => '生产 GO/NO-GO gate',
                'result' => $goLiveGate['result'],
                'evidence' => $goLiveGate['path'],
                'message' => $goLiveGate['message'],
            ],
        ];

        $result = $this->rollupResult(array_column($rows, 'result'));
        return [
            'version' => self::VERSION,
            'environment' => $environment,
            'result' => $result,
            'decision' => $result === 'PASS' ? 'GO-READY' : 'NO-GO',
            'rows' => $rows,
            'provider_rows' => $providerRows,
        ];
    }

    private function latestReport(string $dir, string $pattern): array
    {
        $base = $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
        $files = is_dir($base) ? glob($base . DIRECTORY_SEPARATOR . $pattern) : [];
        if (empty($files)) {
            return [
                'result' => 'PENDING',
                'path' => $dir . '/' . $pattern,
                'message' => '尚未生成报告。',
            ];
        }
        rsort($files);
        $path = (string)$files[0];
        $content = (string)file_get_contents($path);
        $result = $this->readReportResult($content);

        return [
            'result' => $result,
            'path' => $this->relativePath($path),
            'message' => $result === 'PASS' ? '最新报告为 PASS。' : '最新报告不是 PASS，请查看报告。',
        ];
    }

    private function readReportResult(string $content): string
    {
        if (preg_match('/^- Result:\s*(PASS|WARN|FAIL|PENDING|BLOCKED)/mi', $content, $matches)) {
            return strtoupper($matches[1]);
        }
        if (preg_match('/^- Final decision:\s*GO/mi', $content)) {
            return 'PASS';
        }
        if (preg_match('/^- Final decision:\s*NO-GO/mi', $content)) {
            return 'PENDING';
        }

        return 'WARN';
    }

    private function rollupResult(array $results): string
    {
        $normalized = array_map('strtoupper', array_filter(array_map('strval', $results)));
        if (empty($normalized)) {
            return 'PENDING';
        }
        if (in_array('FAIL', $normalized, true) || in_array('BLOCKED', $normalized, true)) {
            return 'FAIL';
        }
        if (in_array('WARN', $normalized, true)) {
            return 'WARN';
        }
        if (in_array('PENDING', $normalized, true)) {
            return 'PENDING';
        }

        return 'PASS';
    }

    private function relativePath(string $path): string
    {
        $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->rootPath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (strpos($normalizedPath, $normalizedRoot) === 0) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($normalizedPath, strlen($normalizedRoot)));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
