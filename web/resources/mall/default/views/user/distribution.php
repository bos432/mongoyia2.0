<?php

use yii\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $promotionLink string */
/* @var $summary array */
/* @var $commissions array */
/* @var $statusLabels array */
/* @var $withdrawSummary array */
/* @var $withdrawRows array */
/* @var $withdrawStatusLabels array */
/* @var $profile array|null */
/* @var $materials array */
/* @var $materialLanguageLabels array */
/* @var $supportLanguage string */
/* @var $supportLanguages array */
/* @var $supportContents array */
/* @var $supportTypeLabels array */
/* @var $riskRows array */
/* @var $profileStatusLabels array */
/* @var $inviteSummary array */
/* @var $inviteRewardStatusLabels array */

$this->title = Yii::t('app', 'Distribution');
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="page-section" data-mongoyia-mobile-ui="distribution">
    <div class="container">
        <div class="row page-center">
            <div class="col-md-12 p-0">
                <div class="card message-send-view">
                    <div class="card-header">
                        <?= $this->render('_nav', ['type' => $this->context->action->id]) ?>
                    </div>

                    <div class="card-body py-5">
                        <h4 class="mb-4">Distribution Center</h4>

                        <div class="mb-5">
                            <h5>Promotion Link</h5>
                            <div class="input-group">
                                <input class="form-control" readonly value="<?= Html::encode($promotionLink) ?>">
                            </div>
                            <p class="text-muted small mt-2 mb-0">Share this link to keep order attribution with your fxid=<?= (int)Yii::$app->user->id ?>.</p>
                        </div>

                        <div class="mb-5">
                            <h5>Distributor Profile</h5>
                            <form method="post" action="<?= Html::encode(Url::to(['/mall/user/distribution-profile'])) ?>" data-mongoyia-distribution-frontend-post-guard="profile">
                                <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <input class="form-control" name="display_name" maxlength="128" placeholder="Display Name" value="<?= Html::encode($profile['display_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <input class="form-control" name="contact_mobile" maxlength="64" placeholder="Mobile" value="<?= Html::encode($profile['contact_mobile'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <input class="form-control" name="contact_email" maxlength="128" placeholder="Email" value="<?= Html::encode($profile['contact_email'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <input class="form-control" name="channel" maxlength="128" placeholder="Channel" value="<?= Html::encode($profile['channel'] ?? '') ?>">
                                    </div>
                                </div>
                                <textarea class="form-control mb-3" name="bio" maxlength="255" rows="2" placeholder="Bio"><?= Html::encode($profile['bio'] ?? '') ?></textarea>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">Profile Status: <?= Html::encode($profileStatusLabels[$profile['profile_status'] ?? ''] ?? ($profile ? $profile['profile_status'] : 'not submitted')) ?></small>
                                    <button class="btn btn-outline-primary" type="submit">Submit Profile</button>
                                </div>
                            </form>
                        </div>

                        <div class="mb-5" data-mongoyia-phase15-distributor-training>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Training & FAQ</h5>
                                <div>
                                    <?php foreach ($supportLanguages as $key => $label): ?>
                                        <a class="btn btn-sm <?= $supportLanguage === $key ? 'btn-primary' : 'btn-outline-primary' ?> mb-1" href="<?= Html::encode(Url::current(['language' => $key])) ?>"><?= Html::encode($label) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if (empty($supportContents)): ?>
                                <p class="text-muted mb-0">No distributor training or FAQ content yet.</p>
                            <?php endif; ?>
                            <?php foreach ($supportContents as $row): ?>
                                <div class="border rounded p-3 mb-2">
                                    <div class="d-flex justify-content-between">
                                        <strong><?= Html::encode($row['title']) ?></strong>
                                        <small class="text-muted"><?= Html::encode($supportTypeLabels[$row['content_type']] ?? $row['content_type']) ?></small>
                                    </div>
                                    <?php if ((string)$row['category'] !== ''): ?>
                                        <div><small class="text-muted"><?= Html::encode($row['category']) ?></small></div>
                                    <?php endif; ?>
                                    <?php if ((string)$row['body'] !== ''): ?>
                                        <p class="mb-2"><?= nl2br(Html::encode($row['body'])) ?></p>
                                    <?php endif; ?>
                                    <?php if ((string)$row['support_url'] !== ''): ?>
                                        <a href="<?= Html::encode($row['support_url']) ?>" target="_blank" rel="noopener">Open resource</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mb-5" data-mongoyia-phase15-promotion-materials>
                            <h5>Promotion Materials</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Type / Language</th>
                                        <th>Content</th>
                                        <th>Resources</th>
                                        <th>Stats</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($materials)): ?>
                                        <tr><td colspan="5" class="text-muted text-center">No promotion materials yet.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($materials as $row): ?>
                                        <tr>
                                            <td><?= Html::encode($row['title']) ?></td>
                                            <td><?= Html::encode($row['material_type']) ?><br><small><?= Html::encode($materialLanguageLabels[$row['language'] ?? ''] ?? ($row['language'] ?? '')) ?></small></td>
                                            <td><?= Html::encode($row['content']) ?></td>
                                            <td>
                                                <?php if ((string)$row['target_url'] !== ''): ?>
                                                    <a class="btn btn-outline-primary btn-sm mb-1" href="<?= Html::encode(Url::to(['/mall/user/distribution-material-track', 'id' => (int)$row['id'], 'action_type' => 'copy'])) ?>" target="_blank" rel="noopener">Open Link</a>
                                                <?php endif; ?>
                                                <?php if ((string)($row['asset_url'] ?? '') !== '' && (int)($row['download_enabled'] ?? 1) > 0): ?>
                                                    <a class="btn btn-outline-secondary btn-sm mb-1" href="<?= Html::encode(Url::to(['/mall/user/distribution-material-track', 'id' => (int)$row['id'], 'action_type' => 'download'])) ?>" target="_blank" rel="noopener">Download</a>
                                                <?php endif; ?>
                                                <?php if ((string)($row['qr_code_url'] ?? '') !== ''): ?>
                                                    <div><img src="<?= Html::encode($row['qr_code_url']) ?>" alt="QR" style="max-width:72px;max-height:72px;"></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small>Open: <?= (int)($row['copy_count'] ?? 0) ?></small><br>
                                                <small>Download: <?= (int)($row['download_count'] ?? 0) ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mb-5">
                            <h5>Risk Records</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Level</th>
                                        <th>Type</th>
                                        <th>Content</th>
                                        <th>Status</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($riskRows)): ?>
                                        <tr><td colspan="4" class="text-muted text-center">No risk records.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($riskRows as $row): ?>
                                        <tr>
                                            <td><?= Html::encode($row['risk_level']) ?></td>
                                            <td><?= Html::encode($row['risk_type']) ?></td>
                                            <td><?= Html::encode($row['content']) ?></td>
                                            <td><?= Html::encode($row['risk_status']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mb-5">
                            <h5>Invite Rewards</h5>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <strong>Invited Users</strong>
                                    <div><?= count($inviteSummary['invites'] ?? []) ?></div>
                                </div>
                                <?php foreach (($inviteSummary['rewardSummary'] ?? []) as $row): ?>
                                    <div class="col-md-4">
                                        <strong><?= Html::encode($inviteRewardStatusLabels[$row['reward_status']] ?? $row['reward_status']) ?> Rewards</strong>
                                        <div><?= (int)$row['rows'] ?> / <?= $this->context->getNumberByCurrency((float)$row['reward_amount']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Invited User</th>
                                        <th>First Order</th>
                                        <th>Reward</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($inviteSummary['rewardRows'])): ?>
                                        <tr><td colspan="5" class="text-muted text-center">No invite rewards yet.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach (($inviteSummary['rewardRows'] ?? []) as $row): ?>
                                        <tr>
                                            <td><?= (int)$row['invited_user_id'] ?></td>
                                            <td>#<?= (int)$row['order_id'] ?><br><small class="text-muted"><?= Html::encode($row['order_sn']) ?></small></td>
                                            <td><?= $this->context->getNumberByCurrency((float)$row['reward_amount']) ?></td>
                                            <td><?= Html::encode($inviteRewardStatusLabels[$row['reward_status']] ?? $row['reward_status']) ?></td>
                                            <td><?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted small mb-0">Invite rewards are ledger records only; they do not trigger payout or fund logs.</p>
                        </div>

                        <div class="mb-5">
                            <h5>Withdrawal Request</h5>
                            <div class="row">
                                <div class="col-md-3">
                                    <strong>Available Amount</strong>
                                    <div><?= $this->context->getNumberByCurrency((float)$withdrawSummary['availableAmount']) ?></div>
                                </div>
                                <div class="col-md-3">
                                    <strong>Available Rows</strong>
                                    <div><?= (int)$withdrawSummary['availableRows'] ?></div>
                                </div>
                                <div class="col-md-3">
                                    <strong>Pending Amount</strong>
                                    <div><?= $this->context->getNumberByCurrency((float)$withdrawSummary['pendingWithdrawAmount']) ?></div>
                                </div>
                                <div class="col-md-3 text-md-right mt-3 mt-md-0">
                                    <form method="post" action="<?= Html::encode(Url::to(['/mall/user/distribution-withdraw'])) ?>" data-mongoyia-distribution-frontend-post-guard="withdraw">
                                        <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                        <button class="btn btn-primary" type="submit" <?= (float)$withdrawSummary['availableAmount'] <= 0 ? 'disabled' : '' ?>>Request Withdrawal</button>
                                    </form>
                                </div>
                            </div>
                            <p class="text-muted small mt-2 mb-0">Withdrawal requests are reviewed offline by the platform. This action does not trigger real payout.</p>
                        </div>

                        <div class="mb-5">
                            <h5>Commission Summary</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Rows</th>
                                        <th>Order Amount</th>
                                        <th>Commission Amount</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($summary)): ?>
                                        <tr><td colspan="4" class="text-muted text-center">No commission summary yet.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($summary as $row): ?>
                                        <tr>
                                            <td><?= Html::encode($statusLabels[$row['commission_status']] ?? $row['commission_status']) ?></td>
                                            <td><?= (int)$row['rows'] ?></td>
                                            <td><?= $this->context->getNumberByCurrency((float)$row['order_amount']) ?></td>
                                            <td><?= $this->context->getNumberByCurrency((float)$row['commission_amount']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div>
                            <h5>Commission Records</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Commission</th>
                                        <th>Order</th>
                                        <th>Store</th>
                                        <th>Order Amount</th>
                                        <th>Rate</th>
                                        <th>Commission Amount</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($commissions)): ?>
                                        <tr><td colspan="8" class="text-muted text-center">No commission records yet.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($commissions as $row): ?>
                                        <tr>
                                            <td>#<?= (int)$row['id'] ?></td>
                                            <td>#<?= (int)$row['order_id'] ?><br><small class="text-muted"><?= Html::encode($row['order_sn']) ?></small></td>
                                            <td><?= (int)$row['store_id'] ?></td>
                                            <td><?= $this->context->getNumberByCurrency((float)$row['order_amount']) ?></td>
                                            <td><?= number_format((float)$row['commission_rate'], 2) ?>%</td>
                                            <td><?= $this->context->getNumberByCurrency((float)$row['commission_amount']) ?></td>
                                            <td><?= Html::encode($statusLabels[$row['commission_status']] ?? $row['commission_status']) ?></td>
                                            <td><?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted small mb-0">Withdrawal and payout actions are reviewed separately by the platform; this user page is read-only.</p>
                        </div>

                        <div class="mt-5">
                            <h5>Withdrawal Records</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Request</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Commission IDs</th>
                                        <th>Applied</th>
                                        <th>Audited</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($withdrawRows)): ?>
                                        <tr><td colspan="6" class="text-muted text-center">No withdrawal records yet.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($withdrawRows as $row): ?>
                                        <tr>
                                            <td>#<?= (int)$row['id'] ?></td>
                                            <td><?= $this->context->getNumberByCurrency((float)$row['amount']) ?></td>
                                            <td><?= Html::encode($withdrawStatusLabels[$row['withdraw_status']] ?? $row['withdraw_status']) ?></td>
                                            <td><small><?= Html::encode($row['commission_ids']) ?></small></td>
                                            <td><?= (int)$row['created_at'] > 0 ? date('Y-m-d H:i', (int)$row['created_at']) : '' ?></td>
                                            <td><?= (int)$row['audited_at'] > 0 ? date('Y-m-d H:i', (int)$row['audited_at']) : '' ?></td>
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
    </div>
</div>
