<?php

use common\helpers\Html;
use common\services\mall\DistributionInviteRewardWorkflowService;
use common\services\mall\DistributionInviteService;
use common\services\mall\DistributionProfileService;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $profileStatus string */
/* @var $limit int */
/* @var $profiles array */
/* @var $materials array */
/* @var $risks array */
/* @var $invites array */
/* @var $inviteRewards array */
/* @var $analyticsRows array */
/* @var $profileStatusLabels array */

$this->title = '分销员运营';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <form method="get" class="form-inline">
                        <select name="profile_status" class="form-control form-control-sm mr-2">
                            <option value="" <?= $profileStatus === '' ? 'selected' : '' ?>>全部资料状态</option>
                            <?php foreach ($profileStatusLabels as $key => $label): ?>
                                <option value="<?= Html::encode($key) ?>" <?= $profileStatus === $key ? 'selected' : '' ?>><?= Html::encode($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="limit" class="form-control form-control-sm mr-2" type="number" min="1" max="500" value="<?= (int)$limit ?>">
                        <button class="btn btn-primary btn-sm" type="submit">查看分销员</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">分销员运营入口：本页审核分销员资料、查看数据分析、推广素材、风险记录、邀请关系和邀请奖励；不处理佣金计算、提现和真实打款。</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">分销员数据分析</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>分销员</th>
                        <th>邀请/首单</th>
                        <th>佣金</th>
                        <th>已审佣金</th>
                        <th>提现</th>
                        <th>待审提现</th>
                        <th>邀请奖励</th>
                        <th>开放风险</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($analyticsRows)): ?>
                        <tr><td colspan="8" class="text-muted text-center">暂无分销统计</td></tr>
                    <?php endif; ?>
                    <?php foreach ($analyticsRows as $row): ?>
                        <tr>
                            <td><?= (int)$row['distributor_user_id'] ?></td>
                            <td><?= (int)$row['invite_count'] ?> / <?= (int)$row['first_order_count'] ?></td>
                            <td><?= (int)$row['commission_rows'] ?><br><small><?= number_format((float)$row['commission_amount'], 2) ?></small></td>
                            <td><?= number_format((float)$row['approved_commission_amount'], 2) ?></td>
                            <td><?= (int)$row['withdraw_rows'] ?><br><small><?= number_format((float)$row['withdraw_amount'], 2) ?></small></td>
                            <td><?= number_format((float)$row['pending_withdraw_amount'], 2) ?></td>
                            <td><?= (int)$row['invite_reward_rows'] ?><br><small><?= number_format((float)$row['invite_reward_amount'], 2) ?></small></td>
                            <td>
                                <?php if ((int)$row['open_risk_count'] > 0): ?>
                                    <span class="badge badge-warning"><?= (int)$row['open_risk_count'] ?></span>
                                <?php else: ?>
                                    <span class="badge badge-success">0</span>
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
                <h3 class="card-title">分销员资料审核</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>分销员</th>
                        <th>展示名称</th>
                        <th>联系方式</th>
                        <th>渠道</th>
                        <th>简介</th>
                        <th>状态</th>
                        <th>操作</th>
                        <th>审核备注</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($profiles)): ?>
                        <tr><td colspan="8" class="text-muted text-center">暂无分销员资料</td></tr>
                    <?php endif; ?>
                    <?php foreach ($profiles as $row): ?>
                        <tr>
                            <td><?= (int)$row['distributor_user_id'] ?></td>
                            <td><?= Html::encode($row['display_name']) ?></td>
                            <td>
                                <?= Html::encode($row['contact_mobile']) ?><br>
                                <small class="text-muted"><?= Html::encode($row['contact_email']) ?></small>
                            </td>
                            <td><?= Html::encode($row['channel']) ?></td>
                            <td><?= Html::encode($row['bio']) ?></td>
                            <td><span class="badge badge-info"><?= Html::encode($profileStatusLabels[$row['profile_status']] ?? $row['profile_status']) ?></span></td>
                            <td><?= Html::encode($row['audit_remark']) ?></td>
                            <td>
                                <?php if ((string)$row['profile_status'] === DistributionProfileService::PROFILE_STATUS_PENDING): ?>
                                    <?php foreach ([DistributionProfileService::ACTION_APPROVE => ['通过', 'btn-outline-success'], DistributionProfileService::ACTION_REJECT => ['驳回', 'btn-outline-warning']] as $action => $button): ?>
                                        <form method="post" action="<?= Html::encode(Url::to(['profile-workflow'])) ?>" class="d-inline">
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

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">推广素材</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>素材</th>
                        <th>类型</th>
                        <th>内容</th>
                        <th>目标链接</th>
                        <th>状态</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($materials)): ?>
                        <tr><td colspan="5" class="text-muted text-center">暂无推广素材</td></tr>
                    <?php endif; ?>
                    <?php foreach ($materials as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?><br><small><?= Html::encode($row['title']) ?></small></td>
                            <td><?= Html::encode($row['material_type']) ?></td>
                            <td><?= Html::encode($row['content']) ?></td>
                            <td><small><?= Html::encode($row['target_url']) ?></small></td>
                            <td><?= Html::encode($row['material_status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">风险记录</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>风险</th>
                        <th>分销员</th>
                        <th>类型</th>
                        <th>级别</th>
                        <th>内容</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($risks)): ?>
                        <tr><td colspan="7" class="text-muted text-center">暂无风险记录</td></tr>
                    <?php endif; ?>
                    <?php foreach ($risks as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td><?= (int)$row['distributor_user_id'] ?></td>
                            <td><?= Html::encode($row['risk_type']) ?></td>
                            <td><?= Html::encode($row['risk_level']) ?></td>
                            <td><?= Html::encode($row['content']) ?></td>
                            <td><span class="badge badge-warning"><?= Html::encode($row['risk_status']) ?></span></td>
                            <td>
                                <?php if ((string)$row['risk_status'] === DistributionProfileService::RISK_STATUS_OPEN): ?>
                                    <form method="post" action="<?= Html::encode(Url::to(['risk-workflow'])) ?>" class="d-inline">
                                        <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn btn-outline-success btn-sm mb-1">关闭</button>
                                    </form>
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

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">邀请关系</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>关系</th>
                        <th>分销员</th>
                        <th>被邀请用户</th>
                        <th>首单</th>
                        <th>状态</th>
                        <th>来源</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($invites)): ?>
                        <tr><td colspan="6" class="text-muted text-center">暂无邀请关系</td></tr>
                    <?php endif; ?>
                    <?php foreach ($invites as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td><?= (int)$row['distributor_user_id'] ?></td>
                            <td><?= (int)$row['invited_user_id'] ?></td>
                            <td><?= (int)$row['first_order_id'] > 0 ? ('#' . (int)$row['first_order_id']) : '未产生' ?></td>
                            <td><span class="badge badge-info"><?= Html::encode($row['invite_status']) ?></span></td>
                            <td><?= Html::encode($row['source']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">邀请奖励</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>奖励</th>
                        <th>分销员</th>
                        <th>被邀请用户</th>
                        <th>订单</th>
                        <th>金额</th>
                        <th>状态</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($inviteRewards)): ?>
                        <tr><td colspan="7" class="text-muted text-center">暂无邀请奖励</td></tr>
                    <?php endif; ?>
                    <?php foreach ($inviteRewards as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td><?= (int)$row['distributor_user_id'] ?></td>
                            <td><?= (int)$row['invited_user_id'] ?></td>
                            <td>#<?= (int)$row['order_id'] ?><br><small><?= Html::encode($row['order_sn']) ?></small></td>
                            <td><?= number_format((float)$row['reward_amount'], 2) ?></td>
                            <td><span class="badge badge-info"><?= Html::encode($row['reward_status']) ?></span></td>
                            <td>
                                <?php if ((string)$row['reward_status'] === DistributionInviteService::REWARD_STATUS_PENDING): ?>
                                    <?php foreach ([DistributionInviteRewardWorkflowService::ACTION_APPROVE => ['通过', 'btn-outline-success'], DistributionInviteRewardWorkflowService::ACTION_REJECT => ['驳回', 'btn-outline-warning']] as $action => $button): ?>
                                        <form method="post" action="<?= Html::encode(Url::to(['invite-reward-workflow'])) ?>" class="d-inline">
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
