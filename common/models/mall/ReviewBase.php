<?php

namespace common\models\mall;

use common\models\BaseModel;
use common\models\Store;
use common\models\User;
use Yii;

/**
 * This is the model base class for table "{{%mall_review}}" to add your code.
 *
 * @property User $user
 * @property Product $product
 * @property Store $store
 */
class ReviewBase extends BaseModel
{
    public const MODERATION_PENDING = 'pending';
    public const MODERATION_APPROVED = 'approved';
    public const MODERATION_REJECTED = 'rejected';
    public const MODERATION_VIOLATION = 'violation';

    /**
     * @return array|array[]
     */
    public function rules()
    {
        return [
            [['id'], 'safe'],
            [['store_id'], 'exist', 'skipOnError' => true, 'targetClass' => Store::className(), 'targetAttribute' => ['store_id' => 'id']],
        ];
    }

    /** add function getXxxLabels here, detail in BaseModel **/
    public static function getModerationStatusLabels($id = null, $all = false, $flip = false)
    {
        $data = [
            self::MODERATION_PENDING => Yii::t('app', 'Pending Review'),
            self::MODERATION_APPROVED => Yii::t('app', 'Approved'),
            self::MODERATION_REJECTED => Yii::t('app', 'Rejected'),
            self::MODERATION_VIOLATION => Yii::t('app', 'Violation'),
        ];

        $all && $data += [];
        $flip && $data = array_flip($data);

        return !is_null($id) ? ($data[$id] ?? $id) : $data;
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Store ID'),
            'parent_id' => Yii::t('app', 'Parent ID'),
            'product_id' => Yii::t('app', 'Product ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'name' => Yii::t('app', 'Name'),
            'order_id' => Yii::t('app', 'Order ID'),
            'star' => Yii::t('app', 'Star'),
            'content' => Yii::t('app', 'Content'),
            'point' => Yii::t('app', 'Point'),
            'like' => Yii::t('app', 'Like'),
            'type' => Yii::t('app', 'Type'),
            'sort' => Yii::t('app', 'Sort'),
            'status' => Yii::t('app', 'Status'),
            'moderation_status' => Yii::t('app', 'Moderation Status'),
            'moderation_remark' => Yii::t('app', 'Moderation Remark'),
            'moderated_at' => Yii::t('app', 'Moderated At'),
            'moderated_by' => Yii::t('app', 'Moderated By'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_by' => Yii::t('app', 'Created By'),
            'updated_by' => Yii::t('app', 'Updated By'),
        ]);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getProduct()
    {
        return $this->hasOne(Product::className(), ['id' => 'product_id']);
    }
}
