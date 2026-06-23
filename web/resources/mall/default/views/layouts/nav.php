<?php
use yii\helpers\Url;
use common\models\mall\Category;
use common\helpers\ArrayHelper;
use yii\helpers\Html;
use common\models\base\Lang;
use common\models\mall\Cart;

/* @var $this yii\web\View */

$store = $this->context->store;
$language = strtolower(str_replace('_', '-', Yii::$app->language ?: 'zh-CN'));
$locale = str_starts_with($language, 'mn') ? 'mn' : (str_starts_with($language, 'en') ? 'en' : 'zh-CN');
$layoutTextMap = [
    'zh-CN' => [
        'freeShipping' => '满 $99 免运费',
        'home' => '首页',
        'item' => '商品:',
        'search' => '搜索',
    ],
    'en' => [
        'freeShipping' => 'Free shipping for orders over $99',
        'home' => 'Home',
        'item' => 'Item:',
        'search' => 'Search',
    ],
    'mn' => [
        'freeShipping' => '$99-өөс дээш захиалгад хүргэлт үнэгүй',
        'home' => 'Нүүр',
        'item' => 'Бараа:',
        'search' => 'Хайлт',
    ],
];
$layoutText = static function (string $key) use ($layoutTextMap, $locale): string {
    return $layoutTextMap[$locale][$key] ?? $layoutTextMap['zh-CN'][$key] ?? $key;
};

$strLang = '';
foreach (Lang::getLanguageCode() as $id => $code) {
    if (($store->lang_frontend & $id) == $id) {
        $strLang .= '<li class="lang-store">' . Html::a('<i class="flag-icon flag-icon-' . Lang::getLanguageFlag($id) .'"></i> '. Lang::getLanguageLabels($id), \frontend\helpers\Url::attachLang($code)) . '</li>';
    }
}

// currency
$strCurrentCurrency = $this->context->getCurrentCurrencySymbol() . $this->context->getCurrentCurrency();

$currencies = json_decode(Yii::$app->settingSystem->getValue('mall_currencies'), true);
$mapCurrency = ArrayHelper::map($currencies, 'code', 'symbol');
$strCurrency = '';
foreach ($currencies as $item) {
    $strCurrency .= Html::tag('li', Html::a(($mapCurrency[$item['code']] . $item['code']), Url::to(['/mall/default/set-currency', 'currency' => $item['code']])));
}


$categories = Category::find()->where(['store_id' => $store->id])->andWhere(['status'=>1])->asArray()->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all();
$categoriesTree = ArrayHelper::tree($categories);
?>


