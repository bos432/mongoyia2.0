<?php

use common\helpers\Html;
use common\models\mall\LogisticsMethod;
use common\models\mall\StoreLogisticsMethod;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $stores array */
/* @var $isPlatformOperator bool */
/* @var $methods array */

$this->title = '物流方式';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <?php if ($isPlatformOperator): ?>
                        <form method="get" class="form-inline d-inline-block">
                            <select name="store_id" class="form-control form-control-sm mr-2">
                                <?php foreach ($stores as $id => $name): ?>
                                    <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary btn-sm" type="submit">切换店铺</button>
                        </form>
                        <?= Html::edit(['edit'], '新增物流方式', ['class' => 'btn btn-success btn-sm ml-2']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>物流方式</th>
                        <th>承运商</th>
                        <th>费用</th>
                        <th>查询链接</th>
                        <th>状态</th>
                        <th>店铺选择</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$methods): ?>
                        <tr><td colspan="8" class="text-muted text-center">暂无物流方式</td></tr>
                    <?php endif; ?>
                    <?php foreach ($methods as $method): ?>
                        <?php $selected = $method['selection_status'] === StoreLogisticsMethod::SELECTION_ENABLED; ?>
                        <tr>
                            <td><?= (int)$method['id'] ?></td>
                            <td>
                                <?= Html::encode($method['name']) ?><br>
                                <small class="text-muted"><?= Html::encode($method['code']) ?></small>
                            </td>
                            <td><?= Html::encode($method['provider']) ?></td>
                            <td>
                                基础 <?= number_format((float)$method['base_fee'], 2) ?><br>
                                <small class="text-muted">kg <?= number_format((float)$method['fee_per_kg'], 2) ?> / vol <?= number_format((float)$method['fee_per_volume'], 2) ?></small>
                            </td>
                            <td><?= Html::encode($method['tracking_url'] ?: '-') ?></td>
                            <td><?= Html::encode(LogisticsMethod::getStatusLabels((int)$method['status'], true)) ?></td>
                            <td>
                                <span class="badge badge-<?= $selected ? 'success' : 'secondary' ?>">
                                    <?= Html::encode(StoreLogisticsMethod::getSelectionStatusLabels($method['selection_status'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($selected): ?>
                                    <form method="post" action="<?= \yii\helpers\Url::to(['unselect']) ?>" class="d-inline" data-mongoyia-logistics-method-selection-post-guard="1">
                                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                                        <?= Html::hiddenInput('method_id', (int)$method['id']) ?>
                                        <?= Html::hiddenInput('store_id', (int)$storeId) ?>
                                        <button type="submit" class="btn btn-warning btn-sm">取消选择</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= \yii\helpers\Url::to(['select']) ?>" class="d-inline" data-mongoyia-logistics-method-selection-post-guard="1">
                                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                                        <?= Html::hiddenInput('method_id', (int)$method['id']) ?>
                                        <?= Html::hiddenInput('store_id', (int)$storeId) ?>
                                        <button type="submit" class="btn btn-success btn-sm">选择</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isPlatformOperator): ?>
                                    <?= Html::edit(['edit', 'id' => $method['id']], Yii::t('app', 'Edit'), ['class' => 'btn btn-default btn-sm']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
