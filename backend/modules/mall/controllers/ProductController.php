<?php

namespace backend\modules\mall\controllers;

use common\helpers\ArrayHelper;
use common\models\mall\Attribute;
use common\models\mall\AttributeSet;
use common\models\mall\AttributeItem;
use common\models\mall\Param;
use common\models\mall\ProductAttributeItemLabel;
use common\models\mall\ProductParam;
use common\models\mall\ProductSku;
use common\models\mall\ProductTag;
use common\models\mall\Tag;
use common\helpers\OfficeHelper;
use Yii;
use common\models\mall\Product;
use common\models\ModelSearch;

use yii\data\ActiveDataProvider;
use yii\data\Pagination;
use yii\helpers\Inflector;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Product
 *
 * Class ProductController
 * @package backend\modules\mall\controllers
 */
class ProductController extends BaseController
{
    public const AUDIT_VERB_GUARD_VERSION = 'MONGOYIA_PRODUCT_AUDIT_POST_VERB_GUARD_V1';

    /**
     * @var bool
     */
    public $isMultiLang = true;
    public $isAutoTranslation = true;

    /**
      * @var Product
      */
    public $modelClass = Product::class;

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
        'sku' => 'text',
        'stock' => 'text',
        'price' => 'text',
        'market_price' => 'text',
    ];

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions']['approve'] = ['post'];
        $behaviors['verbs']['actions']['reject'] = ['post'];

        return $behaviors;
    }

    public function actionIndex()
    {
        $storeId = $this->isMallPlatformOperator() ? null : $this->getStoreId();
        $sa = $this->isMallPlatformOperator();

        if ($this->style == 2) {
            $query = $this->modelClass::find()
                ->where(['>', 'status', $this->modelClass::STATUS_DELETED])
                ->andFilterWhere(['store_id' => $storeId])
                ->orderBy(['id' => SORT_ASC]);

            $dataProvider = new ActiveDataProvider([
                'query' => $query,
                'pagination' => false
            ]);

            return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
                'dataProvider' => $dataProvider,
            ]);
        } elseif ($this->style == 3) {
            $data = $this->modelClass::find()
                ->where(['>', 'status', $this->modelClass::STATUS_DELETED])
                ->andFilterWhere(['store_id' => $storeId]);
            $pages = new Pagination(['totalCount' => $data->count(), 'pageSize' => $this->pageSize]);
            $models = $data->offset($pages->offset)
                ->orderBy(['id' => SORT_DESC])
                ->limit($pages->limit)
                ->all();

            return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
                'models' => $models,
                'pages' => $pages
            ]);
        }

        $searchModel = new ModelSearch([
            'model' => $this->modelClass,
            'scenario' => 'default',
            'likeAttributes' => $this->likeAttributes, // 模糊查询
            'defaultOrder' => $this->defaultOrder,
            'pageSize' => Yii::$app->request->get('page_size', $this->pageSize),
        ]);

        // 管理员级别才能查看所有数据，其他只能查看本store数据
        $params = Yii::$app->request->queryParams;
        if (!$this->isMallPlatformOperator()) {
            $params['ModelSearch']['store_id'] = $this->getStoreId();
            (!isset($params['ModelSearch']['status']) || is_null($params['ModelSearch']['status'])) && $params['ModelSearch']['status'] = '>' . $this->modelClass::STATUS_DELETED;
        } elseif ($this->isAgent()) {
            $params['ModelSearch']['store_id'] = $this->getAgentStoreIds();
        }

        if ($this->style == 11) {
            $params['ModelSearch']['parent_id'] = 0;
        }

        // 可以在filterParams方法中unset($params['ModelSearch']['created_at']) 清除该时间范围，然后筛选到其他字段
        if (Yii::$app->request->get('rangeCreatedAt')) {
            $arrDate = explode(' - ', Yii::$app->request->post('rangeCreatedAt'));
            $arrDate[0] = strtotime($arrDate[0] ?: '-1 month');
            $arrDate[1] = strtotime($arrDate[1] ?? 'now');
            $params['ModelSearch']['created_at'] = implode('><', $arrDate);
        }

        $this->filterParams($params);
        $dataProvider = $searchModel->search($params);

        return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
            'sa'=>$sa
        ]);
    }

    public function actionEdit()
    {
        $id = Yii::$app->request->get('id', null);
        $sa = $this->isMallPlatformOperator();
        $model = $this->findModel($id);
        $this->assertCanAccessProduct($model);
        $productStoreId = (int)($model->store_id ?: $this->getStoreId());
        $model->store_id = $productStoreId;
        $this->beforeEdit($id, $model);
        $lang = $this->isMultiLang ? $this->beforeLang($id, $model) : [];

        $allParams = ArrayHelper::mapIdData(Param::find()->where(['store_id' => $productStoreId, 'status' => Tag::STATUS_ACTIVE])->all());
        $mapAllParamIdName = ArrayHelper::map($allParams, 'id', 'name');

        if (Yii::$app->request->isPost) {
            if ($model->load(Yii::$app->request->post())) {
                $model->translating = Yii::$app->request->post($model->formName())['translating'] ?? 0;
                $model->store_id = $productStoreId;

                $post = Yii::$app->request->post();

                $model->type = ArrayHelper::arrayToInt($post[$model->formName()]['types'] ?? []);
if(!$sa){
    $model->status = 0;
}
//                echo '<pre/>';
//                var_dump($model);exit();
                $hasSkuPriceSync = $model->attribute_set_id > 0 && isset($post['skus']);
                if (!$hasSkuPriceSync && !$this->validateActiveProductPrice($model)) {
                    return $this->redirectError($this->getError($model));
                }
                if (!$this->beforeEditSave($id, $model)) {
                    return $this->redirectError($this->getError($model));
                }

                $transaction = Yii::$app->db->beginTransaction();
                try {
                    if (!$model->save()) {
                        Yii::$app->logSystem->db($model->errors);
                        throw new NotFoundHttpException($this->getError($model));
                    }
                    $this->afterEdit($id, $model);
                    $this->isMultiLang && $this->afterLang($id, $model);

                    // 标签
                    $tags = $post[$model->formName()]['tags'] ?? [];
                    ProductTag::updateAll(['status' => ProductSku::STATUS_DELETED], ['store_id' => $productStoreId, 'product_id' => $model->id]);
                    if (is_array($tags)) {
                        foreach ($tags as $tagId) {
                            $modelTemp = ProductTag::find()->where(['store_id' => $productStoreId, 'product_id' => $model->id, 'tag_id' => $tagId])->one();
                            !$modelTemp && $modelTemp = new ProductTag();
                            $modelTemp->store_id = $productStoreId;
                            $modelTemp->product_id = $model->id;
                            $modelTemp->tag_id = $tagId;
                            $modelTemp->status = Tag::STATUS_ACTIVE;
                            if (!$modelTemp->save()) {
                                Yii::$app->logSystem->db($modelTemp->errors);
                                throw new NotFoundHttpException($this->getError($modelTemp));
                            }
                        }
                    }
                    ProductTag::deleteAll(['status' => ProductSku::STATUS_DELETED, 'store_id' => $productStoreId, 'product_id' => $model->id]);

                    // 计算多属性和sku
                    if ($model->attribute_set_id > 0 && isset($post['skus'])) {
                        $skus = $post['skus'];
                        $minPrice = 0;

                        ProductSku::updateAll(['status' => ProductSku::STATUS_DELETED], ['store_id' => $productStoreId, 'product_id' => $model->id]);

                        foreach ($skus as $attributeValue => $item) {
                            $modelTemp = ProductSku::find()->where(['store_id' => $productStoreId, 'product_id' => $model->id, 'attribute_value' => $attributeValue])->one();
                            !$modelTemp && $modelTemp = new ProductSku();
                            $modelTemp->store_id = $productStoreId;
                            // 按照id顺序用逗号分隔存储
                            $arrAttributeItem = explode(',', $attributeValue);
                            $modelTemp->attribute_value = implode(',', ArrayHelper::intValue($arrAttributeItem, true));
                            $modelTemp->product_id = $model->id;
                            $modelTemp->sku = $item['sku'];
                            $modelTemp->thumb = $item['thumb'];
                            $modelTemp->price = $item['price'];
                            $modelTemp->market_price = $item['market_price'];
                            $modelTemp->cost_price = $item['cost_price'];
                            $modelTemp->wholesale_price = $item['wholesale_price'];
                            $modelTemp->stock = $item['stock'];
                            $modelTemp->status = $item['status'];

                            $minPrice == 0 && $minPrice = $modelTemp->price;
                            ($modelTemp->price > 0) && (($minPrice > $modelTemp->price) && ($minPrice = $modelTemp->price));

                            if (!$modelTemp->save()) {
                                Yii::$app->logSystem->db($modelTemp->errors);
                                throw new NotFoundHttpException($this->getError($modelTemp));
                            }
                        }

                        ProductSku::deleteAll(['status' => ProductSku::STATUS_DELETED, 'store_id' => $productStoreId, 'product_id' => $model->id]);

                        // 多属性价格用大于0的最低价
                        $model->price = $minPrice;
                        if (!$this->validateActiveProductPrice($model)) {
                            throw new NotFoundHttpException($this->getError($model));
                        }
                        if (!$model->save()) {
                            Yii::$app->logSystem->db($model->errors);
                        }
                    } else { // 否则删除所有多属性数据
                        ProductSku::deleteAll(['store_id' => $productStoreId, 'product_id' => $model->id]);
                    }

                    // 计算多属性标签
                    if ($model->attribute_set_id > 0 && isset($post['productAttributeItemLabels'])) {
                        $attributeItems = AttributeItem::find()->all();
                        $mapAttributeItemIdName = ArrayHelper::map($attributeItems, 'id', 'name');
                        $mapAttributeItemIdTye = [];
//                        echo '<pre/>';
                        foreach ($attributeItems as $item) {
                            $mapAttributeItemIdTye[$item->id] = $item->attribute0->type ?? Attribute::TYPE_TEXT;
//                            var_dump($item->toArray());
//                            var_dump($item->attribute0->type);
                        }

                        $productAttributeItemLabels = $post['productAttributeItemLabels'];
                        ProductAttributeItemLabel::updateAll(['status' => ProductSku::STATUS_DELETED], ['store_id' => $productStoreId, 'product_id' => $model->id]);

//                        var_dump($attributeItems);
                        foreach ($productAttributeItemLabels as $attributeValueId => $label) {
                            $modelTemp = ProductAttributeItemLabel::find()->where(['store_id' => $productStoreId, 'product_id' => $model->id, 'attribute_item_id' => $attributeValueId])->one();
                            !$modelTemp && $modelTemp = new ProductAttributeItemLabel();
                            $modelTemp->store_id = $productStoreId;
                            $modelTemp->product_id = $model->id;
                            $modelTemp->attribute_item_id = $attributeValueId;
                            $modelTemp->name = $mapAttributeItemIdName[$attributeValueId] ?? '-';
                            $modelTemp->type = $mapAttributeItemIdTye[$attributeValueId] ?? Attribute::TYPE_TEXT;
                            $modelTemp->label = $modelTemp->type == Attribute::TYPE_COLOR ? str_replace('#', '', $label) : $label;
                            $modelTemp->status = ProductAttributeItemLabel::STATUS_ACTIVE;
//                            var_dump($modelTemp->toArray());
                            if (!$modelTemp->save()) {
                                Yii::$app->logSystem->db($modelTemp->errors);
                                throw new NotFoundHttpException($this->getError($modelTemp));
                            }
                        }

                        ProductAttributeItemLabel::deleteAll(['status' => ProductSku::STATUS_DELETED, 'store_id' => $productStoreId, 'product_id' => $model->id]);
                    } else { // 否则删除所有多属性数据
                        ProductAttributeItemLabel::deleteAll(['store_id' => $productStoreId, 'product_id' => $model->id]);
                    }

                    // 计算参数
                    if ($model->param_id > 0 && isset($post['productParam'])) {
                        $params = $post['productParam'];

                        ProductParam::updateAll(['status' => ProductParam::STATUS_DELETED], ['store_id' => $productStoreId, 'product_id' => $model->id]);

                        foreach ($params as $id => $content) {
                            $modelTemp = ProductParam::find()->where(['store_id' => $productStoreId, 'product_id' => $model->id, 'param_id' => $id])->one();
                            !$modelTemp && $modelTemp = new ProductParam();
                            $modelTemp->store_id = $productStoreId;
                            $modelTemp->product_id = $model->id;
                            $modelTemp->param_id = $id;
                            $modelTemp->name = $mapAllParamIdName[$id] ?? '-';
                            $modelTemp->content = $content;
                            $modelTemp->status = ProductParam::STATUS_ACTIVE;

                            if (!$modelTemp->save()) {
                                Yii::$app->logSystem->db($modelTemp->errors);
                                throw new NotFoundHttpException($this->getError($modelTemp));
                            }
                        }

                        ProductParam::deleteAll(['status' => ProductParam::STATUS_DELETED, 'store_id' => $productStoreId, 'product_id' => $model->id]);
                    } else {
                        ProductParam::deleteAll(['status' => ProductParam::STATUS_DELETED, 'store_id' => $productStoreId, 'product_id' => $model->id]);
                    }

                    $transaction->commit();
                    $this->clearCache();
                    return $this->redirectSuccess(['index']);
                } catch (\Exception $e) {
                    $transaction->rollBack();
                    return $this->redirectError($e->getMessage());
                }

            }
        }

        $model->isAttribute = $model->attribute_set_id > 0 ? 1 : 0;
        $attributes = $attributeItems = [];

        $mapAttributeIdName = ArrayHelper::map($attributes, 'id', 'name');
        $mapProductAttributeItemIdLabel = [];
        $mapProductAttributeItemAttributeItemIdLabel = [];
        if ($id > 0) {
            $productAttributeItemLabels = ProductAttributeItemLabel::find()->where(['product_id' => $id])->all();
            $mapProductAttributeItemIdLabel = ArrayHelper::map($productAttributeItemLabels, 'id', 'label');
            $mapProductAttributeItemAttributeItemIdLabel = ArrayHelper::map($productAttributeItemLabels, 'attribute_item_id', 'label');
        }

        if ($model->isAttribute > 0) {
            $attributeSet = AttributeSet::findOne($model->attribute_set_id);
            if ($attributeSet && count($attributeSet->attributeSetAttributes) > 0) {
                $attributes = Attribute::find()
                    ->where(['store_id' => $model->store_id, 'id' => ArrayHelper::getColumn($attributeSet->attributeSetAttributes, 'attribute_id')])
                    ->orderBy(['sort' => SORT_ASC])
                    ->with('attributeItems')
                    ->all();

                /*if ($id > 0) {
                    foreach ($attributes as &$attribute) {
                        foreach ($attribute->attributeItems as &$attributeValue) {
                            $attributeValue->label = $mapProductAttributeItemAttributeItemIdLabel[$attributeValue->id] ?? '';
                        }
                        unset($attributeValue);
                    }
                    unset($attribute);
                }*/
            }
        }

        // 计算已经启用的属性值
        $enableValueIds = [];
        $productSkus = [];
        $enableValues = [];
        if ($model->attribute_set_id > 0) {
            $productSkus = ProductSku::find()->where(['store_id' => $productStoreId, 'product_id' => $model->id])->asArray()->all();
            if ($productSkus) {
                $enableValueIds = array_unique(explode(',', implode(',', ArrayHelper::getColumn($productSkus, 'attribute_value'))));
            }
            $attributeItems = AttributeItem::find()->where(['store_id' => $productStoreId, 'id' => $enableValueIds])->all();
            foreach ($attributeItems as $attributeItem) {
                $item = $attributeItem->attributes;
                $item['attribute_name'] = $mapAttributeIdName[$attributeItem->attribute_id] ?? '';
                $item['label'] = $mapProductAttributeItemIdLabel[$attributeItem->id] ?? '';

                $enableValues[] = $item;
            }
        }

        // 计算参数
        $productParams = [];
        if ($model->param_id > 0) {
            $productParams = ArrayHelper::map(ProductParam::find()->where(['store_id' => $productStoreId, 'product_id' => $id])->all(), 'param_id', 'content');
        }

        $this->beforeEditRender($id, $model);
        $model->types = ArrayHelper::intToArray($model->type, $this->modelClass::getTypeLabels());
        $allTags = ArrayHelper::map(Tag::find()->where(['store_id' => $productStoreId, 'status' => Tag::STATUS_ACTIVE])->asArray()->all(), 'id', 'name');
        $model->tags = ArrayHelper::getColumn(ProductTag::find()->filterWhere(['product_id' => $id])->asArray()->all(), 'tag_id');
        return $this->render($this->action->id, [
            'model' => $model,
            'attributes' => $attributes,
            'mapProductAttributeItemAttributeItemIdLabel' => $mapProductAttributeItemAttributeItemIdLabel,
            'enableValues' => $enableValues,
            'productSkus' => $productSkus,
            'allParams' => $allParams,
            'productParams' => $productParams,
            'allTags' => $allTags,
            'lang' => $lang,
            'sa'=>$sa
        ]);
    }

    public function actionExport()
    {
        $model = new $this->modelClass();
        $fields = [];
        foreach ($this->exportFields as $field => $type) {
            if ($type == 'select') {
                $getLabels = 'get' . Inflector::camelize($field) . 'Labels';
                $fields[] = [$field, $model->attributeLabels()[$field] ?? '', $type, $this->modelClass::$getLabels(null, true)];
            } elseif ($type == 'date') {
                $fields[] = [$field, $model->attributeLabels()[$field] ?? '', $type, 'Y-m-d'];
            } elseif ($type == 'datetime') {
                $fields[] = [$field, $model->attributeLabels()[$field] ?? '', $type, 'Y-m-d H:i:s'];
            } else {
                $fields[] = [$field, $model->attributeLabels()[$field] ?? '', $type];
            }
        }

        $ext = Yii::$app->request->get('ext', 'xls');
        if (Yii::$app->request->get('template')) {
            $spreadSheet = $this->arrayToSheet([], $fields);
            OfficeHelper::write($spreadSheet, $ext, $this->store->host_name . '_template.' . $ext);
            exit();
        }

        $condition = [];
        if (!$this->isMallPlatformOperator()) {
            $condition['store_id'] = $this->getStoreId();
        }

        $ids = Yii::$app->request->post('ids');
        if ($ids) {
            $condition['id'] = array_filter(array_map('intval', explode(',', $ids)));
        }

        $models = $this->modelClass::find()->filterWhere($condition)->orderBy($this->exportSort)->asArray()->all();
        $spreadSheet = $this->arrayToSheet($models, $fields);

        $arrModelClass = explode('\\', strtolower($this->modelClass));
        OfficeHelper::write($spreadSheet, $ext, $this->store->host_name . '_' . array_pop($arrModelClass) . '_' . date('mdHis') . '.' . $ext);
        exit();
    }

    public function actionApprove()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $model = $this->findModel(Yii::$app->request->post('id', 0));
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        if (!$this->validateActiveProductPrice($model, Product::STATUS_ACTIVE)) {
            return $this->redirectError($this->getError($model));
        }

        $model->status = Product::STATUS_ACTIVE;
        $model->audit_status = 'approved';
        $model->audit_remark = Yii::$app->request->post('remark', 'Approved from backend.');
        $model->reviewed_at = time();
        $model->reviewer_id = Yii::$app->user->id;
        if (!$model->save()) {
            return $this->redirectError($this->getError($model));
        }

        $this->clearCache();
        return $this->redirectSuccess();
    }

    public function actionReject()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        $model = $this->findModel(Yii::$app->request->post('id', 0));
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $model->status = Product::STATUS_INACTIVE;
        $model->audit_status = 'rejected';
        $model->audit_remark = Yii::$app->request->post('remark', 'Rejected from backend.');
        $model->reviewed_at = time();
        $model->reviewer_id = Yii::$app->user->id;
        if (!$model->save()) {
            return $this->redirectError($this->getError($model));
        }

        $this->clearCache();
        return $this->redirectSuccess();
    }

    protected function beforeView($id, $model)
    {
        $this->assertCanAccessProduct($model);
        return true;
    }

    protected function beforeEdit($id = null, $model = null)
    {
        $this->assertCanManageProduct($model);
        return true;
    }

    protected function beforeEditAjaxField($id = null, $model = null, $field = null, $value = null)
    {
        $this->assertCanManageProduct($model);
        return true;
    }

    protected function beforeEditAjaxStatus($id = null, $model = null)
    {
        $this->assertCanManageProduct($model);
        return true;
    }

    protected function beforeEditAjaxStatusSave($id = null, $model = null, $status = null)
    {
        if (!$this->validateActiveProductPrice($model, $status)) {
            return false;
        }

        if (!$this->isMallPlatformOperator() && (int)$status === Product::STATUS_ACTIVE) {
            $model->addError('status', Yii::t('app', 'Seller products must be reviewed by the platform before listing.'));
            return false;
        }

        $this->assertCanManageProduct($model);
        $this->applyProductAuditState($model, (int)$status);
        return true;
    }

    protected function beforeEditStatus($id = null, $model = null)
    {
        $this->assertCanManageProduct($model);
        return true;
    }

    protected function beforeEditStatusSave($id = null, $model = null, $status = null)
    {
        if (!$this->validateActiveProductPrice($model, $status)) {
            return false;
        }

        if (!$this->isMallPlatformOperator() && (int)$status === Product::STATUS_ACTIVE) {
            $model->addError('status', Yii::t('app', 'Seller products must be reviewed by the platform before listing.'));
            return false;
        }

        $this->assertCanManageProduct($model);
        $this->applyProductAuditState($model, (int)$status);
        return true;
    }

    protected function beforeEditSave($id = null, $model = null)
    {
        if (!$model instanceof Product) {
            return true;
        }

        if (!$this->isMallPlatformOperator() && !$this->validateProductCategoryAuthorization($model)) {
            return false;
        }

        $this->applyProductAuditState($model, (int)$model->status);
        return true;
    }

    protected function beforeDeleteModel($id = null, $model = null, $soft = false, $tree = false)
    {
        $this->assertCanManageProduct($model);
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

    protected function assertCanAccessProduct($model)
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

    protected function assertCanManageProduct($model)
    {
        return $this->assertCanAccessProduct($model);
    }

    protected function validateActiveProductPrice($model, $status = null)
    {
        $targetStatus = $status === null ? (int)($model->status ?? 0) : (int)$status;
        if (!$model instanceof Product || $targetStatus !== Product::STATUS_ACTIVE) {
            return true;
        }

        if ((float)$model->price > 0) {
            return true;
        }

        $model->addError('price', Yii::t('app', 'Active products must have a price greater than 0.'));
        return false;
    }

    protected function validateProductCategoryAuthorization(Product $model)
    {
        $storeId = (int)$model->store_id;
        $categoryId = (int)$model->category_id;
        if ($storeId <= 0 || $categoryId <= 0) {
            return true;
        }

        $query = (new \yii\db\Query())
            ->from('{{%store_category_auth}}')
            ->where([
                'store_id' => $storeId,
                'status' => Product::STATUS_ACTIVE,
            ]);

        $hasAnyAuth = $query->exists(Yii::$app->db);
        if (!$hasAnyAuth) {
            return true;
        }

        $isAllowed = (new \yii\db\Query())
            ->from('{{%store_category_auth}}')
            ->where([
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'audit_status' => 'approved',
                'status' => Product::STATUS_ACTIVE,
            ])
            ->exists(Yii::$app->db);
        if ($isAllowed) {
            return true;
        }

        $model->addError('category_id', Yii::t('app', 'This store is not authorized for the selected category.'));
        return false;
    }

    protected function applyProductAuditState(Product $model, int $targetStatus = null)
    {
        if (!$model->hasAttribute('audit_status')) {
            return;
        }

        if (!$this->isMallPlatformOperator()) {
            $model->audit_status = 'submitted';
            $model->reviewed_at = 0;
            $model->reviewer_id = 0;
            return;
        }

        if ($targetStatus === Product::STATUS_ACTIVE) {
            $model->audit_status = 'approved';
            $model->reviewed_at = time();
            $model->reviewer_id = Yii::$app->has('user') && !Yii::$app->user->getIsGuest() ? Yii::$app->user->id : 0;
        } elseif ($targetStatus === Product::STATUS_INACTIVE && !$model->audit_status) {
            $model->audit_status = 'draft';
        }
    }
}
