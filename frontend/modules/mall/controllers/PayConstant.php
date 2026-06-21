<?php

namespace frontend\modules\mall\controllers;

class PayConstant
{
    public static $sub_merchant_id = '';
    public static $sandbox = true;
    public static $merchant_id = '';
    public static $public_key = '';
    public static $private_key = '';

    public static function loadFromEnv()
    {
        self::$sub_merchant_id = env('LIANLIAN_SUB_MERCHANT_ID', '');
        self::$sandbox = env_bool('LIANLIAN_SANDBOX', true);
        self::$merchant_id = env('LIANLIAN_MERCHANT_ID', '');
        self::$public_key = env('LIANLIAN_PUBLIC_KEY', '');
        self::$private_key = env('LIANLIAN_PRIVATE_KEY', '');
    }

    public static function loadFromArray(array $config)
    {
        self::$sub_merchant_id = (string)($config['sub_merchant_id'] ?? '');
        self::$sandbox = (string)($config['environment'] ?? 'test') !== 'live';
        self::$merchant_id = (string)($config['merchant_id'] ?? '');
        self::$public_key = (string)($config['public_key'] ?? '');
        self::$private_key = (string)($config['private_key'] ?? '');
    }

    public static function isConfigured(array $config = null)
    {
        $config === null ? self::loadFromEnv() : self::loadFromArray($config);
        return self::$merchant_id !== '' && self::$public_key !== '' && self::$private_key !== '';
    }
}
