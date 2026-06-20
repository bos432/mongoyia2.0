<?php
use yii\helpers\Url;
use common\models\mall\Order;
use common\models\mall\Product;
use common\models\mall\AttributeItem;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $models \common\models\mall\Cart[] */
/* @var $order \common\models\mall\Order */
/* @var $orderProduct \common\models\mall\OrderProduct */
/* @var $canReview bool */
/* @var $hasReview bool */
/* @var $discount float */
/* @var $total float */

$this->title = Yii::t('mall', 'Order Detail');
$this->params['breadcrumbs'][] = $this->title;
?>

<section class="page-section shop-cart order-detail" data-mongoyia-mobile-ui="order-detail">
    <div class="container">
        <a href="/mall/product/<?=$orderProduct->product_id;?>" class="order-goods">
            <img src="<?= Html::encode($orderProduct->thumb);?>" alt="" style="width: 200px;max-width: 100%;"><br>
            <span><?= Html::encode($orderProduct->sku);?></span>
        </a>
        <div class="order-info">
            <div class="o-i-item">
                <span>订单编号：</span>
                <span>
                    <?= Html::encode($order->sn);?>
                </span>
            </div>
            <div class="o-i-item">
                <span>下单时间：</span>
                <span>
                    <?= date('Y-m-d H:i:s',$order->created_at);?>
                </span>
            </div>
            <div class="o-i-item">
                <span>物流公司：</span>
                <span>
                    <?= Html::encode($order->shipment_name);?>
                </span>
            </div>
            <div class="o-i-item">
                <span>物流单号：</span>
                <span>
                    <?= Html::encode($order->shipment_id);?>
                </span>
            </div>
            <div class="o-i-item">
                <span>支付状态：</span>
                <span>
                    <?= \common\helpers\Html::color($order->status, Order::getStatusLabels($order->payment_status), [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD, Order::SHIPMENT_STATUS_SHIPPING, Order::SHIPMENT_STATUS_RECEIVED], [Order::PAYMENT_STATUS_UNPAID]) ?>
                </span>
            </div>
            <div class="o-i-item">
                <span>配送状态：</span>
                <span>
                    <?= \common\helpers\Html::color($order->shipment_status, Order::getStatusLabels($order->shipment_status), [Order::SHIPMENT_STATUS_UNSHIPPED, Order::SHIPMENT_STATUS_PREPARING, Order::SHIPMENT_STATUS_SHIPPING, Order::SHIPMENT_STATUS_RECEIVED], [Order::PAYMENT_STATUS_UNPAID]) ?>
                </span>
            </div>
            <?php if ((int)$order->shipment_status === Order::SHIPMENT_STATUS_SHIPPING) {
                echo Html::beginForm(['/mall/order/review', 'id' => $order->id], 'post', ['class' => 'd-inline']);
                echo Html::submitButton(Yii::t('app', 'Received'), ['class' => 'btn btn-sm btn-theme ml-3']);
                echo Html::endForm();
            } elseif ((int)$order->shipment_status < Order::SHIPMENT_STATUS_SHIPPING && in_array((int)$order->payment_status, [Order::PAYMENT_STATUS_PAID, Order::PAYMENT_STATUS_COD], true)) {
                echo Html::tag('span', Yii::t('app', 'Awaiting shipment'), ['class' => 'btn btn-sm btn-secondary ml-3 disabled']);
            } ?>
        </div>
        <?php if($canReview){?>
        <br/>
        <br/>
        <h4><?= Yii::t('app', 'Write a review') ?></h4>
        <form action="" method="post">
            <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
            <div class="order-detail-rating" style="display: inline-flex;">
                <?= Yii::t('app', 'Rating') ?>:
                5<input name="star" type="radio" value="5" class="pf">
                4<input name="star" type="radio" value="4" class="pf">
                3<input name="star" type="radio" value="3" class="pf">
                2<input name="star" type="radio" value="2" class="pf">
                1<input name="star" type="radio" value="1" class="pf">
            </div>
            <br/>
            <textarea name="content" id="" cols="30" rows="10" class="order-detail-review"></textarea>
            <input type="submit" value="<?= Yii::t('app', 'Submit') ?>">
        </form>
        <?php } elseif ($hasReview) { ?>
        <br/>
        <br/>
        <h4><?= Yii::t('app', 'Reviewed') ?></h4>
        <?php } elseif ((int)$order->shipment_status === Order::SHIPMENT_STATUS_SHIPPING) { ?>
        <br/>
        <br/>
        <h4><?= Yii::t('app', 'Review after receiving') ?></h4>
        <?php } ?>
        <style>
            .order-info a{
                margin-left:0 !important;
            }
            .pf{
                margin-right: 1vw;
                margin-left: 0.3vw;
            }
        </style>
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
