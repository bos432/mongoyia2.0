<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $isPlatformOperator bool */
/* @var $ticket array */
/* @var $events array */
/* @var $evidenceFiles array */
/* @var $ratingRows array */
/* @var $workflowTargets array */
/* @var $complaintLoopSummary array */
/* @var $complaintCategories array */
/* @var $complaintEvidenceRoles array */
/* @var $complaintStatusLabels array */
/* @var $assistanceTypes array */

$this->title = '客服工单详情';
$this->params['breadcrumbs'][] = ['label' => '客服', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => '客服工单只读', 'url' => ['tickets']];
$this->params['breadcrumbs'][] = $this->title;

$typeLabels = [
    'order_assist' => '订单协助',
    'complaint' => '投诉',
];
$statusLabels = [
    'pending' => '待受理',
    'in_progress' => '处理中',
    'seller_proof' => '待商家举证',
    'platform_review' => '待平台复核',
    'resolved' => '已解决',
    'closed' => '已关闭',
    'rejected' => '驳回',
];
$eventLabels = [
    'create' => '创建',
    'note' => '备注',
    'status_change' => '状态变更',
];
$workflowButtons = [
    'in_progress' => ['label' => '开始处理', 'class' => 'btn-outline-primary'],
    'seller_proof' => ['label' => '要求商家举证', 'class' => 'btn-outline-warning'],
    'platform_review' => ['label' => '提交平台复核', 'class' => 'btn-outline-info'],
    'resolved' => ['label' => '标记解决', 'class' => 'btn-outline-success'],
    'rejected' => ['label' => '驳回投诉', 'class' => 'btn-outline-danger'],
    'closed' => ['label' => '关闭工单', 'class' => 'btn-outline-secondary'],
];
$ratingLabels = [
    'satisfied' => '满意',
    'neutral' => '一般',
    'dissatisfied' => '不满意',
];
$complaintLoopSummary = $complaintLoopSummary ?? [];
$complaintCategories = $complaintCategories ?? [];
$complaintEvidenceRoles = $complaintEvidenceRoles ?? [];
$complaintStatusLabels = $complaintStatusLabels ?? $statusLabels;
$assistanceTypes = $assistanceTypes ?? [];
$complaintProofRows = (array)($complaintLoopSummary['proofs'] ?? []);
$complaintLinkedAssistanceRows = (array)($complaintLoopSummary['linked_assistance_tickets'] ?? []);
$complaintNextStatuses = (array)($complaintLoopSummary['next_statuses'] ?? $workflowTargets);
?>

