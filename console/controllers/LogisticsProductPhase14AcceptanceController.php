<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class LogisticsProductPhase14AcceptanceController extends Controller
{
    public const VERSION = 'MONGOYIA_LOGISTICS_PRODUCT_PHASE14_ACCEPTANCE_V1';
    public const PROVIDER_AFTERFILL_POLICY_VERSION = 'MONGOYIA_PHASE14_LOGISTICS_PROVIDER_AFTERFILL_POLICY_V1';
    public const CHILD_CHECKS_VERSION = 'MONGOYIA_LOGISTICS_PRODUCT_PHASE14_CHILD_CHECKS_V1';
    public const ACCEPTED_EVIDENCE_PATH_GUARD_VERSION = 'MONGOYIA_ACCEPTED_EVIDENCE_PATH_GUARD_V1';

    public $handoverDir = 'runtime/handover';
    public $outputPath = '';
    public $fixture = false;
    public $strict = false;
    public $allowExternalAfterfill = true;
    public $runChildChecks = false;
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
    private $afterfillPending = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'handoverDir',
            'outputPath',
            'fixture',
            'strict',
            'allowExternalAfterfill',
            'runChildChecks',
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
        if ($this->runChildChecks) {
            $this->runChildChecks();
        }
        $this->checkManualAcceptanceInputs();

        $result = $this->result();
        $path = $this->writeReport($result);

        $this->stdout("\nReport written to {$path}\n");
        $this->stdout("Summary: {$this->failures} failure(s), {$this->warnings} warning(s), {$this->pending} pending, {$this->afterfillPending} afterfill pending.\n");

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
        $this->requireFileContains('Phase 14 child readiness wiring', 'console/controllers/LogisticsProductPhase14AcceptanceController.php', [
            'MONGOYIA_LOGISTICS_PRODUCT_PHASE14_CHILD_CHECKS_V1',
            'runChildChecks',
            'childCommands',
            'logistics-provider-phase14-readiness/run',
            'logistics-tracking-phase14-readiness/run',
            'product-inventory-phase14-readiness/run',
            'product-search-video-phase14-readiness/run',
            'favorite-review-phase14-readiness/run',
        ]);
        $this->requireFileContains('Phase 14 accepted evidence path guard', 'console/controllers/LogisticsProductPhase14AcceptanceController.php', [
            'MONGOYIA_ACCEPTED_EVIDENCE_PATH_GUARD_V1',
            'missing accepted evidence path',
            'Accepted evidence flag requires a non-secret evidence path/reference.',
        ]);
        $this->requireFileContains('Phase 14 logistics provider afterfill policy', 'console/controllers/LogisticsProductPhase14AcceptanceController.php', [
            'MONGOYIA_PHASE14_LOGISTICS_PROVIDER_AFTERFILL_POLICY_V1',
            'allowExternalAfterfill',
            'AFTERFILL',
            'Afterfill pending',
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
        $this->requireFileContains('Backend logistics method selection POST guard', 'backend/modules/mall/controllers/LogisticsMethodController.php', [
            'MONGOYIA_LOGISTICS_METHOD_SELECTION_POST_GUARD_V1',
            "'select'] = ['post']",
            "'unselect'] = ['post']",
            "post('method_id', 0)",
            "post('store_id', 0)",
            "get('store_id', 0)",
        ]);
        $this->requireFileContains('Backend logistics method selection UI posts CSRF forms', 'backend/modules/mall/views/logistics-method/index.php', [
            'data-mongoyia-logistics-method-selection-post-guard',
            "Html::hiddenInput('method_id'",
            "Html::hiddenInput('store_id'",
            "Url::to(['select'])",
            "Url::to(['unselect'])",
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
        $this->requireFileContains('Backend order logistics workflow POST guard', 'backend/modules/mall/controllers/OrderController.php', [
            'MONGOYIA_ORDER_LOGISTICS_WORKFLOW_POST_GUARD_V1',
            'MONGOYIA_BACKEND_ORDER_SHIPMENT_POST_ID_GUARD_V1',
            "'logistics-status-batch'] = ['post']",
            "'logistics-review-batch'] = ['post']",
            '$request->isPost ? $request->post(\'id\', 0) : $request->get(\'id\')',
            "post('target_status', 0)",
            "post('review_status', 0)",
        ]);
        $this->requireFileContains('Backend order shipment form POST id guard', 'backend/modules/mall/views/order/fh-ajax.php', [
            'data-mongoyia-order-shipment-post-id-guard',
            "Html::hiddenInput('id'",
            "'action' => Url::to(['fh-ajax'])",
            "'validationUrl' => Url::to(['fh-ajax'])",
        ]);
        $this->requireFileContains('Backend order-product shipment POST id guard', 'backend/modules/mall/controllers/OrderProductController.php', [
            'MONGOYIA_BACKEND_ORDER_PRODUCT_SHIPMENT_POST_ID_GUARD_V1',
            '$request->isPost ? $request->post(\'id\', 0) : $request->get(\'id\')',
            '$model->shipment_status = 80',
        ]);
        $this->requireFileContains('Backend order-product shipment form POST id guard', 'backend/modules/mall/views/order-product/fh-ajax.php', [
            'data-mongoyia-order-product-shipment-post-id-guard',
            "Html::hiddenInput('id'",
            "'action' => Url::to(['fh-ajax'])",
            "'validationUrl' => Url::to(['fh-ajax'])",
        ]);
        $this->requireFileContains('Backend order logistics workflow UI posts CSRF forms', 'backend/modules/mall/views/order/index.php', [
            'data-mongoyia-order-logistics-post-guard',
            'csrfToken',
            'logistics-status-batch',
            'logistics-review-batch',
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
        $this->requireFileContains('Search and product video service', 'common/services/mall/ProductSearchVideoPhase14Service.php', [
            'MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_V1',
            'buildSuggestions',
            'filterFixtureProducts',
            'videoPayload',
        ]);
        $this->requireFileContains('Search and product video readiness', 'console/controllers/ProductSearchVideoPhase14ReadinessController.php', [
            'MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_READINESS_V1',
            'product-search-video-phase14-readiness/run',
            'Search/video fixture',
        ]);
        $this->requireFileContains('Product video migration', 'console/migrations/m260623_180000_mongoyia_product_video_url.php', [
            'video_url',
            '商品视频URL',
        ]);
        $this->requireFileContains('Backend product edit modal POST id guard', 'backend/modules/mall/controllers/ProductController.php', [
            'MONGOYIA_PRODUCT_EDIT_AJAX_POST_ID_GUARD_V1',
            '$request->isPost ? $request->post(\'id\', 0) : $request->get(\'id\')',
        ]);
        $this->requireFileContains('Backend product edit modal posts hidden id', 'backend/modules/mall/views/product/edit-ajax.php', [
            'data-mongoyia-product-edit-ajax-post-id-guard',
            'data-mongoyia-phase14-product-video-admin',
            "Html::hiddenInput('id'",
            "'action' => Url::to(['edit-ajax'])",
            "'validationUrl' => Url::to(['edit-ajax'])",
        ]);
        $this->requireFileContains('APP search and product video UI', 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/search.vue', [
            'data-mongoyia-phase14-search-suggestions',
            'data-mongoyia-phase14-search-sort',
            'sales_desc',
        ]);
        $this->requireFileContains('PC product video UI', 'web/resources/mall/default/views/product/view.php', [
            'data-mongoyia-phase14-product-video',
            'product-details-video',
        ]);
        $this->requireFileContains('Favorite and review moderation service', 'common/services/mall/FavoriteReviewPhase14Service.php', [
            'MONGOYIA_FAVORITE_REVIEW_PHASE14_V1',
            'planStoreFavoriteToggle',
            'reviewModerationTransition',
        ]);
        $this->requireFileContains('Favorite and review moderation readiness', 'console/controllers/FavoriteReviewPhase14ReadinessController.php', [
            'MONGOYIA_FAVORITE_REVIEW_PHASE14_READINESS_V1',
            'MONGOYIA_REVIEW_MODERATION_ID_POST_GUARD_V1',
            'MONGOYIA_BUYER_ORDER_RECEIVED_POST_ID_GUARD_V1',
            'MONGOYIA_PRODUCT_CONSULTATION_POST_ID_GUARD_V1',
            'MONGOYIA_PRODUCT_FAVORITE_POST_READ_GUARD_V1',
            'MONGOYIA_PRODUCT_REVIEW_AJAX_GET_GUARD_V1',
            'favorite-review-phase14-readiness/run',
            'Favorite/review fixture',
        ]);
        $this->requireFileContains('Frontend product/store favorite GET read POST write guard', 'frontend/modules/mall/controllers/ProductController.php', [
            'MONGOYIA_PRODUCT_FAVORITE_POST_READ_GUARD_V1',
            'MONGOYIA_PRODUCT_REVIEW_AJAX_GET_GUARD_V1',
            "'favorite' => ['GET', 'POST']",
            "'store-favorite' => ['GET', 'POST']",
            "'review' => ['GET']",
            "post('product_id', 0)",
            "get('product_id', 0)",
            "post('store_id', 0)",
            "get('store_id', 0)",
            'return $this->error(-1);',
        ]);
        $this->requireFileContains('Frontend product consultation POST id guard', 'frontend/modules/mall/controllers/ProductController.php', [
            'MONGOYIA_PRODUCT_CONSULTATION_POST_ID_GUARD_V1',
            'function actionConsultation',
            "post('product_id', 0)",
        ]);
        $this->requireFileContains('Frontend buyer received-order POST guard', 'frontend/modules/mall/controllers/OrderController.php', [
            'MONGOYIA_BUYER_ORDER_RECEIVED_POST_ID_GUARD_V1',
            "'review' => ['POST']",
            "post('id', 0)",
            'markReceived',
        ]);
        $this->requireFileContains('Frontend buyer received-order form markers', 'web/resources/mall/default/views/order/view.php', [
            'data-mongoyia-buyer-received-post-guard',
            "hiddenInput('id'",
        ]);
        $this->requireFileContains('Store favorite and review moderation migration', 'console/migrations/m260623_190000_mongoyia_store_favorite_review_moderation.php', [
            'mall_store_favorite',
            'moderation_status',
            '/mall/review/approve',
        ]);
        $this->requireFileContains('Review moderation backend UI', 'backend/modules/mall/views/review/index.php', [
            'data-mongoyia-phase14-review-moderation',
            'data-mongoyia-review-moderation-post-guard',
            'mark-violation',
            'name="id"',
        ]);
        $this->requireFileContains('Review moderation backend controller POST ids', 'backend/modules/mall/controllers/ReviewController.php', [
            'MONGOYIA_REVIEW_MODERATION_ID_POST_GUARD_V1',
            "post('id', 0)",
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
            'Complete real logistics provider account/API evidence through backend afterfill before accepting this gate; simulated provider readiness remains covered by source checks.',
            true
        );
        $this->manualFlag(
            'Tracking sync acceptance',
            $this->trackingSyncAccepted,
            $this->trackingEvidencePath,
            'Tracking sync and abnormal status handling were accepted.',
            'Complete real logistics tracking-provider evidence through backend afterfill before accepting this gate; simulated tracking readiness remains covered by source checks.',
            true
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

    private function runChildChecks(): void
    {
        $this->section('Phase 14 child readiness commands');
        foreach ($this->childCommands() as $label => $config) {
            $params = ['interactive' => 0];
            if ($this->fixture && !empty($config['fixture'])) {
                $params['fixture'] = 1;
            }

            try {
                $exitCode = Yii::$app->runAction($config['route'], $params);
                if ((int)$exitCode === ExitCode::OK) {
                    $this->addCheck($label, 'PASS', $config['route'], 'Child readiness command passed.');
                } else {
                    $this->addCheck($label, 'FAIL', $config['route'], 'Child readiness command returned exit code ' . (int)$exitCode . '.');
                }
            } catch (\Throwable $e) {
                $this->addCheck($label, 'FAIL', $config['route'], 'Child readiness command failed: ' . $e->getMessage());
            }
        }
    }

    private function childCommands(): array
    {
        return [
            'Logistics provider adapter readiness' => ['route' => 'logistics-provider-phase14-readiness/run', 'fixture' => true],
            'Logistics tracking sync readiness' => ['route' => 'logistics-tracking-phase14-readiness/run', 'fixture' => true],
            'Product inventory/shipping readiness' => ['route' => 'product-inventory-phase14-readiness/run', 'fixture' => true],
            'Product search/video readiness' => ['route' => 'product-search-video-phase14-readiness/run', 'fixture' => true],
            'Favorite/review moderation readiness' => ['route' => 'favorite-review-phase14-readiness/run', 'fixture' => true],
        ];
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
            '- Afterfill pending: ' . $this->afterfillPending,
            '- Child readiness checks: ' . ($this->runChildChecks ? 'yes' : 'no'),
            '- Scope: logistics provider adapters, tracking sync, SKU generation, shipping timeout/deposit deduction, inventory location, search filters, product video, store favorite, and review moderation.',
            '- Safety: this command is an evidence gate and does not call providers, mutate logistics rows, deduct funds, change stock, alter reviews, or enable live logistics credentials.',
            '- External afterfill policy: ' . ($this->allowExternalAfterfill ? 'enabled' : 'disabled'),
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
            'git pull --ff-only',
            'git rev-parse --short HEAD',
            '/www/server/php/83/bin/php yii migrate/up --interactive=0',
            '/www/server/php/83/bin/php yii cache/flush-all --interactive=0',
            '/etc/init.d/php-fpm-83 restart',
            '/www/server/php/83/bin/php yii logistics-provider-phase14-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii logistics-tracking-phase14-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii product-inventory-phase14-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii product-search-video-phase14-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii favorite-review-phase14-readiness/run --fixture=1 --interactive=0',
            '/www/server/php/83/bin/php yii logistics-product-phase14-acceptance/run --fixture=1 --runChildChecks=1 --allowExternalAfterfill=1 --interactive=0',
            '```',
            '',
            'MONGOYIA_PHASE10_15_CHILD_DEPLOY_CACHE_REFRESH_V1: pull fast-forward changes, print the deployed commit, flush Yii cache, and restart PHP-FPM before collecting Phase 14 logistics/product/favorite/review browser evidence.',
            '',
            '## Browser Role-Flow Checklist',
            '',
            'Record screenshots, generated reports, provider simulator output, or reviewer notes in non-secret evidence files, then pass those paths through the accepted evidence options after review.',
            '',
            '1. Platform backend: verify logistics provider adapter configuration/status, simulated provider readiness, and real-provider disabled/evidence-required state without entering real secrets.',
            '2. Seller backend or seller APP/H5: verify product SKU generation, SKU uniqueness, inventory location, product video URL/display, and shipping timeout fields on a test-only product.',
            '3. Buyer PC/H5/APP: search by keyword/SKU, apply brand/price/sales filters, open product detail, play or display the product video, favorite the store, and submit a review only for an eligible received test order.',
            '4. Seller backend or seller APP/H5: ship a paid/COD test order with logistics company/tracking number and verify shipment state, logistics fee evidence, and refresh persistence.',
            '5. Platform backend: run or review tracking sync evidence for simulated delivered, active shipping, and abnormal/manual-review statuses.',
            '6. Platform backend: review pending test reviews as approve/reject/violation and confirm only approved reviews are public and review sort is stable after refresh.',
            '7. Safety check: confirm no real logistics provider call, real fee deduction, direct stock mutation, direct review approval outside test data, or live credential enablement happened during browser evidence collection.',
            '',
            '## Accepted Evidence Command',
            '',
            'After the Phase 14 evidence is reviewed and accepted, rerun with the reviewed evidence paths. Example:',
            '',
            '```bash',
            '/www/server/php/83/bin/php yii logistics-product-phase14-acceptance/run \\',
            '  --fixture=1 \\',
            '  --runChildChecks=1 \\',
            '  --allowExternalAfterfill=1 \\',
            '  --providerAdapterAccepted=1 --providerEvidencePath=runtime/handover/phase14-provider-evidence.md \\',
            '  --trackingSyncAccepted=1 --trackingEvidencePath=runtime/handover/phase14-tracking-evidence.md \\',
            '  --skuInventoryAccepted=1 --skuInventoryEvidencePath=runtime/handover/phase14-sku-inventory-evidence.md \\',
            '  --searchVideoAccepted=1 --searchVideoEvidencePath=runtime/handover/phase14-search-video-evidence.md \\',
            '  --favoriteReviewAccepted=1 --favoriteReviewEvidencePath=runtime/handover/phase14-favorite-review-evidence.md \\',
            '  --browserAccepted=1 --browserEvidencePath=runtime/handover/phase14-browser-evidence.md \\',
            '  --strict=1 --interactive=0',
            '```',
            '',
        ]);

        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function manualFlag(string $area, bool $accepted, string $evidence, string $passNotes, string $pendingNotes, bool $externalAfterfill = false): void
    {
        $evidence = trim($evidence);
        if ($accepted) {
            if ($evidence === '') {
                $this->addCheck($area, 'FAIL', 'missing accepted evidence path', 'Accepted evidence flag requires a non-secret evidence path/reference. Pass the matching --*EvidencePath option after reviewer acceptance.');
                return;
            }

            $this->addCheck($area, 'PASS', $evidence, $passNotes);
            return;
        }

        if ($externalAfterfill && $this->allowExternalAfterfill) {
            $this->addCheck($area, 'AFTERFILL', $evidence !== '' ? $evidence : 'backend afterfill pending', $pendingNotes);
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
        } elseif ($status === 'AFTERFILL') {
            $this->afterfillPending++;
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
        if ($this->warnings > 0 || $this->pending > 0 || $this->afterfillPending > 0) {
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