<div class="funmall-menu-overlay"></div>
<div class="funmall-menu-wrapper">
    <div class="funmall-menu-logo">
        <a href="<?= Url::to(['/']) ?>"><?= $this->context->getStoreName() ?></a>
    </div>
    <div class="funmall-menu-cart">
        <ul>
            <li><a href="<?= Url::to(['/mall/cart/index']) ?>"><i class="fa fa-shopping-bag"></i> <span><?= $this->context->getCartCount() ?></span></a></li>
        </ul>
        <div class="header-cart-price"><?= Html::encode($layoutText('item')) ?> <span><?= $this->context->getCurrentCurrencySymbol() . $this->context->getCartAmount() ?></span></div>
    </div>
    <div class="funmall-menu-widget">
        <div class="header-top-right-language">
            <i class="flag-icon flag-icon-<?= Lang::getLanguageFlag(Lang::getLanguageCode(Yii::$app->language, true, true)) ?>"></i>
            <div><?= Lang::getLanguageCodeLabels(Yii::$app->language) ?></div>
            <i class="fa fa-chevron-down"></i>
            <ul>
                <?= $strLang ?>
            </ul>
        </div>
        <div class="header-top-right-language pull-right">
            <div class="header-top-right-language">
                <div><?= $strCurrentCurrency ?></div>
                <i class="fa fa-chevron-down"></i>
                <ul>
                    <?= $strCurrency ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="funmall-menu-widget">
        <div class="header-top-right-auth">
            <a href="<?= Url::to(['/mall/user/favorite']) ?>" class="mr-3"><i class="fa fa-heart"></i></a>
            <?php if (Yii::$app->user->isGuest) { ?>
                <a href="<?= Url::to(['/mall/default/login']) ?>" rel="nofollow"><i class="fa fa-user"></i> <?= Yii::t('app', 'Login') ?></a> / <a href="<?= Url::to(['/mall/default/signup']) ?>" rel="nofollow"><?= Yii::t('app', 'Sign up') ?></a>
            <?php } else { ?>
                <div class="header-top-right-language">
                    <div><i class="fa fa-user"></i> <?= substr(Yii::$app->user->identity->email ?: Yii::$app->user->identity->username, 0, 10) . '..' ?></div>
                    <span class="arrow_carrot-down"></span>
                    <ul>
                        <li><a href="<?= Url::to(['/mall/user/order']) ?>"><?= Yii::t('app', 'Orders') ?></a></li>
                        <li><a href="<?= Url::to(['/mall/user/coupon']) ?>"><?= Yii::t('app', 'Coupons') ?></a></li>
                        <li><a href="<?= Url::to(['/mall/user/favorite']) ?>"><?= Yii::t('app', 'Favorites') ?></a></li>
                        <li><a href="<?= Url::to(['/mall/user/address']) ?>"><?= Yii::t('app', 'Addresses') ?></a></li>
                        <li><a href="<?= Url::to(['/mall/user/setting']) ?>"><?= Yii::t('app', 'Profile') ?></a></li>
                        <li><a href="<?= Url::to(['/mall/default/logout']) ?>"><?= Yii::t('app', 'Logout') ?></a></li>
                    </ul>
                </div>
            <?php } ?>
        </div>
    </div>
    <nav class="funmall-menu-nav mobile-menu">
        <ul>
            <li class="<?= Yii::$app->request->getUrl() == '/' ? 'active' : '' ?>"><a href="<?= Url::to(['/']) ?>"><?= Html::encode($layoutText('home')) ?></a></li>
            <?php
            foreach ($categoriesTree as $item) {
                echo '<li>' . Html::a(fbt(Category::getTableCode(), $item['id'], 'name', $item['name']), $this->context->getSeoUrl($item, 'category'));
                if (count($item['children']) > 0) {
                    echo '<ul class="header-menu-dropdown">';
                    foreach ($item['children'] as $child) {
                        echo '<li>' . Html::a(fbt(Category::getTableCode(), $child['id'], 'name', $child['name']), $this->context->getSeoUrl($child, 'category'));
                    }
                    echo '</ul>';
                }
                echo '</li>';
            }
            ?>
<!--            <li class="--><?php //= (strpos(Yii::$app->request->getUrl(), '/mall/category/view?keyword=') !== false) ? 'active' : '' ?><!--"><a href="--><?php //= Url::to(['/mall/category/view', 'keyword' => '']) ?><!--">--><?php //= Yii::t('mall', 'New Arrivals') ?><!--</a></li>-->
<!--            <li class="--><?php //= Yii::$app->request->getUrl() == '/mall/default/feedback' ? 'active' : '' ?><!--"><a href="--><?php //= Url::to(['/mall/default/feedback']) ?><!--">--><?php //= Yii::t('app', 'Feedback') ?><!--</a></li>-->
        </ul>
    </nav>
    <div id="mobile-menu-wrap"></div>
    <div class="header-search-h5">
        <form class="form-inline my-2 my-md-0 input-search" action="<?= Url::to(['/mall/category/view']) ?>" method="get">
            <button type="submit" class="input-btn input-search-btn"><i class="fa fa-search" aria-hidden="true"></i></button>
            <input class="form-control" name="keyword" type="text" placeholder="<?= Html::encode(Yii::$app->request->get('keyword', $layoutText('search'))) ?>">
        </form>
    </div>
    <div class="header-top-right-social">
        <a href="#"><i class="fa fa-facebook"></i></a>
        <a href="#"><i class="fa fa-twitter"></i></a>
        <a href="#"><i class="fa fa-linkedin"></i></a>
        <a href="#"><i class="fa fa-pinterest-p"></i></a>
    </div>
    <div class="funmall-menu-contact">
        <ul>
            <li><?= Html::encode($layoutText('freeShipping')) ?></li>
        </ul>
    </div>
