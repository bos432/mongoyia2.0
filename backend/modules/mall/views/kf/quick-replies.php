<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $isPlatformOperator bool */
/* @var $storeId int */
/* @var $stores array */
/* @var $categories array */
/* @var $categoryLabels array */
/* @var $filters array */
/* @var $rows array */

$this->title = '客服快捷回复';
$this->params['breadcrumbs'][] = ['label' => '客服', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="row" data-mongoyia-customer-service-quick-reply="index">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
                <div class="card-tools">
                    <form method="get" class="form-inline">
                        <?php if ($isPlatformOperator): ?>
                            <select name="store_id" class="form-control form-control-sm mr-2">
                                <option value="0" <?= (int)$storeId === 0 ? 'selected' : '' ?>>全部/通用</option>
                                <?php foreach ($stores as $id => $name): ?>
                                    <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <select name="category" class="form-control form-control-sm mr-2">
                            <option value="">全部分类</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= Html::encode($category) ?>" <?= (string)$filters['category'] === (string)$category ? 'selected' : '' ?>><?= Html::encode($categoryLabels[$category] ?? $category) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="keyword" class="form-control form-control-sm mr-2" type="search" value="<?= Html::encode((string)($filters['keyword'] ?? '')) ?>" placeholder="搜索标题/内容">
                        <button class="btn btn-primary btn-sm" type="submit">筛选</button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">MONGOYIA_CUSTOMER_SERVICE_QUICK_REPLY_PHASE8_V1：平台可维护通用话术和店铺话术，商家只能维护自己店铺话术；工作台选择话术后只插入输入框，不自动发送。</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">新增快捷回复</h3>
            </div>
            <div class="card-body">
                <form method="post" action="<?= Html::encode(Url::to(['quick-reply-save'])) ?>">
                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                    <div class="form-row">
                        <div class="form-group col-md-2">
                            <label>分类</label>
                            <select name="category" class="form-control form-control-sm">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= Html::encode($category) ?>"><?= Html::encode($categoryLabels[$category] ?? $category) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isPlatformOperator): ?>
                            <div class="form-group col-md-2">
                                <label>通用话术</label>
                                <select name="is_global" class="form-control form-control-sm">
                                    <option value="1">平台通用</option>
                                    <option value="0">指定店铺</option>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>店铺</label>
                                <select name="store_id" class="form-control form-control-sm">
                                    <option value="0">通用/无店铺</option>
                                    <?php foreach ($stores as $id => $name): ?>
                                        <option value="<?= (int)$id ?>" <?= (int)$id === (int)$storeId ? 'selected' : '' ?>><?= Html::encode($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="form-group col-md-4">
                            <label>标题</label>
                            <input name="title" class="form-control form-control-sm" type="text" maxlength="120" required>
                        </div>
                        <div class="form-group col-md-2">
                            <label>排序</label>
                            <input name="sort" class="form-control form-control-sm" type="number" min="0" max="9999" value="50">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>回复内容</label>
                        <textarea name="content" class="form-control form-control-sm" rows="3" maxlength="1000" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">保存话术</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>范围</th>
                        <th>分类</th>
                        <th>标题</th>
                        <th>内容</th>
                        <th>排序</th>
                        <th>更新时间</th>
                        <th style="width: 120px;">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-muted text-center">暂无快捷回复</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td><?= (int)$row['is_global'] === 1 || (int)$row['store_id'] === 0 ? '平台通用' : ('店铺 #' . (int)$row['store_id']) ?></td>
                            <td><?= Html::encode($categoryLabels[$row['category']] ?? $row['category']) ?></td>
                            <td><?= Html::encode($row['title']) ?></td>
                            <td style="max-width: 480px; white-space: pre-wrap;"><?= Html::encode($row['content']) ?></td>
                            <td><?= (int)$row['sort'] ?></td>
                            <td><?= (int)$row['updated_at'] > 0 ? date('Y-m-d H:i', (int)$row['updated_at']) : '' ?></td>
                            <td>
                                <form method="post" action="<?= Html::encode(Url::to(['quick-reply-delete'])) ?>" onsubmit="return confirm('确认删除这条快捷回复？');">
                                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
