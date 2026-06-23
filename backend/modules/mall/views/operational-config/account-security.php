<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $snapshot array */

$this->title = '账号安全策略';
$this->params['breadcrumbs'][] = ['label' => '运营配置中心', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

$statusClass = [
    'PASS' => 'success',
    'WARN' => 'warning',
    'FAIL' => 'danger',
    'PENDING' => 'secondary',
    'BLOCKED' => 'dark',
];
$latest = $snapshot['latest_check'] ?? [];
$badge = $statusClass[$latest['result'] ?? 'PENDING'] ?? 'secondary';
?>

<div class="row" data-mongoyia-account-security="<?= Html::encode($snapshot['version'] ?? '') ?>">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <span class="badge badge-<?= $badge ?>"><?= Html::encode($latest['result'] ?? 'PENDING') ?></span>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">
                    控制邮箱找回、手机找回、邮箱/手机安全码登录，以及验证码时效、次数和锁定策略。
                    手机验证码需要短信服务商或 APP 推送证据；本页不保存短信密钥，也不直接发送验证码。
                </p>
                <?php if (!empty($latest['message'])): ?>
                    <p class="text-muted mt-2 mb-0"><?= Html::encode($latest['message']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" data-mongoyia-account-security-policy="1">
            <?= Html::beginForm(['save-account-security'], 'post') ?>
            <div class="card-body">
                <div class="row">
                    <?php foreach (($snapshot['fields'] ?? []) as $field): ?>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>
                                    <?= Html::encode($field['label']) ?>
                                    <?php if (!empty($field['required_for_enable'])): ?>
                                        <span class="text-danger">*</span>
                                    <?php endif; ?>
                                </label>
                                <?php if (($field['type'] ?? '') === 'switch'): ?>
                                    <?= Html::hiddenInput('security[' . $field['code'] . ']', '0') ?>
                                    <div>
                                        <label class="mb-0">
                                            <?= Html::checkbox('security[' . $field['code'] . ']', (string)$field['value'] === '1', ['value' => '1']) ?>
                                            启用
                                        </label>
                                    </div>
                                <?php else: ?>
                                    <?= Html::textInput('security[' . $field['code'] . ']', $field['value'], [
                                        'class' => 'form-control',
                                        'type' => ($field['type'] ?? '') === 'number' ? 'number' : 'text',
                                        'placeholder' => (string)($field['default'] ?? ''),
                                    ]) ?>
                                <?php endif; ?>
                                <small class="text-muted"><?= Html::encode($field['redacted_value']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer">
                <?= Html::submitButton('保存并检测', ['class' => 'btn btn-primary btn-sm']) ?>
                <?= Html::submitButton('仅检测', [
                    'class' => 'btn btn-default btn-sm',
                    'formaction' => Url::to(['check-account-security']),
                ]) ?>
            </div>
            <?= Html::endForm() ?>
        </div>

        <div class="card" data-mongoyia-account-security-routes="1">
            <div class="card-header">
                <h3 class="card-title">前台安全码入口</h3>
            </div>
            <div class="card-body">
                <div class="small text-muted">邮箱请求安全码：<code>/account-security/request-code?channel=email</code></div>
                <div class="small text-muted">邮箱安全码登录：<code>/account-security/login-code?channel=email</code></div>
                <div class="small text-muted">手机请求安全码：<code>/account-security/request-code?channel=mobile</code></div>
                <div class="small text-muted">手机安全码登录：<code>/account-security/login-code?channel=mobile</code></div>
            </div>
        </div>
    </div>
</div>
