<?php
namespace api\controllers;

use api\models\forms\RefreshForm;
use api\models\LoginForm;
use api\modules\v1\models\User;
use Yii;
use yii\base\Response;
use yii\filters\AccessControl;
use yii\filters\Cors;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;

/**
 * Site controller
 */
class SiteController extends BaseController
{
    public $modelClass = '';

    public $skipModelClass = '*';

    public $optionalAuth = ['error', 'index', 'login', 'refresh', 'logout','visit'];

    /**
     * @return string
     */
    public function actionError()
    {
        if (($exception = Yii::$app->getErrorHandler()->exception) === null) {
            $exception = new NotFoundHttpException(Yii::t('yii', 'Page not found.'));
        }

        return Yii::$app->responseSystem->error($exception->getCode(), $exception->getMessage());
    }

    public function actionVisit(){
        $post = Yii::$app->request->post();
        $url = $post['url'];
        return ['code'=>0,'data'=>$url,'message'=>'OK','msg'=>'OK'];
    }

    /**
     * Displays homepage.
     *
     * @return mixed
     */
    public function actionIndex()
    {
        return 'Mongoyia API';
    }

    public function actionLogin()
    {
        $model = new LoginForm();
        $model->attributes = Yii::$app->request->post();
//        var_dump($model->validate());
//        exit();
        if ($model->validate()) {
            $uinfo = Yii::$app->accessTokenSystem->getAccessToken($model->getUser());
//            var_dump($uinfo);exit();
            return $uinfo;
        }

        return $this->error();
    }

    public function actionRefresh()
    {
        $model = new RefreshForm();
        $model->attributes = Yii::$app->request->post();
        if ($model->validate()) {
            return Yii::$app->accessTokenSystem->getAccessToken($model->getUser());
        }

        return $this->error();
    }

    public function actionLogout()
    {
        if (Yii::$app->accessTokenSystem->disableAccessToken(Yii::$app->user->identity->access_token)) {
            return '';
        }

        return $this->error();
    }


    /**
     * Displays homepage.
     *
     * @return mixed
     */
    public function actionProfile()
    {
        return 'profile';
    }

}