<div class="row" data-mongoyia-customer-service-ticket-readonly="view">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
            </div>
            <div class="card-body">
                <p class="text-muted">MONGOYIA_CUSTOMER_SERVICE_TICKET_ASSIGN_BACKEND_V1：本页可分配客服处理人；MONGOYIA_CUSTOMER_SERVICE_TICKET_NOTE_BACKEND_V1：本页可追加内部处理备注；MONGOYIA_CUSTOMER_SERVICE_TICKET_RESULT_BACKEND_V1：本页可审计化写回处理结果；MONGOYIA_CUSTOMER_SERVICE_TICKET_WORKFLOW_BACKEND_V1：本页展示工单详情和事件流水；MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_EVIDENCE_UPLOAD_PHASE8_V1：投诉工单支持图片证据上传、查看和未审核删除；当前不提供退款、改订单、改支付、改库存、发消息等操作。</p>
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

        <?php if ((string)$ticket['ticket_type'] === 'complaint'): ?>
        <div class="card" data-mongoyia-customer-service-complaint-evidence-upload="enabled">
            <div class="card-header">
                <h3 class="card-title">投诉证据</h3>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">仅支持 png/jpg/jpeg/webp 图片，单文件最大 5MB；文件存储在非公开 runtime 目录，上传和删除都会追加客服事件审计，不改变工单状态。</p>
                <form method="post" action="<?= Html::encode(Url::to(['complaint-evidence-upload'])) ?>" enctype="multipart/form-data" class="mb-3">
                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>图片证据</label>
                            <input type="file" name="evidence_file" class="form-control-file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>说明</label>
                            <input type="text" name="note" class="form-control form-control-sm" maxlength="255" placeholder="例如：买家上传的破损照片">
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-outline-primary btn-sm">上传证据</button>
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0">
                        <thead>
                        <tr>
                            <th>文件</th>
                            <th>大小</th>
                            <th>上传人</th>
                            <th>时间</th>
                            <th>说明</th>
                            <th>SHA256</th>
                            <th style="width: 150px;">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($evidenceFiles)): ?>
                            <tr><td colspan="7" class="text-muted text-center">暂无投诉证据</td></tr>
                        <?php endif; ?>
                        <?php foreach ($evidenceFiles as $file): ?>
                            <tr>
                                <td><?= Html::encode($file['original_name']) ?></td>
                                <td><?= number_format(((int)$file['bytes']) / 1024, 1) ?> KB</td>
                                <td><?= Html::encode(($file['operator_type'] ?? '') . ' #' . (int)$file['uploaded_by']) ?></td>
                                <td><?= (int)$file['uploaded_at'] > 0 ? date('Y-m-d H:i', (int)$file['uploaded_at']) : '' ?></td>
                                <td><?= Html::encode((string)($file['note'] ?? '')) ?></td>
                                <td><code><?= Html::encode(substr((string)$file['sha256'], 0, 16)) ?></code></td>
                                <td>
                                    <a class="btn btn-outline-primary btn-sm mb-1" target="_blank" href="<?= Html::encode(Url::to(['complaint-evidence-view', 'id' => (int)$ticket['id'], 'evidence_id' => (string)$file['id']])) ?>">查看</a>
                                    <?php if ((int)($file['reviewed_at'] ?? 0) <= 0): ?>
                                        <form method="post" action="<?= Html::encode(Url::to(['complaint-evidence-delete'])) ?>" class="d-inline-block mb-1" onsubmit="return confirm('确认删除这条未审核证据？');">
                                            <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                            <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                                            <input type="hidden" name="evidence_id" value="<?= Html::encode((string)$file['id']) ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">删除</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card" data-mongoyia-customer-service-complaint-loop="backend">
            <div class="card-header">
                <h3 class="card-title">投诉闭环处理</h3>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">MONGOYIA_CUSTOMER_SERVICE_COMPLAINT_LOOP_BACKEND_V1：分类、用户/客服/商家/平台证据、商家举证、平台复核、处理结论、用户反馈和后续协助单均写入工单审计；不直接退款、赔付、改订单、改支付、改库存。</p>
                <div class="row">
                    <div class="col-md-4">
                        <table class="table table-bordered table-sm">
                            <tbody>
                            <tr>
                                <th style="width: 120px;">投诉分类</th>
                                <td><?= Html::encode((string)($complaintLoopSummary['category_label'] ?? '未分类')) ?></td>
                            </tr>
                            <tr>
                                <th>可流转状态</th>
                                <td>
                                    <?php if (empty($complaintNextStatuses)): ?>
                                        <span class="text-muted">无</span>
                                    <?php endif; ?>
                                    <?php foreach ($complaintNextStatuses as $status): ?>
                                        <span class="badge badge-light"><?= Html::encode($complaintStatusLabels[$status] ?? $status) ?></span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>处理结论</th>
                                <td><?= nl2br(Html::encode((string)($complaintLoopSummary['conclusion']['content'] ?? ''))) ?></td>
                            </tr>
                            <tr>
                                <th>用户反馈</th>
                                <td><?= nl2br(Html::encode((string)($complaintLoopSummary['user_feedback']['content'] ?? ''))) ?></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-8">
                        <form method="post" action="<?= Html::encode(Url::to(['complaint-loop-step'])) ?>">
                            <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label>投诉分类</label>
                                    <select name="category" class="form-control form-control-sm">
                                        <option value="">保持不变</option>
                                        <?php foreach ($complaintCategories as $key => $label): ?>
                                            <option value="<?= Html::encode($key) ?>" <?= (string)($complaintLoopSummary['category'] ?? '') === (string)$key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>证据角色</label>
                                    <select name="evidence_role" class="form-control form-control-sm">
                                        <?php foreach ($complaintEvidenceRoles as $key => $label): ?>
                                            <option value="<?= Html::encode($key) ?>"><?= Html::encode($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>状态流转</label>
                                    <select name="target_status" class="form-control form-control-sm">
                                        <option value="">只记录，不变更状态</option>
                                        <?php foreach ($complaintNextStatuses as $status): ?>
                                            <option value="<?= Html::encode($status) ?>"><?= Html::encode($complaintStatusLabels[$status] ?? $status) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>举证/处理说明</label>
                                <textarea name="evidence_note" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                            </div>
                            <div class="form-group">
                                <label>处理结论</label>
                                <textarea name="conclusion" class="form-control form-control-sm" rows="3" maxlength="4000"><?= Html::encode((string)($complaintLoopSummary['conclusion']['content'] ?? '')) ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>用户反馈</label>
                                <textarea name="user_feedback" class="form-control form-control-sm" rows="2" maxlength="2000"><?= Html::encode((string)($complaintLoopSummary['user_feedback']['content'] ?? '')) ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>事件备注</label>
                                <input type="text" name="event_content" class="form-control form-control-sm" maxlength="1000" placeholder="例如：已要求商家 48 小时内补充质检照片">
                            </div>
                            <button type="submit" class="btn btn-outline-primary btn-sm">记录投诉步骤</button>
                        </form>
                    </div>
                </div>

                <div class="table-responsive mt-3">
                    <table class="table table-bordered table-sm mb-0">
                        <thead>
                        <tr>
                            <th>证据角色</th>
                            <th>说明</th>
                            <th>操作人</th>
                            <th>时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($complaintProofRows)): ?>
                            <tr><td colspan="4" class="text-muted text-center">暂无闭环举证记录</td></tr>
                        <?php endif; ?>
                        <?php foreach ($complaintProofRows as $proof): ?>
                            <tr>
                                <td><?= Html::encode($complaintEvidenceRoles[$proof['role'] ?? ''] ?? ($proof['role'] ?? '')) ?></td>
                                <td><?= nl2br(Html::encode((string)($proof['note'] ?? ''))) ?></td>
                                <td><?= Html::encode(($proof['operator_type'] ?? '') . ' #' . (int)($proof['operator_user_id'] ?? 0)) ?></td>
                                <td><?= (int)($proof['created_at'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$proof['created_at']) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr>
                <form method="post" action="<?= Html::encode(Url::to(['complaint-link-assistance'])) ?>" data-mongoyia-customer-service-complaint-link-assistance="form">
                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>后续协助类型</label>
                            <select name="assistance_type" class="form-control form-control-sm" required>
                                <?php foreach ($assistanceTypes as $key => $definition): ?>
                                    <option value="<?= Html::encode($key) ?>"><?= Html::encode((string)$definition['label']) ?><?= !empty($definition['approval_required']) ? '（需审批）' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-8">
                            <label>协助说明</label>
                            <input type="text" name="content" class="form-control form-control-sm" maxlength="1000" placeholder="例如：投诉判定需发起退款建议，等待售后审批">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-warning btn-sm">生成后续协助单</button>
                </form>

                <div class="table-responsive mt-3">
                    <table class="table table-bordered table-sm mb-0">
                        <thead>
                        <tr>
                            <th>协助单</th>
                            <th>类型</th>
                            <th>来源</th>
                            <th>说明</th>
                            <th>时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($complaintLinkedAssistanceRows)): ?>
                            <tr><td colspan="5" class="text-muted text-center">暂无后续协助单</td></tr>
                        <?php endif; ?>
                        <?php foreach ($complaintLinkedAssistanceRows as $linked): ?>
                            <tr>
                                <td>
                                    <a href="<?= Html::encode(Url::to(['ticket-view', 'id' => (int)($linked['ticket_id'] ?? 0)])) ?>">
                                        #<?= (int)($linked['ticket_id'] ?? 0) ?> <?= Html::encode((string)($linked['ticket_sn'] ?? '')) ?>
                                    </a>
                                </td>
                                <td><?= Html::encode((string)($assistanceTypes[$linked['assistance_type'] ?? '']['label'] ?? ($linked['assistance_type'] ?? ''))) ?></td>
                                <td><?= !empty($linked['created']) ? '新建' : '已存在' ?></td>
                                <td><?= Html::encode((string)($linked['note'] ?? '')) ?></td>
                                <td><?= (int)($linked['created_at'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$linked['created_at']) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card" data-mongoyia-customer-service-rating="backend">
            <div class="card-header">
                <h3 class="card-title">满意度评价</h3>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>时间</th>
                        <th>评价</th>
                        <th>用户</th>
                        <th>原因</th>
                        <th>备注</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($ratingRows)): ?>
                        <tr><td colspan="5" class="text-muted text-center">暂无满意度评价</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ratingRows as $row): ?>
                        <tr>
                            <td><?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?></td>
                            <td><?= Html::encode($ratingLabels[$row['rating']] ?? $row['rating']) ?> (<?= (int)$row['rating_score'] ?>)</td>
                            <td><?= (int)$row['customer_user_id'] ?> <?= Html::encode($row['customer_uuid'] ?? '') ?></td>
                            <td><?= Html::encode($row['reason'] ?? '') ?></td>
                            <td><?= nl2br(Html::encode((string)($row['remark'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
