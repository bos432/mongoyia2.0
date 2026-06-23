<?php

use common\helpers\Html;

/* @var $this yii\web\View */
/* @var $snapshot array */
/* @var $isPlatformOperator bool */

$this->title = '支付统计';
$this->params['breadcrumbs'][] = $this->title;

$filter = $snapshot['filter'] ?? [];
$summary = $snapshot['summary'] ?? [];
$statusClass = [
    'PASS' => 'success',
    'WARN' => 'warning',
    'FAIL' => 'danger',
    'PENDING' => 'secondary',
];
?>

<div class="card" data-mongoyia-payment-statistics="<?= Html::encode($snapshot['version'] ?? '') ?>">
    <div class="card-header">
        <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
    </div>
    <div class="card-body">
        <?= Html::beginForm(['index'], 'get', ['class' => 'form-inline']) ?>
        <label class="mr-2">开始日期</label>
        <?= Html::textInput('start_date', $filter['start_date'] ?? '', ['class' => 'form-control mr-2', 'placeholder' => 'YYYY-MM-DD']) ?>
        <label class="mr-2">结束日期</label>
        <?= Html::textInput('end_date', $filter['end_date'] ?? '', ['class' => 'form-control mr-2', 'placeholder' => 'YYYY-MM-DD']) ?>
        <?php if ($isPlatformOperator): ?>
            <label class="mr-2">店铺 ID</label>
            <?= Html::textInput('store_id', (int)($filter['store_id'] ?? 0) > 0 ? (int)$filter['store_id'] : '', ['class' => 'form-control mr-2', 'placeholder' => '全部']) ?>
        <?php endif; ?>
        <?= Html::submitButton('筛选', ['class' => 'btn btn-primary']) ?>
        <?= Html::endForm() ?>
        <p class="text-muted mt-2 mb-0">
            统计来自支付审计表，只读展示每日金额、支付方式分布、失败原因、回调异常和对账差异；不调用支付服务商，不修改订单、资金或回调状态。
        </p>
    </div>
</div>

<div class="row" data-mongoyia-payment-statistics-summary="1">
    <?php
    $cards = [
        '审计记录' => (int)($summary['attempt_count'] ?? 0),
        '成功笔数' => (int)($summary['success_count'] ?? 0),
        '失败笔数' => (int)($summary['failed_count'] ?? 0),
        '重复/忽略' => (int)($summary['ignored_count'] ?? 0),
        '成功金额' => number_format((float)($summary['success_amount'] ?? 0), 2),
        '失败金额' => number_format((float)($summary['failed_amount'] ?? 0), 2),
    ];
    ?>
    <?php foreach ($cards as $label => $value): ?>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small"><?= Html::encode($label) ?></div>
                    <div class="h4 mb-0"><?= Html::encode((string)$value) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card" data-mongoyia-payment-statistics-daily="1">
            <div class="card-header"><h3 class="card-title">每日支付</h3></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>日期</th><th>记录</th><th>成功</th><th>失败</th><th>成功金额</th></tr></thead>
                    <tbody>
                    <?php foreach (($snapshot['daily_rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= Html::encode($row['day']) ?></td>
                            <td><?= (int)$row['attempt_count'] ?></td>
                            <td><?= (int)$row['success_count'] ?></td>
                            <td><?= (int)$row['failed_count'] ?></td>
                            <td><?= number_format((float)$row['success_amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($snapshot['daily_rows'])): ?><tr><td colspan="5" class="text-center text-muted">暂无数据</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card" data-mongoyia-payment-statistics-provider="1">
            <div class="card-header"><h3 class="card-title">支付方式分布</h3></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>渠道</th><th>事件</th><th>记录</th><th>成功</th><th>失败</th><th>忽略</th><th>成功金额</th></tr></thead>
                    <tbody>
                    <?php foreach (($snapshot['provider_rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= Html::encode($row['provider']) ?></td>
                            <td><?= Html::encode($row['event']) ?></td>
                            <td><?= (int)$row['attempt_count'] ?></td>
                            <td><?= (int)$row['success_count'] ?></td>
                            <td><?= (int)$row['failed_count'] ?></td>
                            <td><?= (int)$row['ignored_count'] ?></td>
                            <td><?= number_format((float)$row['success_amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($snapshot['provider_rows'])): ?><tr><td colspan="7" class="text-center text-muted">暂无数据</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card" data-mongoyia-payment-statistics-failure="1">
            <div class="card-header"><h3 class="card-title">失败原因</h3></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>渠道</th><th>事件</th><th>原因</th><th>次数</th><th>最近时间</th></tr></thead>
                    <tbody>
                    <?php foreach (($snapshot['failure_rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= Html::encode($row['provider']) ?></td>
                            <td><?= Html::encode($row['event']) ?></td>
                            <td><?= Html::encode($row['error_message']) ?></td>
                            <td><?= (int)$row['failed_count'] ?></td>
                            <td><?= (int)$row['latest_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['latest_at']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($snapshot['failure_rows'])): ?><tr><td colspan="5" class="text-center text-muted">暂无失败记录</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card" data-mongoyia-payment-statistics-anomaly="1">
            <div class="card-header"><h3 class="card-title">回调异常</h3></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>项目</th><th>次数</th><th>状态</th></tr></thead>
                    <tbody>
                    <?php foreach (($snapshot['anomaly_rows'] ?? []) as $row): ?>
                        <?php $badge = $statusClass[$row['severity'] ?? 'PENDING'] ?? 'secondary'; ?>
                        <tr>
                            <td><?= Html::encode($row['label']) ?></td>
                            <td><?= (int)$row['count'] ?></td>
                            <td><span class="badge badge-<?= $badge ?>"><?= Html::encode($row['severity']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card" data-mongoyia-payment-statistics-reconciliation="1">
    <div class="card-header"><h3 class="card-title">对账差异</h3></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>审计ID</th><th>订单ID</th><th>店铺</th><th>渠道</th><th>事件</th><th>审计金额</th><th>订单金额</th><th>原因</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach (($snapshot['reconciliation_rows'] ?? []) as $row): ?>
                <tr>
                    <td><?= (int)$row['attempt_id'] ?></td>
                    <td><?= (int)$row['order_id'] ?></td>
                    <td><?= (int)$row['store_id'] ?></td>
                    <td><?= Html::encode($row['provider']) ?></td>
                    <td><?= Html::encode($row['event']) ?></td>
                    <td><?= number_format((float)$row['attempt_amount'], 2) ?></td>
                    <td><?= number_format((float)$row['order_amount'], 2) ?></td>
                    <td><?= Html::encode($row['reason']) ?></td>
                    <td><?= (int)$row['processed_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['processed_at']) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($snapshot['reconciliation_rows'])): ?><tr><td colspan="9" class="text-center text-muted">暂无对账差异</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
