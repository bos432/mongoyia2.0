<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $limit int */
/* @var $stores array */
/* @var $result array */
/* @var $applied bool */

$this->title = '物流费财务复核';
$this->params['breadcrumbs'][] = $this->title;

$statusClass = [
    'dry-run' => 'info',
    'applied' => 'success',
    'blocked' => 'danger',
    'report-only' => 'secondary',
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
                        <button class="btn btn-primary btn-sm" type="submit">预览</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <?php if ($applied): ?>
                    <div class="alert alert-success">已执行本次物流费调账，请复核下方结果和预存金流水。</div>
                <?php endif; ?>
                <div class="row">
                    <div class="col-md-2"><strong>扫描订单：</strong><?= (int)$result['ordersWithFee'] ?></div>
                    <div class="col-md-2"><strong>可调账：</strong><?= (int)$result['adjustable'] ?></div>
                    <div class="col-md-2"><strong>已执行：</strong><?= (int)$result['applied'] ?></div>
                    <div class="col-md-2"><strong>阻断：</strong><?= (int)$result['blocked'] ?></div>
                    <div class="col-md-2"><strong>仅报告：</strong><?= (int)$result['reported'] ?></div>
                    <div class="col-md-2"><strong>预计金额：</strong><?= number_format((float)$result['plannedAmount'], 2) ?></div>
                </div>
                <hr>
                <form method="post" action="<?= Url::to(['apply']) ?>" data-mongoyia-logistics-fee-review-post-guard="1">
                    <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                    <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                    <input type="hidden" name="limit" value="<?= (int)$limit ?>">
                    <button class="btn btn-danger btn-sm" type="submit" <?= (int)$result['adjustable'] <= 0 ? 'disabled' : '' ?>>执行调账</button>
                    <a class="btn btn-default btn-sm" href="<?= Url::to(['/mall/merchant-deposit/index', 'store_id' => $storeId]) ?>">查看预存金流水</a>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>订单</th>
                        <th>店铺</th>
                        <th>状态</th>
                        <th>原因</th>
                        <th>物流费</th>
                        <th>已记流水</th>
                        <th>调账金额</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($result['rows'])): ?>
                        <tr><td colspan="7" class="text-muted text-center">暂无需要复核的物流费异常</td></tr>
                    <?php endif; ?>
                    <?php foreach ($result['rows'] as $row): ?>
                        <?php $badge = $statusClass[$row['status']] ?? 'secondary'; ?>
                        <tr>
                            <td>
                                #<?= (int)$row['id'] ?><br>
                                <small class="text-muted"><?= Html::encode($row['sn'] ?? '') ?></small>
                            </td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><span class="badge badge-<?= $badge ?>"><?= Html::encode($row['status']) ?></span></td>
                            <td><?= Html::encode($row['reason']) ?></td>
                            <td><?= number_format((float)$row['fee'], 2) ?></td>
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
