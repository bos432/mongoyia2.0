<?php

namespace common\models\mall;

use common\models\BaseModel;
use Yii;
use common\models\User;
use common\models\Store;

/**
 * This is the model class for table "{{%mall_coupon_type}}".
 *
 * @property int $id
 * @property int $store_id 商家
 * @property string $name 名称
 * @property string $money 优惠金额
 * @property float $min_amount 最低金额
 * @property int $max_times 最大数量
 * @property int $started_at 开始时间
 * @property int $ended_at 结束时间
 * @property string $sn 编号
 * @property int $type 类型
 * @property int $sort 排序
 * @property int $status 状态
 * @property int $created_at 创建时间
 * @property int $updated_at 更新时间
 * @property int $created_by 创建用户
 * @property int $updated_by 更新用户
 */
class UserCoupon extends BaseModel
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%mall_user_coupon}}';
    }

    public static function get_times($id)
    {
        return (int) static::find()->where(['cid' => (int)$id])->count();
    }

}
