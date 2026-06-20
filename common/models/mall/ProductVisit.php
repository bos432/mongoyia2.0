<?php

namespace common\models\mall;

use Yii;
use common\models\User;
use common\models\Store;

/**
 * This is the model class for table "{{%mall_favorite}}".
 *
 * @property int $id
 */
class ProductVisit extends ProductVisitBase
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%mall_product_visit}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return array_merge(parent::rules(), [
//            [['store_id', 'user_id', 'product_id', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
//            [['user_id'], 'required'],
//            [['name'], 'string', 'max' => 255],
        ]);
    }

    /**
     * {@inheritdoc}
     */
//    public function attributeLabels()
//    {
//        if (Yii::$app->language == Yii::$app->params['sqlCommentLanguage']) {
//            return array_merge(parent::attributeLabels(), [
//                'id' => Yii::t('app', 'ID'),
////                'store_id' => '商家',
////                'name' => '名称',
////                'user_id' => '用户',
////                'product_id' => '商品',
////                'type' => '类型',
////                'sort' => '排序',
////                'status' => '状态',
////                'created_at' => '创建时间',
////                'updated_at' => '更新时间',
////                'created_by' => '创建用户',
////                'updated_by' => '更新用户',
//            ]);
//        } else {
//            return array_merge(parent::attributeLabels(), [
//                'id' => Yii::t('app', 'ID'),
////                'store_id' => Yii::t('app', 'Store ID'),
////                'name' => Yii::t('app', 'Name'),
////                'user_id' => Yii::t('app', 'User ID'),
////                'product_id' => Yii::t('app', 'Product ID'),
////                'type' => Yii::t('app', 'Type'),
////                'sort' => Yii::t('app', 'Sort'),
////                'status' => Yii::t('app', 'Status'),
////                'created_at' => Yii::t('app', 'Created At'),
////                'updated_at' => Yii::t('app', 'Updated At'),
////                'created_by' => Yii::t('app', 'Created By'),
////                'updated_by' => Yii::t('app', 'Updated By'),
//            ]);
//        }
//    }
}
