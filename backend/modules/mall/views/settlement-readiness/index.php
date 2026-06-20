<?php

use common\helpers\Html;
use common\models\mall\Order;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $limit int */
/* @var $stores array */
/* @var $result array */

$this->title = '结算就绪复核';
$this->params['breadcrumbs'][] = $this->title;

$statusClass = [
    'ready' => 'success',
    'logistics review pending' => 'warning',
    'logistics fee not deducted' => 'danger',
    'logistics fee not reconciled' => 'danger',
    'refunded order' => 'secondary',
    'not paid/COD' => 'secondary',
];
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <form method="get" class="form-inline">
                        <select name="store_id" class="form-control form-control-sm mr-2">
                            <option value="0" <?= (int)$storeId === 0 ? 'selected' : '' ?>>全部店铺</option>
                            <?php foreach ($stores as $id => $name): ?>
                                <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="limit" class="form-control form-control-sm mr-2" type="number" min="1" max="500" value="<?= (int)$limit ?>">
                        <button class="btn btn-primary btn-sm" type="submit">复核</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2"><strong>扫描订单：</strong><?= (int)$result['scanned'] ?></div>
                    <div class="col-md-2"><strong>可结算：</strong><?= (int)$result['ready'] ?></div>
                    <div class="col-md-2"><strong>待复核：</strong><?= (int)$result['pendingReview'] ?></div>
                    <div class="col-md-2"><strong>费用异常：</strong><?= (int)$result['feeIssues'] ?></div>
                    <div class="col-md-2"><strong>退款排除：</strong><?= (int)$result['refunded'] ?></div>
                    <div class="col-md-2"><strong>可结算金额：</strong><?= number_format((float)$result['readyAmount'], 2) ?></div>
                </div>
                <hr>
                <p class="text-muted mb-0">只读复核入口：订单必须已收货、已支付或货到付款、未退款、物流复核通过，并且物流费扣费流水与订单物流费一致，才会进入可结算。</p>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>订单</th>
                        <th>店铺</th>
                        <th>结算状态</th>
                        <th>支付状态</th>
                        <th>物流复核</th>
                        <th>物流费</th>
                        <th>扣费流水</th>
                        <th>订单金额</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($result['rows'])): ?>
                        <tr><td colspan="8" class="text-muted text-center">暂无已收货订单需要结算复核</td></tr>
                    <?php endif; ?>
                    <?php foreach ($result['rows'] as $row): ?>
                        <?php $badge = $statusClass[$row['reason']] ?? 'secondary'; ?>
                        <tr>
                            <td>
                                #<?= (int)$row['id'] ?><br>
                                <small class="text-muted"><?= Html::encode($row['sn'] ?? '') ?></small>
                            </td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><span class="badge badge-<?= $badge ?>"><?= Html::encode($row['reason']) ?></span></td>
                            <td><?= Html::encode(Order::getPaymentStatusLabels((int)$row['payment_status'])) ?></td>
                            <td><?= Html::encode(Order::getLogisticsReviewStatusLabels((int)$row['logistics_review_status'])) ?></td>
                            <td><?= number_format((float)$row['shipment_fee'], 2) ?></td>
                            <td><?= number_format((float)$row['logTotal'], 2) ?></td>
                            <td><?= number_format((float)$row['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
