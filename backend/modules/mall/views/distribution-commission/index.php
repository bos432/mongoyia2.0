<?php

use common\helpers\Html;
use common\services\mall\DistributionCommissionService;
use common\services\mall\DistributionCommissionWorkflowService;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $status string */
/* @var $limit int */
/* @var $stores array */
/* @var $rules array */
/* @var $commissions array */
/* @var $summary array */
/* @var $statusLabels array */

$this->title = '分销佣金审核';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <form method="get" class="form-inline">
                        <select name="store_id" class="form-control form-control-sm mr-2">
                            <option value="0" <?= (int)$storeId === 0 ? 'selected' : '' ?>>全部店铺</option>
                            <?php foreach ($stores as $id => $name): ?>
                                <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="commission_status" class="form-control form-control-sm mr-2">
                            <option value="" <?= $status === '' ? 'selected' : '' ?>>全部状态</option>
                            <?php foreach ($statusLabels as $key => $label): ?>
                                <option value="<?= Html::encode($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="limit" class="form-control form-control-sm mr-2" type="number" min="1" max="500" value="<?= (int)$limit ?>">
                        <button class="btn btn-primary btn-sm" type="submit">查看佣金</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">分销佣金审核入口：本页展示规则、佣金账本和状态汇总；审核只更新佣金状态，不处理提现，也不会触发真实打款。</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">分销规则</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>规则</th>
                        <th>店铺</th>
                        <th>比例</th>
                        <th>最低订单金额</th>
                        <th>状态</th>
                        <th>备注</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rules)): ?>
                        <tr><td colspan="6" class="text-muted text-center">暂无分销规则</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rules as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?><br><small class="text-muted"><?= Html::encode($row['name']) ?></small></td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= number_format((float)$row['commission_rate'], 2) ?>%</td>
                            <td><?= number_format((float)$row['min_order_amount'], 2) ?></td>
                            <td><span class="badge badge-info"><?= Html::encode($row['rule_status']) ?></span></td>
                            <td><?= Html::encode($row['remark']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">佣金状态汇总</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>店铺</th>
                        <th>状态</th>
                        <th>笔数</th>
                        <th>订单金额</th>
                        <th>佣金金额</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($summary)): ?>
                        <tr><td colspan="5" class="text-muted text-center">暂无佣金汇总</td></tr>
                    <?php endif; ?>
                    <?php foreach ($summary as $row): ?>
                        <tr>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= Html::encode($statusLabels[$row['commission_status']] ?? $row['commission_status']) ?></td>
                            <td><?= (int)$row['rows'] ?></td>
                            <td><?= number_format((float)$row['order_amount'], 2) ?></td>
                            <td><?= number_format((float)$row['commission_amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">佣金账本</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>佣金</th>
                        <th>订单</th>
                        <th>店铺</th>
                        <th>分销员</th>
                        <th>订单金额</th>
                        <th>比例</th>
                        <th>佣金金额</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($commissions)): ?>
                        <tr><td colspan="10" class="text-muted text-center">暂无佣金记录</td></tr>
                    <?php endif; ?>
                    <?php foreach ($commissions as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?><br><small class="text-muted"><?= Html::encode($row['source']) ?></small></td>
                            <td>#<?= (int)$row['order_id'] ?><br><small class="text-muted"><?= Html::encode($row['order_sn']) ?></small></td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= (int)$row['distributor_user_id'] ?></td>
                            <td><?= number_format((float)$row['order_amount'], 2) ?></td>
                            <td><?= number_format((float)$row['commission_rate'], 2) ?>%</td>
                            <td><?= number_format((float)$row['commission_amount'], 2) ?></td>
                            <td><span class="badge badge-info"><?= Html::encode($statusLabels[$row['commission_status']] ?? $row['commission_status']) ?></span></td>
                            <td><?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?></td>
                            <td>
                                <?php if ((string)$row['commission_status'] === DistributionCommissionService::COMMISSION_STATUS_PENDING): ?>
                                    <?php foreach ([DistributionCommissionWorkflowService::ACTION_APPROVE => ['通过', 'btn-outline-success'], DistributionCommissionWorkflowService::ACTION_REJECT => ['驳回', 'btn-outline-warning']] as $action => $button): ?>
                                        <form method="post" action="<?= Html::encode(Url::to(['workflow'])) ?>" class="d-inline">
                                            <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="workflow_action" value="<?= Html::encode($action) ?>">
                                            <button type="submit" class="btn <?= Html::encode($button[1]) ?> btn-sm mb-1"><?= Html::encode($button[0]) ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">无操作</span>
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
