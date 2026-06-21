<?php

namespace common\services\mall;

class OperationalLaunchSignoffService
{
    public const VERSION = 'MONGOYIA_OPERATIONAL_LAUNCH_SIGNOFF_CENTER_V1';

    private $configService;

    public function __construct(?OperationalConfigService $configService = null)
    {
        $this->configService = $configService ?: new OperationalConfigService();
    }

    public function fields(): array
    {
        return [
            'load_test_report_ref' => $this->field('压测报告引用', 'text', true),
            'security_confirmed' => $this->field('安全确认', 'switch', true),
            'business_signoff' => $this->field('业务签核', 'switch', true),
            'payment_signoff' => $this->field('支付签核', 'switch', true),
            'backup_restore_confirmed' => $this->field('备份恢复确认', 'switch', true),
            'launch_window' => $this->field('上线窗口', 'text', true),
            'rollback_owner' => $this->field('回滚负责人', 'text', true),
            'rollback_plan_ref' => $this->field('回滚方案引用', 'text', true),
            'notes' => $this->field('备注', 'textarea', false),
        ];
    }

    public function snapshot(): array
    {
        $rows = $this->configService->redactedRows([
            'category' => 'launch',
            'provider' => 'signoff',
            'environment' => 'default',
        ]);
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[$row['code']] = $row;
        }

        $fields = [];
        foreach ($this->fields() as $code => $field) {
            $row = $rowMap[$code] ?? null;
            $fields[$code] = array_merge($field, [
                'code' => $code,
                'configured' => $row ? (int)$row['configured'] === 1 : false,
                'value' => $this->value($code, ''),
            ]);
        }

        return [
            'version' => self::VERSION,
            'fields' => $fields,
            'readiness' => $this->readiness(false),
        ];
    }

    public function save(array $input): array
    {
        foreach ($this->fields() as $code => $field) {
            $value = array_key_exists($code, $input) ? trim((string)$input[$code]) : '';
            if ($field['type'] === 'switch') {
                $value = !empty($input[$code]) ? '1' : '0';
            }
            $this->configService->save([
                'store_id' => 0,
                'category' => 'launch',
                'provider' => 'signoff',
                'code' => $code,
                'label' => $field['label'],
                'environment' => 'default',
                'is_enabled' => 1,
                'is_sensitive' => 0,
                'value' => $value,
                'metadata' => ['version' => self::VERSION, 'type' => $field['type'], 'required' => $field['required'] ? 1 : 0],
            ]);
        }

        return $this->readiness(true);
    }

    public function readiness(bool $persist = true): array
    {
        $missing = [];
        foreach ($this->fields() as $code => $field) {
            if (!$field['required']) {
                continue;
            }
            $value = $this->value($code, '');
            if ($field['type'] === 'switch') {
                if ($value !== '1') {
                    $missing[] = $field['label'];
                }
            } elseif (trim($value) === '') {
                $missing[] = $field['label'];
            }
        }
        $result = $missing ? 'FAIL' : 'PASS';
        $message = $missing ? ('NO-GO，缺少：' . implode('、', $missing)) : 'GO，签核和证据引用已齐全，可提交上线评审';
        $data = [
            'result' => $result,
            'message' => $message,
            'missing' => $missing,
        ];
        if ($persist) {
            $this->configService->recordCheck([
                'category' => 'launch',
                'provider' => 'signoff',
                'check_key' => 'readiness',
                'result' => $result,
                'message' => $message,
                'details' => ['missing' => $missing, 'version' => self::VERSION],
            ]);
        }

        return $data;
    }

    private function value(string $code, string $default): string
    {
        try {
            return (string)$this->configService->getValue('launch', 'signoff', $code, 'default', 0, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function field(string $label, string $type, bool $required): array
    {
        return ['label' => $label, 'type' => $type, 'required' => $required];
    }
}
