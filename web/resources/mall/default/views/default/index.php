<?php
use common\helpers\ImageHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use common\models\mall\Product;
use common\models\mall\Category;
use common\helpers\ArrayHelper;

/* @var $this yii\web\View */
/* @var $productsNew \common\models\mall\Product[] */
/* @var $productsHot \common\models\mall\Product[] */


$this->title = '';

$store = $this->context->store;
$categories = Category::find()->where(['store_id' => $store->id])->andWhere(['status'=>1])->asArray()->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->all();
$categoriesTree = ArrayHelper::tree($categories);
$wa = json_decode($store->settings['website_banner_wa'] ?? '[]', true) ?: [];
$wa2 = json_decode($store->settings['website_banner_wa2'] ?? '[]', true) ?: [];
$wa3 = json_decode($store->settings['website_banner_wa3'] ?? '[]', true) ?: [];
$an1 = json_decode($store->settings['website_banner_an1'] ?? '[]', true) ?: [];
$an2 = json_decode($store->settings['website_banner_an2'] ?? '[]', true) ?: [];
$prices = json_decode($store->settings['website_banner_price'] ?? '[]', true) ?: [];
$banner = json_decode($store->settings['website_banner'] ?? '[]', true) ?: [];
//var_dump($banner);exit();
?>

<section class="page-section pt-0 pb-7 banner" data-mongoyia-mobile-ui="home" style="padding-bottom: 3.4rem">
    <div class="hero-slider owl-carousel">
        <?php foreach ($banner as $k=>$item) {?>
        <div class="hs-item set-bg" data-setbg="<?= $item?>">
            <div class="container">
                <div class="row">
                    <div class="col-xl-6 col-lg-7 text-white">
                        <span><?= $wa[$k] ?? ''; ?></span>
                        <h2><?= $wa2[$k] ?? ''; ?></h2>
                        <p><?= $wa3[$k] ?? ''; ?></p>
<!--                        <span>--><?php //= Yii::t('mall', 'New Arrivals') ?><!--</span>-->
<!--                        <h2>--><?php //= Yii::t('mall', 'Erke jackets') ?><!--</h2>-->
<!--                        <p>--><?php //= Yii::t('mall', 'Erke jackets is very suitable for young women and men.') ?><!--</p>-->
                        <a href="<?= $an1[$k] ?? '#' ?>" class="site-btn sb-line"><?= Yii::t('mall', 'Discover') ?></a>
                        <a href="<?= $an2[$k] ?? '#' ?>" class="site-btn sb-white"><?= Yii::t('mall', 'Shop Now') ?></a>
                    </div>
                </div>
                <div class="offer-card text-white">
                    <span><?= Yii::t('mall', 'from') ?></span>
                    <h2><?= $this->context->getNumberByCurrency((float)($prices[$k] ?? 0), 0) ?></h2>
                    <p><?= Yii::t('mall', 'Shop Now') ?></p>
                </div>
            </div>
        </div>
        <?php }?>
<!--        <div class="hs-item set-bg" data-setbg="https://preview.colorlib.com/theme/divisima/img/bg-2.jpg.webp">-->
<!--            <div class="container">-->
<!--                <div class="row">-->
<!--                    <div class="col-xl-6 col-lg-7 text-white">-->
<!--                        <span>--><?php //= Yii::t('mall', 'New Arrivals') ?><!--</span>-->
<!--                        <h2>--><?php //= Yii::t('mall', 'Erke jackets') ?><!--</h2>-->
<!--                        <p>--><?php //= Yii::t('mall', 'Erke jackets is very suitable for young women and men.') ?><!--</p>-->
<!--                        <a href="#" class="site-btn sb-line">--><?php //= Yii::t('mall', 'Discover') ?><!--</a>-->
<!--                        <a href="#" class="site-btn sb-white">--><?php //= Yii::t('mall', 'Shop Now') ?><!--</a>-->
<!--                    </div>-->
<!--                </div>-->
<!--                <div class="offer-card text-white">-->
<!--                    <span>--><?php //= Yii::t('mall', 'from') ?><!--</span>-->
<!--                    <h2>--><?php //= $this->context->getNumberByCurrency(29, 0) ?><!--</h2>-->
<!--                    <p>--><?php //= Yii::t('mall', 'Shop Now') ?><!--</p>-->
<!--                </div>-->
<!--            </div>-->
<!--        </div>-->
    </div>
    <div class="container">
        <div class="slide-num-holder" id="snh-1"></div>
    </div>
