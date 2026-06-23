<?php

namespace api\modules\v1\controllers;

use api\controllers\BaseController;
use common\models\mall\Product;
use common\services\mall\AppSellerApiService;
use Yii;

class AppSellerController extends BaseController
{
    public const VERSION = 'MONGOYIA_APP_SELLER_CONTROLLER_V1';

    public $modelClass = Product::class;
    public $skipModelClass = '*';

    private $sellerService;

    public function actions()
    {
        return [];
    }

    public function actionDashboard()
    {
        return $this->runSellerAction(function (int $storeId) {
            return $this->sellerService()->dashboard($storeId);
        });
    }

    public function actionProducts()
    {
        return $this->runSellerAction(function (int $storeId) {
            if (Yii::$app->request->isPost) {
                return $this->sellerService()->saveProduct($storeId, Yii::$app->request->post());
            }

            return $this->sellerService()->products($storeId, Yii::$app->request->get());
        });
    }

    public function actionOrders()
    {
        return $this->runSellerAction(function (int $storeId) {
            if (Yii::$app->request->isPost) {
                return $this->sellerService()->shipOrder($storeId, Yii::$app->request->post());
            }

            return $this->sellerService()->orders($storeId, Yii::$app->request->get());
        });
    }

    public function actionShipment()
    {
        return $this->runSellerAction(function (int $storeId) {
            return $this->sellerService()->shipOrder($storeId, Yii::$app->request->post());
        });
    }

    public function actionLogistics()
    {
        return $this->runSellerAction(function (int $storeId) {
            return $this->sellerService()->logistics($storeId);
        });
    }

    public function actionDeposit()
    {
        return $this->runSellerAction(function (int $storeId) {
            return $this->sellerService()->deposit($storeId);
        });
    }

    public function actionCoupons()
    {
        return $this->runSellerAction(function (int $storeId) {
            if (Yii::$app->request->isPost) {
                return $this->sellerService()->participateCoupon($storeId, Yii::$app->request->post());
            }

            return $this->sellerService()->coupons($storeId);
        });
    }

    public function actionStatistics()
    {
        return $this->runSellerAction(function (int $storeId) {
            return $this->sellerService()->statistics($storeId);
        });
    }

    public function actionDistribution()
    {
        return $this->runSellerAction(function (int $storeId) {
            return $this->sellerService()->distribution($storeId);
        });
    }

    private function runSellerAction(callable $callback): array
    {
        try {
            $storeId = $this->sellerStoreId();
            if ($storeId <= 0) {
                Yii::$app->response->statusCode = 403;
                return [
                    'version' => self::VERSION,
                    'message' => 'SELLER_STORE_REQUIRED',
                ];
            }

            return $callback($storeId);
        } catch (\Throwable $e) {
            return $this->apiError($e->getMessage(), Yii::$app->response->statusCode >= 400 ? Yii::$app->response->statusCode : 422);
        }
    }

    private function sellerStoreId(): int
    {
        if (Yii::$app->user->isGuest) {
            Yii::$app->response->statusCode = 401;
            return 0;
        }

        return (int)(Yii::$app->user->identity->store_id ?? 0);
    }

    private function sellerService(): AppSellerApiService
    {
        if (!$this->sellerService) {
            $this->sellerService = new AppSellerApiService();
        }

        return $this->sellerService;
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
