<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $isPlatformOperator bool */
/* @var $storeId int */
/* @var $stores array */
/* @var $filters array */
/* @var $limit int */
/* @var $report array */
/* @var $dimensions array */
/* @var $ticketTypes array */
/* @var $complaintCategories array */

$this->title = '客服深度统计';
$this->params['breadcrumbs'][] = ['label' => '客服', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => '客服工单', 'url' => ['tickets']];
$this->params['breadcrumbs'][] = $this->title;

$filters = $report['filters'] ?? $filters;
$totals = $report['totals'] ?? [];
$kpis = $report['kpis'] ?? [];
$languageLabels = ['' => '全部语言', 'zh-CN' => '中文', 'en' => '英语', 'mn' => '蒙古语'];
$channelLabels = ['' => '全部渠道', 'buyer' => '买家', 'merchant' => '商家客服', 'platform' => '平台客服', 'im' => 'IM'];
$mediaLabels = [0 => '全部消息', 1 => '文字', 2 => '图片', 3 => '文件', 4 => '视频', 5 => '语音'];
$ticketTypeLabels = ['' => '全部工单', 'order_assist' => '订单协助', 'complaint' => '投诉'];
$renderRows = static function (array $rows, string $emptyText = '暂无数据') {
    if (empty($rows)) {
        echo '<tr><td colspan="4" class="text-muted text-center">' . Html::encode($emptyText) . '</td></tr>';
        return;
    }
    foreach ($rows as $row) {
        $ratio = max(0, min(100, (float)($row['ratio'] ?? 0)));
        echo '<tr>';
        echo '<td>' . Html::encode((string)($row['label'] ?? $row['key'] ?? '')) . '</td>';
        echo '<td class="text-right">' . (int)($row['count'] ?? 0) . '</td>';
        echo '<td class="text-right">' . Html::encode((string)$ratio) . '%</td>';
        echo '<td><div class="progress" style="height: 8px;"><div class="progress-bar" style="width: ' . $ratio . '%"></div></div></td>';
        echo '</tr>';
    }
};
?>