</section>
<section class="page-section py-3 product" style="position: sticky;top:0;background-color: #fff;z-index: 99999">
    <div class="container header-menu" style="padding-bottom: 0;display:block !important;">
        <style>
            .hero-slider .hs-item .container h2{
                font-size:2rem
            }
            @media only screen and (max-width: 991px) {
                .header-logo, .funmall-open, .header-cart-price {
                    display: none !important;
                }
                .funmall-open{
                    left:15px;
                    top:30px;
                    right:unset;
                }
                .header-search{
                    display: block;
                    width:60vw
                }
                .header .container .row{
                    flex-wrap:nowrap;
                    flex-direction: row;
                    justify-content: space-around;
                    align-items: center;
                }
                .hero-slider .hs-item{
                    height:30vh
                }
                .hero-slider .hs-item .container{
                    padding-top:15px
                }
                .hero-slider .hs-item .container h2{
                    font-size:2rem
                }
                .hero-slider .hs-item .container span{
                    font-size:1rem
                }
                .hero-slider .hs-item .container p{
                    font-size:1rem;
                    margin-bottom:10px
                }
                .hero-slider .hs-item .offer-card{
                    width:80px;
                    height:80px;
                    top:calc(15vh - 40px)
                }
                .hero-slider .hs-item .offer-card h2,.hero-slider .hs-item .offer-card span,.hero-slider .hs-item .offer-card p{
                    font-size:0.5rem;
                    margin-bottom:0
                }
                .hero-slider .hs-item .offer-card h2{
                    font-size:1rem;
                }
                .hero-slider .hs-item .offer-card{
                    display:inline-flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                    padding-top:0;
                    gap:5px
                }
                .slide-num-holder,.slider-nav-warp{
                    display:none
                }
                .banner{
                    padding-bottom:0 !important;
                }
            }
        </style>
        <nav class="funmall-menu-nav mobile-menu" style="display:block !important;max-width: 100%;overflow-x:auto;white-space: nowrap;overflow-y: hidden ">
            <ul>
                <?php
                foreach ($categoriesTree as $item) {
                    echo '<li>' . Html::a(fbt(Category::getTableCode(), $item['id'], 'name', $item['name']), (count($item['children']) > 0 ? $this->context->getSeoUrl($item, 'category') : $this->context->getSeoUrl($item, 'category')));
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
            </ul>
        </nav>
    </div>
</section>
<section class="page-section py-3 product" id="p0">
    <div class="container">
        <div class="row">
            <div class="col-lg-4 col-md-4">
                <div class="section-title">
                    <h3><?= Yii::t('mall', 'Hot Deals') ?></h3>
                </div>
            </div>
        </div>
        <div class="row property-gallery">
            <?php foreach ($productsHot as $product) { ?>
                <div class="col-lg-3 col-md-4 col-sm-6">
                    <div class="product-item">
                        <a href="<?= $this->context->getSeoUrl($product) ?>">
                            <div class="product-item-pic set-bg" data-setbg="<?= $this->context->getImage($product->thumb) ?>">
                                <div class="label type-<?= $product->getTypeOne() ?>"><?= Yii::t('mall', $product->getTypeOne(true)) ?></div>
                                <!--                                <ul class="product-hover">-->
                                <!--                                    <li><a href="--><?php //= $this->context->getImage($product->image) ?><!--" data-fancybox="gallery"><i class="fa fa-expand"></i></a></li>-->
                                <!--                                    <li><a href="--><?php //= $this->context->getSeoUrl($product) ?><!--"><i class="fa fa-shopping-bag"></i></a></li>-->
                                <!--                                </ul>-->
                            </div>
                            <div class="product-item-text">
                                <h6><?= Html::a(fbt(Product::getTableCode(), $product->id, 'name', $product->name), $this->context->getSeoUrl($product)) ?></h6>
                                <!--                                <div class="rating">-->
                                <!--                                    --><?php //= \common\helpers\UiHelper::renderStar($product->star) ?>
                                <!--                                </div>-->
                                <div class="product-price"><?= $this->context->getNumberByCurrency($product->price) ?></div>
                            </div>
                        </a>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</section>

<section class="page-section py-3 product" id="p1">
    <div class="container">
        <div class="row">
            <div class="col-lg-4 col-md-4">
                <div class="section-title">
                    <h3><?= Yii::t('mall', 'New Arrivals') ?></h3>
                </div>
            </div>
        </div>
        <div class="row property-gallery">
            <?php foreach ($productsNew as $product) { ?>
                <div class="col-lg-3 col-md-4 col-sm-6">
                    <div class="product-item">
                        <a href="<?= $this->context->getSeoUrl($product) ?>">
                            <div class="product-item-pic set-bg" data-setbg="<?= $this->context->getImage($product->thumb) ?>">
                                <div class="label type-<?= $product->getTypeOne() ?>"><?= Yii::t('mall', $product->getTypeOne(true)) ?></div>
                            </div>
                            <div class="product-item-text">
                                <h6><?= Html::a(fbt(Product::getTableCode(), $product->id, 'name', $product->name), $this->context->getSeoUrl($product)) ?></h6>
                                <div class="product-price"><?= $this->context->getNumberByCurrency($product->price) ?></div>
                            </div>
                        </a>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</section>

<section class="page-section py-3 product" id="p1">
    <div class="container">
        <div class="row">
            <div class="col-lg-4 col-md-4">
                <div class="section-title">
                    <h3><?= Yii::t('cons', 'TYPE_PROMOTION') ?></h3>
                </div>
            </div>
        </div>
        <div class="row property-gallery">
            <?php foreach ($productsZk as $product) { ?>
                <div class="col-lg-3 col-md-4 col-sm-6">
                    <div class="product-item">
                        <a href="<?= $this->context->getSeoUrl($product) ?>">
                            <div class="product-item-pic set-bg" data-setbg="<?= $this->context->getImage($product->thumb) ?>">
                                <div class="label type-<?= $product->getTypeOne() ?>"><?= Yii::t('mall', $product->getTypeOne(true)) ?></div>
                            </div>
                            <div class="product-item-text">
                                <h6><?= Html::a(fbt(Product::getTableCode(), $product->id, 'name', $product->name), $this->context->getSeoUrl($product)) ?></h6>
                                <div class="product-price"><?= $this->context->getNumberByCurrency($product->price) ?></div>
                            </div>
                        </a>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</section>

<section class="page-section py-5 services">
    <div class="container">
        <div class="row">
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="services-item">
                    <i class="fa fa-car"></i>
                    <h6><?= Yii::t('mall', 'Free Shipping') ?></h6>
                    <p><?= Yii::t('mall', 'For all oder over $99') ?></p>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="services-item">
                    <i class="fa fa-money"></i>
                    <h6><?= Yii::t('mall', 'Money Back Guarantee') ?></h6>
                    <p><?= Yii::t('mall', 'If good have Problems') ?></p>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="services-item">
                    <i class="fa fa-support"></i>
                    <h6><?= Yii::t('mall', 'Online Support 24/7') ?></h6>
                    <p><?= Yii::t('mall', 'Dedicated support') ?></p>
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="services-item">
                    <i class="fa fa-headphones"></i>
                    <h6><?= Yii::t('mall', 'Payment Secure') ?></h6>
                    <p><?= Yii::t('mall', '100% secure payment') ?></p>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    $(document).ready(function(){
        var hero_s = $(".hero-slider");
        hero_s.owlCarousel({
            loop: true,
            margin: 0,
            nav: true,
            items: 1,
            dots: true,
            animateOut: 'fadeOut',
            animateIn: 'fadeIn',
            navText: ['<i class="fa fa-chevron-circle-left"></i>', '<i class="fa fa-chevron-circle-right"></i>'],
            smartSpeed: 1200,
            autoHeight: false,
            autoplay: true,
            onInitialized: function() {
                var a = this.items().length;
                $("#snh-1").html("<span>1</span><span>" + a + "</span>");
            }
        }).on("changed.owl.carousel", function(a) {
            var b = --a.item.index, a = a.item.count;
            $("#snh-1").html("<span> "+ (1 > b ? b + a : b > a ? b - a : b) + "</span><span>" + a + "</span>");

        });

        hero_s.append('<div class="slider-nav-warp"><div class="slider-nav"></div></div>');
        $(".hero-slider .owl-nav, .hero-slider .owl-dots").appendTo('.slider-nav');
    });
</script>
