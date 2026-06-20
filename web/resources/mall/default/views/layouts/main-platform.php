<?php
use yii\helpers\Html;
use frontend\assets\MallPlatformAsset;
use common\widgets\Alert;
use yii\helpers\Url;

/* @var $this \yii\web\View */
/* @var $content string */

$store = $this->context->store;
$language = strtolower(str_replace('_', '-', Yii::$app->language ?: 'zh-CN'));
$locale = str_starts_with($language, 'mn') ? 'mn' : (str_starts_with($language, 'en') ? 'en' : 'zh-CN');
$layoutTextMap = [
    'zh-CN' => [
        'cookieMessage' => '我们使用 Cookie 来改善您的浏览体验。继续使用即表示您同意我们使用 Cookie。',
        'cookieOk' => '确定',
    ],
    'en' => [
        'cookieMessage' => 'We use cookies to give you the best experience on our website. By continuing, you agree to our use of cookies.',
        'cookieOk' => 'OK',
    ],
    'mn' => [
        'cookieMessage' => 'Бид таны хэрэглээг сайжруулахын тулд cookie ашигладаг. Үргэлжлүүлснээр та cookie ашиглахыг зөвшөөрч байна.',
        'cookieOk' => 'Зөвшөөрөх',
    ],
];
$layoutText = static function (string $key) use ($layoutTextMap, $locale): string {
    return $layoutTextMap[$locale][$key] ?? $layoutTextMap['zh-CN'][$key] ?? $key;
};

MallPlatformAsset::register($this);
$this->registerCssFile($this->context->getCss('style-platform.css?v=' . Yii::$app->params['system_version']), ['depends' => MallPlatformAsset::className()]);
$this->registerJsFile($this->context->getJs('main-platform.js'), ['depends' => MallPlatformAsset::className()]);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= Html::csrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?> - <?= Html::encode($store->settings['website_name'] ?: $store->name) ?></title>
    <meta name="keywords" content="<?= Html::encode($store->settings['website_seo_keywords'] ?: $store->settings['website_name']) ?>"/>
    <meta name="description" content="<?= Html::encode($store->settings['website_seo_description'] ?: $store->settings['website_name']) ?>"/>
    <link rel="icon" href="<?= $this->context->getFavicon() ?>" type="image/x-icon" />
    <?php $this->head() ?>
</head>
<body class="bg-light">
<?php $this->beginBody() ?>
    <header>
        <?= $this->render('nav-platform') ?>
    </header>

    <main class="container">
        <?= $content ?>
        <?= !Yii::$app->request->isAjax ? \common\widgets\alert\SweetAlert2::widget() : '' ?>
    </main>

    <footer>
        <?= $this->render('footer-platform') ?>
    </footer>

    <?= strlen($store->settings['website_stat']) > 10 ? $store->settings['website_stat'] : '' ?>
<?php $this->endBody() ?>

<script>
    $(document).ready(function () {
        ua = navigator.userAgent.toLowerCase();
        var regexp=/\.(bot.htm|spider.htm)(\.[a-z0-9\-]+){1,2}\//ig;
        if(!regexp.test(ua)) {
            jQuery.cookieBar({
                message: <?= json_encode($layoutText('cookieMessage'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                acceptText: <?= json_encode($layoutText('cookieOk'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                fixed: true,
                policyButton: false,
                expireDays: 60,
            });
        }
    });
</script>
</body>
</html>
<?php $this->endPage() ?>
