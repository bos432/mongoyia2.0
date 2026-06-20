<?php

namespace backend\modules\mall\controllers;

use Yii;
use common\models\mall\Category;
use common\models\ModelSearch;
use yii\data\ActiveDataProvider;
use yii\data\Pagination;
use yii\web\ForbiddenHttpException;

/**
 * Category
 *
 * Class CategoryController
 * @package backend\modules\mall\controllers
 */
class CategoryController extends BaseController
{
    /**
     * @var bool
     */
    public $isMultiLang = true;
    public $isAutoTranslation = true;

    /**
     * 1带搜索列表 2树形(不分页) 3非常规表格
     * @var array[]
     */
    protected $style = 2;

    /**
      * @var Category
      */
    public $modelClass = Category::class;

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

    public function actionIndex()
    {
        $storeId = $this->getStoreId();
        $sa = $this->isSuperAdmin();
        $platformCategoryStoreId = $this->getMallPlatformCategoryStoreId();

        if ($this->style == 2) {
            $query = $this->modelClass::find()
                ->where(['>', 'status', $this->modelClass::STATUS_DELETED])
                ->andFilterWhere(['store_id' => $platformCategoryStoreId])
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
        if (!$this->isAdmin()) {
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
        $this->assertCanManageCategories();
        $model = $this->findModel($id);
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $this->beforeEdit($id, $model);
        $lang = $this->isMultiLang ? $this->beforeLang($id, $model) : [];

        if (Yii::$app->request->isPost) {
            if ($model->load(Yii::$app->request->post())) {
                $model->translating = Yii::$app->request->post($model->formName())['translating'] ?? 0;
                $model->store_id = $this->getMallPlatformCategoryStoreId();
                if ($this->beforeEditSave($id, $model)) {
                    if ($model->save()) {
                        $this->afterEdit($id, $model);
                        $this->isMultiLang && $this->afterLang($id, $model);
                        $this->clearCache();
                        return $this->redirectSuccess(['index']);
                    } else {
                        Yii::$app->logSystem->db($model->errors);
                        $this->flashError($this->getError($model));
                    }
                } else {
                    $this->flashError(Yii::t('app', 'Something wrong'));
                }
            }
        }

        $this->beforeEditRender($id, $model);
        return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'model' => $model,
            'lang' => $lang,
        ]);
    }

    public function actionExport()
    {
        $this->assertCanManageCategories();
        return parent::actionExport();
    }

    public function actionImportAjax()
    {
        $this->assertCanManageCategories();
        return parent::actionImportAjax();
    }

    protected function beforeView($id, $model)
    {
        $this->assertCanAccessCategory($model);
        return true;
    }

    protected function beforeEdit($id = null, $model = null)
    {
        $this->assertCanManageCategories();
        return true;
    }

    protected function beforeEditAjaxField($id = null, $model = null, $field = null, $value = null)
    {
        $this->assertCanManageCategories();
        return true;
    }

    protected function beforeEditAjaxStatus($id = null, $model = null)
    {
        $this->assertCanManageCategories();
        return true;
    }

    protected function beforeEditStatus($id = null, $model = null)
    {
        $this->assertCanManageCategories();
        return true;
    }

    protected function beforeDeleteModel($id = null, $model = null, $soft = false, $tree = false)
    {
        $this->assertCanManageCategories();
        return true;
    }

    protected function beforeDeleteAll()
    {
        $this->assertCanManageCategories();
        return true;
    }

    protected function findModel($id = null)
    {
        if (!$id) {
            return parent::findModel($id);
        }

        return $this->modelClass::find()
            ->where(['id' => $id, 'store_id' => $this->getMallPlatformCategoryStoreId()])
            ->one();
    }

    protected function assertCanAccessCategory($model)
    {
        if (!$model) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }

    protected function assertCanManageCategories()
    {
        if (!$this->isMallPlatformOperator()) {
            throw new ForbiddenHttpException(Yii::t('app', 'No Auth'));
        }

        return true;
    }

    protected function getMallPlatformCategoryStoreId()
    {
        $storeIds = $this->getMallPlatformOperatorStoreIds();
        return (int)(reset($storeIds) ?: (Yii::$app->params['defaultStoreId'] ?? 0));
    }

}