</div>

<header class="header">
    <div class="header-top">
        <div class="container">
            <div class="row">
                <div class="col-lg-7 col-md-7">
                    <div class="header-top-left">
                        <ul>
                            <li>
                                <div class="header-top-right-language">
                                    <i class="flag-icon flag-icon-<?= Lang::getLanguageFlag(Lang::getLanguageCode(Yii::$app->language, true, true)) ?>"></i>
                                    <div><?= Lang::getLanguageCodeLabels(Yii::$app->language) ?></div>
                                    <span class="arrow_carrot-down"></span>
                                    <ul>
                                        <?= $strLang ?>
                                    </ul>
                                </div>
                            </li>
                            <li>
                                <div class="header-top-right-language">
                                    <div><?= $strCurrentCurrency ?></div>
                                    <span class="arrow_carrot-down"></span>
                                    <ul>
                                        <?= $strCurrency ?>
                                    </ul>
                                </div>
                            </li>
                            <li><?= Html::encode($layoutText('freeShipping')) ?></li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-5 col-md-5">
                    <div class="header-top-right">
                        <div class="header-top-right-social">
                            <a href="#"><i class="fa fa-facebook"></i></a>
                            <a href="#"><i class="fa fa-twitter"></i></a>
                            <a href="#"><i class="fa fa-linkedin"></i></a>
                            <a href="#"><i class="fa fa-pinterest-p"></i></a>
                        </div>
                        <div class="header-top-right-auth">
                            <a href="<?= Url::to(['/mall/user/favorite']) ?>" class="mr-3"><i class="fa fa-heart"></i></a>
                            <?php if (Yii::$app->user->isGuest) { ?>
                                <a href="<?= Url::to(['/mall/default/login']) ?>" rel="nofollow"><i class="fa fa-user"></i> <?= Yii::t('app', 'Login') ?></a> / <a href="<?= Url::to(['/mall/default/signup']) ?>" rel="nofollow"><?= Yii::t('app', 'Sign up') ?></a>
                            <?php } else { ?>
                                <div class="header-top-right-language">
                                    <div><i class="fa fa-user"></i> <?= substr(Yii::$app->user->identity->email ?: Yii::$app->user->identity->username, 0, 10) . '..' ?></div>
                                    <span class="arrow_carrot-down"></span>
                                    <ul>
                                        <li><a href="<?= Url::to(['/mall/user/order']) ?>"><?= Yii::t('app', 'Orders') ?></a></li>
                                        <li><a href="<?= Url::to(['/mall/user/coupon']) ?>"><?= Yii::t('app', 'Coupons') ?></a></li>
                                        <li><a href="<?= Url::to(['/mall/user/favorite']) ?>"><?= Yii::t('app', 'Favorites') ?></a></li>
                                        <li><a href="<?= Url::to(['/mall/user/address']) ?>"><?= Yii::t('app', 'Addresses') ?></a></li>
                                        <li><a href="<?= Url::to(['/mall/user/setting']) ?>"><?= Yii::t('app', 'Profile') ?></a></li>
                                        <li><a href="<?= Url::to(['/mall/default/logout']) ?>"><?= Yii::t('app', 'Logout') ?></a></li>
                                    </ul>
                                </div>
                            <?php } ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <div class="header-logo">
                    <a href="<?= Url::to(['/']) ?>"><?= $this->context->getStoreName() ?></a>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="header-search">
                    <form class="form-inline my-2 my-md-0 input-search" action="<?= Url::to(['/mall/category/view']) ?>" method="get">
                        <button type="submit" class="input-btn input-search-btn"><i class="fa fa-search" aria-hidden="true"></i></button>
                        <input class="form-control" name="keyword" type="text" placeholder="<?= Html::encode(Yii::$app->request->get('keyword', $layoutText('search'))) ?>">
                    </form>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="header-cart">
                    <ul>
                        <li><a href="<?= Url::to(['/mall/cart/index']) ?>"><i class="fa fa-shopping-bag"></i> <span><?= $this->context->getCartCount() ?></span></a></li>
                    </ul>
                    <div class="header-cart-price"><a href="<?= Url::to(['/mall/cart/index']) ?>"><?= Html::encode($layoutText('item')) ?> <span><?= $this->context->getCurrentCurrencySymbol() . $this->context->getCartAmount() ?></span></a></div>
                </div>
            </div>
        </div>
        <div class="funmall-open">
            <i class="fa fa-bars"></i>
        </div>
    </div>
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <nav class="header-menu">
                    <ul>
                        <li class="<?= Yii::$app->request->getUrl() == '/' ? 'active' : '' ?>"><a href="<?= Url::to(['/']) ?>"><?= Html::encode($layoutText('home')) ?></a></li>
                        <?php
                        foreach ($categoriesTree as $item) {
                            echo '<li>' . Html::a(fbt(Category::getTableCode(), $item['id'], 'name', $item['name']), $this->context->getSeoUrl($item, 'category'));
                            if (count($item['children']) > 0) {
                                echo '<ul class="header-menu-dropdown">';
                                foreach ($item['children'] as $child) {
                                    echo '<li>' . Html::a(fbt(Category::getTableCode(), $child['id'], 'name', $child['name']), $this->context->getSeoUrl($child, 'category'));
                                }
                                echo '</ul>';
                            }
                            echo '</li>';
                        }
                        ?>
