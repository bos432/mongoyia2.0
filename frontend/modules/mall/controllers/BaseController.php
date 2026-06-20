<?php

namespace frontend\modules\mall\controllers;

use common\helpers\ArrayHelper;
use common\models\mall\Cart;
use common\helpers\CommonHelper;
use common\models\User;
use frontend\helpers\Url;
use Yii;

/**
 * Class BaseController
 * @package frontend\modules\mall\controllers
 * @author funson86 <funson86@gmail.com>
 */
class BaseController extends \frontend\controllers\BaseController
{
    public $pageSize = 24;

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // 黑名单用户自动注销
        if (!Yii::$app->user->isGuest && Yii::$app->user->identity->status != User::STATUS_ACTIVE) {
            Yii::$app->user->logout();
        }

        // 设置当前货币
        if (!Yii::$app->session->get('currentCurrency')) {
            Yii::$app->session->set('currentCurrency', Yii::$app->settingSystem->getValue('mall_currency_default') ?: 'USD');
        }
        return true;
    }

    public function getSeoUrl($item, $type = 'product')
    {

        if (isset($item['seo_url']) && strlen($item['seo_url']) > 0) {
            return Url::to(['/mall/' . $type . '/view', 'seo_url' => $item['seo_url']]);
        }

        return Url::to(['/mall/' . $type . '/view', 'id' => $item['id'] ?? null]);
    }

    public function getCurrentCurrency()
    {
        return Yii::$app->session->get('currentCurrency', Yii::$app->settingSystem->getValue('mall_currency_default') ?: 'USD');
    }

    public function getCurrentCurrencySymbol()
    {
        $currencies = $this->getCurrencyConfig();
        $mapCurrency = ArrayHelper::map($currencies, 'code', 'symbol');
        return $mapCurrency[$this->getCurrentCurrency()] ?? '';
    }

    public function getCurrentCurrencyRate()
    {
        $currencies = $this->getCurrencyConfig();
        $mapCurrency = ArrayHelper::map($currencies, 'code', 'rate');
        return $mapCurrency[$this->getCurrentCurrency()] ?? 1;
    }

    private function getCurrencyConfig()
    {
        $currencies = Yii::$app->settingSystem->getValue('mall_currencies');
        $currencies = is_string($currencies) && $currencies !== '' ? json_decode($currencies, true) : null;
        return is_array($currencies) && $currencies ? $currencies : [
            ['code' => 'USD', 'symbol' => '$', 'rate' => '1'],
        ];
    }

    public function getCartInfo()
    {
        $cart = Cart::find()->where(['store_id' => $this->getStoreId()])->andWhere(['or', 'session_id = "' . Yii::$app->session->id . '"', 'user_id = ' . (Yii::$app->user->id ?? -1)])->select(['sum(number) as count', 'sum(price) as amount'])->asArray()->one();
        return ['count' => intval($cart['count']), 'amount' => number_format(floatval($cart['amount']), 2)];
    }

    public function getCartCount()
    {
        $item = $this->getCartInfo();
        return $item['count'] ?? 0;
    }

    /**
     * @param bool $raw without currency rate
     * @return mixed|string
     */
    public function getCartAmount($raw = false)
    {
        $item = $this->getCartInfo();
        return $raw ? $item['amount'] : number_format($item['amount'] * $this->getCurrentCurrencyRate(), 2);
    }

    /**
     * @param $number
     * @param int $decimals
     * @param bool $currencyLabel
     * @param bool $raw without currency rate
     * @return mixed|string
     */
    public function getNumberByCurrency($number, $decimals = 2, $currencyLabel = true, $raw = false)
    {
        $item = $this->getCartInfo();
        return ($currencyLabel ? $this->getCurrentCurrencySymbol() : '') . number_format(($raw ? $number : $number * $this->getCurrentCurrencyRate()), $decimals);
    }
}
