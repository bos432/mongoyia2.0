<?php

use yii\grid\GridView;
use common\helpers\Html;
//use common\components\enums\YesNo;
use common\models\mall\Order as ActiveModel;
use yii\helpers\Inflector;
//use common\helpers\ArrayHelper;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */
/* @var $fxbfb common\models\ModelSearch */

$this->title = '客服';
$this->params['breadcrumbs'][] = $this->title;

function fxamount($amount){
//    return (float)$amount/100;
    $fxbfb = (int)(new \yii\db\Query)->from('fb_base_setting')->where(['id'=>884590952113504256])->one()['value'];
    return number_format($amount/100 * $fxbfb,2);
};
//echo '<pre/>';
//var_dump($fxbfb);exit();
?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            height: 100vh;
            display: flex;
        }

        .container {
            display: flex;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        /* 连接配置面板 */
        .config-panel {
            width: 300px;
            background: #f8f9fa;
            border-right: 1px solid #e0e0e0;
            padding: 20px;
        }

        .config-panel h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #333;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: #666;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-primary {
            background: #4CAF50;
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            background: #45a049;
        }

        .btn-danger {
            background: #f44336;
            color: white;
            width: 100%;
            margin-top: 10px;
        }

        .status {
            margin-top: 15px;
            padding: 10px;
            border-radius: 4px;
            font-size: 12px;
            text-align: center;
        }

        .status.connected {
            background: #d4edda;
            color: #155724;
        }

        .status.disconnected {
            background: #f8d7da;
            color: #721c24;
        }

        /* 聊天列表 */
        .chat-list {
            width: 280px;
            border-right: 1px solid #e0e0e0;
            overflow-y: auto;
        }

        .chat-list-header {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            background: #fafafa;
        }

        .chat-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.2s;
        }

        .chat-item:hover {
            background: #f5f5f5;
        }

        .chat-item.active {
            background: #e3f2fd;
        }

        .chat-item-name {
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .chat-item-preview {
            font-size: 12px;
            color: #999;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chat-item-time {
            font-size: 11px;
            color: #bbb;
            margin-top: 3px;
        }

        .chat-item-context {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }

        .chat-item-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .unread-badge {
            min-width: 18px;
            height: 18px;
            padding: 0 6px;
            border-radius: 9px;
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            line-height: 18px;
            text-align: center;
        }

        .chat-search {
            width: calc(100% - 24px);
            margin: 12px;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }

        .chat-empty {
            padding: 24px 15px;
            color: #999;
            font-size: 13px;
            text-align: center;
        }

        /* 聊天区域 */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 90vh;
            max-height: 90vh;
        }

        .chat-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            background: #fafafa;
        }

        .chat-header-context {
            display: block;
            margin-top: 4px;
            color: #6b7280;
            font-size: 12px;
            font-weight: normal;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f9f9f9;
        }

        .message {
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
        }

        .message.sent {
            align-items: flex-end;
        }

        .message.received {
            align-items: flex-start;
        }

        .message-bubble {
            /*max-width: 60%;*/
            padding: 10px 15px;
            border-radius: 18px;
            word-wrap: break-word;
        }

        .message.sent .message-bubble {
            background: #4CAF50;
            color: white;
        }

        .message.received .message-bubble {
            background: white;
            color: #333;
            border: 1px solid #e0e0e0;
        }

        .message-time {
            font-size: 11px;
            color: #999;
            margin-top: 5px;
        }

        .message-image {
            max-width: 200px;
            border-radius: 8px;
            cursor: pointer;
        }

        .system-message {
            text-align: center;
            margin: 16px 0;
            color: #999;
            font-size: 12px;
        }

        /* 输入区域 */
        .input-area {
            border-top: 1px solid #e0e0e0;
            padding: 15px;
            background: white;
        }

        .input-tools {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .tool-btn {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .tool-btn:hover {
            background: #f5f5f5;
            border-color: #ccc;
        }

        .emoji-picker {
            position: relative;
        }

        .emoji-panel {
            position: absolute;
            bottom: 40px;
            left: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: none;
            z-index: 1000;
        }

        .emoji-panel.show {
            display: block;
        }

        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 5px;
        }

        .emoji-item {
            font-size: 20px;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            text-align: center;
            transition: background 0.2s;
        }

        .emoji-item:hover {
            background: #f0f0f0;
        }

        .input-wrapper {
            display: flex;
            gap: 10px;
        }

        .message-input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            resize: none;
            min-height: 40px;
            max-height: 100px;
        }

        .send-btn {
            padding: 10px 30px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }

        .send-btn:hover {
            background: #45a049;
        }

        .send-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        /* 隐藏的文件输入 */
        #imageInput {
            display: none;
        }

        /* 欢迎提示 */
        .welcome-message {
            text-align: center;
            padding: 50px;
            color: #999;
        }

        /* 图片预览 */
        .image-preview {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }

        .image-preview img {
            max-width: 90%;
            max-height: 90%;
        }

        .image-preview.show {
            display: flex;
        }
    </style>
