<?php

use common\helpers\Html;
use common\models\mall\Order;
use common\services\mall\SettlementDraftService;
use common\services\mall\SettlementDraftWorkflowService;

/* @var $this yii\web\View */
/* @var $storeId int */
/* @var $limit int */
/* @var $draftId int */
/* @var $stores array */
/* @var $drafts array */
/* @var $evidenceRows array */
/* @var $orders array */

$this->title = '结算草案';
$this->params['breadcrumbs'][] = $this->title;

$statusLabels = [
    'draft' => '草案',
    'submitted' => '已提交',
    'approved' => '已审核',
    'rejected' => '已驳回',
    'cancelled' => '已取消',
    'closed' => '已关闭',
];

$workflowActions = [
    SettlementDraftService::DRAFT_STATUS_DRAFT => [
        SettlementDraftWorkflowService::ACTION_SUBMIT => ['label' => '提交', 'class' => 'btn-outline-primary'],
        SettlementDraftWorkflowService::ACTION_CANCEL => ['label' => '取消', 'class' => 'btn-outline-secondary'],
    ],
    SettlementDraftService::DRAFT_STATUS_SUBMITTED => [
        SettlementDraftWorkflowService::ACTION_APPROVE => ['label' => '通过', 'class' => 'btn-outline-success'],
        SettlementDraftWorkflowService::ACTION_REJECT => ['label' => '驳回', 'class' => 'btn-outline-warning'],
        SettlementDraftWorkflowService::ACTION_CANCEL => ['label' => '取消', 'class' => 'btn-outline-secondary'],
    ],
    SettlementDraftService::DRAFT_STATUS_REJECTED => [
        SettlementDraftWorkflowService::ACTION_CANCEL => ['label' => '取消', 'class' => 'btn-outline-secondary'],
    ],
];
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
                        <input name="limit" class="form-control form-control-sm mr-2" type="number" min="1" max="500" value="<?= (int)$limit ?>">
                        <button class="btn btn-primary btn-sm" type="submit">查看草案</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">结算草案入口：本页展示已生成的结算草案和订单明细，可记录线下打款凭证；不会写资金流水，也不会发起真实打款。</p>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>草案号</th>
                        <th>店铺</th>
                        <th>状态</th>
                        <th>订单数</th>
                        <th>订单金额</th>
                        <th>已扣物流费</th>
                        <th>拟结算金额</th>
                        <th>打款凭证</th>
                        <th>创建时间</th>
                        <th>明细</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($drafts)): ?>
                        <tr><td colspan="11" class="text-muted text-center">暂无结算草案</td></tr>
                    <?php endif; ?>
                    <?php foreach ($drafts as $row): ?>
                        <tr>
                            <td>
                                #<?= (int)$row['id'] ?><br>
                                <small class="text-muted"><?= Html::encode($row['sn']) ?></small>
                            </td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><span class="badge badge-info"><?= Html::encode($statusLabels[$row['draft_status']] ?? $row['draft_status']) ?></span></td>
                            <td><?= (int)$row['order_count'] ?></td>
                            <td><?= number_format((float)$row['order_amount'], 2) ?></td>
                            <td><?= number_format((float)$row['shipment_fee_deducted'], 2) ?></td>
                            <td><?= number_format((float)$row['net_amount'], 2) ?></td>
                            <td>
                                <?php $evidence = $evidenceRows[(int)$row['id']] ?? null; ?>
                                <?php if ($evidence): ?>
                                    <span class="badge badge-success"><?= Html::encode($evidence['evidence_status']) ?></span><br>
                                    <small class="text-muted"><?= Html::encode($evidence['transaction_no']) ?></small><br>
                                    <small><?= number_format((float)$evidence['amount'], 2) ?> <?= Html::encode($evidence['currency']) ?></small>
                                <?php elseif ((string)$row['draft_status'] === SettlementDraftService::DRAFT_STATUS_APPROVED): ?>
                                    <form method="post" action="<?= Html::encode(\yii\helpers\Url::to(['payout-evidence'])) ?>" class="mb-0" data-mongoyia-settlement-draft-post-guard="payout-evidence">
                                        <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="amount" value="<?= number_format((float)$row['net_amount'], 2, '.', '') ?>">
                                        <input type="hidden" name="currency" value="MNT">
                                        <input type="hidden" name="channel" value="offline">
                                        <input type="text" name="transaction_no" class="form-control form-control-sm mb-1" placeholder="线下流水号" required>
                                        <input type="text" name="evidence_file" class="form-control form-control-sm mb-1" placeholder="凭证文件/工单号">
                                        <input type="text" name="remark" class="form-control form-control-sm mb-1" placeholder="备注">
                                        <button type="submit" class="btn btn-outline-success btn-sm">记录凭证</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">未记录</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?></td>
                            <td>
                                <a class="btn btn-outline-primary btn-sm" href="<?= Html::encode(\yii\helpers\Url::to(['index', 'store_id' => $storeId, 'limit' => $limit, 'draft_id' => (int)$row['id']])) ?>">查看</a>
                            </td>
                            <td>
                                <?php foreach ($workflowActions[$row['draft_status']] ?? [] as $action => $button): ?>
                                    <form method="post" action="<?= Html::encode(\yii\helpers\Url::to(['workflow'])) ?>" class="d-inline" data-mongoyia-settlement-draft-post-guard="workflow">
                                        <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="workflow_action" value="<?= Html::encode($action) ?>">
                                        <button type="submit" class="btn <?= Html::encode($button['class']) ?> btn-sm mb-1"><?= Html::encode($button['label']) ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if ($evidence && (string)$row['draft_status'] === SettlementDraftService::DRAFT_STATUS_APPROVED): ?>
                                    <form method="post" action="<?= Html::encode(\yii\helpers\Url::to(['close'])) ?>" class="d-inline" data-mongoyia-settlement-draft-post-guard="close">
                                        <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn btn-outline-dark btn-sm mb-1">关闭结算</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">草案订单明细</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>订单</th>
                        <th>店铺</th>
                        <th>订单金额</th>
                        <th>已扣物流费</th>
                        <th>支付状态</th>
                        <th>物流状态</th>
                        <th>物流复核</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ((int)$draftId <= 0): ?>
                        <tr><td colspan="7" class="text-muted text-center">选择一个草案查看订单明细</td></tr>
                    <?php elseif (empty($orders)): ?>
                        <tr><td colspan="7" class="text-muted text-center">该草案暂无订单明细</td></tr>
                    <?php endif; ?>
                    <?php foreach ($orders as $row): ?>
                        <tr>
                            <td>
                                #<?= (int)$row['order_id'] ?><br>
                                <small class="text-muted"><?= Html::encode($row['order_sn']) ?></small>
                            </td>
                            <td><?= (int)$row['store_id'] ?></td>
                            <td><?= number_format((float)$row['order_amount'], 2) ?></td>
                            <td><?= number_format((float)$row['shipment_fee_deducted'], 2) ?></td>
                            <td><?= Html::encode(Order::getPaymentStatusLabels((int)$row['payment_status'])) ?></td>
                            <td><?= Html::encode(Order::getShipmentStatusLabels((int)$row['shipment_status'])) ?></td>
                            <td><?= Html::encode(Order::getLogisticsReviewStatusLabels((int)$row['logistics_review_status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
