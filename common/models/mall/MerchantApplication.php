<?php

namespace common\models\mall;

use common\models\BaseModel;
use common\models\Store;
use common\models\User;
use Yii;

class MerchantApplication extends BaseModel
{
    const AUDIT_SUBMITTED = 'submitted';
    const AUDIT_APPROVED = 'approved';
    const AUDIT_REJECTED = 'rejected';

    public static function tableName()
    {
        return '{{%merchant_application}}';
    }

    public function rules()
    {
        return [
            [['store_id', 'user_id', 'submitted_at', 'reviewed_at', 'reviewer_id', 'type', 'sort', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['applicant_name', 'company_name'], 'required'],
            [['requested_category_ids'], 'safe'],
            [['applicant_name'], 'string', 'max' => 120],
            [['mobile'], 'string', 'max' => 64],
            [['email'], 'string', 'max' => 160],
            [['company_name'], 'string', 'max' => 180],
            [['business_license', 'audit_remark'], 'string', 'max' => 255],
            [['audit_status'], 'string', 'max' => 32],
        ];
    }

    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Store ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'applicant_name' => '申请人',
            'mobile' => '手机号',
            'email' => '邮箱',
            'company_name' => '公司/商家名称',
            'business_license' => '营业执照',
            'requested_category_ids' => '申请经营分类',
            'audit_status' => '审核状态',
            'audit_remark' => '审核备注',
            'submitted_at' => '提交时间',
            'reviewed_at' => '审核时间',
            'reviewer_id' => '审核人',
            'status' => Yii::t('app', 'Status'),
            'created_at' => Yii::t('app', 'Created At'),
            'updated_at' => Yii::t('app', 'Updated At'),
        ]);
    }

    public static function getAuditStatusLabels($id = null)
    {
        $labels = [
            self::AUDIT_SUBMITTED => '待审核',
            self::AUDIT_APPROVED => '已通过',
            self::AUDIT_REJECTED => '已驳回',
        ];

        return $id === null ? $labels : ($labels[$id] ?? $id);
    }

    public function getStore()
    {
        return $this->hasOne(Store::class, ['id' => 'store_id']);
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getReviewer()
    {
        return $this->hasOne(User::class, ['id' => 'reviewer_id']);
    }

    public function requestedCategoryIds(): array
    {
        if (is_array($this->requested_category_ids)) {
            return array_values(array_filter(array_map('intval', $this->requested_category_ids)));
        }

        $raw = trim((string)$this->requested_category_ids);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('intval', $decoded)));
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    public function beforeSave($insert)
    {
        if (is_array($this->requested_category_ids)) {
            $this->requested_category_ids = json_encode(array_values(array_filter(array_map('intval', $this->requested_category_ids))));
        }

        if ($insert && !(int)$this->submitted_at) {
            $this->submitted_at = time();
        }

        return parent::beforeSave($insert);
    }
}
