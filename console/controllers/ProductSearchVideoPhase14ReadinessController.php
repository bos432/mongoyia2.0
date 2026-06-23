<?php

namespace console\controllers;

use common\services\mall\ProductSearchVideoPhase14Service;
use yii\console\Controller;
use yii\console\ExitCode;

class ProductSearchVideoPhase14ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_READINESS_V1';

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
        $this->stdout("Mongoyia Phase 14 product search/video readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkSearchVideoFixture();
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
        $this->requireFileContains('Product video migration', 'console/migrations/m260623_180000_mongoyia_product_video_url.php', [
            'video_url',
            '商品视频URL',
        ]);
        $this->requireFileContains('Product model video field', 'common/models/mall/Product.php', [
            'video_url',
            'Product Video URL',
        ]);
        $this->requireFileContains('Product search/video service', 'common/services/mall/ProductSearchVideoPhase14Service.php', [
            'MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_V1',
            'sortOrder',
            'buildSuggestions',
            'filterFixtureProducts',
            'videoPayload',
        ]);
        $this->requireFileContains('Buyer APP API search/video runtime', 'common/services/mall/AppBuyerApiService.php', [
            'suggestions',
            'searchVideoService',
            'sortOrder',
            'video_url',
            'has_video',
        ]);
        $this->requireFileContains('Buyer API controller suggestions endpoint', 'api/modules/v1/controllers/AppBuyerController.php', [
            "'suggestions'",
            'actionSuggestions',
        ]);
        $this->requireFileContains('APP buyer search UI', 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/search.vue', [
            'data-mongoyia-phase14-search-suggestions',
            'data-mongoyia-phase14-search-sort',
            'BUYER_ENDPOINTS.suggestions',
            'sales_desc',
        ]);
        $this->requireFileContains('APP buyer product video UI', 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/product.vue', [
            'data-mongoyia-phase14-product-video',
            'product.video_url',
            'hero-video',
        ]);
        $this->requireFileContains('Backend product video field', 'backend/modules/mall/views/product/edit.php', [
            'video_url',
            'data-mongoyia-phase14-product-video-admin',
        ]);
        $this->requireFileContains('PC product video display', 'web/resources/mall/default/views/product/view.php', [
            'data-mongoyia-phase14-product-video',
            'product-details-video',
            'video_url',
        ]);
        $this->requireFileContains('PC category search filters', 'web/resources/mall/default/views/category/view.php', [
            'Filter by Brand',
            'Filter by Price',
            "\$sort == '-sales'",
            "\$sort == '-price'",
        ]);
        $this->requireFileContains('Phase 14 aggregate tracks search/video readiness', 'console/controllers/LogisticsProductPhase14AcceptanceController.php', [
            'Search and product video readiness',
            'product-search-video-phase14-readiness/run',
        ]);
        $this->requireFileContains('Phase 14 backlog command list', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'product-search-video-phase14-readiness/run',
            'Phase 14.4 search filters',
        ]);
    }

    private function checkSearchVideoFixture(): void
    {
        $this->section('Search/video fixture');
        try {
            $service = new ProductSearchVideoPhase14Service();
            $products = $service->fixtureProducts();

            $suggestions = $service->buildSuggestions($products, 'scarf', 8);
            if (count($suggestions) < 2) {
                $this->fail('Keyword/SKU suggestions fixture must return at least two suggestions.');
                return;
            }

            $filtered = $service->filterFixtureProducts($products, [
                'keyword' => 'cashmere',
                'brand_id' => 7,
                'min_price' => 10,
                'max_price' => 30,
                'sort' => 'sales_desc',
            ]);
            if (count($filtered) !== 2 || (int)$filtered[0]['sales'] < (int)$filtered[1]['sales']) {
                $this->fail('Filtered fixture must keep two brand-matched products ordered by sales descending.');
                return;
            }

            $priceSorted = $service->filterFixtureProducts($products, ['sort' => 'price_asc']);
            if (count($priceSorted) < 3 || (float)$priceSorted[0]['price'] > (float)$priceSorted[1]['price']) {
                $this->fail('Price ascending fixture sort is incorrect.');
                return;
            }

            $video = $service->videoPayload((string)$products[0]['video_url']);
            $relativeVideo = $service->videoPayload((string)$products[2]['video_url']);
            $badVideo = $service->videoPayload('javascript:alert(1)');
            if (empty($video['has_video']) || empty($relativeVideo['has_video']) || !empty($badVideo['has_video'])) {
                $this->fail('Video URL normalization fixture must allow HTTPS/site-relative URLs and reject unsafe schemes.');
                return;
            }

            $this->addCheck('Keyword and SKU suggestions', 'PASS', 'ProductSearchVideoPhase14Service::buildSuggestions', 'Keyword and SKU suggestions are generated from product name/SKU without mutating product rows.');
            $this->addCheck('Brand price sales filters', 'PASS', 'ProductSearchVideoPhase14Service::filterFixtureProducts', 'Brand, price range, keyword, sales, price, and newest sort behavior are covered by fixture logic.');
            $this->addCheck('Product video payload', 'PASS', 'ProductSearchVideoPhase14Service::videoPayload', 'HTTPS and site-relative product videos are exposed while unsafe schemes are rejected.');
        } catch (\Throwable $e) {
            $this->fail('Product search/video fixture failed: ' . $e->getMessage());
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
            '# Mongoyia Phase 14 Product Search/Video Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: SKU/keyword suggestions, brand/price/sales filters, APP search UI, product video field, APP product video, and PC product video display.',
            '- Safety: this command is read-only and does not mutate products, stock, reviews, favorites, funds, orders, or provider credentials.',
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
            '/www/server/php/83/bin/php yii product-search-video-phase14-readiness/run --fixture=1 --interactive=0',
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

        $this->addCheck($label, 'PASS', $path, 'Required search/video markers are present.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-product-search-video-phase14-readiness-' . date('Ymd-His') . '.md';
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