<!--                        <li class="--><?php //= (strpos(Yii::$app->request->getUrl(), '/mall/category/view?keyword=') !== false) ? 'active' : '' ?><!--"><a href="--><?php //= Url::to(['/mall/category/view', 'keyword' => '']) ?><!--">--><?php //= Yii::t('mall', 'New Arrivals') ?><!--</a></li>-->
<!--                        <li class="--><?php //= Yii::$app->request->getUrl() == '/mall/default/feedback' ? 'active' : '' ?><!--"><a href="--><?php //= Url::to(['/mall/default/feedback']) ?><!--">--><?php //= Yii::t('app', 'Feedback') ?><!--</a></li>-->
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</header>

<?php if (isset($this->params['breadcrumbs']) && count($this->params['breadcrumbs'])) { ?>
<div class="breadcrumb-option pb-5" style="background-image: url(https://preview.colorlib.com/theme/estore/assets/img/hero/category.jpg.webp)">
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <div class="breadcrumb-links">
                    <?= \yii\widgets\Breadcrumbs::widget([
                        'links' => isset($this->params['breadcrumbs']) ? $this->params['breadcrumbs'] : [],
                        'options' => ['class' => ''],
                        'tag' => 'span',
                        'itemTemplate' => '{link}',
                        'activeItemTemplate' => '{link}',
                        'encodeLabels' => false,
                        'homeLink' => [
                            'label' => '<i class="fa fa-home"></i> ' . Html::encode($layoutText('home')),
                            'url' => Yii::$app->homeUrl,
                        ]
                    ]) ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<script>

$(document).ready(function () {
    $(".funmall-open").on('click', function () {
        $(".funmall-menu-wrapper").addClass("show-funmall-menu-wrapper");
        $(".funmall-menu-overlay").addClass("active");
        $("body").addClass("over_hid");
    });

    $(".funmall-menu-overlay").on('click', function () {
        $(".funmall-menu-wrapper").removeClass("show-funmall-menu-wrapper");
        $(".funmall-menu-overlay").removeClass("active");
        $("body").removeClass("over_hid");
    });

    $(".mobile-menu").slicknav({
        prependTo: '#mobile-menu-wrap',
        allowParentLinks: true
    });
    if(document.getElementsByClassName('slicknav_menu').lenght>1){
        document.getElementsByClassName('slicknav_menu')[1].style = "display:none"
    }
});

jQuery(".searchForm").submit(function(){
    if (jQuery("#searchText").val() == '') {
        return false;
    }
});
</script>