<div class="container">
    <!-- 配置面板 -->
    <div class="config-panel">
        <h2>客服工作台</h2>
        <button class="btn btn-primary" id="connectBtn">上线</button>
        <button class="btn btn-danger" id="disconnectBtn" style="display:none;">离线</button>
        <div id="connectionStatus" class="status disconnected">未连接</div>
    </div>

    <!-- 聊天列表 -->
    <div class="chat-list">
        <div class="chat-list-header">咨询列表</div>
        <input class="chat-search" id="chatSearch" type="search" placeholder="搜索用户ID">
        <div id="chatList"></div>
    </div>

    <!-- 聊天区域 -->
    <div class="chat-area">
        <div class="chat-header" id="chatHeader">请选择沟通对象</div>

        <div class="messages-container" id="messagesContainer">
            <div class="welcome-message">请先连接并选择沟通对象</div>
        </div>

        <div class="input-area">
            <div class="input-tools">
                <div class="emoji-picker">
                    <button class="tool-btn" id="emojiBtn">😊 表情</button>
                    <div class="emoji-panel" id="emojiPanel">
                        <div class="emoji-grid" id="emojiGrid"></div>
                    </div>
                </div>
                <button class="tool-btn" id="imageBtn">📷 图片</button>
                <input type="file" id="imageInput" accept="image/*">
            </div>
            <div class="input-wrapper">
                <textarea class="message-input" id="messageInput" placeholder="输入消息..."></textarea>
                <button class="send-btn" id="sendBtn" disabled>发送</button>
            </div>
        </div>
    </div>
</div>

<!-- 图片预览 -->
<div class="image-preview" id="imagePreview">
    <img id="previewImg" src="">
</div>

