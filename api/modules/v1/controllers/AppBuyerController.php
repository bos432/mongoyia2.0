<?php

namespace api\modules\v1\controllers;

use api\controllers\BaseController;
use common\models\mall\Product;
use common\services\mall\AppBuyerApiService;
use Yii;

class AppBuyerController extends BaseController
{
    public const VERSION = 'MONGOYIA_APP_BUYER_CONTROLLER_V1';

    public $modelClass = Product::class;
    public $skipModelClass = '*';
    public $optionalAuth = [
        'home',
        'categories',
        'search',
        'suggestions',
        'product',
        'cart',
        'orders',
        'coupons',
        'favorites',
        'store-favorites',
        'reviews',
    ];

    private $buyerService;

    public function actions()
    {
        return [];
    }

    public function actionHome()
    {
        return $this->buyerService()->home((int)$this->getStoreId());
    }

    public function actionCategories()
    {
        return $this->buyerService()->categories((int)$this->getStoreId());
    }

    public function actionSearch()
    {
        return $this->buyerService()->search(Yii::$app->request->get(), (int)$this->getStoreId());
    }

    public function actionSuggestions()
    {
        return $this->buyerService()->suggestions(Yii::$app->request->get(), (int)$this->getStoreId());
    }

    public function actionProduct()
    {
        try {
            return $this->buyerService()->product((int)Yii::$app->request->get('id'), $this->currentUserId());
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage(), 404);
        }
    }

    public function actionCart()
    {
        try {
            if (Yii::$app->request->isPost) {
                $this->requireLogin();
                return $this->buyerService()->addCart($this->currentUserId(), Yii::$app->request->post());
            }

            return $this->buyerService()->cart($this->currentUserId());
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage(), $this->isGuest() ? 401 : 422);
        }
    }

    public function actionOrders()
    {
        try {
            if (Yii::$app->request->isPost) {
                $this->requireLogin();
                Yii::$app->response->statusCode = 409;
                return $this->buyerService()->checkoutReserved();
            }

            return $this->buyerService()->orders($this->currentUserId());
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage(), $this->isGuest() ? 401 : 422);
        }
    }

    public function actionCoupons()
    {
        try {
            return $this->buyerService()->coupons($this->currentUserId());
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage(), $this->isGuest() ? 401 : 422);
        }
    }

    public function actionFavorites()
    {
        try {
            if (Yii::$app->request->isPost) {
                $this->requireLogin();
                return $this->buyerService()->toggleFavorite($this->currentUserId(), (int)Yii::$app->request->post('product_id'));
            }

            return $this->buyerService()->favorites($this->currentUserId());
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage(), $this->isGuest() ? 401 : 422);
        }
    }

    public function actionStoreFavorites()
    {
        try {
            if (Yii::$app->request->isPost) {
                $this->requireLogin();
                return $this->buyerService()->toggleStoreFavorite($this->currentUserId(), (int)Yii::$app->request->post('store_id'));
            }

            return $this->buyerService()->storeFavorites($this->currentUserId());
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage(), $this->isGuest() ? 401 : 422);
        }
    }

    public function actionReviews()
    {
        return $this->buyerService()->reviews(
            (int)Yii::$app->request->get('product_id'),
            (int)Yii::$app->request->get('page', 1),
            (int)Yii::$app->request->get('page_size', 20)
        );
    }

    private function buyerService(): AppBuyerApiService
    {
        if (!$this->buyerService) {
            $this->buyerService = new AppBuyerApiService();
        }

        return $this->buyerService;
    }

    private function currentUserId(): int
    {
        return Yii::$app->user->isGuest ? 0 : (int)Yii::$app->user->id;
    }

    private function isGuest(): bool
    {
        return Yii::$app->user->isGuest;
    }

    private function requireLogin(): void
    {
        if ($this->isGuest()) {
            throw new \RuntimeException('AUTH_REQUIRED');
        }
    }

    private function apiError(string $message, int $statusCode): array
    {
        Yii::$app->response->statusCode = $statusCode;
        return [
            'message' => $message,
            'version' => self::VERSION,
        ];
    }
}
