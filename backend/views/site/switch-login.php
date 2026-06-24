<?php
use yii\helpers\Html;

/* @var $this yii\web\View */

$logoutUrl = ['/site/logout'];
?>
<!doctype html>
<html lang="<?= Html::encode(Yii::$app->language) ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::encode(Yii::t('app', 'Logout')) ?></title>
</head>
<body data-mongoyia-backend-logout-post-switch-page="1">
<!-- MONGOYIA_BACKEND_LOGOUT_POST_SWITCH_PAGE_V4 -->
<main style="font-family:Arial,sans-serif;padding:32px;">
    <p>Switching account...</p>
    <?= Html::beginForm($logoutUrl, 'post', [
        'id' => 'backend-switch-login-form',
        'data-mongoyia-backend-logout-post-switch-form' => '1',
    ]) ?>
    <?= Html::submitButton(Yii::t('app', 'Logout'), [
        'id' => 'backend-switch-login-submit',
        'data-mongoyia-backend-logout-post-switch-submit' => '1',
    ]) ?>
    <?= Html::endForm() ?>
</main>
<script>
(function () {
    var form = document.getElementById('backend-switch-login-form');
    if (form) {
        form.submit();
    }
})();
</script>
</body>
</html>
