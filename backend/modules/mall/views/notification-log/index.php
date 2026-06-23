<?php

use common\helpers\Html;

/* @var $this yii\web\View */
/* @var $snapshot array */
/* @var $isPlatformOperator bool */

$this->title = '通知发送日志';
$this->params['breadcrumbs'][] = ['label' => '运营配置中心', 'url' => ['/mall/operational-config/index']];
$this->params['breadcrumbs'][] = $this->title;

$filter = $snapshot['filter'] ?? [];
$summary = $snapshot['summary'] ?? [];
$events = $snapshot['events'] ?? [];
$eventOptions = [];
foreach ($events as $eventKey => $event) {
    $eventOptions[$eventKey] = $event['label'] ?? $eventKey;
}
$channels = $snapshot['channels'] ?? [];
$statuses = $snapshot['statuses'] ?? [];
$statusClass = [
    'success' => 'success',
    'failed' => 'danger',
    'pending' => 'warning',
    'reserved' => 'secondary',
    'dry_run' => 'info',
];
?>

<div class="card" data-mongoyia-notification-log="<?= Html::encode($snapshot['version'] ?? '') ?>">
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
        <label class="mr-2">事件</label>
        <?= Html::dropDownList('event_key', $filter['event_key'] ?? '', ['' => '全部'] + $eventOptions, ['class' => 'form-control mr-2']) ?>
        <label class="mr-2">渠道</label>
        <?= Html::dropDownList('channel', $filter['channel'] ?? '', ['' => '全部'] + $channels, ['class' => 'form-control mr-2']) ?>
        <label class="mr-2">状态</label>
        <?= Html::dropDownList('delivery_status', $filter['delivery_status'] ?? '', ['' => '全部'] + $statuses, ['class' => 'form-control mr-2']) ?>
        <?= Html::submitButton('筛选', ['class' => 'btn btn-primary']) ?>
        <?= Html::endForm() ?>
        <p class="text-muted mt-2 mb-0">
            这里展示订单、物流、支付、客服回复、投诉结果等通知事件的站内消息和 APP 预留发送日志；不发送短信，不调用推送服务商，不写入任何外部密钥。
        </p>
        <?php if (empty($snapshot['table_exists'])): ?>
            <p class="text-danger mt-2 mb-0">通知日志表尚未创建，请先执行数据库迁移。</p>
        <?php endif; ?>
    </div>
</div>

<div class="row" data-mongoyia-notification-log-summary="1">
    <?php
    $cards = [
        '总记录' => (int)($summary['total'] ?? 0),
        '成功' => (int)($summary['success'] ?? 0),
        '失败' => (int)($summary['failed'] ?? 0),
        '预留' => (int)($summary['reserved'] ?? 0),
        '待处理' => (int)($summary['pending'] ?? 0),
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
    <div class="col-lg-4">
        <div class="card" data-mongoyia-notification-log-events="1">
            <div class="card-header"><h3 class="card-title">事件分布</h3></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>事件</th><th>次数</th><th>最近时间</th></tr></thead>
                    <tbody>
                    <?php foreach (($snapshot['event_rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= Html::encode($events[$row['key']]['label'] ?? $row['key']) ?></td>
                            <td><?= (int)$row['count'] ?></td>
                            <td><?= (int)$row['latest_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['latest_at']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($snapshot['event_rows'])): ?><tr><td colspan="3" class="text-center text-muted">暂无数据</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card" data-mongoyia-notification-log-channels="1">
            <div class="card-header"><h3 class="card-title">渠道分布</h3></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>渠道</th><th>次数</th><th>最近时间</th></tr></thead>
                    <tbody>
                    <?php foreach (($snapshot['channel_rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= Html::encode($channels[$row['key']] ?? $row['key']) ?></td>
                            <td><?= (int)$row['count'] ?></td>
                            <td><?= (int)$row['latest_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['latest_at']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($snapshot['channel_rows'])): ?><tr><td colspan="3" class="text-center text-muted">暂无数据</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card" data-mongoyia-notification-log-statuses="1">
            <div class="card-header"><h3 class="card-title">状态分布</h3></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th>状态</th><th>次数</th><th>最近时间</th></tr></thead>
                    <tbody>
                    <?php foreach (($snapshot['status_rows'] ?? []) as $row): ?>
                        <?php $badge = $statusClass[$row['key']] ?? 'secondary'; ?>
                        <tr>
                            <td><span class="badge badge-<?= $badge ?>"><?= Html::encode($statuses[$row['key']] ?? $row['key']) ?></span></td>
                            <td><?= (int)$row['count'] ?></td>
                            <td><?= (int)$row['latest_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['latest_at']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($snapshot['status_rows'])): ?><tr><td colspan="3" class="text-center text-muted">暂无数据</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card" data-mongoyia-notification-log-recent="1">
    <div class="card-header"><h3 class="card-title">最近通知</h3></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>ID</th><th>店铺</th><th>用户</th><th>事件</th><th>渠道</th><th>标题</th><th>状态</th><th>消息ID</th><th>来源</th><th>追踪号</th><th>时间</th><th>错误摘要</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (($snapshot['recent_rows'] ?? []) as $row): ?>
                <?php $badge = $statusClass[$row['delivery_status']] ?? 'secondary'; ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td><?= (int)$row['store_id'] ?></td>
                    <td><?= (int)$row['user_id'] ?></td>
                    <td><?= Html::encode($events[$row['event_key']]['label'] ?? $row['event_key']) ?></td>
                    <td><?= Html::encode($channels[$row['channel']] ?? $row['channel']) ?></td>
                    <td><?= Html::encode($row['title']) ?></td>
                    <td><span class="badge badge-<?= $badge ?>"><?= Html::encode($statuses[$row['delivery_status']] ?? $row['delivery_status']) ?></span></td>
                    <td><?= (int)$row['message_id'] ?></td>
                    <td><?= Html::encode($row['source']) ?></td>
                    <td><?= Html::encode($row['trace_id']) ?></td>
                    <td><?= (int)$row['sent_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['sent_at']) : '-' ?></td>
                    <td><?= Html::encode($row['error_summary']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($snapshot['recent_rows'])): ?><tr><td colspan="12" class="text-center text-muted">暂无通知日志</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
