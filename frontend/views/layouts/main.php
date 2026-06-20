<?php

/* @var $this \yii\web\View */
/* @var $content string */

use yii\helpers\Html;
use yii\bootstrap4\Nav;
use yii\bootstrap4\NavBar;
use yii\widgets\Breadcrumbs;
use frontend\assets\AppAsset;
use common\widgets\Alert;

AppAsset::register($this);
$url = Yii::$app->request->getUrl();
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>

<?php
NavBar::begin([
    'brandLabel' => $this->context->store->settings['website_name'] ?: $this->context->store->name ?: Yii::$app->name,
    'brandUrl' => Yii::$app->homeUrl,
    'options' => [
        'class' => 'navbar navbar-expand-lg navbar-dark nav-white fixed-top',
    ],
]);
$menuItems = [
    ['label' => Yii::t('app', 'Home'), 'url' => ['/']],
    ['label' => Yii::t('app', 'Mall'), 'url' => ['/mall']],
    ['label' => Yii::t('app', 'Feedback'), 'url' => ['/site/feedback']],
    ['label' => Yii::t('app', 'Backend'), 'url' => ['/backend']],
];
/*if (Yii::$app->user->isGuest) {
    $menuItems[] = ['label' => 'Signup', 'url' => ['/site/signup']];
    $menuItems[] = ['label' => 'Login', 'url' => ['/site/login']];
} else {
    $menuItems[] = '<li>'
        . Html::beginForm(['/site/logout'], 'post')
        . Html::submitButton(
            'Logout (' . Yii::$app->user->identity->username . ')',
            ['class' => 'btn btn-link logout']
        )
        . Html::endForm()
        . '</li>';
}*/
echo Nav::widget([
    'options' => ['class' => 'navbar-nav ml-auto'],
    'items' => $menuItems,
]);
NavBar::end();
?>

<header class="masthead" style="height: <?= $url == '/' ? 50 : 20 ?>vh; min-height: <?= $url == '/' ? 50 : 20 ?>vh">
    <div class="container h-100">
        <div class="row h-100">
            <div class="col-lg-12 text-center my-auto">
                <h3 class=""><?= Html::encode(Yii::$app->params['siteName'] ?? 'Mongoyia') ?></h3>
                <?php if ($url == '/') { ?>
                    <p class="pt-3">
                        <?= Html::a(Yii::t('app', 'Go to Mall'), ['/mall'], ['class' => 'btn btn-danger wow bounceInRight', 'data-wow-duration' => '2s']) ?>
                    </p>
                <?php } ?>
            </div>
        </div>
    </div>
</header>

<script>
    function _scroll(){
        var scrollTop = $(window).scrollTop();
        if (scrollTop < 10){
            $('.navbar').removeClass('bg-dark');
            $('.navbar').css('opacity', 1);
        } else {
            $('.navbar').addClass('bg-dark');
            $('.navbar').css('opacity', 0.95);
        }
    }
    $(window).on('scroll',function() {
        _scroll()
    });

    // 解决手机点击下拉部分透明，下拉后有背景
    $('.navbar-toggler').click(function () {
        if ($('#navbarCollapse').hasClass('show')) {
            $('.navbar').removeClass('bg-dark');
            $('.navbar').css('opacity', 1);
        } else {
            $('.navbar').addClass('bg-dark');
            $('.navbar').css('opacity', 0.95);
        }
    })

</script>


<?= $content ?>
<?= !Yii::$app->request->isAjax ? \common\widgets\alert\SweetAlert2::widget() : '' ?>

<footer class="footer">
    <div class="container">
        <p class="pull-left">&copy; <?= Html::encode(Yii::$app->name) ?> <?= date('Y') ?></p>

        <p class="pull-right"><?= Html::encode(Yii::$app->params['siteName'] ?? 'Mongoyia') ?></p>
    </div>
</footer>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
