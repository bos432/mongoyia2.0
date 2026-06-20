<?php
use yii\helpers\Html;
use frontend\assets\MallAsset;
use common\helpers\CommonHelper;

/* @var $this \yii\web\View */
/* @var $content string */

MallAsset::register($this);
$this->registerCssFile($this->context->getCss('style.css?v=' . Yii::$app->params['system_version']), ['depends' => MallAsset::className()]);
$this->registerJsFile($this->context->getJs('main.js?v=' . Yii::$app->params['system_version']), ['depends' => MallAsset::className()]);

$store = $this->context->store;
$suffix = $store->settings['website_seo_keywords'] ?: $this->context->getStoreName();
//echo '<pre/>';
//var_dump($store->settings);exit();
$title = (($this->title && $suffix) ? ($this->title . ' - ' . $suffix) : ($this->title ?: $suffix));
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Mongoyia">
    <?= Html::csrfMetaTags() ?>
    <title><?= Html::encode($title) ?></title>
    <meta name="keywords" content="<?= $suffix ?>"/>
    <meta name="description" content="<?= Html::encode($store->settings['website_seo_description'] ?: $this->context->getStoreName()) ?>"/>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/pwa-icon.svg">
    <link rel="icon" href="<?= $this->context->getFavicon() ?>" type="image/x-icon" />
    <?php $this->head() ?>
</head>
<body class="mongoyia-pwa-shell" data-mongoyia-pwa="1">

<?php $this->beginBody() ?>

    <?= $this->render('nav') ?>

    <?= $content ?>

    <?= $this->render('footer') ?>
    <?= !Yii::$app->request->isAjax ? \common\widgets\alert\SweetAlert2::widget() : '' ?>

    <!-- Scroll to Top -->
<!--    <button type="button" class="btn btn-scroll-top" id="goTop" title="--><?php //= Yii::t('app', 'Go Top') ?><!--"><span class="fa fa-chevron-up"></span></button>-->

    <?= strlen($store->settings['website_stat']) > 10 ? $store->settings['website_stat'] : '' ?>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/pwa-sw.js', {scope: '/'}).catch(function () {});
            });
        }
    </script>
<?php $this->endBody() ?>
</body>
<style>
    html, body{
        overflow-x:hidden;
    }
</style>
</html>
<?php $this->endPage() ?>
