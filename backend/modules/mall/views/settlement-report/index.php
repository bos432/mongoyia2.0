<?php

use common\helpers\Html;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $dateFrom string */
/* @var $dateTo string */
/* @var $limit int */
/* @var $stores array */
/* @var $report array */

$this->title = '结算报表';
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
                        <input name="date_from" class="form-control form-control-sm mr-2" type="date" value="<?= Html::encode($dateFrom) ?>">
                        <input name="date_to" class="form-control form-control-sm mr-2" type="date" value="<?= Html::encode($dateTo) ?>">
                        <input name="limit" class="form-control form-control-sm mr-2" type="number" min="1" max="1000" value="<?= (int)$limit ?>">
                        <button class="btn btn-primary btn-sm" type="submit">查看报表</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2"><strong>草案数：</strong><?= (int)$report['draftsScanned'] ?></div>
                    <div class="col-md-2"><strong>已关闭：</strong><?= (int)$report['closedDrafts'] ?></div>
                    <div class="col-md-2"><strong>未关闭：</strong><?= (int)$report['openDrafts'] ?></div>
                    <div class="col-md-2"><strong>拟结算：</strong><?= number_format((float)$report['totals']['net_amount'], 2) ?></div>
                    <div class="col-md-2"><strong>已关闭金额：</strong><?= number_format((float)$report['totals']['closed_net_amount'], 2) ?></div>
                    <div class="col-md-2"><strong>凭证金额：</strong><?= number_format((float)$report['totals']['evidence_amount'], 2) ?></div>
                </div>
                <hr>
                <p class="text-muted mb-0">只读报表入口：本页汇总结算草案、关闭状态、凭证金额和未关闭原因，不写资金流水，也不发起真实打款。</p>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>店铺</th>
                        <th>草案数</th>
                        <th>订单数</th>
                        <th>拟结算</th>
                        <th>已关闭金额</th>
                        <th>凭证金额</th>
                        <th>状态分布</th>
                        <th>未关闭原因</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($report['stores'])): ?>
                        <tr><td colspan="8" class="text-muted text-center">暂无结算草案</td></tr>
                    <?php endif; ?>
                    <?php foreach ($report['stores'] as $row): ?>
                        <tr>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= (int)$row['drafts'] ?></td>
                            <td><?= (int)$row['orders'] ?></td>
                            <td><?= number_format((float)$row['net_amount'], 2) ?></td>
                            <td><?= number_format((float)$row['closed_net_amount'], 2) ?></td>
                            <td><?= number_format((float)$row['evidence_amount'], 2) ?></td>
                            <td><?= Html::encode(json_encode($row['statusCounts'], JSON_UNESCAPED_UNICODE)) ?></td>
                            <td><?= Html::encode(json_encode($row['openReasons'], JSON_UNESCAPED_UNICODE)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
