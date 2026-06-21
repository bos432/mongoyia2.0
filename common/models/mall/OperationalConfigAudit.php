<?php

namespace common\models\mall;

use common\models\BaseModel;
use Yii;

class OperationalConfigAudit extends BaseModel
{
    public static function tableName()
    {
        return '{{%mall_operational_config_audit}}';
    }

    public function rules()
    {
        return [
            [['config_id', 'store_id', 'operator_user_id', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['old_redacted', 'new_redacted'], 'string'],
            [['category', 'provider', 'action'], 'string', 'max' => 32],
            [['code', 'request_ip'], 'string', 'max' => 64],
            [['remark'], 'string', 'max' => 255],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'config_id' => Yii::t('app', 'Config ID'),
            'category' => Yii::t('app', 'Category'),
            'provider' => Yii::t('app', 'Provider'),
            'code' => Yii::t('app', 'Code'),
            'action' => Yii::t('app', 'Action'),
            'old_redacted' => Yii::t('app', 'Old Value'),
            'new_redacted' => Yii::t('app', 'New Value'),
            'operator_user_id' => Yii::t('app', 'Operator'),
            'request_ip' => Yii::t('app', 'Request IP'),
        ]);
    }
}
