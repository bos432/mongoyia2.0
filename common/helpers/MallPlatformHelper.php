<?php

namespace common\helpers;

use common\models\mall\Product;
use Yii;
use yii\db\ActiveQuery;

class MallPlatformHelper
{
    public static function isPlatformMode()
    {
        return Yii::$app->params['mallPlatformMode'] ?? true;
    }

    public static function platformStoreIds()
    {
        $configured = trim((string)(Yii::$app->params['mallPlatformStoreIds'] ?? ''));
        if ($configured !== '') {
            return array_values(array_filter(array_map('intval', explode(',', $configured))));
        }

        return [];
    }

    public static function productQuery()
    {
        $query = Product::find()->where(['status' => Product::STATUS_ACTIVE]);
        self::applyProductScope($query);
        return $query;
    }

    public static function applyProductScope(ActiveQuery $query)
    {
        if (!self::isPlatformMode()) {
            return $query->andWhere(['store_id' => Yii::$app->storeSystem->getId()]);
        }

        $storeIds = self::platformStoreIds();
        if ($storeIds) {
            $query->andWhere(['store_id' => $storeIds]);
        }

        return $query;
    }
}
