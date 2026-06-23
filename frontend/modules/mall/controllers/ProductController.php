<?php

namespace frontend\modules\mall\controllers;

use common\helpers\ArrayHelper;
use common\models\mall\Attribute;
use common\models\mall\AttributeSet;
use common\models\mall\Consultation;
use common\models\mall\Favorite;
use common\models\mall\Param;
use common\models\mall\Product;
use common\models\mall\ProductAttributeItemLabel;
use common\models\mall\ProductParam;
use common\models\mall\ProductSku;
use common\models\mall\Review;
use common\models\mall\StoreFavorite;
use common\models\Store;
use Yii;
use yii\data\ActiveDataProvider;

/**
 * Class ProductController
 * @package frontend\modules\mall\controllers
 * @author funson86 <funson86@gmail.com>
 */
class ProductController extends BaseController
{
    public function actionIndex()
    {
        return $this->goHome();
    }

    public function actionView()
    {
//        echo '<pre/>';
        $id = @Yii::$app->request->get('id');
        $seoUrl = Yii::$app->request->get('seo_url');
        if ($id) {
            $model = Product::findOne([ 'id' => $id]);
        } elseif ($seoUrl) {
            $model = Product::findOne([ 'seo_url' => $seoUrl]);
        }

        if (!$model) {
            return $this->goBack();
        }
        $productid = $model->id;
        $data = ['pid'=>$productid,'time'=>time()];
        if(!Yii::$app->user->isGuest){
            $data['uid'] = Yii::$app->user->id;
        }
        Yii::$app->db->createCommand()->insert('fb_mall_product_visit',$data)->execute();

//        (new \yii\db\Query())->from('fb_mall_product_visit')->
//        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
//        $url =  $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
//        echo 1;exit();

        // 计算属性
        $jsonProductAttribute = []; // 控制前端显示价格和库存，简单处理方式，点击后看购物车按钮是否可用
        $enableValueIds = [];
        $productSkus = ProductSku::find()->where(['product_id' => $model->id])->asArray()->all();
        $defaultAttributeIds = [];
        if ($productSkus) {
            $enableValueIds = array_unique(explode(',', implode(',', ArrayHelper::getColumn($productSkus, 'attribute_value'))));
            foreach ($productSkus as $productSku) {
                if ((int)$productSku['stock'] > 0) {
                    $defaultAttributeIds = ArrayHelper::intValue(explode(',', $productSku['attribute_value']), true);
                    sort($defaultAttributeIds);
                    break;
                }
            }
            if (!$defaultAttributeIds) {
                $defaultAttributeIds = ArrayHelper::intValue(explode(',', $productSkus[0]['attribute_value']), true);
                sort($defaultAttributeIds);
            }
        }
        foreach ($productSkus as &$productSku) {
            $jsonProductAttribute[$productSku['attribute_value']] = ['price' => $this->getNumberByCurrency($productSku['price']), 'stock' => $productSku['stock']];

            $attributeValueIds = explode(',', $productSku['attribute_value']);
            $productSku['attribute_value'] = ArrayHelper::intValue($attributeValueIds, true);
        }
        $productAttributeItemLabels = ProductAttributeItemLabel::find()->where(['product_id' => $model->id])->all();
//        $mapProductAttributeItemIdLabel = ArrayHelper::map($productAttributeItemLabels, 'id', 'label');
        $mapProductAttributeItemAttributeItemIdLabel = ArrayHelper::map($productAttributeItemLabels, 'attribute_item_id', 'label');

        // 计算属性
        $attributes = [];
        if ($model->attribute_set_id > 0) {
            $attributeSet = AttributeSet::findOne($model->attribute_set_id);
            if ($attributeSet && count($attributeSet->attributeSetAttributes) > 0) {
                $attributes = Attribute::find()
                    ->where(['id' => ArrayHelper::getColumn($attributeSet->attributeSetAttributes, 'attribute_id')])
                    ->orderBy(['sort' => SORT_ASC])
                    ->with(['attributeItems' => function ($query) use ($enableValueIds) {
                        $query->andWhere(['id' => $enableValueIds]);
                    }])
                    ->all();
            }
        }

        // 计算参数
        $allParams = [];
        $mapProductParamIdContent = $arrProductParamIds = [];
        if ($model->param_id > 0) {
            $allParams = ArrayHelper::mapIdData(Param::find()->where(['parent_id' => $model->param_id, 'status' => Param::STATUS_ACTIVE])->with('children')->all());
            $productParams = ProductParam::find()->where(['product_id' => $model->id])->all();
            $mapProductParamIdContent = ArrayHelper::map($productParams, 'param_id', 'content');
            $arrProductParamIds = ArrayHelper::getColumn($productParams, 'param_id');
        }

        return $this->render($this->action->id, [
            'model' => $model,
            'attributes' => $attributes,
            'mapProductAttributeItemAttributeItemIdLabel' => $mapProductAttributeItemAttributeItemIdLabel,
            'productSkus' => $productSkus, //此种计算方法更复杂，用于点击时显示下面的属性是否可选
            'jsonProductAttribute' => json_encode($jsonProductAttribute),
            'defaultAttributeIds' => $defaultAttributeIds,
            'allParams' => $allParams,
            'mapProductParamIdContent' => $mapProductParamIdContent,
            'arrProductParamIds' => $arrProductParamIds,
//            'url' => $url,
        ]);
    }

