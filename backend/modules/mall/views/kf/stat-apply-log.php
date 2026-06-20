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

$this->title = '客服统计写入审计';
$this->params['breadcrumbs'][] = ['label' => '客服', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => '客服工单', 'url' => ['tickets', 'store_id' => (int)$storeId]];
$this->params['breadcrumbs'][] = $this->title;

$operationLabels = [
    'insert' => '新增',
    'update' => '更新',
    'skip' => '跳过',
];
$totals = $report['totals'] ?? [];
$rows = $report['rows'] ?? [];
?>

<div class="row" data-mongoyia-customer-service-stat-apply-log-review="readonly">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= Html::encode(Url::to(['tickets', 'store_id' => (int)$storeId])) ?>">返回工单</a>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">MONGOYIA_CUSTOMER_SERVICE_STAT_APPLY_LOG_REVIEW_V1：本页只读审阅 CLI 统计 apply 审计日志；不提供后台统计写入按钮，不变更工单、订单、支付、IM、文件、资金或统计数据。</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">筛选</h3>
            </div>
            <div class="card-body">
                <form method="get" class="form-inline">
                    <?php if ($isPlatformOperator): ?>
                        <select name="store_id" class="form-control form-control-sm mr-2">
                            <option value="0" <?= (int)$storeId === 0 ? 'selected' : '' ?>>全部店铺</option>
                            <?php foreach ($stores as $id => $name): ?>
                                <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input name="date_from" class="form-control form-control-sm mr-2" type="text" placeholder="开始日期YYYYMMDD" value="<?= Html::encode($filters['date_from'] ?? '') ?>">
                    <input name="date_to" class="form-control form-control-sm mr-2" type="text" placeholder="结束日期YYYYMMDD" value="<?= Html::encode($filters['date_to'] ?? '') ?>">
                    <input name="batch_sn" class="form-control form-control-sm mr-2" type="text" placeholder="批次号" value="<?= Html::encode($filters['batch_sn'] ?? '') ?>">
                    <select name="operation" class="form-control form-control-sm mr-2">
                        <option value="">全部操作</option>
                        <?php foreach ($operationLabels as $operation => $label): ?>
                            <option value="<?= Html::encode($operation) ?>" <?= (string)($filters['operation'] ?? '') === (string)$operation ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input name="limit" class="form-control form-control-sm mr-2" type="number" min="1" max="500" value="<?= (int)$limit ?>">
                    <button class="btn btn-primary btn-sm" type="submit">查看审计</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">汇总</h3>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>审计行</th>
                        <th>新增</th>
                        <th>更新</th>
                        <th>跳过</th>
                        <th>来源工单</th>
                        <th>批次</th>
                        <th>店铺</th>
                        <th>操作人</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><?= (int)($totals['audit_log_count'] ?? 0) ?></td>
                        <td><?= (int)($totals['insert_count'] ?? 0) ?></td>
                        <td><?= (int)($totals['update_count'] ?? 0) ?></td>
                        <td><?= (int)($totals['skip_count'] ?? 0) ?></td>
                        <td><?= (int)($totals['source_ticket_count'] ?? 0) ?></td>
                        <td><?= (int)($totals['batch_count'] ?? 0) ?></td>
                        <td><?= (int)($totals['store_count'] ?? 0) ?></td>
                        <td><?= (int)($totals['operator_count'] ?? 0) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">审计日志</h3>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>批次</th>
                        <th>日期</th>
                        <th>店铺</th>
                        <th>客服</th>
                        <th>操作</th>
                        <th>统计行</th>
                        <th>来源工单</th>
                        <th>操作人</th>
                        <th>应用时间</th>
                        <th>差异</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="11" class="text-muted text-center">暂无统计写入审计</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td><small><?= Html::encode($row['batch_sn']) ?></small></td>
                            <td><?= (int)$row['stat_date'] ?></td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= (int)$row['service_user_id'] ?></td>
                            <td><?= Html::encode($operationLabels[$row['operation']] ?? $row['operation']) ?></td>
                            <td><?= (int)$row['stat_id'] ?></td>
                            <td><?= (int)$row['source_ticket_count'] ?></td>
                            <td><?= (int)$row['operator_user_id'] ?></td>
                            <td><?= (int)$row['applied_at'] > 0 ? date('Y-m-d H:i', (int)$row['applied_at']) : '' ?></td>
                            <td><small><?= Html::encode($row['diff_summary']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
