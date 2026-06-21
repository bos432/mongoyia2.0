<?php

namespace common\models\mall;

use common\models\BaseModel;
use Yii;

class OperationalConfigCheck extends BaseModel
{
    public static function tableName()
    {
        return '{{%mall_operational_config_check}}';
    }

    public function rules()
    {
        return [
            [['store_id', 'checked_at', 'operator_user_id', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['details_json'], 'string'],
            [['category', 'provider'], 'string', 'max' => 32],
            [['check_key'], 'string', 'max' => 64],
            [['result'], 'string', 'max' => 16],
            [['message', 'remark'], 'string', 'max' => 255],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'category' => Yii::t('app', 'Category'),
            'provider' => Yii::t('app', 'Provider'),
            'check_key' => Yii::t('app', 'Check Key'),
            'result' => Yii::t('app', 'Result'),
            'message' => Yii::t('app', 'Message'),
            'details_json' => Yii::t('app', 'Details'),
            'checked_at' => Yii::t('app', 'Checked At'),
            'operator_user_id' => Yii::t('app', 'Operator'),
        ]);
    }
}