<script>
    const CONFIG = {
        userType: <?= json_encode(!empty($isPlatformOperator) ? 'platform' : 'merchant') ?>,
        userId: <?= (int)$uid;?>,
        isPlatformOperator: <?= !empty($isPlatformOperator) ? 'true' : 'false' ?>,
        storeMap: <?= json_encode($storeMap ?? [], JSON_UNESCAPED_UNICODE) ?>,
        authToken: <?= json_encode($imAuthToken ?? '') ?>,
        wsAddress: <?= json_encode(Yii::$app->params['imWebsocketUrl'] ?? 'ws://127.0.0.1:8767') ?>,
        uploadUrl: <?= json_encode(Yii::$app->params['chatUploadUrl'] ?? '/mall/chat/upload') ?>
    };

    let ws = null;
    let currentChatTarget = null;
    let isConnected = false;
    let manualDisconnect = false;
    let reconnectTimer = null;
    let chatListCache = [];
    const unreadMap = {};

    // 常用emoji列表
    const emojis = [
        '😊', '😂', '🤣', '😍', '😘', '😜', '🤔', '😎',
        '👍', '👎', '👏', '🙏', '💪', '❤️', '💔', '💕',
        '🎉', '🔥', '⭐', '☀️', '🌙', '☁️', '🌧️', '❄️',
        '🍎', '🍊', '🍋', '🍇', '🍉', '🍓', '🍑', '🍒',
        '🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼',
        '🏠', '🚗', '✈️', '🚀', '🚢', '⛵', '🚂', '🚁'
    ];

    // DOM元素
    const elements = {
        connectBtn: document.getElementById('connectBtn'),
        disconnectBtn: document.getElementById('disconnectBtn'),
        connectionStatus: document.getElementById('connectionStatus'),
        chatSearch: document.getElementById('chatSearch'),
        chatList: document.getElementById('chatList'),
        chatHeader: document.getElementById('chatHeader'),
        messagesContainer: document.getElementById('messagesContainer'),
        messageInput: document.getElementById('messageInput'),
        sendBtn: document.getElementById('sendBtn'),
        emojiBtn: document.getElementById('emojiBtn'),
        emojiPanel: document.getElementById('emojiPanel'),
        emojiGrid: document.getElementById('emojiGrid'),
        imageBtn: document.getElementById('imageBtn'),
        imageInput: document.getElementById('imageInput'),
        imagePreview: document.getElementById('imagePreview'),
        previewImg: document.getElementById('previewImg')
    };

    // 初始化emoji面板
    function initEmojiPanel() {
        elements.emojiGrid.innerHTML = '';
        emojis.forEach(emoji => {
            const emojiItem = document.createElement('span');
            emojiItem.className = 'emoji-item';
            emojiItem.textContent = emoji;
            emojiItem.onclick = () => insertEmoji(emoji);
            elements.emojiGrid.appendChild(emojiItem);
        });
    }

    // 插入emoji
    function insertEmoji(emoji) {
        elements.messageInput.value += emoji;
        elements.messageInput.focus();
        updateSendButton();
    }

    function addSystemMessage(text) {
        const systemDiv = document.createElement('div');
        systemDiv.className = 'system-message';
        systemDiv.textContent = text;
        elements.messagesContainer.appendChild(systemDiv);
        scrollToBottom();
    }

    function setWelcomeMessage(text) {
        elements.messagesContainer.innerHTML = '';
        const message = document.createElement('div');
        message.className = 'welcome-message';
        message.textContent = text;
        elements.messagesContainer.appendChild(message);
    }

    function scrollToBottom() {
        elements.messagesContainer.scrollTop = elements.messagesContainer.scrollHeight;
    }

    function updateSendButton() {
        elements.sendBtn.disabled = !isConnected || !currentChatTarget || elements.messageInput.value.trim().length === 0;
    }

    // 连接WebSocket
    function connect() {
        if (!CONFIG.userId) {
            addSystemMessage('当前账号缺少客服用户ID');
            return;
        }

        if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
            return;
        }

        manualDisconnect = false;
        clearTimeout(reconnectTimer);
        updateConnectionStatus(false, '连接中...');
        ws = new WebSocket(CONFIG.wsAddress);

        ws.onopen = () => {
            ws.send(JSON.stringify({
                type: CONFIG.userType,
                user_id: CONFIG.userId,
                auth_token: CONFIG.authToken
            }));

            isConnected = true;
            updateConnectionStatus(true);
            setWelcomeMessage('请选择左侧咨询会话');
            loadChatList();
        };

        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                handleWebSocketMessage(data);
            } catch (error) {
                addSystemMessage('收到无法解析的消息');
            }
        };

        ws.onerror = () => {
            addSystemMessage('WebSocket 连接异常，请检查 IM 服务');
        };

        ws.onclose = () => {
            isConnected = false;
            updateConnectionStatus(false);
            if (!manualDisconnect) {
                reconnectTimer = setTimeout(connect, 5000);
            }
        };
    }

    // 断开连接
    function disconnect() {
        manualDisconnect = true;
        clearTimeout(reconnectTimer);
        if (ws) {
            ws.close();
            ws = null;
        }
        isConnected = false;
        updateConnectionStatus(false);
        elements.chatList.innerHTML = '';
        setWelcomeMessage('已离线，点击上线后继续处理咨询');
    }

    // 更新连接状态
    function updateConnectionStatus(connected, label) {
        if (connected) {
            elements.connectionStatus.textContent = label || '已上线';
            elements.connectionStatus.className = 'status connected';
            elements.connectBtn.style.display = 'none';
            elements.disconnectBtn.style.display = 'block';
        } else {
            elements.connectionStatus.textContent = label || '未连接';
            elements.connectionStatus.className = 'status disconnected';
            elements.connectBtn.style.display = 'block';
            elements.disconnectBtn.style.display = 'none';
        }
        updateSendButton();
    }

    // 处理WebSocket消息
    function handleWebSocketMessage(data) {
        switch (data.type) {
            case 'heartbeat':
                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({type: 'heartbeat'}));
                }
                break;
            case 'chat_list':
                chatListCache = Array.isArray(data.list) ? data.list : [];
                displayChatList();
                break;
            case 'chat_history':
                displayChatHistory(Array.isArray(data.messages) ? data.messages : []);
                if (data.uuid) {
                    unreadMap[data.uuid] = 0;
                    loadChatList();
                }
                break;
            case 'chat':
                if (currentChatTarget && isCurrentChatMessage(data)) {
                    currentChatTarget.product_id = normalizePositiveInt(data.product_id) || normalizePositiveInt(currentChatTarget.product_id);
                    currentChatTarget.store_id = normalizePositiveInt(data.store_id) || normalizePositiveInt(currentChatTarget.store_id);
                    renderChatHeader();
                    displayMessage(data);
                    unreadMap[sessionKey(data)] = 0;
                    markCurrentChatRead();
                } else if (data.uuid) {
                    const key = sessionKey(data);
                    unreadMap[key] = (unreadMap[key] || 0) + 1;
                }
                loadChatList();
                break;
            case 'read_ack':
                if (data.uuid) {
                    unreadMap[sessionKey(data)] = 0;
                    loadChatList();
                }
                break;
            case 'error':
                addSystemMessage('错误: ' + (data.error || data.message || '未知错误'));
                break;
        }
    }

    // 加载聊天列表
    function loadChatList() {
        if (ws && isConnected && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
                type: 'chat_list'
            }));
        }
    }

    // 显示聊天列表
    function displayChatList() {
        elements.chatList.innerHTML = '';
        const keyword = elements.chatSearch.value.trim().toLowerCase();
        const list = chatListCache.filter(chat => !keyword || String(chat.uuid || '').toLowerCase().includes(keyword));

        if (list.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'chat-empty';
            empty.textContent = keyword ? '没有匹配的咨询会话' : '暂无咨询会话';
            elements.chatList.appendChild(empty);
            return;
        }

        list.forEach(chat => {
            const chatItem = document.createElement('div');
            chatItem.className = 'chat-item';
            if (currentChatTarget && sessionKey(chat) === sessionKey(currentChatTarget)) {
                chatItem.classList.add('active');
            }

            const name = document.createElement('div');
            name.className = 'chat-item-name';
            name.textContent = formatChatName(chat);

            const meta = document.createElement('div');
            meta.className = 'chat-item-meta';

            const preview = document.createElement('div');
            preview.className = 'chat-item-preview';
            preview.textContent = chat.content || '';
            meta.appendChild(preview);

            const serverUnread = normalizePositiveInt(chat.unread_count);
            const localUnread = unreadMap[sessionKey(chat)] || 0;
            const unread = currentChatTarget && sessionKey(chat) === sessionKey(currentChatTarget) ? 0 : Math.max(serverUnread, localUnread);
            if (unread > 0) {
                const badge = document.createElement('span');
                badge.className = 'unread-badge';
                badge.textContent = unread > 99 ? '99+' : String(unread);
                meta.appendChild(badge);
            }

            const time = document.createElement('div');
            time.className = 'chat-item-time';
            time.textContent = chat.time || '';

            const context = document.createElement('div');
            context.className = 'chat-item-context';
            context.textContent = formatChatContext(chat);

            chatItem.appendChild(name);
            chatItem.appendChild(meta);
            chatItem.appendChild(time);
            if (context.textContent) {
                chatItem.appendChild(context);
            }
            chatItem.onclick = () => openChat({
                uuid: chat.uuid,
                uid: normalizePositiveInt(chat.uid),
                product_id: normalizePositiveInt(chat.product_id),
                store_id: normalizePositiveInt(chat.store_id)
            }, chatItem);
            elements.chatList.appendChild(chatItem);
        });
    }

    // 打开聊天
    function openChat(target, selectedItem) {
        currentChatTarget = target;
        unreadMap[sessionKey(target)] = 0;

        renderChatHeader();

        document.querySelectorAll('.chat-item').forEach(item => {
            item.classList.remove('active');
        });
        if (selectedItem) {
            selectedItem.classList.add('active');
        }

        loadChatHistory(target);
        displayChatList();
        updateSendButton();
    }

    function isCurrentChatMessage(data) {
        if (!currentChatTarget || data.uuid != currentChatTarget.uuid) {
            return false;
        }

        return !CONFIG.isPlatformOperator || normalizePositiveInt(data.uid) === normalizePositiveInt(currentChatTarget.uid);
    }

    function sessionKey(chat) {
        const uuid = chat && chat.uuid ? String(chat.uuid) : '';
        if (!CONFIG.isPlatformOperator) {
            return uuid;
        }

        return normalizePositiveInt(chat && chat.uid) + '|' + uuid;
    }

    function markCurrentChatRead() {
        if (!currentChatTarget || !ws || ws.readyState !== WebSocket.OPEN) {
            return;
        }

        const data = {
            type: 'mark_read',
            uuid: currentChatTarget.uuid
        };
        if (CONFIG.isPlatformOperator) {
            data.uid = normalizePositiveInt(currentChatTarget.uid);
        }
        ws.send(JSON.stringify(data));
    }

    function renderChatHeader() {
        elements.chatHeader.textContent = formatChatName(currentChatTarget);
        const context = formatChatContext(currentChatTarget);
        if (context) {
            const contextNode = document.createElement('span');
            contextNode.className = 'chat-header-context';
            contextNode.textContent = context;
            elements.chatHeader.appendChild(contextNode);
        }
    }

    function formatChatContext(chat) {
        const parts = [];
        const productId = normalizePositiveInt(chat && chat.product_id);
        const storeId = normalizePositiveInt(chat && chat.store_id);
        if (productId > 0) {
            parts.push('商品 #' + productId);
        }
        if (storeId > 0) {
            parts.push('店铺 #' + storeId);
        }
        return parts.join(' · ');
    }

    function formatChatName(chat) {
        const userText = `用户 ${chat && chat.uuid ? chat.uuid : ''}`;
        if (!CONFIG.isPlatformOperator) {
            return userText;
        }

        const uid = normalizePositiveInt(chat && chat.uid);
        const store = uid > 0 ? CONFIG.storeMap[String(uid)] : null;
        if (store) {
            return `${store.name || '商家'} (#${store.id}) · ${userText}`;
        }

        return uid > 0 ? `商家用户 #${uid} · ${userText}` : userText;
    }

    function normalizePositiveInt(value) {
        const number = parseInt(value, 10);
        return number > 0 ? number : 0;
    }

    // 加载聊天历史
    function loadChatHistory(target) {
        const data = {
            type: 'chat_history'
        };

        data.uuid = target.uuid;
        if (CONFIG.isPlatformOperator) {
            data.uid = normalizePositiveInt(target.uid);
        }

        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify(data));
        }
    }

    // 显示聊天历史
    function displayChatHistory(messages) {
        elements.messagesContainer.innerHTML = '';

        if (messages.length === 0) {
            addSystemMessage('暂无历史消息');
            return;
        }

        const contextMessage = messages.slice().reverse().find(msg => normalizePositiveInt(msg.product_id) > 0 || normalizePositiveInt(msg.store_id) > 0);
        if (contextMessage && currentChatTarget) {
            currentChatTarget.product_id = normalizePositiveInt(currentChatTarget.product_id) || normalizePositiveInt(contextMessage.product_id);
            currentChatTarget.store_id = normalizePositiveInt(currentChatTarget.store_id) || normalizePositiveInt(contextMessage.store_id);
            renderChatHeader();
        }

        messages.forEach(msg => {
            displayMessage(msg);
        });

        scrollToBottom();
    }

    // 显示单条消息
    function displayMessage(msg) {
        const isSent = msg.from == 2;

        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';

        if (msg.msg_type == 2 || msg.msg_type == '2') {
            const imageUrl = normalizeImageUrl(msg.content);
            if (imageUrl) {
                const image = document.createElement('img');
                image.className = 'message-image';
                image.src = imageUrl;
                image.onclick = () => previewImage(imageUrl);
                bubble.appendChild(image);
            } else {
                bubble.textContent = '[图片地址无效]';
            }
        } else {
            bubble.textContent = msg.content || '';
        }

        const time = document.createElement('div');
        time.className = 'message-time';
        time.textContent = msg.timestamp || msg.time || '';

        messageDiv.appendChild(bubble);
        messageDiv.appendChild(time);
        elements.messagesContainer.appendChild(messageDiv);
        scrollToBottom();
    }

    // 发送文字消息
    function sendMessage() {
        const content = elements.messageInput.value.trim();
        if (!content || !currentChatTarget || !isConnected || !ws || ws.readyState !== WebSocket.OPEN) return;

        const data = {
            type: 'chat',
            content: content,
            msg_type: 1,
            target_uuid: currentChatTarget.uuid,
            target_uid: CONFIG.isPlatformOperator ? normalizePositiveInt(currentChatTarget.uid) : undefined,
            product_id: normalizePositiveInt(currentChatTarget.product_id),
            store_id: normalizePositiveInt(currentChatTarget.store_id)
        };

        ws.send(JSON.stringify(data));
        elements.messageInput.value = '';
        updateSendButton();
    }

    // 发送图片消息
    async function sendImage(file) {
        if (!currentChatTarget) {
            alert('请先选择聊天对象');
            return;
        }

        if (!isConnected || !ws || ws.readyState !== WebSocket.OPEN) {
            addSystemMessage('连接已断开，无法发送图片');
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            addSystemMessage('图片大小不能超过5MB');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('file', file);

            addSystemMessage('正在上传图片...');
            const response = await fetch(CONFIG.uploadUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error('上传失败');
            }

            const result = await response.json();

            const imageUrl = normalizeUploadUrl(result);
            if (!imageUrl) {
                throw new Error('上传返回缺少图片地址');
            }

            // 通过WebSocket发送图片地址
            const data = {
                type: 'chat',
                content: imageUrl,
                msg_type: 2,
                target_uuid: currentChatTarget.uuid,
                target_uid: CONFIG.isPlatformOperator ? normalizePositiveInt(currentChatTarget.uid) : undefined,
                product_id: normalizePositiveInt(currentChatTarget.product_id),
                store_id: normalizePositiveInt(currentChatTarget.store_id)
            };

            ws.send(JSON.stringify(data));
            addSystemMessage('图片上传成功');
        } catch (error) {
            addSystemMessage('图片上传失败，请重试');
        }
    }

    function normalizeUploadUrl(result) {
        if (typeof result === 'string') {
            return result;
        }

        if (!result || typeof result !== 'object') {
            return '';
        }

        if (result.url) {
            return result.url;
        }

        if (typeof result.data === 'string') {
            return result.data;
        }

        if (result.data && result.data.url) {
            return result.data.url;
        }

        return '';
    }

    function normalizeImageUrl(url) {
        if (!url || typeof url !== 'string') {
            return '';
        }

        const value = url.trim();
        if (value.startsWith('/attachment/')) {
            return value;
        }

        try {
            const parsed = new URL(value, window.location.origin);
            if ((parsed.protocol === 'http:' || parsed.protocol === 'https:') && parsed.host === window.location.host) {
                return parsed.href;
            }
        } catch (error) {
            return '';
        }

        return '';
    }

    // 图片预览
    function previewImage(src) {
        const safeSrc = normalizeImageUrl(src);
        if (!safeSrc) {
            return;
        }
        elements.previewImg.src = safeSrc;
        elements.imagePreview.classList.add('show');
    }

    // 事件绑定
    elements.connectBtn.onclick = connect;
    elements.disconnectBtn.onclick = disconnect;
    elements.sendBtn.onclick = sendMessage;
    elements.chatSearch.oninput = displayChatList;
    elements.messageInput.oninput = updateSendButton;

    elements.messageInput.onkeypress = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    };

    elements.emojiBtn.onclick = () => {
        elements.emojiPanel.classList.toggle('show');
    };

    elements.imageBtn.onclick = () => {
        elements.imageInput.click();
    };

    elements.imageInput.onchange = (e) => {
        const file = e.target.files[0];
        if (file) {
            sendImage(file);
        }
        e.target.value = '';
    };

    elements.imagePreview.onclick = () => {
        elements.imagePreview.classList.remove('show');
    };

    // 点击其他地方关闭emoji面板
    document.onclick = (e) => {
        if (!elements.emojiBtn.contains(e.target) && !elements.emojiPanel.contains(e.target)) {
            elements.emojiPanel.classList.remove('show');
        }
    };

    // 初始化
    initEmojiPanel();
    setWelcomeMessage('正在连接客服系统...');
    connect();

    window.onbeforeunload = () => {
        manualDisconnect = true;
        if (ws) {
            ws.close();
        }
    };
</script>
