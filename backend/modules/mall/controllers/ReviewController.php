<?php

namespace backend\modules\mall\controllers;

use Yii;
use common\models\mall\Review;
use common\models\ModelSearch;

/**
 * Review
 *
 * Class ReviewController
 * @package backend\modules\mall\controllers
 */
class ReviewController extends BaseController
{
    public const MODERATION_VERB_GUARD_VERSION = 'MONGOYIA_REVIEW_MODERATION_POST_VERB_GUARD_V1';
    public const MODERATION_ID_POST_GUARD_VERSION = 'MONGOYIA_REVIEW_MODERATION_ID_POST_GUARD_V1';

    /**
      * @var Review
      */
    public $modelClass = Review::class;

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

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs']['actions']['approve'] = ['post'];
        $behaviors['verbs']['actions']['reject'] = ['post'];
        $behaviors['verbs']['actions']['mark-violation'] = ['post'];

        return $behaviors;
    }

    public function actionApprove()
    {
        return $this->moderateReview((int)Yii::$app->request->post('id', 0), Review::MODERATION_APPROVED, Review::STATUS_ACTIVE, Yii::t('app', 'Approved'));
    }

    public function actionReject()
    {
        return $this->moderateReview((int)Yii::$app->request->post('id', 0), Review::MODERATION_REJECTED, Review::STATUS_INACTIVE, Yii::t('app', 'Rejected'));
    }

    public function actionMarkViolation()
    {
        return $this->moderateReview((int)Yii::$app->request->post('id', 0), Review::MODERATION_VIOLATION, Review::STATUS_INACTIVE, Yii::t('app', 'Violation'));
    }

    private function moderateReview(int $id, string $moderationStatus, int $status, string $defaultRemark)
    {
        $model = $this->findModel($id);
        if (!$model) {
            return $this->redirectError(Yii::t('app', 'Invalid id'));
        }

        $model->status = $status;
        if (method_exists($model, 'hasAttribute') && $model->hasAttribute('moderation_status')) {
            $model->moderation_status = $moderationStatus;
            $model->moderation_remark = trim((string)Yii::$app->request->post('remark', $defaultRemark));
            $model->moderated_at = time();
            $model->moderated_by = (int)Yii::$app->user->id;
        }
        $model->updated_at = time();
        $model->updated_by = (int)Yii::$app->user->id;

        if (!$model->save(false)) {
            return $this->redirectError($model);
        }

        $this->clearCache();
        return $this->redirectSuccess(null, Yii::t('app', 'Review moderation saved'));
    }
}
