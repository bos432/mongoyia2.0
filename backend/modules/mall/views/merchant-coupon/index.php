<?php

use common\helpers\Html;
use common\models\mall\Coupon;
use common\models\mall\StoreCouponParticipation;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $stores array */
/* @var $isPlatformOperator bool */
/* @var $storeCoupons array */
/* @var $platformCoupons array */
/* @var $usageRows array */

$this->title = '商家优惠券';
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
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">本店优惠券</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>名称</th>
                                <th>优惠</th>
                                <th>最低金额</th>
                                <th>开始</th>
                                <th>结束</th>
                                <th>状态</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$storeCoupons): ?>
                                <tr><td colspan="7" class="text-muted text-center">暂无本店优惠券</td></tr>
                            <?php endif; ?>
                            <?php foreach ($storeCoupons as $coupon): ?>
                                <tr>
                                    <td><?= (int)$coupon['id'] ?></td>
                                    <td><?= Html::encode($coupon['name']) ?></td>
                                    <td><?= Html::encode($coupon['money']) ?></td>
                                    <td><?= Html::encode($coupon['min_amount']) ?></td>
                                    <td><?= $coupon['started_at'] ? date('Y-m-d', (int)$coupon['started_at']) : '-' ?></td>
                                    <td><?= $coupon['ended_at'] ? date('Y-m-d', (int)$coupon['ended_at']) : '-' ?></td>
                                    <td><?= Html::encode(Coupon::getStatusLabels((int)$coupon['status'], true)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">平台券参与</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>平台券</th>
                                <th>优惠</th>
                                <th>最低金额</th>
                                <th>有效期</th>
                                <th>参与状态</th>
                                <th>操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$platformCoupons): ?>
                                <tr><td colspan="7" class="text-muted text-center">暂无平台优惠券</td></tr>
                            <?php endif; ?>
                            <?php foreach ($platformCoupons as $coupon): ?>
                                <?php $joined = $coupon['participation_status'] === StoreCouponParticipation::PARTICIPATION_JOINED; ?>
                                <tr>
                                    <td><?= (int)$coupon['id'] ?></td>
                                    <td><?= Html::encode($coupon['name']) ?></td>
                                    <td><?= Html::encode($coupon['money']) ?></td>
                                    <td><?= Html::encode($coupon['min_amount']) ?></td>
                                    <td><?= $coupon['started_at'] ? date('Y-m-d', (int)$coupon['started_at']) : '-' ?> ~ <?= $coupon['ended_at'] ? date('Y-m-d', (int)$coupon['ended_at']) : '-' ?></td>
                                    <td>
                                        <span class="badge badge-<?= $joined ? 'success' : 'secondary' ?>">
                                            <?= Html::encode(StoreCouponParticipation::getParticipationStatusLabels($coupon['participation_status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($joined): ?>
                                            <form method="post" action="<?= Html::encode(Url::to(['leave'])) ?>" class="d-inline" data-mongoyia-merchant-coupon-post-guard="1">
                                                <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                                <input type="hidden" name="coupon_type_id" value="<?= (int)$coupon['id'] ?>">
                                                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                                                <button type="submit" class="btn btn-warning btn-sm">退出</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="<?= Html::encode(Url::to(['join'])) ?>" class="d-inline" data-mongoyia-merchant-coupon-post-guard="1">
                                                <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                                <input type="hidden" name="coupon_type_id" value="<?= (int)$coupon['id'] ?>">
                                                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                                                <button type="submit" class="btn btn-success btn-sm">参与</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">领取/使用记录</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead>
                            <tr>
                                <th>券ID</th>
                                <th>用户</th>
                                <th>名称</th>
                                <th>优惠</th>
                                <th>订单</th>
                                <th>使用时间</th>
                                <th>状态</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$usageRows): ?>
                                <tr><td colspan="7" class="text-muted text-center">暂无领取或使用记录</td></tr>
                            <?php endif; ?>
                            <?php foreach ($usageRows as $row): ?>
                                <tr>
                                    <td><?= (int)$row['coupon_id'] ?></td>
                                    <td><?= (int)$row['user_id'] ?></td>
                                    <td><?= Html::encode($row['name']) ?></td>
                                    <td><?= Html::encode($row['money']) ?></td>
                                    <td><?= (int)$row['order_id'] ?: '-' ?></td>
                                    <td><?= $row['used_at'] ? date('Y-m-d H:i', (int)$row['used_at']) : '-' ?></td>
                                    <td><?= Html::encode(Coupon::getStatusLabels((int)$row['status'], true)) ?></td>
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
