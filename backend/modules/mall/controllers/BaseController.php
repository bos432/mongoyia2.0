<?php

namespace backend\modules\mall\controllers;

use Yii;

/**
 * Class BaseController
 * @package backend\modules\mall\controllers
 * @author funson86 <funson86@gmail.com>
 */
class BaseController extends \backend\controllers\BaseController
{
    public function isMallPlatformOperator()
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array((int)$this->getStoreId(), $this->getMallPlatformOperatorStoreIds(), true);
    }

    protected function getMallPlatformOperatorStoreIds()
    {
        $configured = trim((string)(Yii::$app->params['mallPlatformOperatorStoreIds'] ?? ''));
        if ($configured === '') {
            $configured = trim((string)(Yii::$app->params['mallPlatformStoreIds'] ?? ''));
        }

        $storeIds = $configured === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $configured))));
        if (!$storeIds) {
            $storeIds[] = (int)(Yii::$app->params['defaultStoreId'] ?? 0);
        }

        return array_values(array_unique(array_filter($storeIds)));
    }

    protected function clearCache()
    {
        return Yii::$app->cacheSystemMall->clearMallAllData($this->getStoreId());
    }
}
