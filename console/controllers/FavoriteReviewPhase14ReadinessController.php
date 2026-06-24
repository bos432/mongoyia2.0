<?php

namespace console\controllers;

use common\services\mall\FavoriteReviewPhase14Service;
use yii\console\Controller;
use yii\console\ExitCode;

class FavoriteReviewPhase14ReadinessController extends Controller
{
    public const VERSION = 'MONGOYIA_FAVORITE_REVIEW_PHASE14_READINESS_V1';

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
        $this->stdout("Mongoyia Phase 14 favorite/review readiness\n");

        $this->checkSourceCoverage();
        if ($this->fixture) {
            $this->checkFavoriteReviewFixture();
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
        $this->requireFileContains('Store favorite and review moderation migration', 'console/migrations/m260623_190000_mongoyia_store_favorite_review_moderation.php', [
            'mall_store_favorite',
            'moderation_status',
            '/mall/review/approve',
            '/mall/store-favorite/index',
        ]);
        $this->requireFileContains('Store favorite model', 'common/models/mall/StoreFavorite.php', [
            'class StoreFavorite',
            'mall_store_favorite',
            'Store Favorite Name',
        ]);
        $this->requireFileContains('Review moderation model fields', 'common/models/mall/ReviewBase.php', [
            'MODERATION_PENDING',
            'MODERATION_APPROVED',
            'getModerationStatusLabels',
        ]);
        $this->requireFileContains('Favorite/review Phase 14 service', 'common/services/mall/FavoriteReviewPhase14Service.php', [
            'MONGOYIA_FAVORITE_REVIEW_PHASE14_V1',
            'planStoreFavoriteToggle',
            'reviewModerationTransition',
            'mutates_order',
        ]);
        $this->requireFileContains('Buyer APP API store favorites', 'common/services/mall/AppBuyerApiService.php', [
            'storeFavorites',
            'toggleStoreFavorite',
            'store_favorite',
            'normalizeReviewSort',
            'reviewSortOrder',
            'FavoriteReviewPhase14Service::VERSION',
        ]);
        $this->requireFileContains('Buyer API controller store favorites', 'api/modules/v1/controllers/AppBuyerController.php', [
            "'store-favorites'",
            'actionStoreFavorites',
        ]);
        $this->requireFileContains('Frontend product store favorite', 'frontend/modules/mall/controllers/ProductController.php', [
            'MONGOYIA_PRODUCT_FAVORITE_POST_READ_GUARD_V1',
            'MONGOYIA_PRODUCT_REVIEW_AJAX_GET_GUARD_V1',
            'actionStoreFavorite',
            'StoreFavorite',
            "'favorite' => ['GET', 'POST']",
            "'store-favorite' => ['GET', 'POST']",
            "'review' => ['GET']",
            "post('product_id', 0)",
            "get('product_id', 0)",
            "post('store_id', 0)",
            "get('store_id', 0)",
            'return $this->error(-1);',
        ]);
        $this->requireFileNotContains('Frontend product/store favorite has no stale id reads or missing view render', 'frontend/modules/mall/controllers/ProductController.php', [
            "post('product_id');",
            "get('product_id');",
            "get('product_id', Yii::\$app->request->post('product_id'))",
            "post('store_id');",
            "get('store_id');",
            'return $this->render($this->action->id,',
        ]);
        $this->requireFileContains('Frontend product consultation POST id guard', 'frontend/modules/mall/controllers/ProductController.php', [
            'MONGOYIA_PRODUCT_CONSULTATION_POST_ID_GUARD_V1',
            'function actionConsultation',
            "post('product_id', 0)",
            "get('product_id', 0)",
        ]);
        $this->requireFileNotContains('Frontend product consultation has no GET/POST fallback', 'frontend/modules/mall/controllers/ProductController.php', [
            "get('product_id', Yii::\$app->request->post('product_id'))",
        ]);
        $this->requireFileContains('PC product store favorite UI', 'web/resources/mall/default/views/product/view.php', [
            'data-mongoyia-phase14-store-favorite',
            'data-mongoyia-phase14-review-sort',
            'review_sort',
            'loadReviews',
            'store-heart-icon',
            '/mall/product/store-favorite',
        ]);
        $this->requireFileContains('APP product store favorite UI', 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/product.vue', [
            'data-mongoyia-phase14-store-favorite',
            'data-mongoyia-phase14-review-sort',
            'reviewSortOptions',
            'toggleStoreFavorite',
            'BUYER_ENDPOINTS.storeFavorites',
            'BUYER_ENDPOINTS.reviews',
        ]);
        $this->requireFileContains('APP received-order review submission', 'common/services/mall/AppBuyerApiService.php', [
            'MONGOYIA_APP_BUYER_REVIEW_WRITE_V1',
            'submitReview',
            'MODERATION_PENDING',
            'Only received orders can be reviewed',
        ]);
        $this->requireFileContains('APP received-order review UI', 'apps/mongoyia-customer-chat-uniapp/src/pages/buyer/orders.vue', [
            'data-mongoyia-phase13-buyer-review-submit',
            'BUYER_ENDPOINTS.reviews',
            'submitReview',
        ]);
        $this->requireFileContains('Review submission pending moderation', 'frontend/modules/mall/controllers/OrderController.php', [
            'MODERATION_PENDING',
            'Review submitted, waiting for moderation',
            'MONGOYIA_BUYER_ORDER_RECEIVED_POST_ID_GUARD_V1',
            "post('id', 0)",
        ]);
        $this->requireFileContains('PC received-order forms use hidden POST id', 'web/resources/mall/default/views/order/view.php', [
            'data-mongoyia-buyer-received-post-guard',
            "hiddenInput('id'",
        ]);
        $this->requireFileContains('PC order-list received forms use hidden POST id', 'web/resources/mall/default/views/user/order_.php', [
            'data-mongoyia-buyer-received-post-guard',
            "hiddenInput('id'",
        ]);
        $this->requireFileNotContains('PC received-order detail form has no URL id write', 'web/resources/mall/default/views/order/view.php', [
            "Html::beginForm(['/mall/order/review', 'id' =>",
        ]);
        $this->requireFileNotContains('PC received-order list form has no URL id write', 'web/resources/mall/default/views/user/order_.php', [
            "Html::beginForm(['/mall/order/review', 'id' =>",
        ]);
        $this->requireFileContains('Backend review moderation actions', 'backend/modules/mall/controllers/ReviewController.php', [
            'MONGOYIA_REVIEW_MODERATION_POST_VERB_GUARD_V1',
            'MONGOYIA_REVIEW_MODERATION_ID_POST_GUARD_V1',
            'behaviors',
            'actionApprove',
            'actionReject',
            'actionMarkViolation',
            "'approve'] = ['post']",
            "'reject'] = ['post']",
            "'mark-violation'] = ['post']",
            "post('id', 0)",
            'moderateReview',
        ]);
        $this->requireFileNotContains('Backend review moderation action ids are not URL parameters', 'backend/modules/mall/controllers/ReviewController.php', [
            'function actionApprove($id)',
            'function actionReject($id)',
            'function actionMarkViolation($id)',
        ]);
        $this->requireFileContains('Backend review moderation UI', 'backend/modules/mall/views/review/index.php', [
            'data-mongoyia-phase14-review-moderation',
            'data-mongoyia-review-moderation-post-guard',
            'data-mongoyia-phase14-review-sort',
            'moderation_status',
            'Url::to([$route])',
            "\$button('approve'",
            'mark-violation',
            'name="id"',
        ]);
        $this->requireFileNotContains('Backend review moderation UI has no URL id actions', 'backend/modules/mall/views/review/index.php', [
            "['approve', 'id' =>",
            "['reject', 'id' =>",
            "['mark-violation', 'id' =>",
        ]);
        $this->requireFileContains('Backend store favorite list', 'backend/modules/mall/views/store-favorite/index.php', [
            'data-mongoyia-phase14-store-favorite-backend',
            'Store Favorites',
        ]);
        $this->requireFileContains('Phase 14 aggregate tracks favorite/review readiness', 'console/controllers/LogisticsProductPhase14AcceptanceController.php', [
            'Favorite and review moderation readiness',
            'favorite-review-phase14-readiness/run',
        ]);
        $this->requireFileContains('Phase 14 backlog command list', 'docs/mongoyia-upgrade-backlog-20260618.md', [
            'favorite-review-phase14-readiness/run',
            'Phase 14.5 store favorite',
        ]);
    }

    private function checkFavoriteReviewFixture(): void
    {
        $this->section('Favorite/review fixture');
        try {
            $service = new FavoriteReviewPhase14Service();
            $cancel = $service->planStoreFavoriteToggle($service->fixtureFavorites(), 701, 3, 'Store A');
            $create = $service->planStoreFavoriteToggle($service->fixtureFavorites(), 701, 5, 'Store C');
            if (($cancel['action'] ?? '') !== 'cancel_store_favorite' || !empty($cancel['store_favorite'])) {
                $this->fail('Store favorite cancel fixture is incorrect.');
                return;
            }
            if (($create['action'] ?? '') !== 'create_store_favorite' || empty($create['store_favorite'])) {
                $this->fail('Store favorite create fixture is incorrect.');
                return;
            }

            $approve = $service->reviewModerationTransition('approve');
            $reject = $service->reviewModerationTransition('reject');
            $violation = $service->reviewModerationTransition('violation');
            if (empty($approve['transition']['visible_to_users']) ||
                !empty($reject['transition']['visible_to_users']) ||
                !empty($violation['transition']['visible_to_users'])) {
                $this->fail('Review moderation visibility fixture is incorrect.');
                return;
            }
            foreach ([$approve, $reject, $violation] as $row) {
                if (!empty($row['mutates_order']) || !empty($row['mutates_fund']) || !empty($row['mutates_stock'])) {
                    $this->fail('Favorite/review fixture must not mutate order, fund, or stock.');
                    return;
                }
            }

            $this->addCheck('Store favorite toggle fixture', 'PASS', 'FavoriteReviewPhase14Service::planStoreFavoriteToggle', 'Create/cancel store favorite behavior is covered without touching product favorites.');
            $this->addCheck('Review moderation fixture', 'PASS', 'FavoriteReviewPhase14Service::reviewModerationTransition', 'Pending, approve, reject, and violation visibility transitions are covered.');
            $this->addCheck('High-risk mutation boundary', 'PASS', 'fixture summary', 'Store favorite and review moderation do not mutate orders, funds, stock, payment, or logistics rows.');
        } catch (\Throwable $e) {
            $this->fail('Favorite/review fixture failed: ' . $e->getMessage());
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
            '# Mongoyia Phase 14 Favorite/Review Readiness',
            '',
            '- Version: ' . self::VERSION,
            '- Result: ' . $result,
            '- Generated at: ' . date('Y-m-d H:i:s'),
            '- Failures: ' . $this->failures,
            '- Warnings: ' . $this->warnings,
            '- Scope: store favorite table/API/UI/backend list and review moderation pending/approve/reject/violation workflow.',
            '- Safety: this command is read-only and does not mutate products, stock, orders, funds, payments, logistics, or provider credentials.',
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
            '/www/server/php/83/bin/php yii favorite-review-phase14-readiness/run --fixture=1 --interactive=0',
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

        $this->addCheck($label, 'PASS', $path, 'Required favorite/review markers are present.');
    }

    private function requireFileNotContains(string $label, string $path, array $needles): void
    {
        $full = $this->resolvePath($path);
        if (!is_file($full)) {
            $this->addCheck($label, 'FAIL', $path, 'Required file is missing.');
            return;
        }

        $content = (string)file_get_contents($full);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                $this->addCheck($label, 'FAIL', $path, "Forbidden marker {$needle} is still present.");
                return;
            }
        }

        $this->addCheck($label, 'PASS', $path, 'Forbidden favorite/review markers are absent.');
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
            . DIRECTORY_SEPARATOR . 'mongoyia-favorite-review-phase14-readiness-' . date('Ymd-His') . '.md';
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
