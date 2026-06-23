<?php
use yii\helpers\Url;
use yii\helpers\Html;
use common\models\mall\Product;
use common\models\mall\AttributeItem;

/* @var $this yii\web\View */
/* @var $models \common\models\mall\Cart[] */
/* @var $productAmount float */
/* @var $discount float */
/* @var $total float */

$this->title = Yii::t('mall', 'Shopping Cart');
$this->params['breadcrumbs'][] = $this->title;
?>

<section class="page-section shop-cart" data-mongoyia-mobile-ui="cart">
    <div class="container">
        <?php if (count($models)) { ?>
        <div class="row">
            <div class="col-lg-12">
                <div class="shop-cart-table">
                    <table style="table-layout: fixed;">
                        <thead>
                        <tr>
                            <th><?= Yii::t('app', 'Product') ?></th>
                            <th><?= Yii::t('app', 'Price') ?></th>
                            <th><?= Yii::t('app', 'Quantity') ?></th>
                            <th><?= Yii::t('app', 'Total') ?></th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($models as $model) { $product = Yii::$app->cacheSystemMall->getProductById($model->product_id); ?>
                        <tr data-id="<?= $model->id ?>">
                            <td class="cart-product-item">
                                <?php if ($product) { ?><a href="<?= $this->context->getSeoUrl($product) ?>"><?php } else { ?><span class="text-muted"><?php } ?>
                                    <?= strlen($model->thumb) > 5 ? "<img class=\"c-img\" src='{$model->thumb}'>" : '' ?>
                                    <div class="cart-product-item-title">
                                        <h6>
                                            <?= $product ? fbt(Product::getTableCode(), $product->id, 'name', $model->name) : Html::encode($model->name ?: Yii::t('mall', 'Unavailable product')) ?>
                                            <?php if (strlen($model->product_attribute_value) > 0) {
                                                $arr = [];
                                                $arrProductAttributeValue = explode(',', $model->product_attribute_value);
                                                foreach ($arrProductAttributeValue as $attributeItemId) {
                                                    $attributeItem = Yii::$app->cacheSystemMall->getAttributeItemById($attributeItemId);
                                                    if ($attributeItem) {
                                                        array_push($arr, fbt(AttributeItem::getTableCode(), $attributeItem->id, 'name', $attributeItem->name));
                                                    }
                                                }
                                                if (count($arr) > 0) {
                                                    echo '<span>[' . implode(', ', $arr) . ']</span>';
                                                }
                                            } ?>
                                        </h6>
                                        <div class="rating">
                                            <?= $product ? \common\helpers\UiHelper::renderStar($product->star) : Html::encode(Yii::t('mall', 'Unavailable product')) ?>
                                        </div>
                                    </div>
                                <?= $product ? '</a>' : '</span>' ?>
                            </td>
                            <td class="cart-price"><?= $this->context->getNumberByCurrency($model->price) ?></td>
                            <td class="cart-quantity">
                                <div class="pro-qty" data-id="<?= $model->id ?>">
                                    <span class="dec qtybtn click-btn" data-type="dec">-</span>
                                    <input type="text" value="<?= $model->number ?>" class="number-btn" data-type="mod">
                                    <span class="inc qtybtn click-btn" data-type="inc">+</span>
                                </div>
                            </td>
                            <td class="cart-total"><?= $this->context->getNumberByCurrency($model->price * $model->number) ?></td>
                            <td class="cart-close click-btn" data-type="del"><span class="fa fa-close" data-id="<?= $model->id ?>"></span></td>
                        </tr>
                        <?php } ?>
                        </tbody>
                        <style>
                            @media only screen and (max-width: 991px) {
                                .number-btn{
                                    width:20px !important;
                                }
                                .shop-cart-table tbody tr .cart-quantity .pro-qty{
                                    width:60px
                                }
                                .cart-price{
                                    width:25% !important;
                                }
                                .c-img{
                                    min-width:90px;
                                    margin-right: 0 !important;
                                }
                                .shop-cart-table tbody tr td{
                                    padding: 0;
                                }
                                .pro-qty .qtybtn{
                                    width:20px !important;
                                }
                                .shop-cart-table tbody tr .cart-price, .shop-cart-table tbody tr .cart-total{
                                    word-break: break-all;
                                }
                            }
                        </style>
                    </table>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                <div class="cart-btn">
                    <a href="<?= Url::to(['/']) ?>"><?= Yii::t('mall', 'Go Shopping') ?></a>
                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                <div class="cart-btn update-btn">
                    <a href="<?= Url::to(['/mall/cart/index']) ?>"><span class="fa fa-refresh"></span> <?= Yii::t('app', 'Refresh') ?></a>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-lg-6 mb-3">
<!--                <div class="discount-content">-->
<!--                    <h6>--><?php //= Yii::t('mall', 'Discount code') ?><!--</h6>-->
<!--                    <dd>-->
<!--                        <input type="text" id="coupon-code" placeholder="--><?php //= Yii::t('mall', 'Enter your coupon code') ?><!--">-->
<!--                        <button type="button" class="site-btn" id="coupon-apply">--><?php //= Yii::t('app', 'Apply') ?><!--</button>-->
<!--                    </dd>-->
<!--                </div>-->
            </div>
            <div class="col-lg-4 offset-lg-2">
                <div class="cart-total-procced">
                    <h6><?= Yii::t('mall', 'Cart Total') ?></h6>
                    <ul>
                        <li><?= Yii::t('app', 'Subtotal') ?> <span><?= $this->context->getNumberByCurrency($productAmount) ?></span></li>
                        <?php if ($discount <> 0) { ?><li><li><?= Yii::t('app', 'Discount') ?> <span><?= $this->context->getNumberByCurrency($discount) ?></span></li><?php } ?>
                        <li><?= Yii::t('app', 'Total') ?> <span><?= $this->context->getNumberByCurrency($total) ?></span></li>
                    </ul>
                    <a href="<?= Yii::$app->request->get('coupon') ? Url::to(['/mall/cart/checkout', 'coupon' => Yii::$app->request->get('coupon')]) : Url::to(['/mall/cart/checkout']) ?><?= $cid==0?'':'?cid='.$cid ?>" class="primary-btn"><?= Yii::t('mall', 'Proceed to checkout') ?></a>
                </div>
            </div>
        </div>
        <?php } else { ?>
        <div class="row">
            <div class="col-md-12 text-center">
                <div class="py-5">
                    <h2><?= Yii::t('mall', 'Your cart is currently empty') ?></h2>
                    <p><?= Yii::t('mall', 'Please add some products to your shopping cart before proceeding to checkout.') ?></p>
                </div>
                <div class="pb-5">
                    <a href="<?= Url::to(['/']) ?>" class="site-btn"><?= Yii::t('mall', 'Shopping Now') ?></a>
                </div>
            </div>

        </div>
        <?php } ?>
    </div>
</section>

<script>
$('.click-btn').click(function () {
    let param = {
        id: $(this).parent().data('id'),
        type: $(this).data('type'),
        _csrf: '<?= Yii::$app->request->getCsrfToken() ?>'
    };
    $.post('<?= Url::to(['/mall/cart/update-ajax']) ?>', param, function(data) {
        if (data.code !== 200) {
            Swal.fire(data.msg);
        }
        window.location.reload();
    }, "json");
})
$('.number-btn').change(function () {
    let param = {
        id: $(this).parent().data('id'),
        type: $(this).data('type'),
        number: $(this).val(),
        _csrf: '<?= Yii::$app->request->getCsrfToken() ?>'
    };
    $.post('<?= Url::to(['/mall/cart/update-ajax']) ?>', param, function(data) {
        if (data.code !== 200) {
            Swal.fire(data.msg);
        }
        window.location.reload();
    }, "json");
})
$('#coupon-apply').click(function () {
    let coupon = $('#coupon-code').val();
    if (coupon.length > 0) {
        window.location.href = '<?= Url::to(['/mall/cart/index']) ?>?coupon=' + coupon;
    }
})
</script>
