<?php

use common\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $suid int */
/* @var $productId int */
/* @var $storeId int */
/* @var $customerUserId int */
/* @var $customerUuid string */
/* @var $ratingLabels array */

$this->title = '在线客服';
?>

<div class="customer-chat-page" data-mongoyia-customer-service-rating="frontend">
    <style>
        .customer-chat-page { max-width: 860px; margin: 16px auto; padding: 0 12px; }
        .customer-chat-box { border: 1px solid #e5e7eb; background: #fff; }
        .customer-chat-header { padding: 12px 14px; border-bottom: 1px solid #e5e7eb; font-weight: 600; display: flex; justify-content: space-between; gap: 12px; }
        .customer-chat-messages { min-height: 320px; max-height: 480px; overflow-y: auto; padding: 14px; background: #f9fafb; }
        .customer-chat-message { margin-bottom: 10px; }
        .customer-chat-message.sent { text-align: right; }
        .customer-chat-bubble { display: inline-block; max-width: 78%; padding: 8px 12px; border-radius: 6px; background: #fff; border: 1px solid #e5e7eb; word-break: break-word; }
        .customer-chat-message.sent .customer-chat-bubble { background: #16a34a; color: #fff; border-color: #16a34a; }
        .customer-chat-tools { padding: 12px 14px; border-top: 1px solid #e5e7eb; }
        .customer-chat-input-row { display: flex; gap: 8px; }
        .customer-chat-input-row textarea { flex: 1; min-height: 44px; resize: vertical; padding: 8px; border: 1px solid #d1d5db; }
        .customer-chat-input-row button, .rating-card button { padding: 8px 14px; border: 1px solid #16a34a; background: #16a34a; color: #fff; cursor: pointer; }
        .rating-card { margin-top: 14px; padding: 14px; border: 1px solid #e5e7eb; background: #fff; }
        .rating-card label { margin-right: 14px; }
        .rating-card input[type="text"], .rating-card textarea { width: 100%; margin-top: 8px; padding: 8px; border: 1px solid #d1d5db; }
        .customer-chat-status { color: #6b7280; font-size: 13px; }
    </style>

    <div class="customer-chat-box">
        <div class="customer-chat-header">
            <span>在线客服</span>
            <span class="customer-chat-status" id="chatStatus">连接中...</span>
        </div>
        <div class="customer-chat-messages" id="chatMessages">
            <div class="customer-chat-message"><span class="customer-chat-bubble">正在连接客服，请稍候。</span></div>
        </div>
        <div class="customer-chat-tools">
            <div class="customer-chat-input-row">
                <textarea id="chatInput" placeholder="请输入咨询内容"></textarea>
                <button type="button" id="sendTextBtn">发送</button>
            </div>
            <div style="margin-top:8px;">
                <input type="file" id="chatImageInput" accept="image/*">
            </div>
        </div>
    </div>

    <div class="rating-card">
        <h3 style="margin:0 0 10px;">服务评价</h3>
        <form id="ratingForm">
            <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
            <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
            <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
            <input type="hidden" name="customer_uuid" value="<?= Html::encode($customerUuid) ?>">
            <input type="hidden" name="chat_uuid" value="<?= Html::encode($customerUuid) ?>">
            <?php foreach ($ratingLabels as $value => $label): ?>
                <label><input type="radio" name="rating" value="<?= Html::encode($value) ?>" required> <?= Html::encode($label) ?></label>
            <?php endforeach; ?>
            <input type="text" name="reason" maxlength="255" placeholder="原因，可选">
            <textarea name="remark" rows="3" maxlength="1000" placeholder="补充说明，可选"></textarea>
            <button type="submit">提交评价</button>
            <span id="ratingStatus" class="customer-chat-status"></span>
        </form>
    </div>
</div>

<script>
    const chatConfig = {
        uid: <?= (int)$suid ?>,
        productId: <?= (int)$productId ?>,
        storeId: <?= (int)$storeId ?>,
        userId: <?= json_encode($customerUuid) ?>,
        tokenUrl: <?= json_encode(Url::to(['token'])) ?>,
        uploadUrl: <?= json_encode(Url::to(['upload'])) ?>,
        ratingUrl: <?= json_encode(Url::to(['rating-submit'])) ?>,
        wsAddress: <?= json_encode(Yii::$app->params['imWebsocketUrl'] ?? 'ws://127.0.0.1:8767') ?>
    };
    const chatMessages = document.getElementById('chatMessages');
    const chatStatus = document.getElementById('chatStatus');
    const chatInput = document.getElementById('chatInput');
    const sendTextBtn = document.getElementById('sendTextBtn');
    const chatImageInput = document.getElementById('chatImageInput');
    let chatWs = null;
    let chatToken = '';

    function addChatMessage(text, sent) {
        const row = document.createElement('div');
        row.className = 'customer-chat-message' + (sent ? ' sent' : '');
        const bubble = document.createElement('span');
        bubble.className = 'customer-chat-bubble';
        bubble.textContent = text;
        row.appendChild(bubble);
        chatMessages.appendChild(row);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    async function connectChat() {
        try {
            const params = new URLSearchParams({gid: String(chatConfig.productId), user_id: chatConfig.userId});
            const response = await fetch(chatConfig.tokenUrl + '?' + params.toString());
            const result = await response.json();
            chatToken = result && result.data ? result.data.token : '';
            chatWs = new WebSocket(chatConfig.wsAddress);
            chatWs.onopen = function () {
                chatWs.send(JSON.stringify({type: 'user', user_id: chatConfig.userId, uid: chatConfig.uid, product_id: chatConfig.productId, store_id: chatConfig.storeId, auth_token: chatToken}));
                chatStatus.textContent = '已连接';
                addChatMessage('客服已连接，可以开始咨询。', false);
            };
            chatWs.onmessage = function (event) {
                try {
                    const data = JSON.parse(event.data);
                    if (data.type === 'chat' && data.content) {
                        addChatMessage(data.msg_type == 2 ? '[图片] ' + data.content : data.content, false);
                    }
                } catch (e) {}
            };
            chatWs.onclose = function () { chatStatus.textContent = '已断开'; };
        } catch (e) {
            chatStatus.textContent = '连接失败';
        }
    }

    sendTextBtn.onclick = function () {
        const text = chatInput.value.trim();
        if (!text || !chatWs || chatWs.readyState !== WebSocket.OPEN) {
            return;
        }
        chatWs.send(JSON.stringify({type: 'chat', content: text, msg_type: 1, target_uid: chatConfig.uid, product_id: chatConfig.productId, store_id: chatConfig.storeId}));
        addChatMessage(text, true);
        chatInput.value = '';
    };

    chatImageInput.onchange = async function () {
        const file = chatImageInput.files[0];
        if (!file || !chatWs || chatWs.readyState !== WebSocket.OPEN) {
            chatImageInput.value = '';
            return;
        }
        const formData = new FormData();
        formData.append('file', file);
        const response = await fetch(chatConfig.uploadUrl, {method: 'POST', body: formData});
        const result = await response.json();
        const url = result && result.data ? result.data.url : result.url;
        if (url) {
            chatWs.send(JSON.stringify({type: 'chat', content: url, msg_type: 2, target_uid: chatConfig.uid, product_id: chatConfig.productId, store_id: chatConfig.storeId}));
            addChatMessage('[图片] ' + url, true);
        }
        chatImageInput.value = '';
    };

    document.getElementById('ratingForm').onsubmit = async function (event) {
        event.preventDefault();
        const status = document.getElementById('ratingStatus');
        status.textContent = '提交中...';
        const response = await fetch(chatConfig.ratingUrl, {method: 'POST', body: new FormData(event.target), headers: {'Accept': 'application/json'}});
        const result = await response.json();
        status.textContent = response.ok ? '评价已提交' : (result.msg || '提交失败');
    };

    connectChat();
</script>
