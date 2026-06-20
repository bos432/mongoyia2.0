<?php

use common\helpers\Html;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $limit int */
/* @var $stores array */
/* @var $result array */

$this->title = '结算打款计划';
$this->params['breadcrumbs'][] = $this->title;
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
                        <button class="btn btn-primary btn-sm" type="submit">生成计划</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2"><strong>扫描订单：</strong><?= (int)$result['scanned'] ?></div>
                    <div class="col-md-2"><strong>可打款订单：</strong><?= (int)$result['readyOrders'] ?></div>
                    <div class="col-md-2"><strong>阻断订单：</strong><?= (int)$result['blockedOrders'] ?></div>
                    <div class="col-md-2"><strong>订单金额：</strong><?= number_format((float)$result['readyAmount'], 2) ?></div>
                    <div class="col-md-2"><strong>已扣物流费：</strong><?= number_format((float)$result['shipmentFeeDeducted'], 2) ?></div>
                    <div class="col-md-2"><strong>计划打款：</strong><?= number_format((float)$result['netPayoutAmount'], 2) ?></div>
                </div>
                <hr>
                <p class="text-muted mb-0">只读计划入口：本页只汇总已通过结算门禁的订单，不创建结算单，不写资金流水，也不发起真实打款。</p>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>店铺</th>
                        <th>可打款订单</th>
                        <th>订单金额</th>
                        <th>已扣物流费</th>
                        <th>计划打款</th>
                        <th>订单ID</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($result['stores'])): ?>
                        <tr><td colspan="6" class="text-muted text-center">暂无可生成打款计划的订单</td></tr>
                    <?php endif; ?>
                    <?php foreach ($result['stores'] as $row): ?>
                        <tr>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= (int)$row['orders'] ?></td>
                            <td><?= number_format((float)$row['orderAmount'], 2) ?></td>
                            <td><?= number_format((float)$row['shipmentFeeDeducted'], 2) ?></td>
                            <td><?= number_format((float)$row['netPayoutAmount'], 2) ?></td>
                            <td><?= Html::encode(implode(',', $row['orderIds'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">阻断订单</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>订单</th>
                        <th>店铺</th>
                        <th>金额</th>
                        <th>阻断原因</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($result['blockedRows'])): ?>
                        <tr><td colspan="4" class="text-muted text-center">暂无阻断订单</td></tr>
                    <?php endif; ?>
                    <?php foreach ($result['blockedRows'] as $row): ?>
                        <tr>
                            <td>
                                #<?= (int)$row['id'] ?><br>
                                <small class="text-muted"><?= Html::encode($row['sn']) ?></small>
                            </td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= number_format((float)$row['amount'], 2) ?></td>
                            <td><span class="badge badge-warning"><?= Html::encode($row['reason']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
