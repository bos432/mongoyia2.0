<?php

namespace common\models\mall;

use common\models\BaseModel;
use common\models\Store;
use Yii;

class StoreCouponParticipation extends BaseModel
{
    const PARTICIPATION_JOINED = 'joined';
    const PARTICIPATION_LEFT = 'left';

    public static function tableName()
    {
        return '{{%store_coupon_participation}}';
    }

    public function rules()
    {
        return [
            [['store_id', 'coupon_type_id'], 'required'],
            [['store_id', 'coupon_type_id', 'joined_at', 'left_at', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['participation_status'], 'string', 'max' => 32],
            [['remark'], 'string', 'max' => 255],
            [['store_id', 'coupon_type_id'], 'unique', 'targetAttribute' => ['store_id', 'coupon_type_id']],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Store ID'),
            'coupon_type_id' => Yii::t('app', 'Coupon Type ID'),
            'participation_status' => '参与状态',
            'remark' => '备注',
            'joined_at' => '参与时间',
            'left_at' => '退出时间',
            'status' => Yii::t('app', 'Status'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
        ]);
    }

    public static function getParticipationStatusLabels($id = null)
    {
        $labels = [
            self::PARTICIPATION_JOINED => '已参与',
            self::PARTICIPATION_LEFT => '已退出',
        ];

        return $id === null ? $labels : ($labels[$id] ?? $id);
    }

    public function getStore()
    {
        return $this->hasOne(Store::class, ['id' => 'store_id']);
    }

    public function getCouponType()
    {
        return $this->hasOne(CouponType::class, ['id' => 'coupon_type_id']);
    }
}
