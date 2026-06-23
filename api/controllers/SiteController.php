<?php
namespace api\controllers;

use api\models\forms\RefreshForm;
use api\models\LoginForm;
use api\models\User;
use common\services\mall\AccountSecurityCodeService;
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

    public $optionalAuth = ['error', 'index', 'login', 'refresh', 'logout', 'visit', 'security-code-request', 'security-code-login'];

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

    public function actionSecurityCodeRequest()
    {
        $result = (new AccountSecurityCodeService())->requestCode(
            $this->securityCodeChannel(),
            $this->securityCodeTarget()
        );

        if (empty($result['success'])) {
            Yii::$app->response->statusCode = $this->securityCodeStatusCode($result);
        }

        return $result;
    }

    public function actionSecurityCodeLogin()
    {
        $result = (new AccountSecurityCodeService())->loginWithCode(
            $this->securityCodeChannel(),
            $this->securityCodeTarget(),
            trim((string)Yii::$app->request->post('code', Yii::$app->request->get('code', '')))
        );
        if (empty($result['success'])) {
            Yii::$app->response->statusCode = $this->securityCodeStatusCode($result);
            return $result;
        }

        $user = User::findOne((int)($result['user_id'] ?? 0));
        if (!$user) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'code' => 'SECURITY_CODE_USER_UNAVAILABLE',
                'message' => 'Target user is unavailable.',
            ];
        }

        return Yii::$app->accessTokenSystem->getAccessToken($user);
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

    private function securityCodeChannel(): string
    {
        $channel = Yii::$app->request->post('channel', Yii::$app->request->get('channel', 'email'));
        $channel = strtolower(trim((string)$channel));
        return $channel === 'mobile' ? 'mobile' : 'email';
    }

    private function securityCodeTarget(): string
    {
        return trim((string)Yii::$app->request->post('target', Yii::$app->request->get('target', '')));
    }

    private function securityCodeStatusCode(array $result): int
    {
        $code = (string)($result['code'] ?? '');
        if ($code === 'SECURITY_CODE_LOGIN_DISABLED') {
            return 404;
        }
        if ($code === 'SECURITY_CODE_TABLE_MISSING' ||
            $code === 'SECURITY_CODE_DELIVERY_FAILED' ||
            $code === 'SECURITY_CODE_MOBILE_RESERVED') {
            return 503;
        }
        if ($code === 'SECURITY_CODE_LOCKED') {
            return 423;
        }
        if ($code === 'SECURITY_CODE_EXPIRED') {
            return 410;
        }

        return 400;
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
