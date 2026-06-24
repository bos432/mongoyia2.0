<?php

namespace backend\modules\mall\controllers;

use common\helpers\IdHelper;
use Yii;
use common\models\mall\CouponType;
use common\models\ModelSearch;

/**
 * CouponType
 *
 * Class CouponTypeController
 * @package backend\modules\mall\controllers
 */
class CouponTypeController extends BaseController
{
    public const COUPON_ISSUE_POST_ID_GUARD_VERSION = 'MONGOYIA_COUPON_TYPE_ISSUE_POST_ID_GUARD_V1';

    /**
      * @var CouponType
      */
    public $modelClass = CouponType::class;

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
        $request = Yii::$app->request;
        $id = $request->isPost ? (int)$request->post('id', 0) : (int)$request->get('id', 0);
        $model = $this->findModel($id);
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

//        $this->beforeEdit($id, $model);

        // ajax 校验
//        $this->activeFormValidate($model);
        if ($request->isPost) {
            $uid = (int)$request->post('uid', 0);
            $cid = (int)$request->post('id', 0);
            if ($uid <= 0 || $cid <= 0) {
                return $this->redirectError(Yii::t('app', 'Invalid id'));
            }
            $res = Yii::$app->db->createCommand()->insert('{{%mall_user_coupon}}',[
                'uid'=>$uid,
                'cid'=>$cid
            ])->execute();
            if($res){
                $this->clearCache();
                return $this->redirectSuccess();
            }else{
                return $this->redirectError(Yii::t('app', 'Something wrong'));
            }
        }

//        $this->beforeEditRender($id, $model);
        return $this->renderAjax(Yii::$app->request->get('view') ?? $this->viewFile ?? $this->action->id, [
            'model' => $model,
        ]);
    }

    protected function beforeEdit($id = null, $model = null)
    {
        $model->startedTime = date('Y-m-d', ($model->started_at > 0 ? $model->started_at : time()));
        $model->endedTime = date('Y-m-d', ($model->ended_at > 0 ? $model->ended_at : time() + 3 * 86400));
    }

    protected function beforeEditSave($id = null, $model = null)
    {
        $post = Yii::$app->request->post();
        $model->started_at = strtotime($post[$model->formName()]['startedTime']);
        $model->ended_at = strtotime($post[$model->formName()]['endedTime']) + 86400 - 1;
        !$model->sn && $model->sn = substr(IdHelper::uuid(), -8);
        $model->type = strpos($model->money, '%') ? $this->modelClass::TYPE_PERCENT : $this->modelClass::TYPE_FIXED;

        return true;
    }

}
