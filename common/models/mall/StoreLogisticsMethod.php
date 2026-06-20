<?php

namespace common\models\mall;

use common\models\BaseModel;
use common\models\Store;
use Yii;

class StoreLogisticsMethod extends BaseModel
{
    const SELECTION_ENABLED = 'enabled';
    const SELECTION_DISABLED = 'disabled';

    public static function tableName()
    {
        return '{{%store_logistics_method}}';
    }

    public function rules()
    {
        return [
            [['store_id', 'logistics_method_id'], 'required'],
            [['store_id', 'logistics_method_id', 'selected_at', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['selection_status'], 'string', 'max' => 32],
            [['remark'], 'string', 'max' => 255],
            [['store_id', 'logistics_method_id'], 'unique', 'targetAttribute' => ['store_id', 'logistics_method_id']],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Store ID'),
            'logistics_method_id' => '物流方式',
            'selection_status' => '选择状态',
            'selected_at' => '选择时间',
            'remark' => '备注',
            'status' => Yii::t('app', 'Status'),
        ]);
    }

    public static function getSelectionStatusLabels($id = null)
    {
        $labels = [
            self::SELECTION_ENABLED => '已选择',
            self::SELECTION_DISABLED => '未选择',
        ];

        return $id === null ? $labels : ($labels[$id] ?? $id);
    }

    public function getStore()
    {
        return $this->hasOne(Store::class, ['id' => 'store_id']);
    }

    public function getLogisticsMethod()
    {
        return $this->hasOne(LogisticsMethod::class, ['id' => 'logistics_method_id']);
    }
}
