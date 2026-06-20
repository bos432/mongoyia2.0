<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $isPlatformOperator bool */
/* @var $ticket array */
/* @var $events array */
/* @var $workflowTargets array */

$this->title = '客服工单详情';
$this->params['breadcrumbs'][] = ['label' => '客服', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => '客服工单只读', 'url' => ['tickets']];
$this->params['breadcrumbs'][] = $this->title;

$typeLabels = [
    'order_assist' => '订单协助',
    'complaint' => '投诉',
];
$statusLabels = [
    'pending' => '待处理',
    'in_progress' => '处理中',
    'resolved' => '已解决',
    'closed' => '已关闭',
];
$eventLabels = [
    'create' => '创建',
    'note' => '备注',
    'status_change' => '状态变更',
];
$workflowButtons = [
    'in_progress' => ['label' => '开始处理', 'class' => 'btn-outline-primary'],
    'resolved' => ['label' => '标记解决', 'class' => 'btn-outline-success'],
    'closed' => ['label' => '关闭工单', 'class' => 'btn-outline-secondary'],
];
?>

<div class="row" data-mongoyia-customer-service-ticket-readonly="view">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
            </div>
            <div class="card-body">
                <p class="text-muted">MONGOYIA_CUSTOMER_SERVICE_TICKET_ASSIGN_BACKEND_V1：本页可分配客服处理人；MONGOYIA_CUSTOMER_SERVICE_TICKET_NOTE_BACKEND_V1：本页可追加内部处理备注；MONGOYIA_CUSTOMER_SERVICE_TICKET_RESULT_BACKEND_V1：本页可审计化写回处理结果；MONGOYIA_CUSTOMER_SERVICE_TICKET_WORKFLOW_BACKEND_V1：本页展示工单详情和事件流水；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_GATE_V1：投诉证据上传/写处理仅做 gate，占位按钮禁用；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_POLICY_GATE_V1：投诉证据上传文件策略仅做 gate；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_IMPLEMENTATION_GATE_V1：投诉证据上传实现契约仅做 gate；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_CLEANUP_READINESS_V1：投诉证据上传清理范围仅做 readiness；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_ENABLEMENT_GATE_V1：后台上传控件启用条件仅做 gate；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_APPLY_WORKFLOW_V1：证据写入仅允许 CLI audited apply；当前仅开放分配、备注、结果写回和状态流转；不提供退款、改订单、发消息、上传文件等操作。</p>
                <table class="table table-bordered mb-0">
                    <tbody>
                    <tr>
                        <th style="width: 160px;">工单号</th>
                        <td><?= Html::encode($ticket['ticket_sn']) ?></td>
                        <th style="width: 160px;">状态</th>
                        <td><?= Html::encode($statusLabels[$ticket['ticket_status']] ?? $ticket['ticket_status']) ?></td>
                    </tr>
                    <tr>
                        <th>类型</th>
                        <td><?= Html::encode($typeLabels[$ticket['ticket_type']] ?? $ticket['ticket_type']) ?></td>
                        <th>优先级</th>
                        <td><?= Html::encode($ticket['priority']) ?></td>
                    </tr>
                    <tr>
                        <th>店铺</th>
                        <td><?= (int)$ticket['store_id'] ?></td>
                        <th>商品</th>
                        <td><?= (int)$ticket['product_id'] ?></td>
                    </tr>
                    <tr>
                        <th>订单</th>
                        <td><?= (int)$ticket['order_id'] ?> <?= Html::encode($ticket['order_sn'] ?? '') ?></td>
                        <th>聊天会话</th>
                        <td><?= Html::encode($ticket['chat_uuid'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <th>用户</th>
                        <td><?= (int)$ticket['customer_user_id'] ?> <?= Html::encode($ticket['customer_uuid'] ?? '') ?></td>
                        <th>商家/平台客服</th>
                        <td><?= (int)$ticket['merchant_user_id'] ?> / <?= (int)$ticket['platform_user_id'] ?></td>
                    </tr>
                    <tr>
                        <th>标题</th>
                        <td colspan="3"><?= Html::encode($ticket['title']) ?></td>
                    </tr>
                    <tr>
                        <th>内容</th>
                        <td colspan="3"><?= nl2br(Html::encode((string)($ticket['content'] ?? ''))) ?></td>
                    </tr>
                    <tr>
                        <th>处理结果</th>
                        <td colspan="3"><?= nl2br(Html::encode((string)($ticket['result'] ?? ''))) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" data-mongoyia-customer-service-ticket-assign="form">
            <div class="card-header">
                <h3 class="card-title">分配处理人</h3>
            </div>
            <div class="card-body">
                <form method="post" action="<?= Html::encode(Url::to(['ticket-assign'])) ?>">
                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>处理人类型</label>
                            <?php if ($isPlatformOperator): ?>
                                <select name="assignment_type" class="form-control form-control-sm" required>
                                    <option value="merchant">商家客服</option>
                                    <option value="platform">平台客服</option>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="assignment_type" value="merchant">
                                <input class="form-control form-control-sm" type="text" value="商家客服" disabled>
                            <?php endif; ?>
                        </div>
                        <div class="form-group col-md-3">
                            <label>处理人用户ID</label>
                            <input name="assignee_user_id" class="form-control form-control-sm" type="number" min="1" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>备注</label>
                            <input name="remark" class="form-control form-control-sm" type="text" maxlength="255">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">分配处理人</button>
                </form>
            </div>
        </div>

        <div class="card" data-mongoyia-customer-service-ticket-note="form">
            <div class="card-header">
                <h3 class="card-title">处理备注</h3>
            </div>
            <div class="card-body">
                <form method="post" action="<?= Html::encode(Url::to(['ticket-note'])) ?>">
                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                    <div class="form-group">
                        <textarea name="content" class="form-control form-control-sm" rows="3" maxlength="4000" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">添加备注</button>
                </form>
            </div>
        </div>

        <div class="card" data-mongoyia-customer-service-ticket-result="form">
            <div class="card-header">
                <h3 class="card-title">处理结果写回</h3>
            </div>
            <div class="card-body">
                <form method="post" action="<?= Html::encode(Url::to(['ticket-result'])) ?>">
                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                    <div class="form-group">
                        <textarea name="result" class="form-control form-control-sm" rows="4" maxlength="4000" required><?= Html::encode((string)($ticket['result'] ?? '')) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-success btn-sm">写回处理结果</button>
                </form>
            </div>
        </div>

        <div class="card" data-mongoyia-customer-service-complaint-evidence-gate="reserved">
            <div class="card-header">
                <h3 class="card-title">投诉证据 Gate</h3>
            </div>
            <div class="card-body">
                <p class="text-muted mb-2">投诉证据上传和后台 evidence_json 写处理保持禁用；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_POLICY_GATE_V1 仅保留图片类型/大小策略 gate；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_IMPLEMENTATION_GATE_V1 仅保留存储、审计契约 gate；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_CLEANUP_READINESS_V1 仅保留 fixture/tmp 清理范围 readiness；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_ENABLEMENT_GATE_V1 仅保留权限、UI、审计、回滚和清理启用门禁；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_APPLY_WORKFLOW_V1 仅允许 CLI audited apply 写入 evidence_json 并追加审计事件。</p>
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm mr-1"
                    data-mongoyia-customer-service-complaint-evidence-upload="disabled"
                    disabled
                >投诉证据上传待启用</button>
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-mongoyia-customer-service-complaint-evidence-apply="disabled"
                    disabled
                >投诉证据写入待启用</button>
            </div>
        </div>

        <?php if (!empty($workflowTargets)): ?>
            <div class="card" data-mongoyia-customer-service-ticket-workflow="actions">
                <div class="card-header">
                    <h3 class="card-title">状态操作</h3>
                </div>
                <div class="card-body">
                    <?php foreach ($workflowTargets as $targetStatus): ?>
                        <?php $button = $workflowButtons[$targetStatus] ?? ['label' => $statusLabels[$targetStatus] ?? $targetStatus, 'class' => 'btn-outline-primary']; ?>
                        <form method="post" action="<?= Html::encode(Url::to(['ticket-workflow'])) ?>" class="d-inline-block mb-1">
                            <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                            <input type="hidden" name="target_status" value="<?= Html::encode($targetStatus) ?>">
                            <button type="submit" class="btn <?= Html::encode($button['class']) ?> btn-sm">
                                <?= Html::encode($button['label']) ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">事件流水</h3>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>时间</th>
                        <th>类型</th>
                        <th>状态</th>
                        <th>操作人</th>
                        <th>内容</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($events)): ?>
                        <tr><td colspan="5" class="text-muted text-center">暂无事件流水</td></tr>
                    <?php endif; ?>
                    <?php foreach ($events as $row): ?>
                        <tr>
                            <td><?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?></td>
                            <td><?= Html::encode($eventLabels[$row['event_type']] ?? $row['event_type']) ?></td>
                            <td><?= Html::encode(($row['from_status'] ?? '') . ' -> ' . ($row['to_status'] ?? '')) ?></td>
                            <td><?= Html::encode(($row['operator_type'] ?? '') . ' #' . (int)$row['operator_user_id']) ?></td>
                            <td><?= nl2br(Html::encode((string)($row['content'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
