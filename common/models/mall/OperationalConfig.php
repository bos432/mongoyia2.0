<?php

namespace common\models\mall;

use common\models\BaseModel;
use Yii;

class OperationalConfig extends BaseModel
{
    public static function tableName()
    {
        return '{{%mall_operational_config}}';
    }

    public function rules()
    {
        return [
            [['store_id', 'is_enabled', 'is_sensitive', 'last_checked_at', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['value_plain', 'value_ciphertext', 'metadata_json'], 'string'],
            [['category', 'code'], 'required'],
            [['category', 'provider'], 'string', 'max' => 32],
            [['code'], 'string', 'max' => 64],
            [['label'], 'string', 'max' => 128],
            [['environment', 'last_check_status'], 'string', 'max' => 16],
            [['value_hash'], 'string', 'max' => 64],
            [['last_check_message', 'remark'], 'string', 'max' => 255],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'store_id' => Yii::t('app', 'Store ID'),
            'category' => Yii::t('app', 'Category'),
            'provider' => Yii::t('app', 'Provider'),
            'code' => Yii::t('app', 'Code'),
            'label' => Yii::t('app', 'Name'),
            'environment' => Yii::t('app', 'Environment'),
            'is_enabled' => Yii::t('app', 'Enabled'),
            'is_sensitive' => Yii::t('app', 'Sensitive'),
            'value_plain' => Yii::t('app', 'Value'),
            'value_ciphertext' => Yii::t('app', 'Encrypted Value'),
            'value_hash' => Yii::t('app', 'Value Hash'),
            'metadata_json' => Yii::t('app', 'Metadata'),
            'last_checked_at' => Yii::t('app', 'Last Checked At'),
            'last_check_status' => Yii::t('app', 'Last Check Status'),
            'last_check_message' => Yii::t('app', 'Last Check Message'),
        ]);
    }
}
