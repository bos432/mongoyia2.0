<?php

namespace console\controllers;

use common\services\mall\ProductInventoryPhase14Service;
use yii\console\Controller;
use yii\console\ExitCode;

class ProductInventoryPhase14ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_PRODUCT_INVENTORY_PHASE14_READINESS_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia Phase 14 product inventory readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkProductInventoryFixture();
        }

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s).\n");

        if ($this->failures > 0 || ($this->strict && $this->warnings > 0)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Source coverage');
        $this->requireFileContains('Product inventory migration', 'console/migrations/m260623_170000_mongoyia_product_inventory_shipping_fields.php', [
            'shipment_timeout_hours',
            'shipment_timeout_deduct_fee',
            'inventory_location',
        ]);
        $this->requireFileContains('Product model fields', 'common/models/mall/Product.php', [
            'shipment_timeout_hours',
            'shipment_timeout_deduct_fee',
            'Shipment Timeout Hours',
        ]);
        $this->requireFileContains('Product SKU model fields', 'common/models/mall/ProductSku.php', [
            'inventory_location',
            'Inventory Location',
        ]);
        $this->requireFileContains('Product inventory service', 'common/services/mall/ProductInventoryPhase14Service.php', [
            'MONGOYIA_PRODUCT_INVENTORY_PHASE14_V1',
            'generateSkuCode',
            'planSkuInventory',
            'planShippingTimeout',
            'sku_generation_apply_requires_product_audit_and_inventory_acceptance',
            'shipping_timeout_deposit_apply_requires_finance_acceptance',
            'deduct_deposit_pending_apply',
            'blocked_insufficient_fund',
        ]);
        $this->requireFileContains('Phase 14 aggregate tracks product inventory readiness', 'console/controllers/LogisticsProductPhase14AcceptanceController.php', [
            'Product inventory and shipping timeout readiness',
            'product-inventory-phase14-readiness/run',
        ]);
        $this->requireFileContains('Phase 14 backlog command list', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'product-inventory-phase14-readiness/run',
            'Phase 14.3 SKU generation',
        ]);
    }

    private function checkProductInventoryFixture(): void
    {
        $this->section('Product inventory fixture');
        try {
            $service = new ProductInventoryPhase14Service();
            $skuPlan = $service->planSkuInventory($service->fixtureSkuItems(), ['MGY-DUPLICATE']);
            $skuSummary = $skuPlan['summary'] ?? [];
            if (($skuPlan['version'] ?? '') !== ProductInventoryPhase14Service::VERSION) {
                $this->fail('Product inventory plan version marker is missing.');
                return;
            }
            if ((int)($skuSummary['items'] ?? 0) !== 3 || (int)($skuSummary['generated'] ?? 0) !== 2) {
                $this->fail('SKU fixture must scan three items and generate two SKU codes.');
                return;
            }
            if ((int)($skuSummary['duplicates'] ?? 0) !== 1 || (int)($skuSummary['missing_location'] ?? 0) !== 1) {
                $this->fail('SKU fixture must flag one duplicate and one missing inventory location.');
                return;
            }
            if (!empty($skuSummary['mutates_product']) || !empty($skuSummary['mutates_stock'])) {
                $this->fail('SKU inventory fixture must be read-only.');
                return;
            }

            $now = 1782038400;
            $timeoutPlan = $service->planShippingTimeout($service->fixtureShippingOrders($now), $now);
            $timeoutSummary = $timeoutPlan['summary'] ?? [];
            if ((int)($timeoutSummary['orders'] ?? 0) !== 4) {
                $this->fail('Shipping timeout fixture must scan four orders.');
                return;
            }
            if ((int)($timeoutSummary['timeout_pending_deduction'] ?? 0) !== 1 ||
                (int)($timeoutSummary['blocked_insufficient_fund'] ?? 0) !== 1 ||
                (int)($timeoutSummary['watching'] ?? 0) !== 1 ||
                (int)($timeoutSummary['already_shipped'] ?? 0) !== 1) {
                $this->fail('Shipping timeout fixture buckets are incorrect.');
                return;
            }
            if (abs((float)($timeoutSummary['planned_deduction_amount'] ?? 0) - 5.00) > 0.001) {
                $this->fail('Shipping timeout fixture planned deduction amount must be 5.00.');
                return;
            }
            if (!empty($timeoutSummary['mutates_order']) || !empty($timeoutSummary['mutates_fund'])) {
                $this->fail('Shipping timeout fixture must be read-only.');
                return;
            }

            $this->addCheck('SKU generation plan', 'PASS', 'ProductInventoryPhase14Service::planSkuInventory', 'Generated SKU codes, duplicate detection, inventory location validation, and apply gate are present.');
            $this->addCheck('Shipping timeout plan', 'PASS', 'ProductInventoryPhase14Service::planShippingTimeout', 'Timeout deduction, insufficient-fund block, watch bucket, and already-shipped bucket are present.');
            $this->addCheck('High-risk mutation boundary', 'PASS', 'fixture summary', 'Product, stock, order, and fund writes remain gated and dry-run first.');
        } catch (\Throwable $e) {
            $this->fail('Product inventory fixture failed: ' . $e->getMessage());
        }
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Phase 14 Product Inventory Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: automatic SKU generation plan, inventory location field, shipping timeout setting, and deposit deduction dry-run gate.',
            '- Safety: this command does not mutate products, SKU rows, stock, orders, funds, or shipment rows.',
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
            '## BaoTa Verification Command',
            '',
            '```bash',
            'cd /www/wwwroot/demo2026.mongoyia.com',
            'git pull',
            '/www/server/php/83/bin/php yii migrate/up --interactive=0',
            '/www/server/php/83/bin/php yii product-inventory-phase14-readiness/run --fixture=1 --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function requireFileContains(string $label, string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) === false) {
                $this->addCheck($label, 'FAIL', $path, "Missing marker {$needle}.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Required product inventory markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
    }

    private function fail(string $message): void
    {
        $this->addCheck($message, 'FAIL', 'readiness check', $message);
    }

    private function addCheck(string $area, string $status, string $evidence, string $notes): void
    {
        $status = strtoupper($status);
        if ($status === 'FAIL') {
            $this->failures++;
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
        if ($this->warnings > 0) {
            return 'WARN';
        }

        return 'PASS';
    }

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-product-inventory-phase14-readiness-' . date('Ymd-His') . '.md';
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0) {
            return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function mdCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
    }
}
