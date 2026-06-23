<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;

class LogisticsProductPhase14AcceptanceController extends Controller
{
    public const VERSION = 'MONGOYIA_LOGISTICS_PRODUCT_PHASE14_ACCEPTANCE_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $providerAdapterAccepted = false;
    public $trackingSyncAccepted = false;
    public $skuInventoryAccepted = false;
    public $searchVideoAccepted = false;
    public $favoriteReviewAccepted = false;
    public $browserAccepted = false;
    public $providerEvidencePath = '';
    public $trackingEvidencePath = '';
    public $skuInventoryEvidencePath = '';
    public $searchVideoEvidencePath = '';
    public $favoriteReviewEvidencePath = '';
    public $browserEvidencePath = '';

    private $checks = [];
    private $failures = 0;
    private $warnings = 0;
    private $pending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
            'providerAdapterAccepted',
            'trackingSyncAccepted',
            'skuInventoryAccepted',
            'searchVideoAccepted',
            'favoriteReviewAccepted',
            'browserAccepted',
            'providerEvidencePath',
            'trackingEvidencePath',
            'skuInventoryEvidencePath',
            'searchVideoEvidencePath',
            'favoriteReviewEvidencePath',
            'browserEvidencePath',
        ]);
    }

    public function actionRun()
    {
        $this->stdout("Mongoyia Phase 14 logistics/product acceptance\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkPlannedScopeMatrix();
        }
        $this->checkManualAcceptanceInputs();

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending.\n");

        if ($this->failures > 0 || ($this->strict && ($this->warnings > 0 || $this->pending > 0))) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function checkSourceCoverage(): void
    {
        $this->section('Phase 14 source coverage');
        $this->requireFileContains('Phase 14 backlog registration', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'Logistics provider adapters, tracking sync, SKU generation',
            'logistics-product-phase14-acceptance/run',
        ]);
        $this->requireFileContains('Existing logistics method foundation', 'common/models/mall/LogisticsMethod.php', [
            'class LogisticsMethod',
            'tracking_url',
            'provider',
        ]);
        $this->requireFileContains('Existing store logistics selection foundation', 'common/models/mall/StoreLogisticsMethod.php', [
            'class StoreLogisticsMethod',
            'SELECTION_ENABLED',
            'logistics_method_id',
        ]);
        $this->requireFileContains('Existing order logistics foundation', 'common/models/mall/OrderBase.php', [
            'SHIPMENT_STATUS_UNSHIPPED',
            'SHIPMENT_STATUS_SHIPPING',
            'shipment_fee',
        ]);
        $this->requireFileContains('Existing product SKU foundation', 'common/models/mall/ProductSkuBase.php', [
            'stock_code',
            'attribute_value',
            'stock',
        ]);
        $this->requireFileContains('Existing favorite/review foundation', 'common/models/mall/FavoriteBase.php', [
            'product_id',
            'store_id',
        ]);
        $this->requireFileContains('Existing review foundation', 'common/models/mall/ReviewBase.php', [
            'product_id',
            'star',
            'content',
        ]);
        $this->requireFileContains('Logistics provider adapter service', 'common/services/mall/LogisticsProviderAdapterService.php', [
            'MONGOYIA_LOGISTICS_PROVIDER_ADAPTER_V1',
            'PROVIDER_SIMULATED',
            'createShipmentPreview',
            'queryTracking',
            'batchTracking',
            'provider_secret_never_logged',
        ]);
        $this->requireFileContains('Logistics provider adapter readiness', 'console/controllers/LogisticsProviderPhase14ReadinessController.php', [
            'MONGOYIA_LOGISTICS_PROVIDER_PHASE14_READINESS_V1',
            'logistics-provider-phase14-readiness/run',
            'Simulated provider fixture',
        ]);
        $this->requireFileContains('Logistics tracking sync service', 'common/services/mall/LogisticsTrackingSyncService.php', [
            'MONGOYIA_LOGISTICS_TRACKING_SYNC_V1',
            'planSync',
            'manual_review_required',
            'mark_received_pending_apply',
            'provider_evidence_required',
        ]);
        $this->requireFileContains('Logistics tracking sync readiness', 'console/controllers/LogisticsTrackingPhase14ReadinessController.php', [
            'MONGOYIA_LOGISTICS_TRACKING_PHASE14_READINESS_V1',
            'logistics-tracking-phase14-readiness/run',
            'Tracking plan fixture',
        ]);
        $this->requireFileContains('Product inventory and shipping timeout service', 'common/services/mall/ProductInventoryPhase14Service.php', [
            'MONGOYIA_PRODUCT_INVENTORY_PHASE14_V1',
            'generateSkuCode',
            'planSkuInventory',
            'planShippingTimeout',
            'shipping_timeout_deposit_apply_requires_finance_acceptance',
        ]);
        $this->requireFileContains('Product inventory and shipping timeout readiness', 'console/controllers/ProductInventoryPhase14ReadinessController.php', [
            'MONGOYIA_PRODUCT_INVENTORY_PHASE14_READINESS_V1',
            'product-inventory-phase14-readiness/run',
            'Product inventory fixture',
        ]);
        $this->requireFileContains('Product inventory migration', 'console/migrations/m260623_170000_mongoyia_product_inventory_shipping_fields.php', [
            'shipment_timeout_hours',
            'shipment_timeout_deduct_fee',
            'inventory_location',
        ]);
    }

    private function checkPlannedScopeMatrix(): void
    {
        $this->section('Phase 14 planned scope matrix');
        foreach ([
            'Logistics provider adapter contract' => 'Provider adapter config, simulated provider, real provider evidence gate, readiness command, and no committed provider secrets.',
            'Tracking sync contract' => 'Single/batch tracking query, abnormal status mapping, audit evidence, retry-safe sync, and readiness command.',
            'SKU/inventory/shipping contract' => 'Automatic SKU code generation, shipping timeout/deposit deduction dry-run, inventory location, safe stock checks, and readiness command.',
            'Search/video contract' => 'SKU/keyword suggestions, brand/price/sales filters, and product video exposure.',
            'Store favorite/review moderation contract' => 'Store favorite, review approval/reject workflow, sorting, and violation handling.',
        ] as $area => $notes) {
            $this->addCheck($area, 'PASS', 'planned Phase 14 scope', $notes);
        }
    }

    private function checkManualAcceptanceInputs(): void
    {
        $this->section('Phase 14 implementation and evidence gates');
        $this->manualFlag(
            'Logistics provider adapter acceptance',
            $this->providerAdapterAccepted,
            $this->providerEvidencePath,
            'Provider adapter and evidence were accepted.',
            'Implement logistics provider adapter layer, simulated provider tests, real provider evidence gate, and secret redaction.'
        );
        $this->manualFlag(
            'Tracking sync acceptance',
            $this->trackingSyncAccepted,
            $this->trackingEvidencePath,
            'Tracking sync and abnormal status handling were accepted.',
            'Implement tracking query/sync, abnormal status mapping, retry/idempotency, and audit evidence.'
        );
        $this->manualFlag(
            'SKU inventory shipping acceptance',
            $this->skuInventoryAccepted,
            $this->skuInventoryEvidencePath,
            'SKU/inventory/shipping timeout/deposit behavior was accepted.',
            'Implement automatic SKU generation, shipping timeout/deposit deduction, inventory location display, and stock safety checks.'
        );
        $this->manualFlag(
            'Search and product video acceptance',
            $this->searchVideoAccepted,
            $this->searchVideoEvidencePath,
            'Search/filter/video behavior was accepted.',
            'Implement SKU/keyword suggestions, brand/price/sales filters, and product video display.'
        );
        $this->manualFlag(
            'Favorite and review moderation acceptance',
            $this->favoriteReviewAccepted,
            $this->favoriteReviewEvidencePath,
            'Store favorite and review moderation behavior was accepted.',
            'Implement store favorite, review approval/reject workflow, sorting, and violation handling.'
        );
        $this->manualFlag(
            'Phase 14 browser role-flow acceptance',
            $this->browserAccepted,
            $this->browserEvidencePath,
            'Browser role-flow evidence was accepted.',
            'Validate logistics tracking, fee difference deduction, inventory location, SKU uniqueness, product video, store favorite, and review moderation in browser.'
        );
    }

    private function writeReport(string $result): string
    {
        $path = $this->outputPath !== '' ? $this->resolvePath($this->outputPath) : $this->defaultReportPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [
            '# Mongoyia Phase 14 Logistics/Product Acceptance',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Pending: ' . $this->pending,
            '- Scope: logistics provider adapters, tracking sync, SKU generation, shipping timeout/deposit deduction, inventory location, search filters, product video, store favorite, and review moderation.',
            '- Safety: this command is an evidence gate and does not call providers, mutate logistics rows, deduct funds, change stock, alter reviews, or enable live logistics credentials.',
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
            '/www/server/php/83/bin/php yii logistics-product-phase14-acceptance/run --fixture=1 --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function manualFlag(string $area, bool $accepted, string $evidence, string $passNotes, string $pendingNotes): void
    {
        if ($accepted) {
            $this->addCheck($area, 'PASS', $evidence !== '' ? $evidence : 'external evidence recorded', $passNotes);
            return;
        }

        $this->addCheck($area, 'PENDING', $evidence !== '' ? $evidence : 'pending implementation/evidence', $pendingNotes);
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

        $this->addCheck($label, 'PASS', $path, 'Required Phase 14 markers are present.');
    }

    private function section(string $name): void
    {
        $this->stdout("\n[{$name}]\n");
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

    private function defaultReportPath(): string
    {
        return $this->resolvePath($this->handoverDir)
            . DIRECTORY_SEPARATOR . 'mongoyia-logistics-product-phase14-acceptance-' . date('Ymd-His') . '.md';
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
