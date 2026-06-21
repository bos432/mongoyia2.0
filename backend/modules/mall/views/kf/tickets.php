<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $isPlatformOperator bool */
/* @var $storeId int */
/* @var $limit int */
/* @var $filters array */
/* @var $slaOptions array */
/* @var $slaReadiness array */
/* @var $slaHandling array */
/* @var $statDashboard array */
/* @var $stores array */
/* @var $ticketTypes array */
/* @var $ticketStatuses array */
/* @var $tickets array */
/* @var $statRows array */

$this->title = '客服工单';
$this->params['breadcrumbs'][] = ['label' => '客服', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

$typeLabels = [
    'order_assist' => '订单协助',
    'complaint' => '投诉',
];
$statusLabels = [
    'pending' => '待处理',
    'in_progress' => '处理中',
    'resolved' => '已解决',
    'closed' => '已关闭',
];
$slaActionLabels = [
    'first_response_overdue' => '首响超时',
    'resolution_overdue' => '解决超时',
    'result_writeback_required' => '缺处理结果',
    'first_response_watch' => '首响即将超时',
    'resolution_watch' => '解决即将超时',
    'no_action' => '正常',
];
$slaReadinessTotals = $slaReadiness['totals'] ?? [];
$slaHandlingTotals = $slaHandling['totals'] ?? [];
$statTotals = $statDashboard['totals'] ?? [];
$avgFirstResponseSeconds = (int)($statTotals['ticket_count'] ?? 0) > 0 ? round((int)($statTotals['first_response_seconds_total'] ?? 0) / max(1, (int)$statTotals['ticket_count'])) : 0;
$avgResolvedSeconds = (int)($statTotals['resolved_count'] ?? 0) > 0 ? round((int)($statTotals['resolved_seconds_total'] ?? 0) / max(1, (int)$statTotals['resolved_count'])) : 0;
$resolvedBase = (int)($statTotals['resolved_count'] ?? 0) + (int)($statTotals['unresolved_count'] ?? 0);
$resolvedRate = $resolvedBase > 0 ? round(((int)($statTotals['resolved_count'] ?? 0) / $resolvedBase) * 100, 1) : 0;
?>

<div class="row" data-mongoyia-customer-service-ticket-readonly="index">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <form method="get" class="form-inline">
                        <?php if ($isPlatformOperator): ?>
                            <select name="store_id" class="form-control form-control-sm mr-2">
                                <option value="0" <?= (int)$storeId === 0 ? 'selected' : '' ?>>全部店铺</option>
                                <?php foreach ($stores as $id => $name): ?>
                                    <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <select name="ticket_type" class="form-control form-control-sm mr-2">
                            <option value="">全部类型</option>
                            <?php foreach ($ticketTypes as $type): ?>
                                <option value="<?= Html::encode($type) ?>" <?= (string)$filters['ticket_type'] === (string)$type ? 'selected' : '' ?>><?= Html::encode($typeLabels[$type] ?? $type) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="ticket_status" class="form-control form-control-sm mr-2">
                            <option value="">全部状态</option>
                            <?php foreach ($ticketStatuses as $status): ?>
                                <option value="<?= Html::encode($status) ?>" <?= (string)$filters['ticket_status'] === (string)$status ? 'selected' : '' ?>><?= Html::encode($statusLabels[$status] ?? $status) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="first_response_seconds" class="form-control form-control-sm mr-2" type="number" min="60" max="86400" title="首响 SLA 秒数" value="<?= (int)($slaOptions['first_response_seconds'] ?? 1800) ?>">
                        <input name="resolution_seconds" class="form-control form-control-sm mr-2" type="number" min="300" max="2592000" title="解决 SLA 秒数" value="<?= (int)($slaOptions['resolution_seconds'] ?? 86400) ?>">
                        <input name="watch_window_seconds" class="form-control form-control-sm mr-2" type="number" min="60" max="86400" title="即将超时窗口秒数" value="<?= (int)($slaOptions['watch_window_seconds'] ?? 3600) ?>">
                        <input name="limit" class="form-control form-control-sm mr-2" type="number" min="1" max="500" value="<?= (int)$limit ?>">
                        <button class="btn btn-primary btn-sm" type="submit">查看工单</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">MONGOYIA_CUSTOMER_SERVICE_TICKET_READONLY_V1：本页只读展示列表、详情、事件和日统计；MONGOYIA_CUSTOMER_SERVICE_TICKET_CREATE_BACKEND_V1：本页可创建待处理工单；MONGOYIA_CUSTOMER_SERVICE_STAT_EXPORT_BACKEND_V1：统计导出只读；MONGOYIA_CUSTOMER_SERVICE_STAT_WIDGET_READINESS_V1：统计写入 Widget 仅做 readiness，占位按钮禁用；MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_GATE_V1：统计重算写入仅做 dry-run/apply gate，占位按钮禁用；MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_WORKFLOW_V1：统计重算写入 workflow 仅支持 CLI 显式 apply，后台占位按钮禁用；MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_REVIEW_V1：统计写入审计后台只读；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EXPORT_BACKEND_V1：投诉证据导出只读；MONGOYIA_CUSTOMER_SERVICE_RESOLUTION_EXPORT_BACKEND_V1：解决结果导出只读；MONGOYIA_CUSTOMER_SERVICE_SLA_READINESS_BACKEND_V1：SLA就绪导出只读；MONGOYIA_CUSTOMER_SERVICE_SLA_HANDLING_BACKEND_V1：SLA处理建议导出只读；MONGOYIA_CUSTOMER_SERVICE_RESULT_SIGNOFF_BACKEND_V1：结果签字导出只读；不会变更订单、不会发送 IM 消息、不会上传文件。</p>
            </div>
        </div>

        <div class="card" data-mongoyia-customer-service-ticket-create="form">
            <div class="card-header">
                <h3 class="card-title">创建工单</h3>
            </div>
            <div class="card-body">
                <form method="post" action="<?= Html::encode(Url::to(['ticket-create'])) ?>">
                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                    <div class="form-row">
                        <div class="form-group col-md-2">
                            <label>类型</label>
                            <select name="ticket_type" class="form-control form-control-sm" required>
                                <?php foreach ($ticketTypes as $type): ?>
                                    <option value="<?= Html::encode($type) ?>"><?= Html::encode($typeLabels[$type] ?? $type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isPlatformOperator): ?>
                            <div class="form-group col-md-2">
                                <label>店铺</label>
                                <select name="store_id" class="form-control form-control-sm" required>
                                    <option value="">选择店铺</option>
                                    <?php foreach ($stores as $id => $name): ?>
                                        <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="form-group col-md-2">
                            <label>订单ID</label>
                            <input name="order_id" class="form-control form-control-sm" type="number" min="0" value="0">
                        </div>
                        <div class="form-group col-md-2">
                            <label>订单号</label>
                            <input name="order_sn" class="form-control form-control-sm" type="text" maxlength="64">
                        </div>
                        <div class="form-group col-md-2">
                            <label>商品ID</label>
                            <input name="product_id" class="form-control form-control-sm" type="number" min="0" value="0">
                        </div>
                        <div class="form-group col-md-2">
                            <label>用户ID</label>
                            <input name="customer_user_id" class="form-control form-control-sm" type="number" min="0" value="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>用户会话</label>
                            <input name="customer_uuid" class="form-control form-control-sm" type="text" maxlength="128">
                        </div>
                        <div class="form-group col-md-3">
                            <label>聊天会话</label>
                            <input name="chat_uuid" class="form-control form-control-sm" type="text" maxlength="128">
                        </div>
                        <?php if ($isPlatformOperator): ?>
                            <div class="form-group col-md-2">
                                <label>商家客服ID</label>
                                <input name="merchant_user_id" class="form-control form-control-sm" type="number" min="0" value="0">
                            </div>
                        <?php endif; ?>
                        <div class="form-group col-md-4">
                            <label>标题</label>
                            <input name="title" class="form-control form-control-sm" type="text" maxlength="255" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>内容</label>
                        <textarea name="content" class="form-control form-control-sm" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">创建工单</button>
                </form>
            </div>
        </div>

        <div class="card" data-mongoyia-customer-service-sla-dashboard="phase8">
            <div class="card-header">
                <h3 class="card-title">SLA 看板</h3>
                <div class="card-tools">
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-export-sla="csv"
                        href="<?= Html::encode(Url::to(['sla-readiness', 'store_id' => (int)$storeId, 'ticket_type' => (string)$filters['ticket_type'], 'first_response_seconds' => (int)($slaOptions['first_response_seconds'] ?? 1800), 'resolution_seconds' => (int)($slaOptions['resolution_seconds'] ?? 86400), 'limit' => (int)$limit])) ?>"
                    >导出SLA CSV</a>
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-export-sla-handling="csv"
                        href="<?= Html::encode(Url::to(['sla-handling', 'store_id' => (int)$storeId, 'ticket_type' => (string)$filters['ticket_type'], 'first_response_seconds' => (int)($slaOptions['first_response_seconds'] ?? 1800), 'resolution_seconds' => (int)($slaOptions['resolution_seconds'] ?? 86400), 'watch_window_seconds' => (int)($slaOptions['watch_window_seconds'] ?? 3600), 'limit' => (int)$limit])) ?>"
                    >导出处理建议 CSV</a>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted">MONGOYIA_CUSTOMER_SERVICE_SLA_DASHBOARD_PHASE8_V1：本看板只读展示首响超时、解决超时、即将超时和缺处理结果；告警发送依赖 Phase 7 邮件告警配置，页面不自动关闭、不赔付、不升级资金处理。</p>
                <div class="row text-center">
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0"><?= (int)($slaReadinessTotals['ticket_count'] ?? 0) ?></div>
                            <small class="text-muted">扫描工单</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0 text-danger"><?= (int)($slaHandlingTotals['first_response_overdue_count'] ?? 0) ?></div>
                            <small class="text-muted">首响超时</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0 text-danger"><?= (int)($slaHandlingTotals['resolution_overdue_count'] ?? 0) ?></div>
                            <small class="text-muted">解决超时</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0 text-warning"><?= (int)($slaHandlingTotals['first_response_watch_count'] ?? 0) + (int)($slaHandlingTotals['resolution_watch_count'] ?? 0) ?></div>
                            <small class="text-muted">即将超时</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0 text-info"><?= (int)($slaReadinessTotals['missing_result_count'] ?? 0) ?></div>
                            <small class="text-muted">缺处理结果</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0"><?= (int)($slaHandlingTotals['action_required_count'] ?? 0) ?></div>
                            <small class="text-muted">需处理</small>
                        </div>
                    </div>
                </div>
                <?php $alertCount = (int)($slaHandlingTotals['first_response_overdue_count'] ?? 0) + (int)($slaHandlingTotals['resolution_overdue_count'] ?? 0); ?>
                <div class="alert <?= $alertCount > 0 ? 'alert-warning' : 'alert-light' ?> mb-3">
                    <?= $alertCount > 0 ? '存在 SLA 超时工单，若 Phase 7 邮件告警已启用，可按阈值发送超时告警。' : '当前筛选范围内没有 SLA 超时工单。' ?>
                </div>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>工单</th>
                        <th>店铺</th>
                        <th>状态</th>
                        <th>首响耗时</th>
                        <th>解决耗时</th>
                        <th>处理建议</th>
                        <th>标题</th>
                        <th>详情</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $visibleSlaRows = 0; ?>
                    <?php foreach (($slaHandling['rows'] ?? []) as $row): ?>
                        <?php if ((string)($row['suggested_action'] ?? 'no_action') === 'no_action') { continue; } ?>
                        <?php $visibleSlaRows++; ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?><br><small class="text-muted"><?= Html::encode($row['ticket_sn'] ?? '') ?></small></td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= Html::encode($statusLabels[$row['ticket_status']] ?? $row['ticket_status']) ?></td>
                            <td><?= (int)$row['first_response_seconds'] ?>s</td>
                            <td><?= (int)$row['resolution_seconds'] ?>s</td>
                            <td><span class="badge badge-warning"><?= Html::encode($slaActionLabels[$row['suggested_action']] ?? $row['suggested_action']) ?></span></td>
                            <td><?= Html::encode($row['title'] ?? '') ?></td>
                            <td><a class="btn btn-outline-primary btn-sm" href="<?= Html::encode(Url::to(['ticket-view', 'id' => (int)$row['id'], 'store_id' => (int)$storeId])) ?>">查看</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($visibleSlaRows === 0): ?>
                        <tr><td colspan="8" class="text-muted text-center">暂无需要处理的 SLA 工单</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>工单号</th>
                        <th>类型</th>
                        <th>状态</th>
                        <th>优先级</th>
                        <th>店铺</th>
                        <th>订单</th>
                        <th>用户</th>
                        <th>创建时间</th>
                        <th>详情</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr><td colspan="9" class="text-muted text-center">暂无客服工单</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $row): ?>
                        <tr>
                            <td>
                                #<?= (int)$row['id'] ?><br>
                                <small class="text-muted"><?= Html::encode($row['ticket_sn']) ?></small>
                            </td>
                            <td><?= Html::encode($typeLabels[$row['ticket_type']] ?? $row['ticket_type']) ?></td>
                            <td><span class="badge badge-info"><?= Html::encode($statusLabels[$row['ticket_status']] ?? $row['ticket_status']) ?></span></td>
                            <td><?= Html::encode($row['priority']) ?></td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td>
                                <?= (int)$row['order_id'] ?><br>
                                <small class="text-muted"><?= Html::encode($row['order_sn'] ?? '') ?></small>
                            </td>
                            <td>
                                <?= (int)$row['customer_user_id'] ?><br>
                                <small class="text-muted"><?= Html::encode($row['customer_uuid'] ?? '') ?></small>
                            </td>
                            <td><?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?></td>
                            <td><a class="btn btn-outline-primary btn-sm" href="<?= Html::encode(Url::to(['ticket-view', 'id' => (int)$row['id'], 'store_id' => (int)$storeId])) ?>">查看</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">客服日统计预览</h3>
                <div class="card-tools">
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-export-stat="csv"
                        href="<?= Html::encode(Url::to(['stat-export', 'store_id' => (int)$storeId, 'limit' => 60])) ?>"
                    >导出统计 CSV</a>
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-export-complaint="csv"
                        href="<?= Html::encode(Url::to(['complaint-export', 'store_id' => (int)$storeId, 'ticket_status' => (string)$filters['ticket_status'], 'limit' => 100])) ?>"
                    >导出投诉 CSV</a>
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-export-resolution="csv"
                        href="<?= Html::encode(Url::to(['resolution-export', 'store_id' => (int)$storeId, 'ticket_type' => (string)$filters['ticket_type'], 'limit' => 100])) ?>"
                    >导出解决 CSV</a>
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-export-sla="csv"
                        href="<?= Html::encode(Url::to(['sla-readiness', 'store_id' => (int)$storeId, 'ticket_type' => (string)$filters['ticket_type'], 'limit' => 100])) ?>"
                    >导出SLA CSV</a>
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-export-sla-handling="csv"
                        href="<?= Html::encode(Url::to(['sla-handling', 'store_id' => (int)$storeId, 'ticket_type' => (string)$filters['ticket_type'], 'limit' => 100])) ?>"
                    >导出SLA处理建议 CSV</a>
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-export-result-signoff="csv"
                        href="<?= Html::encode(Url::to(['result-signoff', 'store_id' => (int)$storeId, 'ticket_type' => (string)$filters['ticket_type'], 'limit' => 100])) ?>"
                    >导出结果签字 CSV</a>
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        data-mongoyia-customer-service-stat-apply-log-review="link"
                        href="<?= Html::encode(Url::to(['stat-apply-log', 'store_id' => (int)$storeId, 'limit' => 100])) ?>"
                    >查看写入审计</a>
                </div>
            </div>
            <div class="card-body" data-mongoyia-customer-service-stat-dashboard="phase8">
                <p class="text-muted">MONGOYIA_CUSTOMER_SERVICE_STAT_DASHBOARD_PHASE8_V1：本看板只读展示客服统计，统计写入和重算仍走审计流程，不在页面直接覆盖。</p>
                <div class="row text-center">
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0"><?= (int)($statTotals['session_count'] ?? 0) ?></div>
                            <small class="text-muted">会话数</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0"><?= (int)($statTotals['ticket_count'] ?? 0) ?></div>
                            <small class="text-muted">工单数</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0"><?= (int)($statTotals['complaint_count'] ?? 0) ?></div>
                            <small class="text-muted">投诉数</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0 text-success"><?= (int)($statTotals['resolved_count'] ?? 0) ?></div>
                            <small class="text-muted">已解决</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0 text-warning"><?= (int)($statTotals['unresolved_count'] ?? 0) ?></div>
                            <small class="text-muted">未解决</small>
                        </div>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h4 mb-0"><?= $resolvedRate ?>%</div>
                            <small class="text-muted">解决率</small>
                        </div>
                    </div>
                </div>
                <div class="row text-center">
                    <div class="col-md-3 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h5 mb-0"><?= (int)$avgFirstResponseSeconds ?>s</div>
                            <small class="text-muted">平均首响</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h5 mb-0"><?= (int)$avgResolvedSeconds ?>s</div>
                            <small class="text-muted">平均解决</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h5 mb-0"><?= (int)($statDashboard['rowsScanned'] ?? 0) ?></div>
                            <small class="text-muted">统计行</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="border rounded p-2">
                            <div class="h5 mb-0"><?= empty($statDashboard['issues']) ? 'OK' : 'WARN' ?></div>
                            <small class="text-muted">看板状态</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body border-bottom" data-mongoyia-customer-service-stat-widget-readiness="reserved">
                <span class="text-muted">统计 Widget 写入 readiness 已保留，真实写入必须另走审核实现。</span>
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm ml-2"
                    data-mongoyia-customer-service-stat-widget-apply="disabled"
                    disabled
                >统计写入待启用</button>
            </div>
            <div class="card-body border-bottom" data-mongoyia-customer-service-stat-apply-gate="reserved" data-mongoyia-customer-service-stat-apply-workflow="reserved">
                <span class="text-muted">统计重算写入 dry-run/apply workflow 已保留，后台真实写入按钮仍需单独审核后启用。</span>
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm ml-2"
                    data-mongoyia-customer-service-stat-apply="disabled"
                    disabled
                >统计重算写入待启用</button>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>日期</th>
                        <th>店铺</th>
                        <th>客服</th>
                        <th>会话数</th>
                        <th>工单数</th>
                        <th>订单协助</th>
                        <th>投诉</th>
                        <th>解决</th>
                        <th>未解决</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($statRows)): ?>
                        <tr><td colspan="9" class="text-muted text-center">暂无客服统计</td></tr>
                    <?php endif; ?>
                    <?php foreach ($statRows as $row): ?>
                        <tr>
                            <td><?= (int)$row['stat_date'] ?></td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= (int)$row['service_user_id'] ?></td>
                            <td><?= (int)$row['session_count'] ?></td>
                            <td><?= (int)$row['ticket_count'] ?></td>
                            <td><?= (int)$row['order_assist_count'] ?></td>
                            <td><?= (int)$row['complaint_count'] ?></td>
                            <td><?= (int)$row['resolved_count'] ?></td>
                            <td><?= (int)$row['unresolved_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
