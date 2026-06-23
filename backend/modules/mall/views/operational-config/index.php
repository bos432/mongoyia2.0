<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $summary array */
/* @var $payment array */
/* @var $paymentEnvironments array */
/* @var $mail array */
/* @var $translation array */
/* @var $opsAlert array */
/* @var $launch array */
/* @var $providerEvidence array */
/* @var $phase10Readiness array */

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
                <p class="mt-2 mb-0">
                    <?= Html::a('商家独立支付配置', ['merchant-payment'], [
                        'class' => 'btn btn-sm btn-outline-primary',
                        'data-mongoyia-merchant-payment-entry' => '1',
                    ]) ?>
                    <?= Html::a('支付统计', ['/mall/payment-stat/index'], [
                        'class' => 'btn btn-sm btn-outline-info',
                        'data-mongoyia-payment-statistics-entry' => '1',
                    ]) ?>
                    <?= Html::a('第三方登录配置', ['identity-config'], [
                        'class' => 'btn btn-sm btn-outline-secondary',
                        'data-mongoyia-identity-config-entry' => '1',
                    ]) ?>
                    <?= Html::a('账号安全策略', ['account-security'], [
                        'class' => 'btn btn-sm btn-outline-secondary',
                        'data-mongoyia-account-security-entry' => '1',
                    ]) ?>
                    <?= Html::a('通知日志', ['/mall/notification-log/index'], [
                        'class' => 'btn btn-sm btn-outline-secondary',
                        'data-mongoyia-notification-log-entry' => '1',
                    ]) ?>
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

        <div class="card" data-mongoyia-operational-phase10-readiness="<?= Html::encode($phase10Readiness['version'] ?? '') ?>">
            <?php $phase10Badge = $statusClass[$phase10Readiness['result'] ?? 'PENDING'] ?? 'secondary'; ?>
            <div class="card-header">
                <h3 class="card-title">Phase 10 上线运营 readiness</h3>
                <div class="card-tools">
                    <span class="badge badge-<?= $phase10Badge ?>"><?= Html::encode($phase10Readiness['decision'] ?? 'NO-GO') ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>检查项</th>
                        <th>结果</th>
                        <th>证据</th>
                        <th>说明</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($phase10Readiness['rows'] ?? []) as $row): ?>
                        <?php $badge = $statusClass[$row['result'] ?? 'PENDING'] ?? 'secondary'; ?>
                        <tr>
                            <td><?= Html::encode($row['name']) ?></td>
                            <td><span class="badge badge-<?= $badge ?>"><?= Html::encode($row['result']) ?></span></td>
                            <td><code><?= Html::encode($row['evidence']) ?></code></td>
                            <td><?= Html::encode($row['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body">
                <strong>服务商明细：</strong>
                <?php foreach (($phase10Readiness['provider_rows'] ?? []) as $row): ?>
                    <?php $badge = $statusClass[$row['result'] ?? 'PENDING'] ?? 'secondary'; ?>
                    <span class="badge badge-<?= $badge ?> mr-1"><?= Html::encode($row['name']) ?>: <?= Html::encode($row['result']) ?></span>
                <?php endforeach; ?>
                <p class="text-muted mb-0 mt-2">
                    只有服务商证据、上线签核、脱敏导出和生产 GO/NO-GO gate 都通过后，这里才会显示 GO-READY。
                </p>
            </div>
        </div>

        <div class="mb-3" data-mongoyia-operational-provider-evidence="<?= Html::encode($providerEvidence['version'] ?? '') ?>">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h3 class="mb-0">服务商证据验收</h3>
                <span class="text-muted small">当前环境：<?= Html::encode($paymentEnvironments[$providerEvidence['environment'] ?? 'test'] ?? ($providerEvidence['environment'] ?? 'test')) ?></span>
            </div>
            <p class="text-muted">
                这里只记录 QPay、LianLian、PayPal、SMTP、翻译和告警的脱敏证据引用，例如服务商后台截图编号、沙箱测试报告、内部工单或审计报告；不要录入私钥、Basic Auth、HMAC Secret、SMTP 密码、API Key 或原始回调报文。
            </p>
            <div class="row">
                <?php foreach (($providerEvidence['providers'] ?? []) as $provider): ?>
                    <?php $latest = $provider['latest_check'] ?? []; ?>
                    <?php $badge = $statusClass[$latest['result'] ?? 'PENDING'] ?? 'secondary'; ?>
                    <div class="col-lg-4 col-md-12">
                        <?= Html::beginForm(['save-provider-evidence'], 'post') ?>
                        <?= Html::hiddenInput('provider', $provider['provider']) ?>
                        <?= Html::hiddenInput('environment', $providerEvidence['environment'] ?? 'test') ?>
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title"><?= Html::encode($provider['label']) ?></h4>
                                <div class="card-tools">
                                    <span class="badge badge-<?= $badge ?>"><?= Html::encode($latest['result'] ?? 'PENDING') ?></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small"><?= Html::encode($provider['description']) ?></p>
                                <?php foreach (($provider['fields'] ?? []) as $field): ?>
                                    <div class="form-group">
                                        <label>
                                            <?= Html::encode($field['label']) ?>
                                            <?php if (!empty($field['required'])): ?><span class="text-danger">*</span><?php endif; ?>
                                        </label>
                                        <?php if (($field['type'] ?? '') === 'switch'): ?>
                                            <?= Html::hiddenInput('evidence[' . $field['code'] . ']', '0') ?>
                                            <div>
                                                <label class="mb-0">
                                                    <?= Html::checkbox('evidence[' . $field['code'] . ']', (string)$field['value'] === '1', ['value' => '1']) ?>
                                                    已确认
                                                </label>
                                            </div>
                                        <?php elseif (($field['type'] ?? '') === 'textarea'): ?>
                                            <?= Html::textarea('evidence[' . $field['code'] . ']', $field['value'], [
                                                'class' => 'form-control',
                                                'rows' => 3,
                                                'placeholder' => '只填写脱敏说明或外部证据引用',
                                            ]) ?>
                                        <?php else: ?>
                                            <?= Html::textInput('evidence[' . $field['code'] . ']', $field['value'], [
                                                'class' => 'form-control',
                                                'placeholder' => '报告编号、工单号、截图编号或审计链接',
                                            ]) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!empty($latest['message'])): ?>
                                    <div class="text-muted small"><?= Html::encode($latest['message']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <?= Html::submitButton('保存证据并检测', ['class' => 'btn btn-primary btn-sm']) ?>
                                <?= Html::submitButton('仅检测证据', [
                                    'class' => 'btn btn-default btn-sm',
                                    'formaction' => Url::to(['check-provider-evidence']),
                                ]) ?>
                            </div>
                        </div>
                        <?= Html::endForm() ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3" data-mongoyia-operational-payment-config="<?= Html::encode($payment['version'] ?? '') ?>">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h3 class="mb-0">支付配置中心</h3>
                <div>
                    <?php foreach ($paymentEnvironments as $env => $label): ?>
                        <?php $active = ($payment['environment'] ?? 'test') === $env; ?>
                        <?= Html::a(Html::encode($label), ['index', 'environment' => $env], [
                            'class' => 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-default'),
                        ]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="text-muted">
                当前环境：<?= Html::encode($paymentEnvironments[$payment['environment']] ?? $payment['environment']) ?>。
                支付私钥、Basic Auth、Secret、HMAC 等敏感值加密保存，留空表示保持原值；正式模式启用前必须先补齐必填项。
            </p>

            <div class="row">
                <?php foreach (($payment['providers'] ?? []) as $provider): ?>
                    <?php $latest = $provider['latest_check'] ?? []; ?>
                    <?php $badge = $statusClass[$latest['result'] ?? 'PENDING'] ?? 'secondary'; ?>
                    <div class="col-lg-4 col-md-12">
                        <?php $formAction = Url::to(['save-payment']); ?>
                        <?= Html::beginForm($formAction, 'post') ?>
                        <?= Html::hiddenInput('provider', $provider['provider']) ?>
                        <?= Html::hiddenInput('environment', $payment['environment']) ?>
                        <div class="card">
                                <div class="card-header">
                                    <h4 class="card-title"><?= Html::encode($provider['label']) ?></h4>
                                    <div class="card-tools">
                                        <span class="badge badge-<?= $badge ?>"><?= Html::encode($latest['result'] ?? 'PENDING') ?></span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted small"><?= Html::encode($provider['description']) ?></p>

                                    <?php foreach ($provider['fields'] as $field): ?>
                                        <?php if (($field['type'] ?? '') === 'mode'): ?>
                                            <?= Html::hiddenInput('config[' . $field['code'] . ']', $payment['environment']) ?>
                                            <?php continue; ?>
                                        <?php endif; ?>
                                        <div class="form-group">
                                            <label>
                                                <?= Html::encode($field['label']) ?>
                                                <?php if (!empty($field['required_for_enable'])): ?>
                                                    <span class="text-danger">*</span>
                                                <?php endif; ?>
                                            </label>
                                            <?php if (($field['type'] ?? '') === 'switch'): ?>
                                                <?= Html::hiddenInput('config[' . $field['code'] . ']', '0') ?>
                                                <div>
                                                    <label class="mb-0">
                                                        <?= Html::checkbox('config[' . $field['code'] . ']', (string)$field['value'] === '1', ['value' => '1']) ?>
                                                        启用
                                                    </label>
                                                </div>
                                            <?php elseif (($field['type'] ?? '') === 'textarea'): ?>
                                                <?= Html::textarea('config[' . $field['code'] . ']', $field['value'], [
                                                    'class' => 'form-control',
                                                    'rows' => 3,
                                                    'placeholder' => !empty($field['sensitive']) && $field['configured'] ? '已配置，留空保持原值' : (string)($field['default'] ?? ''),
                                                ]) ?>
                                            <?php else: ?>
                                                <?= Html::textInput('config[' . $field['code'] . ']', $field['value'], [
                                                    'class' => 'form-control',
                                                    'type' => ($field['type'] ?? '') === 'number' ? 'number' : 'text',
                                                    'placeholder' => !empty($field['sensitive']) && $field['configured'] ? '已配置，留空保持原值' : (string)($field['default'] ?? ''),
                                                ]) ?>
                                            <?php endif; ?>
                                            <small class="text-muted">
                                                <?= !empty($field['sensitive']) ? Html::encode($field['redacted_value']) : '当前值可明文显示' ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>

                                    <div class="border-top pt-2">
                                        <strong>回调/返回地址</strong>
                                        <?php foreach ($provider['callback_urls'] as $name => $url): ?>
                                            <div class="small text-muted"><?= Html::encode($name) ?>: <code><?= Html::encode($url) ?></code></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <?= Html::submitButton('保存并检测', ['class' => 'btn btn-primary btn-sm']) ?>
                                    <?= Html::submitButton('仅检测', [
                                        'class' => 'btn btn-default btn-sm',
                                        'formaction' => Url::to(['check-payment']),
                                    ]) ?>
                                    <?php if (!empty($latest['message'])): ?>
                                        <div class="text-muted small mt-2"><?= Html::encode($latest['message']) ?></div>
                                    <?php endif; ?>
                                </div>
                        </div>
                        <?= Html::endForm() ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3" data-mongoyia-customer-service-translation-config="<?= Html::encode($translation['version'] ?? '') ?>">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h3 class="mb-0">客服翻译配置</h3>
            </div>
            <p class="text-muted">
                客服消息翻译支持 OpenAI-compatible 和 Google-compatible 两类驱动；API Key 加密保存，页面只展示脱敏状态。翻译失败时聊天仍保留原文，不阻塞消息发送。
            </p>

            <div class="row">
                <?php foreach (($translation['providers'] ?? []) as $provider): ?>
                    <?php $latest = $provider['latest_check'] ?? []; ?>
                    <?php $badge = $statusClass[$latest['result'] ?? 'PENDING'] ?? 'secondary'; ?>
                    <div class="col-lg-6 col-md-12">
                        <?= Html::beginForm(['save-translation'], 'post') ?>
                        <?= Html::hiddenInput('provider', $provider['provider']) ?>
                        <?= Html::hiddenInput('environment', $payment['environment'] ?? 'test') ?>
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title"><?= Html::encode($provider['label']) ?></h4>
                                <div class="card-tools">
                                    <span class="badge badge-<?= $badge ?>"><?= Html::encode($latest['result'] ?? 'PENDING') ?></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small"><?= Html::encode($provider['description']) ?></p>

                                <?php foreach (($provider['fields'] ?? []) as $field): ?>
                                    <div class="form-group">
                                        <label>
                                            <?= Html::encode($field['label']) ?>
                                            <?php if (!empty($field['required_for_enable'])): ?>
                                                <span class="text-danger">*</span>
                                            <?php endif; ?>
                                        </label>
                                        <?php if (($field['type'] ?? '') === 'switch'): ?>
                                            <?= Html::hiddenInput('config[' . $field['code'] . ']', '0') ?>
                                            <div>
                                                <label class="mb-0">
                                                    <?= Html::checkbox('config[' . $field['code'] . ']', (string)$field['value'] === '1', ['value' => '1']) ?>
                                                    启用
                                                </label>
                                            </div>
                                        <?php elseif (($field['type'] ?? '') === 'select'): ?>
                                            <?= Html::dropDownList('config[' . $field['code'] . ']', $field['value'], $translation['work_languages'] ?? [], ['class' => 'form-control']) ?>
                                        <?php else: ?>
                                            <?= Html::textInput('config[' . $field['code'] . ']', $field['value'], [
                                                'class' => 'form-control',
                                                'type' => ($field['type'] ?? '') === 'number' ? 'number' : 'text',
                                                'placeholder' => !empty($field['sensitive']) && $field['configured'] ? '已配置，留空保持原值' : (string)($field['default'] ?? ''),
                                            ]) ?>
                                        <?php endif; ?>
                                        <small class="text-muted">
                                            <?= !empty($field['sensitive']) ? Html::encode($field['redacted_value']) : '当前值可明文显示' ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>

                                <div class="border-top pt-2">
                                    <strong>测试翻译</strong>
                                    <div class="form-row mt-2">
                                        <div class="col-md-5">
                                            <?= Html::textInput('test_text', 'Hello', ['class' => 'form-control form-control-sm', 'maxlength' => 255]) ?>
                                        </div>
                                        <div class="col-md-3">
                                            <?= Html::dropDownList('source_language', 'en', $translation['languages'] ?? [], ['class' => 'form-control form-control-sm']) ?>
                                        </div>
                                        <div class="col-md-3">
                                            <?= Html::dropDownList('target_language', 'mn', $translation['languages'] ?? [], ['class' => 'form-control form-control-sm']) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer">
                                <?= Html::submitButton('保存翻译配置', ['class' => 'btn btn-primary btn-sm']) ?>
                                <?= Html::submitButton('仅检测', [
                                    'class' => 'btn btn-default btn-sm',
                                    'formaction' => Url::to(['check-translation']),
                                ]) ?>
                                <?= Html::submitButton('测试翻译', [
                                    'class' => 'btn btn-default btn-sm',
                                    'formaction' => Url::to(['test-translation']),
                                ]) ?>
                                <?php if (!empty($latest['message'])): ?>
                                    <div class="text-muted small mt-2"><?= Html::encode($latest['message']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?= Html::endForm() ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3" data-mongoyia-operational-mail-config="<?= Html::encode($mail['version'] ?? '') ?>">
            <?php $latestMail = $mail['latest_check'] ?? []; ?>
            <?php $mailBadge = $statusClass[$latestMail['result'] ?? 'PENDING'] ?? 'secondary'; ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h3 class="mb-0">邮件配置中心</h3>
                <span class="badge badge-<?= $mailBadge ?>"><?= Html::encode($latestMail['result'] ?? 'PENDING') ?></span>
            </div>
            <p class="text-muted">
                SMTP 密码加密保存，留空表示保持原值。测试发送会记录成功/失败、错误摘要和测试时间。
            </p>

            <div class="card">
                <?= Html::beginForm(['save-mail'], 'post') ?>
                <?= Html::hiddenInput('environment', $payment['environment']) ?>
                <div class="card-body">
                    <div class="row">
                        <?php foreach (($mail['fields'] ?? []) as $field): ?>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>
                                        <?= Html::encode($field['label']) ?>
                                        <?php if (!empty($field['required_for_enable'])): ?>
                                            <span class="text-danger">*</span>
                                        <?php endif; ?>
                                    </label>
                                    <?php if (($field['type'] ?? '') === 'switch'): ?>
                                        <?= Html::hiddenInput('mail[' . $field['code'] . ']', '0') ?>
                                        <div>
                                            <label class="mb-0">
                                                <?= Html::checkbox('mail[' . $field['code'] . ']', (string)$field['value'] === '1', ['value' => '1']) ?>
                                                启用
                                            </label>
                                        </div>
                                    <?php elseif (($field['type'] ?? '') === 'select'): ?>
                                        <?= Html::dropDownList('mail[' . $field['code'] . ']', $field['value'], $mail['encryption_options'] ?? [], ['class' => 'form-control']) ?>
                                    <?php else: ?>
                                        <?= Html::textInput('mail[' . $field['code'] . ']', $field['value'], [
                                            'class' => 'form-control',
                                            'type' => ($field['type'] ?? '') === 'number' ? 'number' : 'text',
                                            'placeholder' => !empty($field['sensitive']) && $field['configured'] ? '已配置，留空保持原值' : (string)($field['default'] ?? ''),
                                        ]) ?>
                                    <?php endif; ?>
                                    <small class="text-muted">
                                        <?= !empty($field['sensitive']) ? Html::encode($field['redacted_value']) : '当前值可明文显示' ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <?= Html::submitButton('保存邮件配置', ['class' => 'btn btn-primary btn-sm']) ?>
                    <?php if (!empty($latestMail['message'])): ?>
                        <span class="text-muted small ml-2"><?= Html::encode($latestMail['message']) ?></span>
                    <?php endif; ?>
                </div>
                <?= Html::endForm() ?>
            </div>

            <div class="card">
                <div class="card-body">
                    <?= Html::beginForm(['test-mail'], 'post', ['class' => 'form-inline']) ?>
                    <?= Html::hiddenInput('environment', $payment['environment']) ?>
                    <?= Html::textInput('test_to', $mail['fields']['test_to']['value'] ?? '', [
                        'class' => 'form-control mr-2',
                        'placeholder' => '测试收件人',
                    ]) ?>
                    <?= Html::submitButton('发送测试邮件', ['class' => 'btn btn-default btn-sm']) ?>
                    <?= Html::endForm() ?>
                </div>
            </div>
        </div>

        <div class="mb-3" data-mongoyia-operational-ops-alert="<?= Html::encode($opsAlert['version'] ?? '') ?>">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h3 class="mb-0">运维检查和告警中心</h3>
                <?= Html::beginForm(['test-alert'], 'post') ?>
                <?= Html::hiddenInput('environment', $payment['environment']) ?>
                <?= Html::submitButton('发送测试告警', ['class' => 'btn btn-default btn-sm']) ?>
                <?= Html::endForm() ?>
            </div>
            <p class="text-muted">
                本页只展示任务命令、推荐频率、最近运行证据和告警配置，不直接修改服务器 crontab 或 systemd。
            </p>

            <div class="card">
                <div class="card-header"><h4 class="card-title">任务检查</h4></div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                        <tr>
                            <th>任务</th>
                            <th>命令</th>
                            <th>推荐频率</th>
                            <th>最近结果</th>
                            <th>建议</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($opsAlert['tasks'] ?? []) as $task): ?>
                            <?php $badge = $statusClass[$task['last_result'] ?? 'PENDING'] ?? 'secondary'; ?>
                            <tr>
                                <td><?= Html::encode($task['name']) ?></td>
                                <td><code><?= Html::encode($task['command']) ?></code></td>
                                <td><?= Html::encode($task['frequency']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $badge ?>"><?= Html::encode($task['last_result']) ?></span><br>
                                    <small class="text-muted">
                                        <?= (int)$task['last_run_at'] > 0 ? date('Y-m-d H:i:s', (int)$task['last_run_at']) : '-' ?>
                                        <?= Html::encode($task['last_message']) ?>
                                    </small>
                                </td>
                                <td><?= Html::encode($task['advice']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <?= Html::beginForm(['save-alert'], 'post') ?>
                <?= Html::hiddenInput('environment', $payment['environment']) ?>
                <div class="card-header"><h4 class="card-title">告警配置</h4></div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach (($opsAlert['fields'] ?? []) as $field): ?>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= Html::encode($field['label']) ?></label>
                                    <?php if (($field['type'] ?? '') === 'switch'): ?>
                                        <?= Html::hiddenInput('alert[' . $field['code'] . ']', '0') ?>
                                        <div>
                                            <label class="mb-0">
                                                <?= Html::checkbox('alert[' . $field['code'] . ']', (string)$field['value'] === '1', ['value' => '1']) ?>
                                                启用
                                            </label>
                                        </div>
                                    <?php elseif (($field['type'] ?? '') === 'textarea'): ?>
                                        <?= Html::textarea('alert[' . $field['code'] . ']', $field['value'], [
                                            'class' => 'form-control',
                                            'rows' => 3,
                                        ]) ?>
                                    <?php else: ?>
                                        <?= Html::textInput('alert[' . $field['code'] . ']', $field['value'], [
                                            'class' => 'form-control',
                                            'type' => ($field['type'] ?? '') === 'number' ? 'number' : 'text',
                                            'placeholder' => !empty($field['sensitive']) && $field['configured'] ? '已配置，留空保持原值' : (string)($field['default'] ?? ''),
                                        ]) ?>
                                    <?php endif; ?>
                                    <small class="text-muted">
                                        <?= !empty($field['sensitive']) ? Html::encode($field['redacted_value']) : '当前值可明文显示' ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <?= Html::submitButton('保存告警配置', ['class' => 'btn btn-primary btn-sm']) ?>
                </div>
                <?= Html::endForm() ?>
            </div>
        </div>

        <div class="mb-3" data-mongoyia-operational-launch-signoff="<?= Html::encode($launch['version'] ?? '') ?>">
            <?php $readiness = $launch['readiness'] ?? []; ?>
            <?php $launchBadge = $statusClass[$readiness['result'] ?? 'PENDING'] ?? 'secondary'; ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h3 class="mb-0">上线签核和证据管理</h3>
                <span class="badge badge-<?= $launchBadge ?>"><?= Html::encode($readiness['result'] ?? 'PENDING') ?></span>
            </div>
            <p class="text-muted">
                这里记录非敏感证据引用和签核状态，不保存支付密钥、原始回调报文或私钥内容。
                <?= Html::encode($readiness['message'] ?? '') ?>
            </p>

            <div class="card">
                <?= Html::beginForm(['save-launch'], 'post') ?>
                <?= Html::hiddenInput('environment', $payment['environment']) ?>
                <div class="card-body">
                    <div class="row">
                        <?php foreach (($launch['fields'] ?? []) as $field): ?>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>
                                        <?= Html::encode($field['label']) ?>
                                        <?php if (!empty($field['required'])): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <?php if (($field['type'] ?? '') === 'switch'): ?>
                                        <?= Html::hiddenInput('launch[' . $field['code'] . ']', '0') ?>
                                        <div>
                                            <label class="mb-0">
                                                <?= Html::checkbox('launch[' . $field['code'] . ']', (string)$field['value'] === '1', ['value' => '1']) ?>
                                                已确认
                                            </label>
                                        </div>
                                    <?php elseif (($field['type'] ?? '') === 'textarea'): ?>
                                        <?= Html::textarea('launch[' . $field['code'] . ']', $field['value'], ['class' => 'form-control', 'rows' => 3]) ?>
                                    <?php else: ?>
                                        <?= Html::textInput('launch[' . $field['code'] . ']', $field['value'], ['class' => 'form-control']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <?= Html::submitButton('保存签核记录', ['class' => 'btn btn-primary btn-sm']) ?>
                </div>
                <?= Html::endForm() ?>
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
