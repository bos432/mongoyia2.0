<?php

use yii\grid\GridView;
use common\helpers\Html;
use common\services\mall\CustomerServiceAssistanceService;
use yii\helpers\Url;
//use common\components\enums\YesNo;
use common\models\mall\Order as ActiveModel;
use yii\helpers\Inflector;
//use common\helpers\ArrayHelper;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $searchModel common\models\ModelSearch */
/* @var $fxbfb common\models\ModelSearch */
/* @var $quickReplies array */

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
            max-width: 1600px;
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

        .chat-filter-row {
            display: flex;
            gap: 8px;
            padding: 0 12px 12px;
        }

        .chat-filter-row select {
            min-width: 0;
            flex: 1;
            padding: 7px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
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

        .context-panel {
            width: 340px;
            border-left: 1px solid #e0e0e0;
            background: #fbfbfb;
            height: 90vh;
            max-height: 90vh;
            overflow-y: auto;
        }

        .context-header {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            background: #fafafa;
        }

        .context-card {
            margin: 12px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #fff;
        }

        .context-card h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #111827;
        }

        .context-empty,
        .context-loading,
        .context-error {
            padding: 12px;
            color: #6b7280;
            font-size: 13px;
        }

        .context-error {
            color: #b91c1c;
        }

        .context-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .context-label {
            color: #6b7280;
            flex: 0 0 82px;
        }

        .context-value {
            flex: 1;
            color: #111827;
            text-align: right;
            word-break: break-word;
        }

        .context-ticket {
            border-top: 1px solid #f3f4f6;
            padding-top: 8px;
            margin-top: 8px;
            font-size: 12px;
        }

        .context-ticket:first-of-type {
            border-top: none;
            padding-top: 0;
            margin-top: 0;
        }

        .context-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 11px;
            margin-left: 4px;
        }

        .context-actions {
            margin: 12px;
            padding: 12px;
            border: 1px solid #dbeafe;
            border-radius: 6px;
            background: #eff6ff;
        }

        .context-actions-title {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #1e3a8a;
        }

        .context-actions input,
        .context-actions select,
        .context-actions textarea {
            width: 100%;
            margin-bottom: 8px;
            padding: 7px 8px;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            font-size: 12px;
            background: #fff;
        }

        .context-actions-row {
            display: flex;
            gap: 8px;
        }

        .context-action-btn {
            flex: 1;
            padding: 8px;
            border: 1px solid #93c5fd;
            border-radius: 4px;
            background: #fff;
            color: #1d4ed8;
            cursor: pointer;
            font-size: 12px;
        }

        .context-action-btn:disabled {
            color: #9ca3af;
            border-color: #d1d5db;
            cursor: not-allowed;
        }

        .context-search-result {
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            background: #fff;
            padding: 8px;
            margin-top: 8px;
            cursor: pointer;
        }

        .context-search-result:hover {
            border-color: #2563eb;
        }

        .context-search-result-title {
            font-size: 12px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .context-search-result-meta,
        .context-status {
            font-size: 12px;
            color: #64748b;
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

        .message-original {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid rgba(0, 0, 0, .08);
            color: #777;
            font-size: 12px;
            line-height: 1.45;
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

        .quick-reply-select {
            min-width: 220px;
            max-width: 320px;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            background: #fff;
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
        #imageInput,
        #fileInput,
        #videoInput {
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
        <p><a href="<?= Html::encode(Url::to(['quick-replies'])) ?>" target="_blank">管理快捷回复</a></p>
        <button class="btn btn-primary" id="connectBtn">上线</button>
        <button class="btn btn-danger" id="disconnectBtn" style="display:none;">离线</button>
        <div id="connectionStatus" class="status disconnected">未连接</div>
    </div>

    <!-- 聊天列表 -->
    <div class="chat-list">
        <div class="chat-list-header">咨询列表</div>
        <input class="chat-search" id="chatSearch" type="search" placeholder="搜索用户ID">
        <div class="chat-filter-row">
            <?php if (!empty($isPlatformOperator)): ?>
                <select id="storeFilter">
                    <option value="">全部店铺</option>
                    <?php foreach (($storeMap ?? []) as $store): ?>
                        <option value="<?= (int)($store['id'] ?? 0) ?>"><?= Html::encode((string)($store['name'] ?? ('店铺 #' . (int)($store['id'] ?? 0)))) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <select id="storeFilter" style="display:none;"><option value="">全部店铺</option></select>
            <?php endif; ?>
            <select id="statusFilter">
                <option value="">全部会话</option>
                <option value="unread">仅未读</option>
                <option value="active">当前会话</option>
            </select>
        </div>
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
                <button class="tool-btn" id="fileBtn">文件</button>
                <button class="tool-btn" id="videoBtn">视频</button>
                <button class="tool-btn" id="voiceBtn">语音</button>
                <select class="quick-reply-select" id="quickReplySelect">
                    <option value="">快捷回复</option>
                </select>
                <input type="file" id="imageInput" accept="image/*">
                <input type="file" id="fileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip">
                <input type="file" id="videoInput" accept="video/mp4,video/webm">
            </div>
            <div class="input-wrapper">
                <textarea class="message-input" id="messageInput" placeholder="输入消息..."></textarea>
                <button class="send-btn" id="sendBtn" disabled>发送</button>
            </div>
        </div>
    </div>

    <div class="context-panel" data-mongoyia-customer-service-session-context="panel">
        <div class="context-header">会话上下文</div>
        <div class="context-actions" data-mongoyia-customer-service-assistance="search">
            <div class="context-actions-title">订单/商品查询</div>
            <select id="assistanceKind">
                <option value="order">订单</option>
                <option value="product">商品</option>
            </select>
            <input id="assistanceKeyword" type="text" maxlength="80" placeholder="订单号、手机号、邮箱、用户、商品、SKU">
            <div class="context-actions-row">
                <button class="context-action-btn" id="assistanceSearchBtn" type="button">查询</button>
            </div>
            <div id="assistanceResults" class="context-status">可按订单号、手机号、邮箱、商品名或 SKU 查询。</div>
        </div>
        <div class="context-actions" data-mongoyia-customer-service-assistance-request="panel">
            <div class="context-actions-title">协助处理单</div>
            <select id="assistanceTypeSelect"></select>
            <textarea id="assistanceContent" rows="2" maxlength="1000" placeholder="协助说明，例如支付指导、物流查询、退款建议原因"></textarea>
            <div class="context-actions-row">
                <button class="context-action-btn" id="createAssistanceRequestBtn" type="button" disabled>创建协助单</button>
            </div>
            <div id="assistanceStatus" class="context-status">协助单只进入审批/流转，不直接修改订单、支付、资金或库存。</div>
        </div>
        <div class="context-actions" data-mongoyia-customer-service-chat-ticket="actions">
            <div class="context-actions-title">从当前聊天创建工单</div>
            <input id="ticketOrderId" type="number" min="0" placeholder="订单ID，可留空">
            <input id="ticketTitle" type="text" maxlength="255" placeholder="标题，可留空自动生成">
            <textarea id="ticketContent" rows="2" maxlength="1000" placeholder="处理说明，可留空"></textarea>
            <div class="context-actions-row">
                <button class="context-action-btn" id="createOrderAssistBtn" type="button" disabled>订单协助</button>
                <button class="context-action-btn" id="createComplaintBtn" type="button" disabled>投诉工单</button>
            </div>
        </div>
        <div id="contextPanel">
            <div class="context-empty">选择咨询会话后显示用户、商品、订单和历史工单。</div>
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
        uploadUrl: <?= json_encode(Yii::$app->params['chatUploadUrl'] ?? '/mall/chat/upload') ?>,
        mediaUploadUrl: <?= json_encode(Url::to(['media-upload'])) ?>,
        translationUrl: <?= json_encode(Url::to(['translate'])) ?>,
        assistanceSearchUrl: <?= json_encode(Url::to(['assistance-search'])) ?>,
        assistanceDetailUrl: <?= json_encode(Url::to(['assistance-detail'])) ?>,
        assistanceRequestUrl: <?= json_encode(Url::to(['assistance-request'])) ?>,
        assistanceTypes: <?= json_encode((new CustomerServiceAssistanceService())->assistanceTypes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        staffWorkLanguage: <?= json_encode($staffWorkLanguage ?? 'en') ?>,
        sessionContextUrl: <?= json_encode(Url::to(['session-context'])) ?>,
        ticketCreateUrl: <?= json_encode(Url::to(['ticket-create-from-session'])) ?>,
        quickReplies: <?= json_encode($quickReplies ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        csrfParam: <?= json_encode(Yii::$app->request->csrfParam) ?>,
        csrfToken: <?= json_encode(Yii::$app->request->csrfToken) ?>
    };

    let ws = null;
    let currentChatTarget = null;
    let isConnected = false;
    let manualDisconnect = false;
    let reconnectTimer = null;
    let chatListCache = [];
    let currentSessionContext = null;
    let currentAssistanceDetail = null;
    let mediaRecorder = null;
    let voiceChunks = [];
    let voiceStartAt = 0;
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
        storeFilter: document.getElementById('storeFilter'),
        statusFilter: document.getElementById('statusFilter'),
        chatList: document.getElementById('chatList'),
        chatHeader: document.getElementById('chatHeader'),
        contextPanel: document.getElementById('contextPanel'),
        assistanceKind: document.getElementById('assistanceKind'),
        assistanceKeyword: document.getElementById('assistanceKeyword'),
        assistanceSearchBtn: document.getElementById('assistanceSearchBtn'),
        assistanceResults: document.getElementById('assistanceResults'),
        assistanceTypeSelect: document.getElementById('assistanceTypeSelect'),
        assistanceContent: document.getElementById('assistanceContent'),
        createAssistanceRequestBtn: document.getElementById('createAssistanceRequestBtn'),
        assistanceStatus: document.getElementById('assistanceStatus'),
        ticketOrderId: document.getElementById('ticketOrderId'),
        ticketTitle: document.getElementById('ticketTitle'),
        ticketContent: document.getElementById('ticketContent'),
        createOrderAssistBtn: document.getElementById('createOrderAssistBtn'),
        createComplaintBtn: document.getElementById('createComplaintBtn'),
        messagesContainer: document.getElementById('messagesContainer'),
        messageInput: document.getElementById('messageInput'),
        sendBtn: document.getElementById('sendBtn'),
        emojiBtn: document.getElementById('emojiBtn'),
        emojiPanel: document.getElementById('emojiPanel'),
        emojiGrid: document.getElementById('emojiGrid'),
        imageBtn: document.getElementById('imageBtn'),
        fileBtn: document.getElementById('fileBtn'),
        fileInput: document.getElementById('fileInput'),
        videoBtn: document.getElementById('videoBtn'),
        videoInput: document.getElementById('videoInput'),
        voiceBtn: document.getElementById('voiceBtn'),
        quickReplySelect: document.getElementById('quickReplySelect'),
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

    function initQuickReplies() {
        renderQuickReplies();
    }

    function initAssistanceTypes() {
        if (!elements.assistanceTypeSelect) {
            return;
        }
        elements.assistanceTypeSelect.innerHTML = '';
        Object.keys(CONFIG.assistanceTypes || {}).forEach(key => {
            const definition = CONFIG.assistanceTypes[key] || {};
            const option = document.createElement('option');
            option.value = key;
            option.textContent = (definition.label || key) + (definition.approval_required ? '（需审批）' : '');
            elements.assistanceTypeSelect.appendChild(option);
        });
    }

    function renderQuickReplies() {
        if (!elements.quickReplySelect) {
            return;
        }
        const selectedStoreId = currentChatTarget ? normalizePositiveInt(currentChatTarget.store_id) : 0;
        elements.quickReplySelect.innerHTML = '<option value="">快捷回复</option>';
        (CONFIG.quickReplies || []).forEach(reply => {
            const storeId = normalizePositiveInt(reply.store_id);
            const isGlobal = normalizePositiveInt(reply.is_global) === 1 || storeId === 0;
            if (selectedStoreId > 0 && !isGlobal && storeId !== selectedStoreId) {
                return;
            }
            const option = document.createElement('option');
            option.value = String(reply.id || '');
            option.textContent = (isGlobal ? '通用' : '店铺') + ' · ' + (reply.title || reply.content || '').slice(0, 28);
            option.dataset.content = reply.content || '';
            elements.quickReplySelect.appendChild(option);
        });
    }

    function insertQuickReply() {
        const option = elements.quickReplySelect.options[elements.quickReplySelect.selectedIndex];
        const content = option && option.dataset ? (option.dataset.content || '') : '';
        if (!content) {
            return;
        }
        const current = elements.messageInput.value;
        elements.messageInput.value = current ? current.replace(/\s*$/, '\n') + content : content;
        elements.quickReplySelect.value = '';
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
        updateContextActionButtons();
    }

    function updateContextActionButtons() {
        const disabled = !currentChatTarget;
        elements.createOrderAssistBtn.disabled = disabled;
        elements.createComplaintBtn.disabled = disabled;
        if (elements.createAssistanceRequestBtn) {
            const hasOrder = currentSessionContext && currentSessionContext.order && currentSessionContext.order.id;
            const hasProduct = currentSessionContext && currentSessionContext.product && currentSessionContext.product.id;
            elements.createAssistanceRequestBtn.disabled = !(hasOrder || hasProduct);
        }
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
        resetContextPanel('已离线，选择咨询会话后显示上下文。');
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
                    if (data.from == 1 && data.source_language) {
                        currentChatTarget.customer_language = data.source_language;
                    }
                    renderChatHeader();
                    displayMessage(data);
                    unreadMap[sessionKey(data)] = 0;
                    markCurrentChatRead();
                    loadSessionContext();
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
        const selectedStoreId = normalizePositiveInt(elements.storeFilter && elements.storeFilter.value);
        const selectedStatus = elements.statusFilter ? elements.statusFilter.value : '';
        const list = chatListCache.filter(chat => {
            if (keyword && !String(chat.uuid || '').toLowerCase().includes(keyword)) {
                return false;
            }
            if (selectedStoreId > 0 && normalizePositiveInt(chat.store_id) !== selectedStoreId) {
                return false;
            }
            const serverUnread = normalizePositiveInt(chat.unread_count);
            const localUnread = unreadMap[sessionKey(chat)] || 0;
            const unread = currentChatTarget && sessionKey(chat) === sessionKey(currentChatTarget) ? 0 : Math.max(serverUnread, localUnread);
            if (selectedStatus === 'unread' && unread <= 0) {
                return false;
            }
            if (selectedStatus === 'active' && (!currentChatTarget || sessionKey(chat) !== sessionKey(currentChatTarget))) {
                return false;
            }
            return true;
        });

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
        elements.ticketOrderId.value = '';
        elements.ticketTitle.value = '';
        elements.ticketContent.value = '';

        renderChatHeader();

        document.querySelectorAll('.chat-item').forEach(item => {
            item.classList.remove('active');
        });
        if (selectedItem) {
            selectedItem.classList.add('active');
        }

        loadChatHistory(target);
        loadSessionContext();
        renderQuickReplies();
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

    function resetContextPanel(text) {
        currentSessionContext = null;
        currentAssistanceDetail = null;
        elements.contextPanel.innerHTML = '';
        const empty = document.createElement('div');
        empty.className = 'context-empty';
        empty.textContent = text || '选择咨询会话后显示用户、商品、订单和历史工单。';
        elements.contextPanel.appendChild(empty);
        updateContextActionButtons();
    }

    async function loadSessionContext() {
        if (!currentChatTarget || !CONFIG.sessionContextUrl) {
            resetContextPanel();
            return;
        }

        elements.contextPanel.innerHTML = '<div class="context-loading">正在加载会话上下文...</div>';
        const params = new URLSearchParams();
        params.set('chat_uuid', currentChatTarget.uuid || '');
        params.set('customer_uuid', currentChatTarget.uuid || '');
        params.set('product_id', normalizePositiveInt(currentChatTarget.product_id));
        params.set('store_id', normalizePositiveInt(currentChatTarget.store_id));
        params.set('customer_user_id', normalizePositiveInt(currentChatTarget.customer_user_id));
        params.set('order_id', normalizePositiveInt(currentChatTarget.order_id));

        try {
            const response = await fetch(CONFIG.sessionContextUrl + '?' + params.toString(), {
                headers: {'Accept': 'application/json'}
            });
            const data = await response.json();
            if (!response.ok || data.error) {
                throw new Error(data.error || '会话上下文加载失败');
            }
            currentSessionContext = data;
            renderSessionContext(data);
        } catch (error) {
            currentSessionContext = null;
            elements.contextPanel.innerHTML = '';
            const node = document.createElement('div');
            node.className = 'context-error';
            node.textContent = error.message || '会话上下文加载失败';
            elements.contextPanel.appendChild(node);
        }
    }

    function renderSessionContext(data) {
        if (data.order && data.order.id && !elements.ticketOrderId.value) {
            elements.ticketOrderId.value = data.order.id;
        }
        elements.contextPanel.innerHTML = '';
        renderContextCard('用户摘要', [
            ['用户ID', data.user && data.user.id ? '#' + data.user.id : '未识别'],
            ['账号', data.user && data.user.username ? data.user.username : ''],
            ['姓名', data.user && data.user.name ? data.user.name : ''],
            ['手机', data.user && data.user.mobile ? data.user.mobile : ''],
            ['邮箱', data.user && data.user.email ? data.user.email : ''],
            ['消费次数', data.user && data.user.consume_count ? data.user.consume_count : 0],
            ['消费金额', data.user && data.user.consume_amount ? formatMoney(data.user.consume_amount) : '0.00']
        ]);
        renderContextCard('商品摘要', [
            ['商品ID', data.product && data.product.id ? '#' + data.product.id : '未关联'],
            ['名称', data.product && data.product.name ? data.product.name : ''],
            ['SKU', data.product && data.product.sku ? data.product.sku : ''],
            ['价格', data.product && data.product.price ? formatMoney(data.product.price) : ''],
            ['库存', data.product && data.product.stock !== undefined ? data.product.stock : '']
        ]);
        renderContextCard('订单摘要', [
            ['订单ID', data.order && data.order.id ? '#' + data.order.id : '未关联'],
            ['订单号', data.order && data.order.sn ? data.order.sn : ''],
            ['收货人', data.order && data.order.name ? data.order.name : ''],
            ['金额', data.order && data.order.amount ? formatMoney(data.order.amount) : ''],
            ['支付状态', data.order && data.order.payment_status !== undefined ? data.order.payment_status : ''],
            ['物流状态', data.order && data.order.shipment_status !== undefined ? data.order.shipment_status : ''],
            ['创建时间', data.order && data.order.created_at ? formatTimestamp(data.order.created_at) : '']
        ]);
        if (Array.isArray(data.order_items) && data.order_items.length > 0) {
            renderOrderItemsCard(data.order_items);
        }
        if (data.logistics) {
            renderContextCard('物流摘要', [
                ['物流公司', data.logistics.wlgs || data.logistics.shipment_name || ''],
                ['物流单号', data.logistics.wldh || ''],
                ['发货时间', data.logistics.shipped_at ? formatTimestamp(data.logistics.shipped_at) : ''],
                ['处理边界', data.logistics.note || '只读查询']
            ]);
        }
        if (Array.isArray(data.payment_attempts) && data.payment_attempts.length > 0) {
            renderPaymentAttemptsCard(data.payment_attempts);
        }
        if (data.boundaries) {
            renderContextCard('处理边界', [
                ['订单修改', data.boundaries.order_mutation_allowed ? '允许' : '禁止'],
                ['支付修改', data.boundaries.payment_mutation_allowed ? '允许' : '禁止'],
                ['资金修改', data.boundaries.fund_mutation_allowed ? '允许' : '禁止'],
                ['库存修改', data.boundaries.stock_mutation_allowed ? '允许' : '禁止'],
                ['协助单', data.boundaries.assistance_creates_ticket_only ? '仅创建工单/审批流' : '']
            ]);
        }
        renderTicketsCard(Array.isArray(data.tickets) ? data.tickets : []);
        updateContextActionButtons();
    }

    function renderContextCard(title, rows) {
        const card = document.createElement('div');
        card.className = 'context-card';
        const heading = document.createElement('h3');
        heading.textContent = title;
        card.appendChild(heading);
        rows.forEach(row => {
            const line = document.createElement('div');
            line.className = 'context-row';
            const label = document.createElement('span');
            label.className = 'context-label';
            label.textContent = row[0];
            const value = document.createElement('span');
            value.className = 'context-value';
            value.textContent = row[1] === undefined || row[1] === null || row[1] === '' ? '-' : String(row[1]);
            line.appendChild(label);
            line.appendChild(value);
            card.appendChild(line);
        });
        elements.contextPanel.appendChild(card);
    }

    function renderOrderItemsCard(items) {
        const rows = items.slice(0, 8).map(item => [
            '#' + (item.product_id || item.id || '-'),
            (item.name || '-') + ' / ' + (item.sku || '-') + ' × ' + (item.number || 0) + ' / ' + formatMoney(item.price || 0)
        ]);
        renderContextCard('订单明细', rows);
    }

    function renderPaymentAttemptsCard(attempts) {
        const rows = attempts.slice(0, 5).map(item => [
            item.provider || item.event || ('#' + item.id),
            (item.result || '-') + ' / ' + formatMoney(item.amount || 0) + ' ' + (item.currency || '') + (item.processed_at ? ' / ' + formatTimestamp(item.processed_at) : '')
        ]);
        renderContextCard('支付记录', rows);
    }

    function renderTicketsCard(tickets) {
        const card = document.createElement('div');
        card.className = 'context-card';
        const heading = document.createElement('h3');
        heading.textContent = '历史工单';
        card.appendChild(heading);
        if (tickets.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'context-empty';
            empty.textContent = '暂无关联工单';
            card.appendChild(empty);
        }
        tickets.forEach(ticket => {
            const item = document.createElement('div');
            item.className = 'context-ticket';
            const title = document.createElement('div');
            title.textContent = '#' + ticket.id + ' ' + (ticket.title || ticket.ticket_sn || '');
            const meta = document.createElement('div');
            const status = document.createElement('span');
            status.className = 'context-badge';
            status.textContent = ticket.ticket_status || '';
            const type = document.createElement('span');
            type.className = 'context-badge';
            type.textContent = ticket.ticket_type || '';
            meta.appendChild(status);
            meta.appendChild(type);
            item.appendChild(title);
            item.appendChild(meta);
            card.appendChild(item);
        });
        elements.contextPanel.appendChild(card);
    }

    function formatMoney(value) {
        const number = Number(value);
        return Number.isFinite(number) ? number.toFixed(2) : String(value || '');
    }

    function formatTimestamp(value) {
        const number = normalizePositiveInt(value);
        if (!number) {
            return '';
        }
        const date = new Date(number * 1000);
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0') + ' ' + String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
    }

    function currentSearchStoreId() {
        if (!CONFIG.isPlatformOperator) {
            return 0;
        }
        return normalizePositiveInt(elements.storeFilter && elements.storeFilter.value) ||
            normalizePositiveInt(currentChatTarget && currentChatTarget.store_id);
    }

    async function searchAssistanceContext() {
        const kind = elements.assistanceKind.value || 'order';
        const params = new URLSearchParams();
        params.set('kind', kind);
        params.set('q', elements.assistanceKeyword.value || '');
        params.set('limit', '10');
        const storeId = currentSearchStoreId();
        if (storeId > 0) {
            params.set('store_id', storeId);
        }

        elements.assistanceResults.textContent = '正在查询...';
        try {
            const response = await fetch(CONFIG.assistanceSearchUrl + '?' + params.toString(), {
                headers: {'Accept': 'application/json'}
            });
            const result = await response.json();
            if (!response.ok || !result || result.code != 200) {
                throw new Error(result && result.msg ? result.msg : '查询失败');
            }
            renderAssistanceResults(kind, result.data && Array.isArray(result.data.items) ? result.data.items : []);
        } catch (error) {
            elements.assistanceResults.textContent = error.message || '查询失败';
        }
    }

    function renderAssistanceResults(kind, items) {
        elements.assistanceResults.innerHTML = '';
        if (items.length === 0) {
            elements.assistanceResults.textContent = '没有找到匹配记录';
            return;
        }

        items.forEach(item => {
            const node = document.createElement('div');
            node.className = 'context-search-result';
            node.onclick = () => loadAssistanceDetail(kind, item.id);
            const title = document.createElement('div');
            title.className = 'context-search-result-title';
            title.textContent = kind === 'product'
                ? ('#' + item.id + ' ' + (item.name || '未命名商品'))
                : ('#' + item.id + ' ' + (item.sn || '未命名订单'));
            const meta = document.createElement('div');
            meta.className = 'context-search-result-meta';
            meta.textContent = kind === 'product'
                ? ('SKU ' + (item.sku || '-') + ' / 库存 ' + (item.stock || 0) + ' / ' + formatMoney(item.price || 0))
                : ('金额 ' + formatMoney(item.amount || 0) + ' / 支付 ' + (item.payment_status !== undefined ? item.payment_status : '-') + ' / 物流 ' + (item.shipment_status !== undefined ? item.shipment_status : '-'));
            node.appendChild(title);
            node.appendChild(meta);
            elements.assistanceResults.appendChild(node);
        });
    }

    async function loadAssistanceDetail(kind, id) {
        const params = new URLSearchParams();
        params.set('kind', kind);
        params.set('id', normalizePositiveInt(id));
        elements.assistanceStatus.textContent = '正在加载详情...';
        try {
            const response = await fetch(CONFIG.assistanceDetailUrl + '?' + params.toString(), {
                headers: {'Accept': 'application/json'}
            });
            const result = await response.json();
            if (!response.ok || !result || result.code != 200 || !result.data) {
                throw new Error(result && result.msg ? result.msg : '详情加载失败');
            }
            currentAssistanceDetail = result.data;
            mergeAssistanceDetail(kind, result.data);
            elements.assistanceStatus.textContent = '已载入' + (kind === 'product' ? '商品' : '订单') + '上下文';
        } catch (error) {
            elements.assistanceStatus.textContent = error.message || '详情加载失败';
        }
    }

    function mergeAssistanceDetail(kind, detail) {
        const base = currentSessionContext || {
            context: {},
            user: {},
            product: {},
            order: {},
            tickets: []
        };
        const context = Object.assign({}, base.context || {});
        if (kind === 'product' && detail.product) {
            base.product = detail.product;
            context.product_id = detail.product.id || 0;
            context.store_id = context.store_id || detail.product.store_id || 0;
        }
        if (kind !== 'product' && detail.order) {
            base.order = detail.order;
            base.order_items = Array.isArray(detail.items) ? detail.items : [];
            base.payment_attempts = Array.isArray(detail.payment_attempts) ? detail.payment_attempts : [];
            base.logistics = detail.logistics || {};
            context.order_id = detail.order.id || 0;
            context.order_sn = detail.order.sn || '';
            context.store_id = context.store_id || detail.order.store_id || 0;
            context.customer_user_id = context.customer_user_id || detail.order.user_id || 0;
            if (base.order_items.length > 0 && !context.product_id) {
                context.product_id = base.order_items[0].product_id || 0;
            }
            elements.ticketOrderId.value = detail.order.id || '';
        }
        base.context = context;
        base.boundaries = detail.boundaries || base.boundaries || {};
        currentSessionContext = base;
        renderSessionContext(base);
    }

    async function createAssistanceRequest() {
        const context = currentSessionContext && currentSessionContext.context ? currentSessionContext.context : {};
        const order = currentSessionContext && currentSessionContext.order ? currentSessionContext.order : {};
        const product = currentSessionContext && currentSessionContext.product ? currentSessionContext.product : {};
        const user = currentSessionContext && currentSessionContext.user ? currentSessionContext.user : {};
        const storeFromUid = CONFIG.isPlatformOperator && currentChatTarget && currentChatTarget.uid ? CONFIG.storeMap[String(currentChatTarget.uid)] : null;
        const storeId = normalizePositiveInt(context.store_id) || normalizePositiveInt(order.store_id) || normalizePositiveInt(product.store_id) || normalizePositiveInt(currentChatTarget && currentChatTarget.store_id) || normalizePositiveInt(storeFromUid && storeFromUid.id);
        if (storeId <= 0) {
            elements.assistanceStatus.textContent = '请先选择或查询可识别店铺的订单/商品';
            return;
        }

        const formData = new FormData();
        formData.append(CONFIG.csrfParam, CONFIG.csrfToken);
        formData.append('assistance_type', elements.assistanceTypeSelect.value || 'payment_guidance');
        formData.append('store_id', storeId);
        formData.append('product_id', normalizePositiveInt(context.product_id) || normalizePositiveInt(product.id) || normalizePositiveInt(currentChatTarget && currentChatTarget.product_id));
        formData.append('order_id', normalizePositiveInt(elements.ticketOrderId.value) || normalizePositiveInt(context.order_id) || normalizePositiveInt(order.id));
        formData.append('order_sn', context.order_sn || order.sn || '');
        formData.append('customer_user_id', normalizePositiveInt(context.customer_user_id) || normalizePositiveInt(user.id) || normalizePositiveInt(order.user_id));
        formData.append('customer_uuid', context.customer_uuid || (currentChatTarget && currentChatTarget.uuid) || '');
        formData.append('merchant_user_id', normalizePositiveInt(currentChatTarget && currentChatTarget.uid));
        formData.append('chat_uuid', context.chat_uuid || (currentChatTarget && currentChatTarget.uuid) || '');
        formData.append('title', elements.ticketTitle.value || '');
        formData.append('content', elements.assistanceContent.value || elements.ticketContent.value || '');

        elements.createAssistanceRequestBtn.disabled = true;
        elements.assistanceStatus.textContent = '正在创建协助单...';
        try {
            const response = await fetch(CONFIG.assistanceRequestUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json'},
                body: formData
            });
            const result = await response.json();
            if (!response.ok || !result || result.code != 200 || !result.success) {
                throw new Error(result && (result.msg || result.message) ? (result.msg || result.message) : '创建协助单失败');
            }
            const data = result.data || {};
            elements.assistanceContent.value = '';
            elements.assistanceStatus.textContent = '协助单创建成功：#' + data.ticket_id + ' ' + (data.ticket_sn || '') + (data.approval_required ? '（需审批）' : '');
            if (currentChatTarget) {
                loadSessionContext();
            }
        } catch (error) {
            elements.assistanceStatus.textContent = error.message || '创建协助单失败';
            updateContextActionButtons();
        }
    }

    async function createTicketFromSession(ticketType) {
        if (!currentChatTarget) {
            addSystemMessage('请先选择咨询会话');
            return;
        }

        const context = currentSessionContext && currentSessionContext.context ? currentSessionContext.context : {};
        const order = currentSessionContext && currentSessionContext.order ? currentSessionContext.order : {};
        const product = currentSessionContext && currentSessionContext.product ? currentSessionContext.product : {};
        const user = currentSessionContext && currentSessionContext.user ? currentSessionContext.user : {};
        const storeFromUid = CONFIG.isPlatformOperator && currentChatTarget.uid ? CONFIG.storeMap[String(currentChatTarget.uid)] : null;
        const storeId = normalizePositiveInt(context.store_id) || normalizePositiveInt(order.store_id) || normalizePositiveInt(product.store_id) || normalizePositiveInt(currentChatTarget.store_id) || normalizePositiveInt(storeFromUid && storeFromUid.id);

        const formData = new FormData();
        formData.append(CONFIG.csrfParam, CONFIG.csrfToken);
        formData.append('ticket_type', ticketType);
        formData.append('store_id', storeId);
        formData.append('product_id', normalizePositiveInt(context.product_id) || normalizePositiveInt(product.id) || normalizePositiveInt(currentChatTarget.product_id));
        formData.append('order_id', normalizePositiveInt(elements.ticketOrderId.value) || normalizePositiveInt(context.order_id) || normalizePositiveInt(order.id));
        formData.append('order_sn', order.sn || '');
        formData.append('customer_user_id', normalizePositiveInt(context.customer_user_id) || normalizePositiveInt(user.id));
        formData.append('customer_uuid', context.customer_uuid || currentChatTarget.uuid || '');
        formData.append('merchant_user_id', normalizePositiveInt(currentChatTarget.uid));
        formData.append('chat_uuid', context.chat_uuid || currentChatTarget.uuid || '');
        formData.append('title', elements.ticketTitle.value || '');
        formData.append('content', elements.ticketContent.value || '');

        elements.createOrderAssistBtn.disabled = true;
        elements.createComplaintBtn.disabled = true;
        try {
            const response = await fetch(CONFIG.ticketCreateUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json'},
                body: formData
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || '创建工单失败');
            }
            addSystemMessage('工单创建成功：#' + result.ticket_id + ' ' + (result.ticket_sn || ''));
            elements.ticketTitle.value = '';
            elements.ticketContent.value = '';
            loadSessionContext();
        } catch (error) {
            addSystemMessage(error.message || '创建工单失败');
            updateContextActionButtons();
        }
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
            loadSessionContext();
        }

        const languageMessage = messages.slice().reverse().find(msg => msg.from == 1 && msg.source_language);
        if (languageMessage && currentChatTarget) {
            currentChatTarget.customer_language = languageMessage.source_language;
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
        } else if (msg.msg_type == 3 || msg.msg_type == '3') {
            const link = document.createElement('a');
            link.href = normalizeMediaUrl(msg.content);
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = '打开文件';
            bubble.appendChild(link);
        } else if (msg.msg_type == 4 || msg.msg_type == '4') {
            const video = document.createElement('video');
            video.controls = true;
            video.src = normalizeMediaUrl(msg.content);
            video.style.maxWidth = '260px';
            bubble.appendChild(video);
        } else if (msg.msg_type == 5 || msg.msg_type == '5') {
            const audio = document.createElement('audio');
            audio.controls = true;
            audio.src = normalizeMediaUrl(msg.content);
            bubble.appendChild(audio);
        } else {
            bubble.textContent = displayText(msg, isSent);
            appendOriginalText(bubble, msg, isSent);
        }

        const time = document.createElement('div');
        time.className = 'message-time';
        time.textContent = msg.timestamp || msg.time || '';

        messageDiv.appendChild(bubble);
        messageDiv.appendChild(time);
        elements.messagesContainer.appendChild(messageDiv);
        scrollToBottom();
    }

    function displayText(msg, isSent) {
        if (!isSent && msg.translation_status === 'translated' && msg.translated_content) {
            return msg.translated_content;
        }

        return msg.content || '';
    }

    function appendOriginalText(bubble, msg, isSent) {
        if (isSent || msg.translation_status !== 'translated' || !msg.translated_content || !msg.content || msg.translated_content === msg.content) {
            return;
        }

        const original = document.createElement('div');
        original.className = 'message-original';
        original.textContent = '原文: ' + msg.content;
        bubble.appendChild(original);
    }

    // 发送文字消息
    async function sendMessage() {
        const content = elements.messageInput.value.trim();
        if (!content || !currentChatTarget || !isConnected || !ws || ws.readyState !== WebSocket.OPEN) return;
        const targetLanguage = currentChatTarget.customer_language || 'en';
        const translation = await translateMessage(content, 'staff_to_user', CONFIG.staffWorkLanguage, targetLanguage);

        const data = {
            type: 'chat',
            content: content,
            msg_type: 1,
            target_uuid: currentChatTarget.uuid,
            target_uid: CONFIG.isPlatformOperator ? normalizePositiveInt(currentChatTarget.uid) : undefined,
            product_id: normalizePositiveInt(currentChatTarget.product_id),
            store_id: normalizePositiveInt(currentChatTarget.store_id),
            ...translation
        };

        ws.send(JSON.stringify(data));
        elements.messageInput.value = '';
        updateSendButton();
    }

    async function translateMessage(content, direction, sourceLanguage, targetLanguage) {
        const fallback = {
            original_content: content,
            source_language: sourceLanguage || '',
            target_language: targetLanguage || '',
            translated_content: '',
            translation_status: 'none',
            translation_provider: '',
            translation_error: '',
            translated_at: 0
        };

        if (!CONFIG.translationUrl) {
            return fallback;
        }

        try {
            const formData = new FormData();
            formData.append(CONFIG.csrfParam, CONFIG.csrfToken);
            formData.append('content', content);
            formData.append('direction', direction);
            formData.append('source_language', sourceLanguage || '');
            formData.append('target_language', targetLanguage || '');

            const response = await fetch(CONFIG.translationUrl, {
                method: 'POST',
                body: formData
            });
            if (!response.ok) {
                throw new Error('translation HTTP ' + response.status);
            }

            const result = await response.json();
            if (!result || result.code != 200 || !result.data || !result.data.metadata) {
                throw new Error(result && result.msg ? result.msg : 'translation failed');
            }

            return result.data.metadata;
        } catch (error) {
            fallback.translation_status = 'failed';
            fallback.translation_error = String(error && error.message ? error.message : error).slice(0, 255);
            return fallback;
        }
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

    async function sendMedia(file, media, duration = 0) {
        if (!currentChatTarget) {
            alert('请先选择聊天对象');
            return;
        }

        if (!isConnected || !ws || ws.readyState !== WebSocket.OPEN) {
            addSystemMessage('连接已断开，无法发送媒体消息');
            return;
        }

        try {
            const formData = new FormData();
            formData.append(CONFIG.csrfParam, CONFIG.csrfToken);
            formData.append('file', file);
            formData.append('media', media);
            formData.append('duration', String(duration || 0));

            addSystemMessage('正在上传媒体...');
            const response = await fetch(CONFIG.mediaUploadUrl, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (!response.ok || !result || result.code != 200 || !result.data) {
                throw new Error(result && result.msg ? result.msg : '媒体上传失败');
            }

            const mediaUrl = normalizeMediaUrl(result.data.url || '');
            if (!mediaUrl) {
                throw new Error('上传返回缺少媒体地址');
            }

            ws.send(JSON.stringify({
                type: 'chat',
                content: mediaUrl,
                msg_type: result.data.msg_type,
                target_uuid: currentChatTarget.uuid,
                target_uid: CONFIG.isPlatformOperator ? normalizePositiveInt(currentChatTarget.uid) : undefined,
                product_id: normalizePositiveInt(currentChatTarget.product_id),
                store_id: normalizePositiveInt(currentChatTarget.store_id)
            }));
            addSystemMessage('媒体上传成功');
        } catch (error) {
            addSystemMessage(error.message || '媒体上传失败，请重试');
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

    function normalizeMediaUrl(url) {
        if (!url || typeof url !== 'string') {
            return '';
        }

        const value = url.trim();
        if (value.startsWith('/mall/chat/media-view?')) {
            return value;
        }

        try {
            const parsed = new URL(value, window.location.origin);
            if ((parsed.protocol === 'http:' || parsed.protocol === 'https:') && parsed.host === window.location.host && parsed.pathname === '/mall/chat/media-view') {
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
    elements.createOrderAssistBtn.onclick = () => createTicketFromSession('order_assist');
    elements.createComplaintBtn.onclick = () => createTicketFromSession('complaint');
    elements.assistanceSearchBtn.onclick = searchAssistanceContext;
    elements.createAssistanceRequestBtn.onclick = createAssistanceRequest;
    elements.assistanceKeyword.onkeypress = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchAssistanceContext();
        }
    };
    elements.chatSearch.oninput = displayChatList;
    if (elements.storeFilter) {
        elements.storeFilter.onchange = displayChatList;
    }
    if (elements.statusFilter) {
        elements.statusFilter.onchange = displayChatList;
    }
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

    if (elements.quickReplySelect) {
        elements.quickReplySelect.onchange = insertQuickReply;
    }

    elements.imageInput.onchange = (e) => {
        const file = e.target.files[0];
        if (file) {
            sendImage(file);
        }
        e.target.value = '';
    };

    elements.fileBtn.onclick = () => {
        elements.fileInput.click();
    };

    elements.fileInput.onchange = (e) => {
        const file = e.target.files[0];
        if (file) {
            sendMedia(file, 'file');
        }
        e.target.value = '';
    };

    elements.videoBtn.onclick = () => {
        elements.videoInput.click();
    };

    elements.videoInput.onchange = (e) => {
        const file = e.target.files[0];
        if (file) {
            sendMedia(file, 'video');
        }
        e.target.value = '';
    };

    elements.voiceBtn.onclick = async () => {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            elements.voiceBtn.textContent = '语音';
            return;
        }

        if (!navigator.mediaDevices || !window.MediaRecorder) {
            addSystemMessage('当前浏览器不支持录音');
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({audio: true});
            voiceChunks = [];
            voiceStartAt = Date.now();
            const mimeType = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
            mediaRecorder = new MediaRecorder(stream, mimeType ? {mimeType} : undefined);
            mediaRecorder.ondataavailable = (event) => {
                if (event.data && event.data.size > 0) {
                    voiceChunks.push(event.data);
                }
            };
            mediaRecorder.onstop = () => {
                stream.getTracks().forEach(track => track.stop());
                const duration = Math.ceil((Date.now() - voiceStartAt) / 1000);
                if (voiceChunks.length > 0) {
                    const blob = new Blob(voiceChunks, {type: mediaRecorder.mimeType || 'audio/webm'});
                    const file = new File([blob], `voice-${Date.now()}.webm`, {type: blob.type || 'audio/webm'});
                    sendMedia(file, 'voice', duration);
                }
                mediaRecorder = null;
                voiceChunks = [];
            };
            mediaRecorder.start();
            elements.voiceBtn.textContent = '停止录音';
            addSystemMessage('正在录音，再点一次发送');
        } catch (error) {
            addSystemMessage('录音启动失败，请检查麦克风权限');
        }
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
    initQuickReplies();
    initAssistanceTypes();
    setWelcomeMessage('正在连接客服系统...');
    connect();

    window.onbeforeunload = () => {
        manualDisconnect = true;
        if (ws) {
            ws.close();
        }
    };
</script>
