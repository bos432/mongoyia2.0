<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $snapshot array */
/* @var $identityEnvironments array */

$this->title = '第三方登录配置';
$this->params['breadcrumbs'][] = ['label' => '运营配置中心', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

$statusClass = [
    'PASS' => 'success',
    'WARN' => 'warning',
    'FAIL' => 'danger',
    'PENDING' => 'secondary',
    'BLOCKED' => 'dark',
];
?>

<div class="row" data-mongoyia-identity-config="<?= Html::encode($snapshot['version'] ?? '') ?>">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <?php foreach ($identityEnvironments as $env => $label): ?>
                        <?php $active = ($snapshot['environment'] ?? 'test') === $env; ?>
                        <?= Html::a(Html::encode($label), ['identity-config', 'environment' => $env], [
                            'class' => 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-default'),
                        ]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">
                    Google/Facebook 的 Client Secret、App Secret 加密保存，页面只显示脱敏状态。
                    回调 URL 需要同步配置到 Google Cloud Console 或 Meta for Developers；绑定/解绑实际写入将在 provider 证据验收后启用。
                </p>
            </div>
        </div>

        <div class="row" data-mongoyia-identity-provider-cards="1">
            <?php foreach (($snapshot['providers'] ?? []) as $provider): ?>
                <?php $latest = $provider['latest_check'] ?? []; ?>
                <?php $badge = $statusClass[$latest['result'] ?? 'PENDING'] ?? 'secondary'; ?>
                <div class="col-lg-6 col-md-12">
                    <?= Html::beginForm(['save-identity-config'], 'post') ?>
                    <?= Html::hiddenInput('provider', $provider['provider']) ?>
                    <?= Html::hiddenInput('environment', $snapshot['environment'] ?? 'test') ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><?= Html::encode($provider['label']) ?></h3>
                            <div class="card-tools">
                                <span class="badge badge-<?= $badge ?>"><?= Html::encode($latest['result'] ?? 'PENDING') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small"><?= Html::encode($provider['description']) ?></p>

                            <?php foreach (($provider['fields'] ?? []) as $field): ?>
                                <?php if (($field['type'] ?? '') === 'mode'): ?>
                                    <?= Html::hiddenInput('config[' . $field['code'] . ']', $snapshot['environment'] ?? 'test') ?>
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
                                    <?php else: ?>
                                        <?= Html::textInput('config[' . $field['code'] . ']', $field['value'], [
                                            'class' => 'form-control',
                                            'placeholder' => !empty($field['sensitive']) && $field['configured'] ? '已配置，留空保持原值' : (string)($field['default'] ?? ''),
                                        ]) ?>
                                    <?php endif; ?>
                                    <small class="text-muted">
                                        <?= !empty($field['sensitive']) ? Html::encode($field['redacted_value']) : '当前值可明文显示' ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>

                            <div class="border-top pt-2" data-mongoyia-identity-callback-urls="1">
                                <strong>回调/绑定地址</strong>
                                <?php foreach (($provider['callback_urls'] ?? []) as $name => $url): ?>
                                    <div class="small text-muted"><?= Html::encode($name) ?>: <code><?= Html::encode($url) ?></code></div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (!empty($latest['message'])): ?>
                                <div class="text-muted small mt-2"><?= Html::encode($latest['message']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <?= Html::submitButton('保存并检测', ['class' => 'btn btn-primary btn-sm']) ?>
                            <?= Html::submitButton('仅检测', [
                                'class' => 'btn btn-default btn-sm',
                                'formaction' => Url::to(['check-identity-config']),
                            ]) ?>
                        </div>
                    </div>
                    <?= Html::endForm() ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
