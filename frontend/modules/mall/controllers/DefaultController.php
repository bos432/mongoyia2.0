<?php

namespace frontend\modules\mall\controllers;

use common\helpers\ArrayHelper;
use common\helpers\MallPlatformHelper;
use common\models\forms\base\FeedbackForm;
use common\models\forms\LoginEmailForm;
use common\models\mall\Cart;
use common\models\mall\Product;
use common\models\ModelSearch;
use common\models\Store;
use frontend\models\ContactForm;
use frontend\models\PasswordResetRequestForm;
use frontend\models\ResendVerificationEmailForm;
use frontend\models\ResetPasswordForm;
use frontend\models\SignupEmailForm;
use frontend\models\VerifyEmailForm;
use InvalidArgumentException;
use Yii;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;

/**
 * Default controller for the `mall` module
 */
class DefaultController extends BaseController
{
    public const FRONTEND_LOGOUT_POST_GUARD_VERSION = 'MONGOYIA_FRONTEND_LOGOUT_POST_GUARD_V1';
    public const FRONTEND_LOGIN_RETURN_URL_GUARD_VERSION = 'MONGOYIA_FRONTEND_LOGIN_RETURN_URL_GUARD_V1';

    public $likeAttributes = ['name'];

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'logout' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Renders the index view for the module
     * @return string
     */
    public function actionIndex()
    {
        /*if ($this->store->parent_id == 0) {
            return $this->actionIndexPlatform();
        }*/

        $productsNew = MallPlatformHelper::productQuery()->andWhere(['type'=>[2,3,6,7]])->orderBy(['created_at' => SORT_DESC])->limit(4)->all();
        $productsHot = MallPlatformHelper::productQuery()->andWhere(['type'=>[1,3,5,7]])->orderBy(['sales' => SORT_DESC])->limit(4)->all();
        $productsZk = MallPlatformHelper::productQuery()->andWhere(['type'=>[4,5,6,7]])->orderBy(['sales' => SORT_DESC])->limit(4)->all();
//        echo '<pre/>';
//        foreach ($productsNew as $v){
//            echo $v['type'];
//        }
//        exit();
        return $this->render($this->action->id, [
            'productsNew' => $productsNew,
            'productsHot' => $productsHot,
            'productsZk' => $productsZk,
        ]);
    }

    public function actionIndexnew()
    {
        /*if ($this->store->parent_id == 0) {
            return $this->actionIndexPlatform();
        }*/

        $productsNew = MallPlatformHelper::productQuery()->orderBy(['created_at' => SORT_DESC])->limit(4)->all();
        $productsHot = MallPlatformHelper::productQuery()->orderBy(['sales' => SORT_DESC])->limit(4)->all();
        return $this->render($this->action->id, [
            'productsNew' => $productsNew,
            'productsHot' => $productsHot,
        ]);
    }

    /**
     * 支持平台类型  www.funmall.com/mall-yongchang
     * @return string
     * @throws \yii\web\NotFoundHttpException
     */
    public function actionIndexPlatform()
    {
        $this->layout = 'main-platform';
        $searchModel = new ModelSearch([
            'model' => Store::class,
            'scenario' => 'default',
            'likeAttributes' => $this->likeAttributes,
            'defaultOrder' => [
                'status' => SORT_ASC,
                'sort' => SORT_ASC,
                'id' => SORT_DESC,
            ],
            'pageSize' => Yii::$app->request->get('page_size', $this->pageSize),
        ]);

        // 管理员级别才能查看所有数据，其他只能查看本store数据
        $params = Yii::$app->request->queryParams;
        $params['ModelSearch']['parent_id'] = $this->getStoreId();
        $params['ModelSearch']['status'] = Store::STATUS_ACTIVE;

        $listChildren = [];
        $dataProvider = $searchModel->search($params);

        // 排序
        $sort = $dataProvider->getSort();
        $sort->attributes['id']['asc'] = ['status' => SORT_ASC, 'id' => SORT_DESC];
        $sort->attributes['like']['asc'] = ['status' => SORT_ASC, 'like' => SORT_DESC, 'id' => SORT_DESC];
        $sort->attributes['click']['asc'] = ['status' => SORT_ASC, 'click' => SORT_DESC, 'id' => SORT_DESC];

        return $this->render($this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
            'listChildren' => $listChildren,
        ]);
    }

    public function actionSetCurrency()
    {
        $currency = Yii::$app->request->get('currency', Yii::$app->settingSystem->getValue('mall_currency_default'));
        Yii::$app->session->set('currentCurrency', $currency);

        return $this->goBack();
    }

    public function actionSearch()
    {
        $keyword = trim((string)Yii::$app->request->get('keyword', ''));
        return $this->redirect(['/mall/category/view', 'keyword' => $keyword]);
    }

    /**
     * Logs in a user.
     *
     * @return mixed
     */
    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['/mall/user/order']);
        }
        $oldSessionId = Yii::$app->session->id;

        $model = new LoginEmailForm();