    public function actionFavorite()
    {
        // 收藏&取消
        if (Yii::$app->request->isPost) {
            if (Yii::$app->user->isGuest) {
                return $this->error(-2);
            }

            $productId = Yii::$app->request->post('product_id');
            if (!$productId) {
                return $this->error(-11, Yii::t('mall', 'Need Product'));
            }
            $product = Product::findOne(['id' => $productId]);
            if (!$product) {
                return $this->error(-11, Yii::t('mall', 'Need Product'));
            }

            $model = Favorite::find()->where(['user_id' => Yii::$app->user->id, 'product_id' => $productId])->one();
            if ($model) {
                $model->delete();
                return $this->success(0);
            } else {
                $model = new Favorite();
                $model->user_id = Yii::$app->user->id;
                $model->product_id = $productId;
                $model->store_id = (int)$product->store_id;
                $model->name = $product->name;
                if (!$model->save()) {
                    Yii::$app->logSystem->db($model->errors);
                    return $this->success(0);
                }
                return $this->success(1);
            }
        } else if (Yii::$app->request->isAjax) { // 查询
            if (Yii::$app->user->isGuest) {
                return $this->error(-2);
            }

            $productId = Yii::$app->request->get('product_id');
            if (!$productId) {
                return $this->error(-11, Yii::t('mall', 'Need Product'));
            }
            $product = Product::findOne(['id' => $productId]);
            if (!$product) {
                return $this->error(-11, Yii::t('mall', 'Need Product'));
            }

            $model = Favorite::find()->where(['user_id' => Yii::$app->user->id, 'product_id' => $productId])->one();
            if ($model) {
                return $this->success(1);
            }
            return $this->success(0);
        }

        // 列表
        $models = Favorite::find()->where(['user_id' => Yii::$app->user->id])->all();
        return $this->render($this->action->id, [
            'models' => $models,
        ]);
    }

