<?php

namespace common\models\mall;

use common\models\BaseModel;
use common\models\Store;
use Yii;

class StoreCategoryAuth extends BaseModel
{
    const AUDIT_APPROVED = 'approved';
    const AUDIT_REJECTED = 'rejected';

    public static function tableName()
    {
        return '{{%store_category_auth}}';
    }

    public function rules()
    {
        return [
            [['store_id', 'category_id'], 'required'],
            [['store_id', 'category_id', 'source_application_id', 'authorized_at', 'expires_at', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['audit_status'], 'string', 'max' => 32],
            [['audit_remark'], 'string', 'max' => 255],
            [['store_id', 'category_id'], 'unique', 'targetAttribute' => ['store_id', 'category_id']],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Store ID'),
            'category_id' => Yii::t('app', 'Category ID'),
            'source_application_id' => '来源申请',
            'audit_status' => '授权状态',
            'audit_remark' => '授权备注',
            'authorized_at' => '授权时间',
            'expires_at' => '到期时间',
            'status' => Yii::t('app', 'Status'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
        ]);
    }

    public static function getAuditStatusLabels($id = null)
    {
        $labels = [
            self::AUDIT_APPROVED => '已授权',
            self::AUDIT_REJECTED => '已驳回',
        ];

        return $id === null ? $labels : ($labels[$id] ?? $id);
    }

    public function getStore()
    {
        return $this->hasOne(Store::class, ['id' => 'store_id']);
    }

    public function getCategory()
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    public function getSourceApplication()
    {
        return $this->hasOne(MerchantApplication::class, ['id' => 'source_application_id']);
    }

    public function beforeSave($insert)
    {
        if ($insert && !(int)$this->authorized_at && $this->audit_status === self::AUDIT_APPROVED) {
            $this->authorized_at = time();
        }

        return parent::beforeSave($insert);
    }
}