<div class="row" data-mongoyia-customer-service-analytics="phase9">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= Html::encode(Url::to(['tickets', 'store_id' => (int)$storeId])) ?>">返回工单</a>
                    <a
                        class="btn btn-outline-primary btn-sm"
                        data-mongoyia-customer-service-analytics-export="csv"
                        href="<?= Html::encode(Url::to(['analytics-export'] + Yii::$app->request->get())) ?>"
                    >导出 CSV</a>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">MONGOYIA_CUSTOMER_SERVICE_ANALYTICS_V1：按客服、店铺、语言、渠道、时段、消息类型、工单类型和投诉类型做只读统计；定时聚合需走审计化 CLI，不在后台直接覆盖统计。</p>
                <form method="get">
                    <div class="form-row">
                        <?php if ($isPlatformOperator): ?>
                            <div class="form-group col-md-2">
                                <label>店铺</label>
                                <select name="store_id" class="form-control form-control-sm">
                                    <option value="0" <?= (int)$storeId === 0 ? 'selected' : '' ?>>全部店铺</option>
                                    <?php foreach ($stores as $id => $name): ?>
                                        <option value="<?= (int)$id ?>" <?= (int)$storeId === (int)$id ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="form-group col-md-2">
                            <label>开始日期</label>
                            <input name="date_from" class="form-control form-control-sm" value="<?= Html::encode((string)($filters['date_from'] ?? '')) ?>" placeholder="YYYYMMDD">
                        </div>
                        <div class="form-group col-md-2">
                            <label>结束日期</label>
                            <input name="date_to" class="form-control form-control-sm" value="<?= Html::encode((string)($filters['date_to'] ?? '')) ?>" placeholder="YYYYMMDD">
                        </div>
                        <div class="form-group col-md-2">
                            <label>客服ID</label>
                            <input name="service_user_id" type="number" min="0" class="form-control form-control-sm" value="<?= (int)($filters['service_user_id'] ?? 0) ?>">
                        </div>
                        <div class="form-group col-md-2">
                            <label>语言</label>
                            <select name="language" class="form-control form-control-sm">
                                <?php foreach ($languageLabels as $key => $label): ?>
                                    <option value="<?= Html::encode((string)$key) ?>" <?= (string)($filters['language'] ?? '') === (string)$key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>渠道</label>
                            <select name="channel" class="form-control form-control-sm">
                                <?php foreach ($channelLabels as $key => $label): ?>
                                    <option value="<?= Html::encode((string)$key) ?>" <?= (string)($filters['channel'] ?? '') === (string)$key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-2">
                            <label>消息类型</label>
                            <select name="msg_type" class="form-control form-control-sm">
                                <?php foreach ($mediaLabels as $key => $label): ?>
                                    <option value="<?= (int)$key ?>" <?= (int)($filters['msg_type'] ?? 0) === (int)$key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>工单类型</label>
                            <select name="ticket_type" class="form-control form-control-sm">
                                <?php foreach ($ticketTypeLabels as $key => $label): ?>
                                    <option value="<?= Html::encode((string)$key) ?>" <?= (string)($filters['ticket_type'] ?? '') === (string)$key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>投诉类型</label>
                            <select name="complaint_category" class="form-control form-control-sm">
                                <option value="">全部投诉</option>
                                <?php foreach ($complaintCategories as $key => $label): ?>
                                    <option value="<?= Html::encode($key) ?>" <?= (string)($filters['complaint_category'] ?? '') === (string)$key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>首响 SLA</label>
                            <input name="first_response_seconds" type="number" min="60" max="86400" class="form-control form-control-sm" value="<?= (int)($filters['first_response_seconds'] ?? 1800) ?>">
                        </div>
                        <div class="form-group col-md-2">
                            <label>解决 SLA</label>
                            <input name="resolution_seconds" type="number" min="300" max="2592000" class="form-control form-control-sm" value="<?= (int)($filters['resolution_seconds'] ?? 86400) ?>">
                        </div>
                        <div class="form-group col-md-2">
                            <label>扫描上限</label>
                            <input name="limit" type="number" min="1" max="5000" class="form-control form-control-sm" value="<?= (int)$limit ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">查看统计</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row text-center">
                    <?php foreach ([
                        'consultation_count' => '咨询量',
                        'message_count' => '消息数',
                        'media_count' => '媒体消息',
                        'ticket_count' => '工单数',
                        'complaint_count' => '投诉数',
                        'resolved_count' => '已解决',
                    ] as $key => $label): ?>
                        <div class="col-md-2 col-6 mb-2">
                            <div class="border rounded p-2">
                                <div class="h4 mb-0"><?= (int)($totals[$key] ?? 0) ?></div>
                                <small class="text-muted"><?= Html::encode($label) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="row text-center">
                    <?php foreach ([
                        'average_first_response_seconds' => '平均首响(s)',
                        'average_resolution_seconds' => '平均解决(s)',
                        'timeout_rate' => '超时率(%)',
                        'satisfaction_score' => '满意度均分',
                        'translation_failure_rate' => '翻译失败率(%)',
                        'media_send_failure_rate' => '媒体失败率(%)',
                    ] as $key => $label): ?>
                        <div class="col-md-2 col-6 mb-2">
                            <div class="border rounded p-2">
                                <div class="h5 mb-0"><?= Html::encode((string)($kpis[$key] ?? 0)) ?></div>
                                <small class="text-muted"><?= Html::encode($label) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="alert <?= empty($report['issues']) ? 'alert-light' : 'alert-warning' ?> mb-0">
                    <?= empty($report['issues']) ? '统计源可读取；当前报表为只读聚合。' : Html::encode(implode('；', (array)$report['issues'])) ?>
                </div>
            </div>
        </div>

        <div class="row">
            <?php foreach ([
                'languageRows' => '语言分布',
                'channelRows' => '渠道分布',
                'mediaRows' => '消息类型',
                'ticketTypeRows' => '工单类型',
                'complaintTypeRows' => '投诉类型',
                'ratingRows' => '满意度',
            ] as $key => $title): ?>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title"><?= Html::encode($title) ?></h3></div>
                        <div class="card-body p-0 table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>项目</th><th class="text-right">数量</th><th class="text-right">占比</th><th>趋势</th></tr></thead>
                                <tbody><?php $renderRows((array)($report[$key] ?? [])); ?></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row">
            <?php foreach ([
                'staffRankRows' => '客服排行',
                'storeRankRows' => '店铺排行',
                'hourRows' => '高峰时段',
            ] as $key => $title): ?>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title"><?= Html::encode($title) ?></h3></div>
                        <div class="card-body p-0 table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>项目</th><th class="text-right">数量</th><th class="text-right">占比</th><th>趋势</th></tr></thead>
                                <tbody><?php $renderRows((array)($report[$key] ?? [])); ?></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card" data-mongoyia-customer-service-analytics-aggregation="dry-run">
            <div class="card-header"><h3 class="card-title">定时聚合与告警联动</h3></div>
            <div class="card-body">
                <table class="table table-bordered table-sm mb-3">
                    <tbody>
                    <?php foreach (($report['aggregationPlan'] ?? []) as $key => $value): ?>
                        <tr>
                            <th style="width: 260px;"><?= Html::encode((string)$key) ?></th>
                            <td><?= Html::encode(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0">
                        <thead><tr><th>告警信号</th><th>状态</th><th>值</th><th>Phase 7 邮件告警</th></tr></thead>
                        <tbody>
                        <?php foreach (($report['alertSignals'] ?? []) as $signal): ?>
                            <tr>
                                <td><?= Html::encode((string)$signal['key']) ?></td>
                                <td><?= Html::encode((string)$signal['status']) ?></td>
                                <td><?= Html::encode((string)$signal['value']) ?></td>
                                <td><?= !empty($signal['phase7_email_alert_ready']) ? '可接入' : '未接入' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
