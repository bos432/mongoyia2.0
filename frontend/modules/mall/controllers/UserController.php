<?php

namespace frontend\modules\mall\controllers;

use common\models\forms\ChangePasswordForm;
use common\models\mall\Address;
use common\models\mall\Cart;
use common\models\mall\Coupon;
use common\models\mall\CouponType;
use common\models\mall\Favorite;
use common\models\mall\Order;
use common\models\mall\Product;
use common\models\mall\ProductSku;
use common\models\mall\ProductVisit;
use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionInviteService;
use common\services\mall\DistributionMaterialPhase15Service;
use common\services\mall\DistributionProfileService;
use common\services\mall\DistributionSupportContentService;
use common\services\mall\DistributionWithdrawService;
use common\models\ModelSearch;
use Yii;
use yii\db\Query;
use yii\helpers\Url;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;

/**
 * Class UserController
 * @package frontend\modules\mall\controllers
 * @author funson86 <funson86@gmail.com>
 */
class UserController extends BaseController
{
    public const DISTRIBUTION_FRONTEND_POST_GUARD_VERSION = 'MONGOYIA_DISTRIBUTION_FRONTEND_POST_VERB_GUARD_V1';
    public const USER_ENTRY_INTERNAL_FORWARD_VERSION = 'MONGOYIA_USER_ENTRY_INTERNAL_FORWARD_V1';
    public const USER_COUPON_SAFE_FALLBACK_VERSION = 'MONGOYIA_USER_COUPON_SAFE_FALLBACK_V1';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@']
                    ]
                ]
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'getcode' => ['POST'],
                    'distribution-profile' => ['POST'],
                    'distribution-withdraw' => ['POST'],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        return Yii::$app->runAction('/mall/user/order');
    }

    public function actionGetcode()
    {
        // MONGOYIA_USER_COUPON_CLAIM_POST_GUARD_V1: coupon claims must not be triggered by GET links.
        $cid = (int)Yii::$app->request->post('cid', 0);
        if (Yii::$app->user->isGuest) {
            return $this->redirect('/mall/default/login');
        }
        if ($cid <= 0) {
            return $this->redirectError(Yii::t('app', 'Invalid Param'), ['/mall/user/coupon']);
        }

        $exists = (new Query())
            ->from('{{%mall_user_coupon}}')
            ->where(['uid' => Yii::$app->user->id, 'cid' => $cid])
            ->exists(Yii::$app->db);
        if (!$exists) {
            Yii::$app->db->createCommand()->insert('{{%mall_user_coupon}}', [
                'uid' => Yii::$app->user->id,
                'cid' => $cid,
            ])->execute();
        }

        return $this->redirect(['/mall/user/coupon']);
    }

    public function actionOrder()
    {
        $userId = Yii::$app->user->id;

        $searchModel = new ModelSearch([
            'model' => Order::class,
            'scenario' => 'default',
        ]);

        $params = Yii::$app->request->queryParams;
        $params['ModelSearch']['store_id'] = $this->getStoreId();
        $params['ModelSearch']['user_id'] = Yii::$app->user->id;
        $params['ModelSearch']['status'] = '>' . Order::STATUS_DELETED;
        $params['ModelSearch']['parent_id'] = 0;

        $dataProvider = $searchModel->search($params);

        return $this->render($this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
        ]);
    }

    public function actionCoupon()
    {
        try {
            $couponIds = (new Query())
                ->select('cid')
                ->from('{{%mall_user_coupon}}')
                ->where(['uid' => Yii::$app->user->id])
                ->column(Yii::$app->db);
        } catch (\Throwable $e) {
            Yii::warning('Skipped user coupon lookup: ' . $e->getMessage(), __METHOD__);
            $couponIds = [];
        }

        $couponIds = array_values(array_unique(array_filter(array_map('intval', $couponIds))));
        $query = CouponType::find()->alias('ct')->where(['ct.status' => CouponType::STATUS_ACTIVE]);
        if ($couponIds) {
            $query->andWhere(['ct.id' => $couponIds]);
        } else {
            $query->andWhere('0=1');
        }
        $query->orderBy(['ct.id' => SORT_DESC]);

        $dataProvider = new \yii\data\ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 10],
        ]);
        $searchModel = new ModelSearch([
            'model' => CouponType::class,
            'scenario' => 'default',
        ]);

        return $this->render($this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
        ]);
    }

    public function actionHistory()
    {
        $query = ProductVisit::class;

        $searchModel = new ModelSearch([
            'model' => $query,
            'scenario' => 'default',
            'defaultOrder' => ['id' => SORT_DESC],
            'groupBy'=>['pid'],
            'relations' => ['product' => ['status']],
            'pageSize' => 3
        ]);

        $params = Yii::$app->request->queryParams;
//        var_dump($params);exit();
//        $params['ModelSearch']['store_id'] = $this->getStoreId();
//        var_dump(Yii::$app->user->id);exit();
        $params['ModelSearch']['uid'] = Yii::$app->user->id;;
        $params['ModelSearch']['product.status'] = 1;
        $dataProvider = $searchModel->search($params);
//        echo '<pre/>';
//        var_dump($searchModel);exit();
//        var_dump($dataProvider);exit();
//        echo 111;exit();
        return $this->render($this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
        ]);
    }

    public function actionFavorite()
    {
        $searchModel = new ModelSearch([
            'model' => Favorite::class,
            'scenario' => 'default',
            'groupBy'=>['product_id'],
            'relations' => ['product' => ['status']],
            'pageSize' => 3
        ]);

        $params = Yii::$app->request->queryParams;
        $params['ModelSearch']['store_id'] = $this->getStoreId();
        $params['ModelSearch']['user_id'] = Yii::$app->user->id;
        $params['ModelSearch']['product.status'] = 1;
        $dataProvider = $searchModel->search($params);
        $dataProvider->query->with('product');

        return $this->render($this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
        ]);
    }

    public function actionDistribution()
    {
        $userId = (int)Yii::$app->user->id;
        $profileService = new DistributionProfileService();
        $inviteService = new DistributionInviteService();
        $withdrawService = new DistributionWithdrawService();
        $materialService = new DistributionMaterialPhase15Service();
        $supportService = new DistributionSupportContentService();
        $supportLanguage = $supportService->normalizeLanguage((string)Yii::$app->request->get('language', Yii::$app->language));
        $statusLabels = [
            DistributionCommissionService::COMMISSION_STATUS_PENDING => Yii::t('app', 'Pending'),
            DistributionCommissionService::COMMISSION_STATUS_APPROVED => Yii::t('app', 'Approved'),
            DistributionCommissionService::COMMISSION_STATUS_REJECTED => Yii::t('app', 'Rejected'),
            DistributionCommissionService::COMMISSION_STATUS_WITHDRAWN => Yii::t('app', 'Withdrawn'),
        ];
        $withdrawStatusLabels = [
            DistributionWithdrawService::WITHDRAW_STATUS_PENDING => Yii::t('app', 'Pending'),
            DistributionWithdrawService::WITHDRAW_STATUS_APPROVED => Yii::t('app', 'Approved'),
            DistributionWithdrawService::WITHDRAW_STATUS_REJECTED => Yii::t('app', 'Rejected'),
        ];

        $summary = (new Query())
            ->select([
                'commission_status',
                'rows' => 'COUNT(*)',
                'order_amount' => 'SUM(order_amount)',
                'commission_amount' => 'SUM(commission_amount)',
            ])
            ->from('{{%mall_distribution_commission}}')
            ->where(['distributor_user_id' => $userId, 'status' => 1])
            ->groupBy(['commission_status'])
            ->orderBy(['commission_status' => SORT_ASC])
            ->all();

        $commissions = (new Query())
            ->from('{{%mall_distribution_commission}}')
            ->where(['distributor_user_id' => $userId, 'status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(50)
            ->all();

        $promotionLink = Url::to(['/mall/default/index', 'fxid' => $userId], true);

        return $this->render($this->action->id, [
            'promotionLink' => $promotionLink,
            'summary' => $summary,
            'commissions' => $commissions,
            'statusLabels' => $statusLabels,
            'withdrawSummary' => $withdrawService->summary($userId),
            'withdrawRows' => $this->distributionWithdrawRows($userId),
            'withdrawStatusLabels' => $withdrawStatusLabels,
            'profile' => $profileService->profile($userId),
            'materials' => $materialService->visibleMaterials($supportLanguage, 20),
            'materialLanguageLabels' => $supportService->languageLabels(),
            'supportLanguage' => $supportLanguage,
            'supportLanguages' => $supportService->languageLabels(),
            'supportContents' => $supportService->visibleForDistributor($supportLanguage, 50),
            'supportTypeLabels' => $supportService->typeLabels(),
            'riskRows' => $profileService->risks($userId, 10),
            'profileStatusLabels' => $this->distributionProfileStatusLabels(),
            'inviteSummary' => $inviteService->summary($userId),
            'inviteRewardStatusLabels' => $this->distributionInviteRewardStatusLabels(),
        ]);
    }

    public function actionDistributionProfile()
    {
        $userId = (int)Yii::$app->user->id;
        $result = (new DistributionProfileService())->saveProfile($userId, Yii::$app->request->post(), true);
        if ($result['skippedReason'] !== '') {
            return $this->redirectError($result['skippedReason'], ['/mall/user/distribution']);
        }

        return $this->redirectSuccess(['/mall/user/distribution']);
    }

    public function actionDistributionMaterialTrack()
    {
        $userId = (int)Yii::$app->user->id;
        $id = (int)Yii::$app->request->get('id', 0);
        $actionType = (string)Yii::$app->request->get('action_type', DistributionMaterialPhase15Service::ACTION_COPY);
        $result = (new DistributionMaterialPhase15Service())->recordAction(
            $id,
            $userId,
            $actionType,
            'frontend-distributor-center',
            (string)Yii::$app->request->getUserAgent(),
            true
        );
        if ((int)$result['created'] <= 0) {
            return $this->redirectError($result['skippedReason'] ?: Yii::t('app', 'No eligible records'), ['/mall/user/distribution']);
        }

        $url = (string)$result['redirectUrl'];
        if ($url !== '' && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || strpos($url, '/') === 0)) {
            return $this->redirect($url);
        }

        return $this->redirectSuccess(['/mall/user/distribution']);
    }

    public function actionDistributionWithdraw()
    {
        $userId = (int)Yii::$app->user->id;
        $result = (new DistributionWithdrawService())->requestWithdraw($userId, [], true, 'frontend distribution withdraw request');
        if ((int)$result['created'] <= 0) {
            return $this->redirectError($result['skippedReason'] ?: Yii::t('app', 'No eligible records'), ['/mall/user/distribution']);
        }

        return $this->redirectSuccess(['/mall/user/distribution']);
    }

    private function distributionWithdrawRows(int $userId): array
    {
        return (new Query())
            ->from('{{%mall_distribution_withdraw}}')
            ->where(['distributor_user_id' => $userId, 'status' => 1])
            ->orderBy(['id' => SORT_DESC])
            ->limit(20)
            ->all();
    }

    private function distributionProfileStatusLabels(): array
    {
        return [
            DistributionProfileService::PROFILE_STATUS_PENDING => Yii::t('app', 'Pending'),
            DistributionProfileService::PROFILE_STATUS_APPROVED => Yii::t('app', 'Approved'),
            DistributionProfileService::PROFILE_STATUS_REJECTED => Yii::t('app', 'Rejected'),
        ];
    }

    private function distributionInviteRewardStatusLabels(): array
    {
        return [
            DistributionInviteService::REWARD_STATUS_PENDING => Yii::t('app', 'Pending'),
            DistributionInviteService::REWARD_STATUS_APPROVED => Yii::t('app', 'Approved'),
            DistributionInviteService::REWARD_STATUS_REJECTED => Yii::t('app', 'Rejected'),
            DistributionInviteService::REWARD_STATUS_WITHDRAWN => Yii::t('app', 'Withdrawn'),
        ];
    }

    public function actionAddress()
    {
        $searchModel = new ModelSearch([
            'model' => Address::class,
            'scenario' => 'default',
        ]);

        $params = Yii::$app->request->queryParams;
        $params['ModelSearch']['store_id'] = $this->getStoreId();
        $params['ModelSearch']['user_id'] = Yii::$app->user->id;
        $params['ModelSearch']['status'] = '>' . Address::STATUS_DELETED;
        $dataProvider = $searchModel->search($params);

        return $this->render($this->action->id, [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
        ]);
    }

    public function actionSetting()
    {
        $user = Yii::$app->user->identity;
        $model = new ChangePasswordForm();

        if (Yii::$app->request->isPost) {
            if (Yii::$app->request->post()['User']) {
                $user->load(Yii::$app->request->post());
                if (!$user->save()) {
                    Yii::$app->logSystem->db($user->errors);
                    $this->flashError('error', Yii::t('app', 'Operation Failed'));
                } else {
                    $this->flashSuccess(Yii::t('app', 'Operate Successfully'));
                }
            } else {
                if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->changePassword()) {
                    Yii::$app->user->logout();
                    return $this->redirectSuccess(['/mall/default/login']);
                }
            }
        }

        return $this->render($this->action->id, [
            'user' => $user,
            'model' => $model,
        ]);
    }

}