//        $model->checkCaptchaRequired();
//        echo '<pre/>';
//        var_dump($model->login());
//        exit();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            $this->afterLogin($oldSessionId);
            $returnUrl = $this->safeLoginReturnUrl((string)Yii::$app->request->get('returnUrl', ''));
            if ($returnUrl !== '') {
                return $this->redirect($returnUrl);
            }
            return $this->goBack();
        } else {
            $model->password = '';

            return $this->render($this->action->id, [
                'model' => $model,
            ]);
        }
    }

    protected function afterLogin($oldSessionId)
    {
        //保持购物车数据还在
        Cart::updateAll(['user_id' => Yii::$app->user->id, 'session_id' => Yii::$app->session->id], ['store_id' => $this->getStoreId(), 'session_id' => $oldSessionId]);
        //合并之前登录时选入购物车的
        Cart::updateAll(['session_id' => Yii::$app->session->id], ['store_id' => $this->getStoreId(), 'user_id' => Yii::$app->user->id]);

        // 合并重复的
        /** @var Cart[] $models */
        $models = Cart::find()->where(['store_id' => $this->getStoreId(), 'user_id' => Yii::$app->user->id])->all();
        /** @var Cart[] $map */
        $map = [];
        foreach ($models as $model) {
            $key = $model->product_id . '-' . $model->product_attribute_value;
            if (isset($map[$key])) {
                $exist = $map[$key];
                $exist->number += $model->number;
                $exist->save();
                $model->delete();
            } else {
                $map[$key] = $model;
            }
        }
        return true;
    }

    private function safeLoginReturnUrl(string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '' || strpos($returnUrl, '/') !== 0 || strpos($returnUrl, '//') === 0) {
            return '';
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnUrl) || preg_match('/[\r\n]/', $returnUrl)) {
            return '';
        }

        return $returnUrl;
    }

    /**
     * Logs in a user.
     *
     * @return mixed
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }

    /**
     * Signs user up.
     *
     * @return mixed
     */
    public function actionSignup()
    {
        $model = new SignupEmailForm();
        if ($model->load(Yii::$app->request->post()) && $model->signup()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Thank you for registration. Please check your inbox for verification email.'));
            return $this->goHome();
        }

        return $this->render('signup', [
            'model' => $model,
        ]);
    }

    /**
     * Requests password reset.
     *
     * @return mixed
     */
    public function actionRequestPasswordReset()
    {
        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->setFlash('success', Yii::t('app', 'Check your email for further instructions.'));

                return $this->goHome();
            } else {
                Yii::$app->session->setFlash('error', 'Sorry, we are unable to reset password for the provided email address.');
            }
        }

        return $this->render('requestPasswordResetToken', [
            'model' => $model,
        ]);
    }

    /**
     * Resets password.
     *
     * @return mixed
     * @throws BadRequestHttpException
     */
    public function actionResetPassword()
    {
        $token = Yii::$app->request->get('token');
        if (!$token) {
            return $this->goBack();
        }

        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'New password saved.'));

            return $this->goHome();
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    /**
     * Verify email address
     *
     * @throws BadRequestHttpException
     * @return yii\web\Response
     */
    public function actionVerifyEmail()
    {
        $token = Yii::$app->request->get('token');
        if (!$token) {
            return $this->goBack();
        }

        try {
            $model = new VerifyEmailForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
        if ($user = $model->verifyEmail()) {
            if (Yii::$app->user->login($user)) {
                Yii::$app->session->setFlash('success', Yii::t('app', 'Your email has been confirmed!'));
                return $this->goHome();
            }
        }

        Yii::$app->session->setFlash('error', Yii::t('app', 'Sorry, we are unable to verify your account with provided token.'));
        return $this->goHome();
    }

    /**
     * Resend verification email
     *
     * @return mixed
     */
    public function actionResendVerificationEmail()
    {
        $model = new ResendVerificationEmailForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->setFlash('success', Yii::t('app', 'Check your email for further instructions.'));
                return $this->goHome();
            }
            Yii::$app->session->setFlash('error', Yii::t('app', 'Sorry, we are unable to resend verification email for the provided email address.'));
        }

        return $this->render('resendVerificationEmail', [
            'model' => $model
        ]);
    }

    public function actionAbout()
    {
        $about = '111';

        return $this->render($this->action->id, [
            'about' => $about,
        ]);
    }

    public function actionHelp()
    {
        $about = '222';

        return $this->render($this->action->id, [
            'about' => $about,
        ]);
    }

    public function actionFaq()
    {
        $about = '333';

        return $this->render($this->action->id, [
            'about' => $about,
        ]);
    }

    public function actionFeedback()
    {
        $model = new FeedbackForm();
        $model->checkCaptchaRequired();

        if ($model->load(Yii::$app->request->post()) && $model->create()) {
            $this->flashSuccess(Yii::t('app', 'Operate Successfully'));
        }

        return $this->render($this->action->id, [
            'model' => $model,
        ]);
    }

    public function actionContact()
    {
        $model = new ContactForm();
        if (Yii::$app->request->isPost) {
            $model->load(Yii::$app->request->post());
            Yii::$app->session->setFlash('info', Yii::t('app', 'Contact form is read-only until SMTP provider evidence is configured.'));
            return $this->refresh();
        }

        return $this->render($this->action->id, [
            'model' => $model,
        ]);
    }
}
