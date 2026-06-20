<?php

use common\helpers\Html;
use common\services\mall\DistributionWithdrawService;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $status string */
/* @var $limit int */
/* @var $withdrawRows array */
/* @var $summary array */
/* @var $statusLabels array */

$this->title = '分销提现审核';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <form method="get" class="form-inline">
                        <select name="withdraw_status" class="form-control form-control-sm mr-2">
                            <option value="" <?= $status === '' ? 'selected' : '' ?>>全部状态</option>
                            <?php foreach ($statusLabels as $key => $label): ?>
                                <option value="<?= Html::encode($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="limit" class="form-control form-control-sm mr-2" type="number" min="1" max="500" value="<?= (int)$limit ?>">
                        <button class="btn btn-primary btn-sm" type="submit">查看提现</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">分销提现审核入口：通过后只把关联佣金标记为已提现，不写资金流水，也不会触发真实打款。</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">提现状态汇总</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>状态</th>
                        <th>笔数</th>
                        <th>金额</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($summary)): ?>
                        <tr><td colspan="3" class="text-muted text-center">暂无提现汇总</td></tr>
                    <?php endif; ?>
                    <?php foreach ($summary as $row): ?>
                        <tr>
                            <td><?= Html::encode($statusLabels[$row['withdraw_status']] ?? $row['withdraw_status']) ?></td>
                            <td><?= (int)$row['rows'] ?></td>
                            <td><?= number_format((float)$row['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">提现申请</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>申请</th>
                        <th>分销员</th>
                        <th>金额</th>
                        <th>佣金ID</th>
                        <th>状态</th>
                        <th>申请备注</th>
                        <th>审核备注</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($withdrawRows)): ?>
                        <tr><td colspan="9" class="text-muted text-center">暂无提现申请</td></tr>
                    <?php endif; ?>
                    <?php foreach ($withdrawRows as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td><?= (int)$row['distributor_user_id'] ?></td>
                            <td><?= number_format((float)$row['amount'], 2) ?></td>
                            <td><small><?= Html::encode($row['commission_ids']) ?></small></td>
                            <td><span class="badge badge-info"><?= Html::encode($statusLabels[$row['withdraw_status']] ?? $row['withdraw_status']) ?></span></td>
                            <td><?= Html::encode($row['apply_remark']) ?></td>
                            <td><?= Html::encode($row['audit_remark']) ?></td>
                            <td>
                                <?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?><br>
                                <small class="text-muted"><?= (int)$row['audited_at'] > 0 ? date('Y-m-d H:i', (int)$row['audited_at']) : '' ?></small>
                            </td>
                            <td>
                                <?php if ((string)$row['withdraw_status'] === DistributionWithdrawService::WITHDRAW_STATUS_PENDING): ?>
                                    <?php foreach ([DistributionWithdrawService::ACTION_APPROVE => ['通过', 'btn-outline-success'], DistributionWithdrawService::ACTION_REJECT => ['驳回', 'btn-outline-warning']] as $action => $button): ?>
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
