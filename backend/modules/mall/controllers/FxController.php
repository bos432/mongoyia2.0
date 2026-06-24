<?php

namespace backend\modules\mall\controllers;

use common\models\mall\Product;
use Yii;
use common\models\mall\Order;
use common\models\ModelSearch;
use yii\data\ActiveDataProvider;
use yii\data\Pagination;

/**
 * Order
 *
 * Class OrderController
 * @package backend\modules\mall\controllers
 */
class FxController extends BaseController
{
    public const SHIPMENT_POST_ID_GUARD_VERSION = 'MONGOYIA_FX_SHIPMENT_POST_ID_GUARD_V1';

    /**
      * @var Order
      */
    public $modelClass = Order::class;
    

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
        $uid = Yii::$app->user->identity->id;

        if ($this->style == 2) {
            $query = $this->modelClass::find()
                ->where(['>', 'status', $this->modelClass::STATUS_DELETED])
                ->andWhere(['=','fx_id',$uid])
                ->andWhere(['in','payment_status',[10,40]])
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
                ->andWhere(['=','fx_id',$uid])
                ->andWhere(['in','payment_status',[10,40]])
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
            $params['ModelSearch']['fx_id'] = $uid;
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
        $dataProvider = $searchModel->search($params,true);
        $fxSetting = $this->distributionPercentSetting();
        $fxbfb = $fxSetting['percent'];
//        echo '<pre/>';
//        var_dump($fxbfb);exit();
        return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'dataProvider' => $dataProvider,
            'fxbfb'=>$fxbfb,
            'fxSettingNotice' => $fxSetting['notice'],
            'searchModel' => $searchModel,
            'sa'=>$sa,
        ]);exit();
    }

    public function actionShowAjax(){
        $id = Yii::$app->request->get('id');
        $uid = Yii::$app->user->id;
        $url = 'https://www.mongoyia.com/product/'.$id.'?fxid='.$uid;
        return $this->renderAjax(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'url' => $url,
        ]);
    }

    public function actionGoods(){
        $searchModel = new ModelSearch([
            'model' => Product::class,
            'scenario' => 'default',
            'likeAttributes' => $this->likeAttributes, // 模糊查询
            'defaultOrder' => $this->defaultOrder,
            'pageSize' => Yii::$app->request->get('page_size', $this->pageSize),
        ]);
        $params = Yii::$app->request->queryParams;
        $params['ModelSearch']['status'] = 1;
        $dataProvider = $searchModel->search($params);
        $fxSetting = $this->distributionPercentSetting();
        $fxbfb = $fxSetting['percent'];
        return $this->render(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'dataProvider' => $dataProvider,
            'fxbfb'=>$fxbfb,
            'fxSettingNotice' => $fxSetting['notice'],
            'searchModel' => $searchModel,
        ]);exit();
    }

    public function actionFhAjax()
    {
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
            $model->shipment_status = 70;
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

    private function distributionPercentSetting(): array
    {
        $query = (new \yii\db\Query())->from('{{%base_setting}}');
        $row = (clone $query)->where(['id' => 884590952113504256])->one();
        if (!$row) {
            $row = (clone $query)
                ->where(['code' => ['mall_fx_percent', 'mall_distribution_percent', 'distribution_percent', 'fxbfb']])
                ->orderBy(['id' => SORT_ASC])
                ->one();
        }

        $percent = isset($row['value']) ? (int)$row['value'] : 0;
        if ($percent <= 0) {
            return [
                'percent' => 0,
                'notice' => Yii::t('app', 'Distribution percent setting is missing; commission preview uses 0.'),
            ];
        }

        return [
            'percent' => $percent,
            'notice' => '',
        ];
    }

}
