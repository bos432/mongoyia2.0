<?php

use common\helpers\Html;
use common\models\base\FundLog;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $store common\models\Store */
/* @var $stores array */
/* @var $isPlatformOperator bool */
/* @var $logs array */

$this->title = '商家预存金';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <?php if ($isPlatformOperator): ?>
                    <div class="card-tools">
                        <form method="get" class="form-inline">
                            <select name="store_id" class="form-control form-control-sm mr-2">
                                <?php foreach ($stores as $id => $name): ?>
                                    <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary btn-sm" type="submit">切换店铺</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">余额概览</h3>
                            </div>
                            <div class="card-body">
                                <p><strong>店铺：</strong><?= Html::encode($store->name) ?> #<?= (int)$store->id ?></p>
                                <p><strong>当前余额：</strong><?= number_format((float)$store->fund, 2) ?></p>
                                <p><strong>累计充值：</strong><?= number_format((float)$store->fund_amount, 2) ?></p>
                                <p><strong>累计扣费：</strong><?= number_format((float)$store->consume_amount, 2) ?></p>
                                <p><strong>扣费次数：</strong><?= (int)$store->consume_count ?></p>
                            </div>
                        </div>
                    </div>
                    <?php if ($isPlatformOperator): ?>
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">充值/扣费</h3>
                                </div>
                                <div class="card-body">
                                    <form method="post" action="<?= \yii\helpers\Url::to(['adjust', 'store_id' => $storeId]) ?>">
                                        <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                                        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                                        <div class="form-row">
                                            <div class="form-group col-md-3">
                                                <label>金额</label>
                                                <input class="form-control" name="amount" type="number" step="0.01" placeholder="正数充值，负数扣费">
                                            </div>
                                            <div class="form-group col-md-4">
                                                <label>名称</label>
                                                <input class="form-control" name="name" maxlength="255" placeholder="例如物流差价扣费">
                                            </div>
                                            <div class="form-group col-md-5">
                                                <label>备注</label>
                                                <input class="form-control" name="remark" maxlength="255">
                                            </div>
                                        </div>
                                        <button class="btn btn-success" type="submit">保存流水</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">预存金流水</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>名称</th>
                                <th>类型</th>
                                <th>变动</th>
                                <th>原余额</th>
                                <th>新余额</th>
                                <th>备注</th>
                                <th>时间</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$logs): ?>
                                <tr><td colspan="8" class="text-muted text-center">暂无预存金流水</td></tr>
                            <?php endif; ?>
                            <?php foreach ($logs as $row): ?>
                                <tr>
                                    <td><?= (int)$row['id'] ?></td>
                                    <td><?= Html::encode($row['name']) ?></td>
                                    <td><?= Html::encode(FundLog::getTypeLabels((int)$row['type'], true)) ?></td>
                                    <td><?= number_format((float)$row['change'], 2) ?></td>
                                    <td><?= number_format((float)$row['original'], 2) ?></td>
                                    <td><?= number_format((float)$row['balance'], 2) ?></td>
                                    <td><?= Html::encode($row['remark']) ?></td>
                                    <td><?= $row['created_at'] ? date('Y-m-d H:i', (int)$row['created_at']) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
