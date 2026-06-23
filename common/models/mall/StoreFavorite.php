<?php

namespace common\models\mall;

use Yii;

/**
 * This is the model class for table "{{%mall_store_favorite}}".
 *
 * @property int $id
 * @property int $store_id 商家
 * @property int $user_id 用户
 * @property string $name 店铺名称
 * @property int $sort 排序
 * @property int $status 状态
 * @property int $created_at 创建时间
 * @property int $updated_at 更新时间
 * @property int $created_by 创建用户
 * @property int $updated_by 更新用户
 */
class StoreFavorite extends StoreFavoriteBase
{
    public static function tableName()
    {
        return '{{%mall_store_favorite}}';
    }

    public function rules()
    {
        return array_merge(parent::rules(), [
            [['store_id', 'user_id', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['store_id', 'user_id'], 'required'],
            [['name'], 'string', 'max' => 255],
        ]);
    }

    public function attributeLabels()
    {
        if (Yii::$app->language == Yii::$app->params['sqlCommentLanguage']) {
            return array_merge(parent::attributeLabels(), [
                'id' => Yii::t('app', 'ID'),
                'store_id' => '商家',
                'user_id' => '用户',
                'name' => '店铺名称',
                'sort' => '排序',
                'status' => '状态',
                'created_at' => '创建时间',
                'updated_at' => '更新时间',
                'created_by' => '创建用户',
                'updated_by' => '更新用户',
            ]);
        }

        return array_merge(parent::attributeLabels(), [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Store ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'name' => Yii::t('app', 'Store Favorite Name'),
            'sort' => Yii::t('app', 'Sort'),
            'status' => Yii::t('app', 'Status'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_by' => Yii::t('app', 'Created By'),
            'updated_by' => Yii::t('app', 'Updated By'),
        ]);
    }
}
