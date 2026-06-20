<?php

use common\helpers\Html;
use common\models\mall\Order;

/* @var $this yii\web\View */
/* @var $store common\models\Store */
/* @var $storeId int */
/* @var $stores array */
/* @var $isPlatformOperator bool */
/* @var $periodStats array */
/* @var $topProducts array */
/* @var $shipmentStats array */
/* @var $overallProducts array */

$this->title = '商家统计';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <?php if ($isPlatformOperator): ?>
                    <div class="card-tools">
                        <form method="get" class="form-inline">
                            <select name="store_id" class="form-control form-control-sm mr-2">
                                <?php foreach ($stores as $id => $name): ?>
                                    <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary btn-sm" type="submit">切换店铺</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    当前店铺：<?= Html::encode($store->name) ?> #<?= (int)$store->id ?>
                </p>

                <div class="row">
                    <?php foreach ($periodStats as $stat): ?>
                        <div class="col-lg-3 col-md-6 col-sm-12">
                            <div class="small-box bg-light">
                                <div class="inner">
                                    <h4><?= Html::encode($stat['label']) ?></h4>
                                    <p class="mb-1">订单数：<?= (int)$stat['orders'] ?></p>
                                    <p class="mb-1">销售额：<?= number_format((float)$stat['amount'], 2) ?></p>
                                    <p class="mb-1">销售件数：<?= (int)$stat['items'] ?></p>
                                    <p class="mb-1">浏览量：<?= (int)$stat['visits'] ?></p>
                                    <p class="mb-1">客单价：<?= number_format((float)$stat['avg_order_amount'], 2) ?></p>
                                    <p class="mb-1">件均价：<?= number_format((float)$stat['avg_item_amount'], 2) ?></p>
                                    <p class="mb-0">浏览转化率：<?= number_format((float)$stat['visit_order_rate'] * 100, 2) ?>%</p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row">
                    <div class="col-lg-6 col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">商品概览</h3>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-hover mb-0">
                                    <tbody>
                                    <tr>
                                        <th>商品数</th>
                                        <td><?= (int)$overallProducts['products'] ?></td>
                                    </tr>
                                    <tr>
                                        <th>商品累计销量</th>
                                        <td><?= (int)$overallProducts['sales'] ?></td>
                                    </tr>
                                    <tr>
                                        <th>商品累计浏览</th>
                                        <td><?= (int)$overallProducts['clicks'] ?></td>
                                    </tr>
                                    <tr>
                                        <th>库存合计</th>
                                        <td><?= (int)$overallProducts['stock'] ?></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">物流状态</h3>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-hover mb-0">
                                    <tbody>
                                    <?php foreach (Order::getShipmentStatusLabels() as $status => $label): ?>
                                        <tr>
                                            <th><?= Html::encode($label) ?></th>
                                            <td><?= (int)($shipmentStats[$status] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">商品销量排行</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>商品</th>
                                <th>订单件数</th>
                                <th>订单金额</th>
                                <th>商品销量</th>
                                <th>浏览量</th>
                                <th>库存</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$topProducts): ?>
                                <tr>
                                    <td colspan="7" class="text-muted text-center">暂无商品统计数据</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($topProducts as $product): ?>
                                <tr>
                                    <td><?= (int)$product['id'] ?></td>
                                    <td><?= Html::encode($product['name']) ?></td>
                                    <td><?= (int)$product['ordered_items'] ?></td>
                                    <td><?= number_format((float)$product['ordered_amount'], 2) ?></td>
                                    <td><?= (int)$product['sales'] ?></td>
                                    <td><?= (int)$product['click'] ?></td>
                                    <td><?= (int)$product['stock'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
