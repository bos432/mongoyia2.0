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

    public function actionApprove($id)
    {
        return $this->moderateReview((int)$id, Review::MODERATION_APPROVED, Review::STATUS_ACTIVE, Yii::t('app', 'Approved'));
    }

    public function actionReject($id)
    {
        return $this->moderateReview((int)$id, Review::MODERATION_REJECTED, Review::STATUS_INACTIVE, Yii::t('app', 'Rejected'));
    }

    public function actionMarkViolation($id)
    {
        return $this->moderateReview((int)$id, Review::MODERATION_VIOLATION, Review::STATUS_INACTIVE, Yii::t('app', 'Violation'));
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
