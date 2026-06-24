<?php

namespace backend\modules\mall\controllers;

use common\models\mall\OrderProduct;
use common\models\Store;
use Yii;
use common\models\mall\Order;
use common\models\ModelSearch;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;

/**
 * Order
 *
 * Class OrderController
 * @package backend\modules\mall\controllers
 */
class OrderProductController extends BaseController
{
    public const SHIPMENT_POST_ID_GUARD_VERSION = 'MONGOYIA_BACKEND_ORDER_PRODUCT_SHIPMENT_POST_ID_GUARD_V1';

    /**
      * @var Order
      */
    public $modelClass = OrderProduct::class;
    

    /**
      * 模糊查询字段
      * @var string[]
      */
    public $likeAttributes = ['name'];

    /**
     * 可编辑字段
     *
     * @var int
     */
    protected $editAjaxFields = ['name', 'sort'];

    /**
     * 导入导出字段
     *
     * @var int
     */
    protected $exportFields = [
        'id' => 'text',
        'name' => 'text',
        'type' => 'select',
    ];

    public function actionFhAjax()
    {
        $this->assertCanManageOrderProducts();
        $request = Yii::$app->request;
        $id = $request->isPost ? $request->post('id', 0) : $request->get('id');
        $model = $this->findModel($id);
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $this->beforeEdit($id, $model);

        // ajax 校验
        $this->activeFormValidate($model);
        if ($request->isPost && $model->load($request->post())) {
            $model->translating = $request->post($model->formName())['translating'] ?? 0;
            $model->shipment_status = 80;
            $model->status = 80;
//            echo '<pre/>';
//            var_dump($model);exit();
            if ($this->beforeEditSave($id, $model)) {
                if (!$model->save()) {
                    return $this->redirectError($this->getError($model));
                }
            } else {
                return $this->redirectError(Yii::t('app', 'Something wrong'));
            }

            $this->afterEdit($id, $model);
            $this->clearCache();
            return $this->redirectSuccess();
        }

        $this->beforeEditRender($id, $model);
        return $this->renderAjax(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'model' => $model,
        ]);
    }

    public function actionIndex()
    {
        $query = $this->modelClass::find()
            ->select([
                'id' => 'fb_mall_order_product.id',
                'product_amount' => 'fb_mall_order.product_amount',
                'amount' => 'fb_mall_order.amount',
                'price'=>'fb_mall_order_product.price',
                'store_id' => 'fb_mall_order_product.store_id',
                'created_at'=>'fb_mall_order_product.created_at'
            ])
            ->joinWith(['order'], false)
            ->where(['=', 'fb_mall_order.payment_status', 40])
            ->asArray()
            ->orderBy(['fb_mall_order_product.id' => SORT_DESC]);

        if (!$this->isMallPlatformOperator()) {
            $query->andWhere(['fb_mall_order_product.store_id' => $this->getStoreId()]);
        }

        $searchParams = Yii::$app->request->get('SearchModel', []);
        if ($this->isMallPlatformOperator() && !empty($searchParams['store_id'])) {
            $query->andWhere(['fb_mall_order_product.store_id' => (int)$searchParams['store_id']]);
        }

        $rangeCreatedAt = Yii::$app->request->get('rangeCreatedAt', '');
        if ($rangeCreatedAt !== '') {
            $times = explode(' - ', $rangeCreatedAt);
            $startAt = strtotime($times[0] ?? '');
            $endAt = strtotime($times[1] ?? '');
            if ($startAt && $endAt) {
                $query->andWhere(['between', 'fb_mall_order.updated_at', $startAt, $endAt]);
            }
        }

        $total = 0;
        foreach ($query->all() as $v) {
            $productAmount = (float)($v['product_amount'] ?? 0);
            if ($productAmount <= 0) {
                continue;
            }
            $total += round((float)($v['amount'] ?? 0) / $productAmount * (float)($v['price'] ?? 0), 2);
        }
        $total = number_format($total,2);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => false
        ]);
        $searchModel = new ModelSearch([
            'model' => $this->modelClass,
            'scenario' => 'default',
        ]);
        return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel'=>$searchModel,
            'total'=>$total
        ]);
    }

    public function actionJs()
    {
        $query = Store::find()
            ->select([
                'fb_store.id',
                'fb_store.name AS store_name',
                'total_actual_amount' => new \yii\db\Expression(
                    'SUM(CASE WHEN fb_mall_order.product_amount > 0 THEN (fb_mall_order.amount / fb_mall_order.product_amount) * fb_mall_order_product.price ELSE 0 END)'
                ),
            ])
            ->joinWith(['orderProducts' => function($query) {
                $query->joinWith(['order' => function($q) {
                    $q->select(['fb_mall_order.id', 'product_amount', 'amount', 'payment_status']);
                }])
                ->andWhere(['fb_mall_order.payment_status' => 40])
                ->andWhere(['between', 'fb_mall_order.updated_at', strtotime('first day of last month 00:00:00'), strtotime('first day of this month 00:00:00')])
                ;
            }], false)
            ->groupBy('fb_store.id')
            ->asArray();
        if (!$this->isMallPlatformOperator()) {
            $query->andWhere(['fb_store.id' => $this->getStoreId()]);
        }
//        echo '<pre/>';
//        var_dump($query->all());exit();
//            ->all();
//        echo '<pre/>';
//        foreach ($query as $v){
//            var_dump($v);
//        }exit();
//        var_dump($query);
//        $query = $this->modelClass::find()
//            ->select([
//                'id' => 'fb_mall_order_product.id',
//                'product_amount' => 'fb_mall_order.product_amount',
//                'amount' => 'fb_mall_order.amount',
//                'store_id' => 'fb_mall_product.store_id',
//                'price'=>'fb_mall_order_product.price',
//                'created_at'=>'fb_mall_order_product.created_at'
//            ])
//            ->joinWith([
//                'order'=>function($query){
//                    $query->select(['product_amount','amount']);
//                },
//                'product'=>function($query){
//                    $query->select(['store_id']);
//                }
//            ])
//            ->where(['=', 'fb_mall_order.payment_status', 40])
//            ->asArray()
//            ->orderBy(['id' => SORT_DESC]);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => false
        ]);
        $searchModel = new ModelSearch([
            'model' => $this->modelClass,
            'scenario' => 'default',
        ]);
        return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel'=>$searchModel
        ]);

    }

    public function actionExport()
    {
        $this->assertCanManageOrderProducts();
        return parent::actionExport();
    }

    public function actionImportAjax()
    {
        $this->assertCanManageOrderProducts();
        return parent::actionImportAjax();
    }

    protected function beforeView($id, $model)
    {
        $this->assertCanAccessOrderProduct($model);
        return true;
    }

    protected function beforeEdit($id = null, $model = null)
    {
        $this->assertCanManageOrderProducts();
        return true;
    }

    protected function beforeEditAjaxField($id = null, $model = null, $field = null, $value = null)
    {
        $this->assertCanManageOrderProducts();
        return true;
    }

    protected function beforeEditAjaxStatus($id = null, $model = null)
    {
        $this->assertCanManageOrderProducts();
        return true;
    }

    protected function beforeDeleteModel($id = null, $model = null, $soft = false, $tree = false)
    {
        $this->assertCanManageOrderProducts();
        return true;
    }

    protected function beforeDeleteAll()
    {
        $this->assertCanManageOrderProducts();
        return true;
    }

    protected function findModel($id = null)
    {
        if (!$id) {
            return parent::findModel($id);
        }

        if ($this->isMallPlatformOperator()) {
            return $this->modelClass::find()->where(['id' => $id])->one();
        }

        return $this->modelClass::find()
            ->where(['id' => $id, 'store_id' => $this->getStoreId()])
            ->one();
    }

    protected function assertCanAccessOrderProduct($model)
    {
        if (!$model) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        if ($this->isMallPlatformOperator()) {
            return true;
        }

        if ((int)$model->store_id !== (int)$this->getStoreId()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }

    protected function assertCanManageOrderProducts()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }

}
