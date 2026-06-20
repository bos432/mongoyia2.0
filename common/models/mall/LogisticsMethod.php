<?php

namespace common\models\mall;

use common\models\BaseModel;
use Yii;

class LogisticsMethod extends BaseModel
{
    public static function tableName()
    {
        return '{{%logistics_method}}';
    }

    public function rules()
    {
        return [
            [['name'], 'required'],
            [['base_fee', 'fee_per_kg', 'fee_per_volume'], 'number'],
            [['store_id', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['name', 'code', 'provider', 'tracking_url', 'remark'], 'string', 'max' => 255],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Store ID'),
            'name' => '物流方式',
            'code' => '代码',
            'provider' => '承运商',
            'base_fee' => '基础费用',
            'fee_per_kg' => '每公斤费用',
            'fee_per_volume' => '每体积费用',
            'tracking_url' => '查询链接',
            'remark' => '备注',
            'status' => Yii::t('app', 'Status'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
        ]);
    }
}
