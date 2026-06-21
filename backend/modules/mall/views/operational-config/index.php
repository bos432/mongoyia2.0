<?php

use common\helpers\Html;

/* @var $this yii\web\View */
/* @var $summary array */

$this->title = '运营配置中心';
$this->params['breadcrumbs'][] = $this->title;

$statusClass = [
    'PASS' => 'success',
    'WARN' => 'warning',
    'FAIL' => 'danger',
    'PENDING' => 'secondary',
    'BLOCKED' => 'dark',
];
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2"><strong>配置项：</strong><?= (int)$summary['config_count'] ?></div>
                    <div class="col-md-2"><strong>已启用：</strong><?= (int)$summary['enabled_count'] ?></div>
                    <div class="col-md-2"><strong>敏感项：</strong><?= (int)$summary['sensitive_count'] ?></div>
                    <div class="col-md-2"><strong>已配置：</strong><?= (int)$summary['configured_count'] ?></div>
                    <div class="col-md-4">
                        <strong>主密钥：</strong>
                        <?php if ((int)$summary['missing_master_key'] === 1): ?>
                            <span class="badge badge-warning">OP_CONFIG_MASTER_KEY 未配置</span>
                        <?php else: ?>
                            <span class="badge badge-success">已配置</span>
                        <?php endif; ?>
                    </div>
                </div>
                <hr>
                <p class="text-muted mb-0">
                    运营配置中心用于支付、邮件、定时任务、告警和上线证据。敏感配置只显示脱敏状态，真实值由加密服务读取；数据库中不应出现私钥、Basic Auth、HMAC Secret、SMTP 密码或告警 Token 明文。
                </p>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>分类</th>
                        <th>提供方</th>
                        <th>代码</th>
                        <th>环境</th>
                        <th>启用</th>
                        <th>敏感</th>
                        <th>状态</th>
                        <th>脱敏值</th>
                        <th>最后检测</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($summary['rows'])): ?>
                        <tr><td colspan="9" class="text-muted text-center">暂无运营配置；请先运行迁移和后续 Phase 7 配置录入流程</td></tr>
                    <?php endif; ?>
                    <?php foreach ($summary['rows'] as $row): ?>
                        <?php $badge = $statusClass[$row['last_check_status']] ?? 'secondary'; ?>
                        <tr>
                            <td><?= Html::encode($row['category']) ?></td>
                            <td><?= Html::encode($row['provider']) ?></td>
                            <td>
                                <?= Html::encode($row['label'] ?: $row['code']) ?><br>
                                <small class="text-muted"><?= Html::encode($row['code']) ?></small>
                            </td>
                            <td><?= Html::encode($row['environment']) ?></td>
                            <td><?= (int)$row['is_enabled'] === 1 ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-secondary">关闭</span>' ?></td>
                            <td><?= (int)$row['is_sensitive'] === 1 ? '<span class="badge badge-danger">敏感</span>' : '<span class="badge badge-info">普通</span>' ?></td>
                            <td><span class="badge badge-<?= $badge ?>"><?= Html::encode($row['last_check_status']) ?></span></td>
                            <td><?= Html::encode($row['redacted_value']) ?></td>
                            <td>
                                <?= (int)$row['last_checked_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['last_checked_at']) : '-' ?><br>
                                <small class="text-muted"><?= Html::encode($row['last_check_message']) ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">最近检测</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>时间</th>
                        <th>分类</th>
                        <th>提供方</th>
                        <th>检测项</th>
                        <th>结果</th>
                        <th>消息</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($summary['latest_checks'])): ?>
                        <tr><td colspan="6" class="text-muted text-center">暂无检测记录</td></tr>
                    <?php endif; ?>
                    <?php foreach ($summary['latest_checks'] as $check): ?>
                        <?php $badge = $statusClass[$check['result']] ?? 'secondary'; ?>
                        <tr>
                            <td><?= (int)$check['checked_at'] > 0 ? date('Y-m-d H:i:s', (int)$check['checked_at']) : '-' ?></td>
                            <td><?= Html::encode($check['category']) ?></td>
                            <td><?= Html::encode($check['provider']) ?></td>
                            <td><?= Html::encode($check['check_key']) ?></td>
                            <td><span class="badge badge-<?= $badge ?>"><?= Html::encode($check['result']) ?></span></td>
                            <td><?= Html::encode($check['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
