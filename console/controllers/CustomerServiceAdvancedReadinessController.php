<?php

namespace console\controllers;

use common\services\mall\CustomerServiceAdvancedService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class CustomerServiceAdvancedReadinessController extends Controller
{
    public $baseUrl = 'http://127.0.0.1:8089';
    public $profile = 'local';
    public $strict = false;
    public $requireAppliedSchema = false;
    public $handoverDir = 'runtime/handover';
    public $outputPath = '';

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;
    private $dryRunRows = [];
    private $workflowDryRunRows = [];

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'baseUrl',
            'profile',
            'strict',
            'requireAppliedSchema',
            'handoverDir',
            'outputPath',
        ]);
    }

    public function actionRun()
    {
        $this->baseUrl = rtrim((string)$this->baseUrl, '/');
        $this->profile = strtolower((string)$this->profile);
        $this->stdout("Mongoyia advanced customer-service readiness\n");

        $this->checkContract();
        $this->checkMigration();
        $this->checkService();
        $this->checkAppliedSchema();
        $this->checkDryRunPlan();
        $this->checkWorkflowDryRunPlan();
        $this->checkRuntimeBoundary();

        $result = $this->result();
        $path = $this->writeReport($result);
        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0))) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkContract(): void
    {
        $this->requireFileMarkers('Advanced customer-service contract', 'docs/mongoyia-customer-service-contract.md', [
            'MONGOYIA_CUSTOMER_SERVICE_ADVANCED_SCHEMA_V1',
            'MONGOYIA_CUSTOMER_SERVICE_TICKET_SCHEMA_V1',
            'MONGOYIA_CUSTOMER_SERVICE_EVENT_SCHEMA_V1',
            'MONGOYIA_CUSTOMER_SERVICE_STAT_DAILY_SCHEMA_V1',
            'MONGOYIA_CUSTOMER_SERVICE_ADVANCED_DRY_RUN_SERVICE_V1',
            'MONGOYIA_CUSTOMER_SERVICE_ADVANCED_WORKFLOW_DRY_RUN_V1',
            '`mall_customer_service_ticket`',
            '`mall_customer_service_event`',
            '`mall_customer_service_stat_daily`',
            'CustomerServiceAdvancedService.php',
            'ticket workflow test before runtime enablement',
        ]);
    }

    private function checkMigration(): void
    {
        $this->requireFileMarkers('Advanced customer-service migration', 'console/migrations/m260619_100000_mongoyia_customer_service_advanced.php', [
            'class m260619_100000_mongoyia_customer_service_advanced extends Migration',
            'mall_customer_service_ticket',
            'mall_customer_service_event',
            'mall_customer_service_stat_daily',
            'ticket_type',
            'ticket_status',
            'order_assist_count',
            'complaint_count',
            "createIndex('mall_customer_service_ticket_k0'",
            "createIndex('mall_customer_service_stat_daily_u0'",
            'safeDown',
        ]);
    }

    private function checkService(): void
    {
        $this->requireFileMarkers('Advanced customer-service dry-run service', 'common/services/mall/CustomerServiceAdvancedService.php', [
            'class CustomerServiceAdvancedService',
            'TICKET_TYPE_ORDER_ASSIST',
            'TICKET_TYPE_COMPLAINT',
            'TICKET_STATUS_PENDING',
            'dryRunPlan',
            'workflowDryRunPlan',
            'validateRows',
            'validateWorkflowRows',
            'supportedTicketTypes',
            'supportedTransitions',
            'transitionBlockReason',
            'mall_customer_service_ticket',
            'mall_customer_service_event',
            'mall_customer_service_stat_daily',
        ]);
    }

    private function checkAppliedSchema(): void
    {
        $required = $this->schemaRequired();
        $tables = $this->expectedTables();
        $missing = [];
        $invalid = [];

        foreach ($tables as $table => $config) {
            $schema = Yii::$app->db->schema->getTableSchema($table, true);
            if ($schema === null) {
                $missing[] = $table;
                continue;
            }

            foreach ($config['columns'] as $column) {
                if (!isset($schema->columns[$column])) {
                    $invalid[] = "{$table}.{$column}";
                }
            }

            $indexNames = $this->indexNames($table);
            foreach ($config['indexes'] as $index) {
                if (!in_array($index, $indexNames, true)) {
                    $invalid[] = "{$table} index {$index}";
                }
            }
        }

        if (!$missing && !$invalid) {
            $this->addCheck('Advanced customer-service applied schema', 'PASS', 'database', 'Ticket/event/stat daily tables and indexes are present.');
            return;
        }

        if ($required) {
            $details = trim(implode(', ', array_merge($missing, $invalid)));
            $this->addCheck('Advanced customer-service applied schema', 'FAIL', $details, 'Test/profile-required schema is missing; run the migration before acceptance.');
            return;
        }

        $this->addCheck(
            'Advanced customer-service applied schema',
            'PASS',
            $missing ? implode(', ', $missing) : 'not fully applied',
            'Local profile treats the schema as staged; test profile or --requireAppliedSchema=1 will require the migration to be applied.'
        );
    }

    private function checkDryRunPlan(): void
    {
        $plan = (new CustomerServiceAdvancedService())->dryRunPlan($this->sampleContext());
        $this->dryRunRows = $plan['rows'];
        if (!empty($plan['issues'])) {
            $this->addCheck('Advanced customer-service dry-run plan', 'FAIL', implode('; ', $plan['issues']), 'Dry-run service returned validation issues.');
            return;
        }

        $tables = array_values(array_unique(array_map(static function ($row) {
            return (string)$row['table'];
        }, $this->dryRunRows)));
        sort($tables);
        $this->addCheck('Advanced customer-service dry-run plan', 'PASS', count($this->dryRunRows) . ' planned rows', 'Dry-run service produced ' . implode(', ', $tables) . ' drafts without database mutation.');
    }

    private function checkWorkflowDryRunPlan(): void
    {
        $plan = (new CustomerServiceAdvancedService())->workflowDryRunPlan($this->sampleContext());
        $this->workflowDryRunRows = $plan['rows'];
        if (!empty($plan['issues'])) {
            $this->addCheck('Advanced customer-service workflow dry-run plan', 'FAIL', implode('; ', $plan['issues']), 'Workflow dry-run service returned validation issues.');
            return;
        }

        $transitions = [];
        foreach ($this->workflowDryRunRows as $row) {
            if (($row['table'] ?? '') !== 'mall_customer_service_event') {
                continue;
            }
            $fields = $row['fields'] ?? [];
            if (($fields['event_type'] ?? '') !== CustomerServiceAdvancedService::EVENT_TYPE_STATUS_CHANGE) {
                continue;
            }
            $transitions[] = ($fields['from_status'] ?? '') . '->' . ($fields['to_status'] ?? '');
        }

        foreach (['pending->in_progress', 'in_progress->resolved'] as $required) {
            if (!in_array($required, $transitions, true)) {
                $this->addCheck('Advanced customer-service workflow dry-run plan', 'FAIL', implode(', ', $transitions), "Missing required transition {$required}.");
                return;
            }
        }

        $this->addCheck(
            'Advanced customer-service workflow dry-run plan',
            'PASS',
            count($this->workflowDryRunRows) . ' planned rows',
            'Workflow dry-run covers pending->in_progress and in_progress->resolved with ticket/stat update drafts only.'
        );
    }

    private function checkRuntimeBoundary(): void
    {
        foreach ([
            'backend/modules/mall/views/kf/index.php',
            'web/resources/mall/default/views/chat/index.php',
        ] as $path) {
            $this->requireFileMissingMarkers('Advanced customer-service runtime UI remains reserved in ' . $path, $path, [
                'data-mongoyia-customer-service-order-assist',
                'data-mongoyia-customer-service-complaint',
                'data-mongoyia-customer-service-stat',
                'MONGOYIA_CUSTOMER_SERVICE_ADVANCED_RUNTIME_V1',
            ]);
        }

        $this->requireFileMarkers('Current customer-service readiness remains available', 'console/controllers/CustomerServiceTestController.php', [
            'class CustomerServiceTestController extends Controller',
            'checkBackendWorkbenches',
            'checkFrontendTokenEndpoint',
            'Reserved order-assist/complaint/stat widgets are not exposed.',
        ]);
    }

    private function sampleContext(): array
    {
        $product = (new \yii\db\Query())
            ->select(['p.id', 'p.store_id', 's.user_id'])
            ->from(['p' => '{{%mall_product}}'])
            ->leftJoin(['s' => '{{%store}}'], 's.id = p.store_id')
            ->where(['>', 'p.status', -10])
            ->andWhere(['>', 'p.store_id', 0])
            ->orderBy(['p.id' => SORT_ASC])
            ->one(Yii::$app->db) ?: [];

        $order = (new \yii\db\Query())
            ->select(['id', 'store_id', 'sn', 'user_id'])
            ->from('{{%mall_order}}')
            ->where(['>', 'status', -10])
            ->orderBy(['id' => SORT_DESC])
            ->one(Yii::$app->db) ?: [];

        $operatorUserId = (int)(new \yii\db\Query())
            ->select('id')
            ->from('{{%user}}')
            ->where(['username' => 'codex_platform_backend_test_5'])
            ->scalar(Yii::$app->db);

        return [
            'product_id' => (int)($product['id'] ?? 0),
            'store_id' => (int)($product['store_id'] ?? ($order['store_id'] ?? 0)),
            'merchant_user_id' => (int)($product['user_id'] ?? 0),
            'order_id' => (int)($order['id'] ?? 0),
            'order_sn' => (string)($order['sn'] ?? ''),
            'customer_user_id' => (int)($order['user_id'] ?? 0),
            'customer_uuid' => 'readiness_user',
            'chat_uuid' => 'readiness_chat',
            'operator_user_id' => $operatorUserId,
        ];
    }

    private function expectedTables(): array
    {
        return [
            '{{%mall_customer_service_ticket}}' => [
                'columns' => [
                    'id',
                    'ticket_sn',
                    'ticket_type',
                    'ticket_status',
                    'priority',
                    'store_id',
                    'product_id',
                    'order_id',
                    'customer_user_id',
                    'customer_uuid',
                    'merchant_user_id',
                    'platform_user_id',
                    'chat_uuid',
                    'title',
                    'content',
                    'result',
                    'evidence_json',
                    'first_response_at',
                    'resolved_at',
                    'closed_at',
                    'status',
                ],
                'indexes' => [
                    'mall_customer_service_ticket_u0',
                    'mall_customer_service_ticket_k0',
                    'mall_customer_service_ticket_k1',
                    'mall_customer_service_ticket_k2',
                ],
            ],
            '{{%mall_customer_service_event}}' => [
                'columns' => [
                    'id',
                    'ticket_id',
                    'event_type',
                    'from_status',
                    'to_status',
                    'operator_user_id',
                    'operator_type',
                    'content',
                    'metadata_json',
                    'status',
                ],
                'indexes' => [
                    'mall_customer_service_event_k0',
                    'mall_customer_service_event_k1',
                ],
            ],
            '{{%mall_customer_service_stat_daily}}' => [
                'columns' => [
                    'id',
                    'stat_date',
                    'store_id',
                    'service_user_id',
                    'session_count',
                    'ticket_count',
                    'order_assist_count',
                    'complaint_count',
                    'resolved_count',
                    'unresolved_count',
                    'first_response_seconds_total',
                    'resolved_seconds_total',
                    'status',
                ],
                'indexes' => [
                    'mall_customer_service_stat_daily_u0',
                    'mall_customer_service_stat_daily_k0',
                    'mall_customer_service_stat_daily_k1',
                ],
            ],
        ];
    }

    private function schemaRequired(): bool
    {
        return (bool)$this->requireAppliedSchema || in_array($this->profile, ['test', 'prod'], true);
    }

    private function indexNames(string $table): array
    {
        $rawName = Yii::$app->db->schema->getRawTableName($table);
        $rows = Yii::$app->db->createCommand('SHOW INDEX FROM ' . Yii::$app->db->quoteTableName($rawName))->queryAll();
        $names = [];
        foreach ($rows as $row) {
            $name = (string)($row['Key_name'] ?? '');
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function requireFileMarkers(string $label, string $path, array $markers): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($markers as $marker) {
            if (strpos($content, $marker) === false) {
                $this->addCheck($label, 'FAIL', $path, "Missing marker `{$marker}`.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Required advanced customer-service markers are present.');
    }

    private function requireFileMissingMarkers(string $label, string $path, array $markers): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($markers as $marker) {
            if (strpos($content, $marker) !== false) {
                $this->addCheck($label, 'FAIL', $path, "Advanced runtime marker `{$marker}` is exposed before implementation gate.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Advanced customer-service runtime widgets remain reserved.');
    }

    private function addCheck(string $area, string $status, string $evidence, string $notes): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') {
            $this->failures++;
        } elseif ($status === 'PENDING') {
            $this->pending++;
        } elseif ($status !== 'PASS') {
            $this->warnings++;
            $status = 'WARN';
        }

        $this->checks[] = [
            'area' => $area,
            'status' => $status,
            'evidence' => $evidence,
            'notes' => $notes,
        ];
        $this->stdout(str_pad($status, 8) . "{$area}\n");
    }

    private function result(): string
    {
        if ($this->failures > 0) {
            return 'FAIL';
        }
        if ($this->warnings > 0 || $this->pending > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Advanced Customer Service Readiness',
            '',
            '- Result: ' . $result,
            '- Base URL: ' . $this->baseUrl,
            '- Profile: ' . $this->profile,
            '- Require applied schema: ' . ($this->schemaRequired() ? 'yes' : 'no'),
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Evidence type: non-mutating schema/readiness report; no runtime UI enablement, no WSS, no chat/order mutation.',
            '',
            '## Checks',
            '',
            '| Status | Area | Evidence | Notes |',
            '|---|---|---|---|',
        ];

        foreach ($this->checks as $check) {
            $lines[] = '| ' . $this->mdCell($check['status']) . ' | '
                . $this->mdCell($check['area']) . ' | `'
                . $this->mdCell($check['evidence']) . '` | '
                . $this->mdCell($check['notes']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Dry-Run Plan',
            '',
            '| Operation | Table | Key | Purpose | Field summary |',
            '|---|---|---|---|---|',
        ]);

        foreach ($this->dryRunRows as $row) {
            $lines[] = '| ' . $this->mdCell($row['operation']) . ' | `'
                . $this->mdCell($row['table']) . '` | `'
                . $this->mdCell($row['key']) . '` | '
                . $this->mdCell($row['purpose']) . ' | `'
                . $this->mdCell($this->fieldSummary($row['fields'])) . '` |';
        }

        $lines = array_merge($lines, [
            '',
            '## Workflow Dry-Run Plan',
            '',
            '| Operation | Table | Key | Purpose | Field summary |',
            '|---|---|---|---|---|',
        ]);

        foreach ($this->workflowDryRunRows as $row) {
            $lines[] = '| ' . $this->mdCell($row['operation']) . ' | `'
                . $this->mdCell($row['table']) . '` | `'
                . $this->mdCell($row['key']) . '` | '
                . $this->mdCell($row['purpose']) . ' | `'
                . $this->mdCell($this->fieldSummary($row['fields'])) . '` |';
        }

        $lines = array_merge($lines, [
            '',
            '## Scope Boundary',
            '',
            '- This report stages the advanced customer-service schema for order assistance, complaint handling, audit events, and daily statistics.',
            '- Workflow dry-run validates pending->in_progress->resolved transitions without changing tickets, orders, chats, or statistics.',
            '- Local profile validates migration readiness without requiring applied schema; test/prod profiles require the migration to be applied.',
            '- Runtime UI controls remain reserved until backend permissions, controllers, views, regression tests, and cleanup are added together.',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    private function fieldSummary(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = $key . '=' . (is_scalar($value) ? (string)$value : json_encode($value));
        }

        return implode(', ', $parts);
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-customer-service-advanced-readiness-' . date('Ymd-His') . '.md';
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