    public function actionStoreFavorite()
    {
        $tableReady = Yii::$app->db->schema->getTableSchema(StoreFavorite::tableName(), true) !== null;
        if (Yii::$app->request->isPost) {
            if (Yii::$app->user->isGuest) {
                return $this->error(-2);
            }
            if (!$tableReady) {
                return $this->error(-11, Yii::t('app', 'Store favorite table is not ready'));
            }

            $storeId = (int)Yii::$app->request->post('store_id');
            if ($storeId <= 0) {
                return $this->error(-11, Yii::t('mall', 'Need Store'));
            }
            $store = Store::findOne(['id' => $storeId]);
            if (!$store) {
                return $this->error(-11, Yii::t('mall', 'Need Store'));
            }

            $model = StoreFavorite::find()
                ->where(['user_id' => Yii::$app->user->id, 'store_id' => $storeId])
                ->andWhere(['>', 'status', StoreFavorite::STATUS_DELETED])
                ->one();
            if ($model) {
                $model->status = StoreFavorite::STATUS_DELETED;
                $model->save(false);
                return $this->success(0);
            }

            $model = new StoreFavorite();
            $model->user_id = Yii::$app->user->id;
            $model->store_id = $storeId;
            $model->name = (string)$store->name;
            $model->status = StoreFavorite::STATUS_ACTIVE;
            $model->created_at = time();
            $model->updated_at = time();
            $model->created_by = Yii::$app->user->id;
            $model->updated_by = Yii::$app->user->id;
            if (!$model->save()) {
                Yii::$app->logSystem->db($model->errors);
                return $this->success(0);
            }

            return $this->success(1);
        } else if (Yii::$app->request->isAjax) {
            if (Yii::$app->user->isGuest) {
                return $this->error(-2);
            }
            if (!$tableReady) {
                return $this->success(0);
            }

            $storeId = (int)Yii::$app->request->get('store_id');
            if ($storeId <= 0) {
                return $this->error(-11, Yii::t('mall', 'Need Store'));
            }

            $model = StoreFavorite::find()
                ->where(['user_id' => Yii::$app->user->id, 'store_id' => $storeId])
                ->andWhere(['>', 'status', StoreFavorite::STATUS_DELETED])
                ->one();
            return $this->success($model ? 1 : 0);
        }

        return $this->error(-1);
    }

    /**
     * @return array|mixed
     * @throws \yii\base\InvalidConfigException
     */
    public function actionConsultation()
    {
        if (!Yii::$app->request->isAjax) {
            return $this->error();
        }

        $productId = Yii::$app->request->get('product_id', Yii::$app->request->post('product_id'));
        if (!$productId) {
            return $this->error(-1);
        }
        $product = Product::findOne([ 'id' => $productId]);
        if (!$product) {
            return $this->error(-1);
        }

        // add
        if (Yii::$app->request->isPost) {
            if (Yii::$app->user->isGuest) {
                return $this->error(-2);
            }

            if (strlen(Yii::$app->request->post('question')) <= 0) {
                return $this->error(-1);
            }

            $model = new Consultation();
            $model->product_id = $productId;
            $model->question = Yii::$app->request->post('question');
            $model->user_id = Yii::$app->user->id;
            $model->name = Yii::$app->user->identity->email ?: Yii::$app->user->identity->username;
            $model->status = Consultation::STATUS_INACTIVE;

            if (!$model->save()) {
                Yii::$app->logSystem->db($model->errors);
                return $this->error();
            }
            return $this->success();
        }

        $query = Consultation::find()->where(['product_id' => $productId, 'status' => Consultation::STATUS_ACTIVE]);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['defaultPageSize' => Yii::$app->params['defaultPageSizeProductConsultation'] ?? 1],
            'sort' => ['defaultOrder' => ['created_at' => SORT_DESC]],
        ]);

        return $this->success($this->renderPartial($this->action->id, [
            'models' => $dataProvider->getModels(),
            'pagination' => $dataProvider->pagination,
        ]));
    }

    public function actionReview()
    {
        if (!Yii::$app->request->isAjax) {
            return $this->error();
        }

        $productId = Yii::$app->request->get('product_id', Yii::$app->request->post('product_id'));
        if (!$productId) {
            return $this->error(-1);
        }
        $product = Product::findOne([ 'id' => $productId]);
        if (!$product) {
            return $this->error(-1);
        }

        $query = Review::find()->where(['product_id' => $productId, 'status' => Review::STATUS_ACTIVE]);
        $reviewSchema = Yii::$app->db->schema->getTableSchema(Review::tableName(), true);
        if ($reviewSchema !== null && isset($reviewSchema->columns['moderation_status'])) {
            $query->andWhere(['moderation_status' => Review::MODERATION_APPROVED]);
        }
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['defaultPageSize' => Yii::$app->params['defaultPageSizeProductRank'] ?? 1],
            'sort' => ['defaultOrder' => ['created_at' => SORT_DESC]],
        ]);
//        echo '<pre/>';
//        var_dump($dataProvider->getModels());
//        exit();

        return $this->success($this->renderPartial($this->action->id, [
            'models' => $dataProvider->getModels(),
            'pagination' => $dataProvider->pagination,
        ]));

    }
}
