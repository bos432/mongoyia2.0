<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $snapshot array */
/* @var $paymentEnvironments array */
/* @var $isPlatformOperator bool */

$this->title = '商家支付配置';
$this->params['breadcrumbs'][] = ['label' => '运营配置中心', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

$statusClass = [
    'PASS' => 'success',
    'WARN' => 'warning',
    'FAIL' => 'danger',
    'PENDING' => 'secondary',
    'BLOCKED' => 'dark',
];

$storeId = (int)($snapshot['store_id'] ?? 0);
$environment = (string)($snapshot['environment'] ?? 'test');
$permission = $snapshot['permission'] ?? [];
$payment = $snapshot['payment'] ?? [];
$permissionBadge = $statusClass[$permission['status'] ?? 'PENDING'] ?? 'secondary';
?>

<div class="card" data-mongoyia-merchant-payment-config="<?= Html::encode($snapshot['version'] ?? '') ?>">
    <div class="card-header">
        <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
        <div class="card-tools">
            <span class="badge badge-<?= $permissionBadge ?>">
                <?= ((int)($permission['allowed'] ?? 0) === 1) ? '已允许商家自配' : '未允许商家自配' ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3"><strong>店铺 ID：</strong><?= $storeId > 0 ? (int)$storeId : '-' ?></div>
            <div class="col-md-3"><strong>当前环境：</strong><?= Html::encode($paymentEnvironments[$environment] ?? $environment) ?></div>
            <div class="col-md-6"><strong>正式启用策略：</strong><?= Html::encode($snapshot['live_enablement_policy'] ?? '') ?></div>
        </div>
        <p class="text-muted mb-0 mt-2">
            商家支付资料按店铺独立保存，敏感字段加密入库并脱敏展示。第一版允许测试模式配置和检测；正式模式启用仍需 Phase 10 服务商/生产证据和 Phase 11 浏览器验收通过后再开放。
        </p>
    </div>
</div>

<?php if ($isPlatformOperator): ?>
    <div class="card" data-mongoyia-merchant-payment-permission="platform-control">
        <div class="card-header">
            <h3 class="card-title">平台授权</h3>
        </div>
        <div class="card-body">
            <?= Html::beginForm(['merchant-payment'], 'get', ['class' => 'form-inline mb-3']) ?>
            <label class="mr-2">查看店铺 ID</label>
            <?= Html::textInput('store_id', $storeId > 0 ? $storeId : '', ['class' => 'form-control mr-2', 'placeholder' => '例如 2']) ?>
            <?= Html::hiddenInput('environment', $environment) ?>
            <?= Html::submitButton('打开', ['class' => 'btn btn-default']) ?>
            <?= Html::endForm() ?>

            <?= Html::beginForm(['save-merchant-payment-permission'], 'post') ?>
            <?= Html::hiddenInput('store_id', $storeId) ?>
            <?= Html::hiddenInput('environment', $environment) ?>
            <div class="form-group">
                <label>
                    <?= Html::hiddenInput('permission[allowed]', '0') ?>
                    <?= Html::checkbox('permission[allowed]', (int)($permission['allowed'] ?? 0) === 1, ['value' => '1']) ?>
                    允许该商家维护独立支付资料
                </label>
            </div>
            <div class="form-group">
                <label>授权备注</label>
                <?= Html::textInput('permission[note]', '', [
                    'class' => 'form-control',
                    'placeholder' => '只写非敏感说明，例如审批单号或业务原因',
                ]) ?>
            </div>
            <?= Html::submitButton('保存授权', [
                'class' => 'btn btn-primary',
                'disabled' => $storeId <= 0,
            ]) ?>
            <?= Html::endForm() ?>
        </div>
    </div>
<?php endif; ?>

<div class="mb-3">
    <?php foreach ($paymentEnvironments as $env => $label): ?>
        <?php $active = $environment === $env; ?>
        <?= Html::a(Html::encode($label), ['merchant-payment', 'store_id' => $storeId, 'environment' => $env], [
            'class' => 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-default'),
        ]) ?>
    <?php endforeach; ?>
</div>

<?php if ($storeId <= 0): ?>
    <div class="alert alert-warning">请先输入要管理的店铺 ID。</div>
<?php elseif ((int)($permission['allowed'] ?? 0) !== 1): ?>
    <div class="alert alert-warning"><?= Html::encode($permission['message'] ?? '平台尚未允许该商家维护独立支付资料。') ?></div>
<?php endif; ?>

<div class="row" data-mongoyia-merchant-payment-provider-cards="<?= Html::encode($payment['version'] ?? '') ?>">
    <?php foreach (($payment['providers'] ?? []) as $provider): ?>
        <?php $latest = $provider['latest_check'] ?? []; ?>
        <?php $badge = $statusClass[$latest['result'] ?? 'PENDING'] ?? 'secondary'; ?>
        <div class="col-lg-4 col-md-12">
            <?= Html::beginForm(['save-merchant-payment'], 'post') ?>
            <?= Html::hiddenInput('store_id', $storeId) ?>
            <?= Html::hiddenInput('provider', $provider['provider']) ?>
            <?= Html::hiddenInput('environment', $environment) ?>
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
                        <?php if (($field['type'] ?? '') === 'mode'): ?>
                            <?= Html::hiddenInput('config[' . $field['code'] . ']', $environment) ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="form-group">
                            <label>
                                <?= Html::encode($field['label']) ?>
                                <?php if (!empty($field['required_for_enable'])): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <?php if (($field['type'] ?? '') === 'switch'): ?>
                                <?= Html::hiddenInput('config[' . $field['code'] . ']', '0') ?>
                                <div>
                                    <label class="mb-0">
                                        <?= Html::checkbox('config[' . $field['code'] . ']', (string)$field['value'] === '1', [
                                            'value' => '1',
                                            'disabled' => $environment === 'live',
                                        ]) ?>
                                        启用
                                    </label>
                                </div>
                            <?php elseif (($field['type'] ?? '') === 'textarea'): ?>
                                <?= Html::textarea('config[' . $field['code'] . ']', $field['value'], [
                                    'class' => 'form-control',
                                    'rows' => 3,
                                    'placeholder' => !empty($field['sensitive']) ? '留空保持原加密值' : '',
                                    'disabled' => (int)($permission['allowed'] ?? 0) !== 1,
                                ]) ?>
                            <?php else: ?>
                                <?= Html::textInput('config[' . $field['code'] . ']', $field['value'], [
                                    'class' => 'form-control',
                                    'placeholder' => !empty($field['sensitive']) ? '留空保持原加密值' : '',
                                    'disabled' => (int)($permission['allowed'] ?? 0) !== 1,
                                ]) ?>
                            <?php endif; ?>
                            <small class="text-muted">
                                <?= !empty($field['sensitive']) ? '敏感字段加密保存；当前状态：' : '当前值：' ?>
                                <?= Html::encode($field['redacted_value'] ?? 'NOT CONFIGURED') ?>
                            </small>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($provider['callback_urls'])): ?>
                        <div class="small">
                            <strong>回调地址：</strong>
                            <?php foreach ($provider['callback_urls'] as $key => $url): ?>
                                <div><?= Html::encode($key) ?>：<code><?= Html::encode($url) ?></code></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($latest['message'])): ?>
                        <div class="text-muted small mt-2"><?= Html::encode($latest['message']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <?= Html::submitButton('保存并检测', [
                        'class' => 'btn btn-primary btn-sm',
                        'disabled' => $storeId <= 0 || (int)($permission['allowed'] ?? 0) !== 1,
                    ]) ?>
                    <?= Html::submitButton('仅检测', [
                        'class' => 'btn btn-default btn-sm',
                        'formaction' => Url::to(['check-merchant-payment']),
                        'disabled' => $storeId <= 0,
                    ]) ?>
                    <?php if ($environment === 'live'): ?>
                        <span class="text-danger small ml-2">正式启用被证据门阻断</span>
                    <?php endif; ?>
                </div>
            </div>
            <?= Html::endForm() ?>
        </div>
    <?php endforeach; ?>
</div>
