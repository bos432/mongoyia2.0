<?php
use yii\helpers\Html;
use yii\helpers\Url;
use common\models\mall\Product;
use common\models\mall\AttributeItem;

/* @var $this yii\web\View */
/* @var $models \common\models\mall\Cart[] */
/* @var $productAmount float */
/* @var $discount float */
/* @var $total float */
/* @var $suid int */
/* @var $productId int */
/* @var $storeId int */

$language = strtolower(str_replace('_', '-', Yii::$app->language ?: 'zh-CN'));
$locale = str_starts_with($language, 'mn') ? 'mn' : (str_starts_with($language, 'en') ? 'en' : 'zh-CN');
$chatTextMap = [
    'zh-CN' => [
        'title' => '客服',
        'onlineSupport' => '在线客服',
        'connecting' => '连接中...',
        'emoji' => '表情',
        'image' => '图片',
        'messagePlaceholder' => '输入消息...',
        'product' => '商品',
        'store' => '店铺',
        'authRequestFailed' => '客服认证请求失败',
        'authFailed' => '客服认证失败',
        'authFailedRetry' => '客服认证失败，请刷新页面重试',
        'connectedStart' => '连接成功，开始对话',
        'parseFailed' => '收到无法解析的消息',
        'connectFailedRetry' => '连接失败，请刷新页面重试',
        'disconnected' => '连接已断开',
        'reconnecting' => '正在重新连接...',
        'noHistory' => '暂无历史消息',
        'invalidImage' => '[图片地址无效]',
        'disconnectedCannotSend' => '连接已断开，无法发送',
        'imageTooLarge' => '图片大小不能超过5MB',
        'uploadingImage' => '正在上传图片...',
        'uploadFailed' => '上传失败',
        'missingImageUrl' => '上传返回缺少图片地址',
        'imageUploaded' => '图片上传成功',
        'imageUploadFailedRetry' => '图片上传失败，请重试',
        'errorPrefix' => '错误',
        'unknownError' => '未知错误',
        'ratingTitle' => '服务评价',
        'ratingSatisfied' => '满意',
        'ratingNeutral' => '一般',
        'ratingDissatisfied' => '不满意',
        'ratingReasonPlaceholder' => '原因，可选',
        'ratingRemarkPlaceholder' => '补充说明，可选',
        'ratingSubmit' => '提交评价',
        'ratingSubmitting' => '提交中...',
        'ratingSubmitted' => '评价已提交',
        'ratingSubmitFailed' => '提交失败',
    ],
    'en' => [
        'title' => 'Customer Service',
        'onlineSupport' => 'Customer Service',
        'connecting' => 'Connecting...',
        'emoji' => 'Emoji',
        'image' => 'Image',
        'messagePlaceholder' => 'Type a message...',
        'product' => 'Product',
        'store' => 'Store',
        'authRequestFailed' => 'Customer-service authentication request failed',
        'authFailed' => 'Customer-service authentication failed',
        'authFailedRetry' => 'Customer-service authentication failed. Please refresh and try again.',
        'connectedStart' => 'Connected. Start chatting.',
        'parseFailed' => 'Received an unreadable message',
        'connectFailedRetry' => 'Connection failed. Please refresh and try again.',
        'disconnected' => 'Connection closed',
        'reconnecting' => 'Reconnecting...',
        'noHistory' => 'No chat history yet',
        'invalidImage' => '[Invalid image URL]',
        'disconnectedCannotSend' => 'Connection closed. Cannot send.',
        'imageTooLarge' => 'Image size cannot exceed 5 MB',
        'uploadingImage' => 'Uploading image...',
        'uploadFailed' => 'Upload failed',
        'missingImageUrl' => 'Upload response did not include an image URL',
        'imageUploaded' => 'Image uploaded',
        'imageUploadFailedRetry' => 'Image upload failed. Please try again.',
        'errorPrefix' => 'Error',
        'unknownError' => 'Unknown error',
        'ratingTitle' => 'Service Rating',
        'ratingSatisfied' => 'Satisfied',
        'ratingNeutral' => 'Neutral',
        'ratingDissatisfied' => 'Dissatisfied',
        'ratingReasonPlaceholder' => 'Reason, optional',
        'ratingRemarkPlaceholder' => 'More details, optional',
        'ratingSubmit' => 'Submit rating',
        'ratingSubmitting' => 'Submitting...',
        'ratingSubmitted' => 'Rating submitted',
        'ratingSubmitFailed' => 'Submit failed',
    ],
    'mn' => [
        'title' => 'Хэрэглэгчийн үйлчилгээ',
        'onlineSupport' => 'Хэрэглэгчийн үйлчилгээ',
        'connecting' => 'Холбогдож байна...',
        'emoji' => 'Эможи',
        'image' => 'Зураг',
        'messagePlaceholder' => 'Мессеж бичих...',
        'product' => 'Бүтээгдэхүүн',
        'store' => 'Дэлгүүр',
        'authRequestFailed' => 'Хэрэглэгчийн үйлчилгээний баталгаажуулалтын хүсэлт амжилтгүй боллоо',
        'authFailed' => 'Хэрэглэгчийн үйлчилгээний баталгаажуулалт амжилтгүй боллоо',
        'authFailedRetry' => 'Баталгаажуулалт амжилтгүй боллоо. Хуудсыг шинэчлээд дахин оролдоно уу.',
        'connectedStart' => 'Холбогдлоо. Яриагаа эхлүүлнэ үү.',
        'parseFailed' => 'Уншиж болохгүй мессеж ирлээ',
        'connectFailedRetry' => 'Холболт амжилтгүй боллоо. Хуудсыг шинэчлээд дахин оролдоно уу.',
        'disconnected' => 'Холболт тасарлаа',
        'reconnecting' => 'Дахин холбогдож байна...',
        'noHistory' => 'Чатын түүх алга',
        'invalidImage' => '[Зургийн холбоос буруу]',
        'disconnectedCannotSend' => 'Холболт тасарсан тул илгээх боломжгүй',
        'imageTooLarge' => 'Зургийн хэмжээ 5 MB-аас хэтрэхгүй байх ёстой',
        'uploadingImage' => 'Зураг байршуулж байна...',
        'uploadFailed' => 'Байршуулалт амжилтгүй боллоо',
        'missingImageUrl' => 'Байршуулалтын хариунд зургийн холбоос алга',
        'imageUploaded' => 'Зураг байршууллаа',
        'imageUploadFailedRetry' => 'Зураг байршуулж чадсангүй. Дахин оролдоно уу.',
        'errorPrefix' => 'Алдаа',
        'unknownError' => 'Тодорхойгүй алдаа',
        'ratingTitle' => 'Үйлчилгээний үнэлгээ',
        'ratingSatisfied' => 'Сэтгэл хангалуун',
        'ratingNeutral' => 'Дунд зэрэг',
        'ratingDissatisfied' => 'Сэтгэл хангалуун бус',
        'ratingReasonPlaceholder' => 'Шалтгаан, сонголтоор',
        'ratingRemarkPlaceholder' => 'Нэмэлт тайлбар, сонголтоор',
        'ratingSubmit' => 'Үнэлгээ илгээх',
        'ratingSubmitting' => 'Илгээж байна...',
        'ratingSubmitted' => 'Үнэлгээ илгээгдлээ',
        'ratingSubmitFailed' => 'Илгээж чадсангүй',
    ],
];
$chatTexts = $chatTextMap[$locale];
$chatText = static function (string $key) use ($chatTexts, $chatTextMap): string {
    return $chatTexts[$key] ?? $chatTextMap['zh-CN'][$key] ?? $key;
};
$appendLangParam = static function (string $url) use ($locale): string {
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'lang=' . rawurlencode($locale);
};
$chatTokenUrl = Url::to(['/mall/chat/token', 'lang' => $locale]);
$chatUploadUrl = $appendLangParam((string)(Yii::$app->params['chatUploadUrl'] ?? '/mall/chat/upload'));

$this->title = $chatText('title');
$this->params['breadcrumbs'][] = $this->title;
?>

<section class="page-section" data-mongoyia-mobile-ui="chat">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .chat-container {
            width: 100%;
            max-width: 600px;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            margin: 0 auto;
            overflow-y: auto;
        }

        /* 聊天头部 */
        .chat-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .header-title {
            font-size: 16px;
            font-weight: 600;
        }

        .header-status {
            font-size: 12px;
            opacity: 0.9;
            margin-top: 2px;
        }

        .header-context {
            font-size: 12px;
            opacity: 0.88;
            margin-top: 2px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }

        .status-dot.online {
            background: #4CAF50;
        }

        .status-dot.offline {
            background: #999;
        }

        /* 消息区域 */
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
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            word-wrap: break-word;
            line-height: 1.5;
        }

        .message.sent .message-bubble {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            border-radius: 12px;
            cursor: pointer;
        }

        /* 系统消息 */
        .system-message {
            text-align: center;
            margin: 20px 0;
            color: #999;
            font-size: 12px;
        }

        /* 输入区域 */
        .input-area {
            border-top: 1px solid #e0e0e0;
            background: white;
        }

        .input-tools {
            display: flex;
            gap: 8px;
            padding: 10px 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .tool-btn {
            padding: 8px 12px;
            border: none;
            background: #f5f5f5;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .tool-btn:hover {
            background: #e8e8e8;
        }

        .input-wrapper {
            display: flex;
            gap: 10px;
            padding: 10px 15px;
        }

        .rating-panel {
            margin: 0 15px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            background: #fff;
            font-size: 13px;
        }

        .rating-panel summary {
            padding: 9px 12px;
            cursor: pointer;
            color: #333;
        }

        .rating-form {
            padding: 0 12px 12px;
        }

        .rating-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 8px;
        }

        .rating-form input[type="text"],
        .rating-form textarea {
            width: 100%;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 8px;
            font-size: 13px;
            font-family: inherit;
        }

        .rating-submit {
            border: 0;
            border-radius: 18px;
            padding: 7px 14px;
            background: #667eea;
            color: #fff;
            cursor: pointer;
        }

        .rating-status {
            margin-left: 8px;
            color: #666;
        }

        .message-input {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 25px;
            font-size: 14px;
            resize: none;
            min-height: 44px;
            max-height: 100px;
            outline: none;
            font-family: inherit;
        }

        .message-input:focus {
            border-color: #667eea;
        }

        .send-btn {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s;
        }

        .send-btn:hover {
            transform: scale(1.05);
        }

        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Emoji选择器 */
        .emoji-picker {
            position: relative;
        }

        .emoji-panel {
            position: absolute;
            bottom: 50px;
            left: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            display: none;
            /*width: 280px;*/
            z-index: 1000;
        }

        .emoji-panel.show {
            display: block;
        }

        .emoji-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }

        .emoji-item {
            font-size: 22px;
            cursor: pointer;
            padding: 5px;
            border-radius: 8px;
            text-align: center;
            transition: background 0.2s;
        }

        .emoji-item:hover {
            background: #f0f0f0;
        }

        /* 隐藏的文件输入 */
        #imageInput {
            display: none;
        }

        /* 图片预览 */
        .image-preview {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            cursor: pointer;
        }

        .image-preview img {
            max-width: 90%;
            max-height: 90%;
            border-radius: 8px;
        }

        .image-preview.show {
            display: flex;
        }

        /* 加载动画 */
        .connecting {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #999;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* 滚动条样式 */
        .messages-container::-webkit-scrollbar {
            width: 6px;
        }

        .messages-container::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .messages-container::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }

        .messages-container::-webkit-scrollbar-thumb:hover {
            background: #999;
        }
        @media only screen and (max-width: 991px) {
            .chat-container{
                width: 100vw;
                height: 100dvh;
                position: fixed;
                top: 0;
                left: 0;
                z-index: 999999;
            }
            .chat-header {
                flex-shrink: 0;
            }
            .input-tools {
                flex-wrap: wrap;
            }
            .input-wrapper {
                min-width: 0;
            }
            .message-input {
                min-width: 0;
            }
        }
    </style>
    <div class="chat-container">
        <!-- 聊天头部 -->
        <div class="chat-header">
            <div class="header-info">
                <div class="avatar">💬</div>
                <div>
                    <div class="header-title"><?= Html::encode($chatText('onlineSupport')) ?></div>
                    <div class="header-status">
                        <span class="status-dot online" id="statusDot"></span>
<!--                        <span id="statusText">在线</span>-->
                    </div>
                    <div class="header-context" id="chatContext"></div>
                </div>
            </div>
        </div>

        <!-- 消息区域 -->
        <div class="messages-container" id="messagesContainer">
            <div class="connecting" id="connectingOverlay">
                <div class="spinner"></div>
                <div><?= Html::encode($chatText('connecting')) ?></div>
            </div>
        </div>

        <!-- 输入区域 -->
        <div class="input-area">
            <div class="input-tools">
                <div class="emoji-picker">
                    <button class="tool-btn" id="emojiBtn">😊 <?= Html::encode($chatText('emoji')) ?></button>
                    <div class="emoji-panel" id="emojiPanel">
                        <div class="emoji-grid" id="emojiGrid"></div>
                    </div>
                </div>
                <button class="tool-btn" id="imageBtn">📷 <?= Html::encode($chatText('image')) ?></button>
                <input type="file" id="imageInput" accept="image/*">
            </div>
            <div class="input-wrapper">
                <textarea class="message-input" id="messageInput" placeholder="<?= Html::encode($chatText('messagePlaceholder')) ?>" rows="1"></textarea>
                <button class="send-btn" id="sendBtn" disabled>➤</button>
            </div>
            <details class="rating-panel" data-mongoyia-customer-service-rating="frontend">
                <summary><?= Html::encode($chatText('ratingTitle')) ?></summary>
                <form class="rating-form" id="ratingForm">
                    <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                    <input type="hidden" name="store_id" value="<?= (int)($storeId ?? 0) ?>">
                    <input type="hidden" name="product_id" value="<?= (int)($productId ?? 0) ?>">
                    <input type="hidden" name="customer_uuid" id="ratingCustomerUuid" value="">
                    <input type="hidden" name="chat_uuid" id="ratingChatUuid" value="">
                    <div class="rating-options">
                        <label><input type="radio" name="rating" value="satisfied" required> <?= Html::encode($chatText('ratingSatisfied')) ?></label>
                        <label><input type="radio" name="rating" value="neutral" required> <?= Html::encode($chatText('ratingNeutral')) ?></label>
                        <label><input type="radio" name="rating" value="dissatisfied" required> <?= Html::encode($chatText('ratingDissatisfied')) ?></label>
                    </div>
                    <input type="text" name="reason" maxlength="255" placeholder="<?= Html::encode($chatText('ratingReasonPlaceholder')) ?>">
                    <textarea name="remark" rows="2" maxlength="1000" placeholder="<?= Html::encode($chatText('ratingRemarkPlaceholder')) ?>"></textarea>
                    <button class="rating-submit" type="submit"><?= Html::encode($chatText('ratingSubmit')) ?></button>
                    <span class="rating-status" id="ratingStatus"></span>
                </form>
            </details>
        </div>
    </div>

    <!-- 图片预览 -->
    <div class="image-preview" id="imagePreview">
        <img id="previewImg" src="">
    </div>

    <script>
        // 配置
        const CONFIG = {
            merchantId: <?= $suid;?>,
            productId: <?= (int)($productId ?? 0) ?>,
            storeId: <?= (int)($storeId ?? 0) ?>,
            wsAddress: <?= json_encode(Yii::$app->params['imWebsocketUrl'] ?? 'ws://127.0.0.1:8767') ?>,
            tokenUrl: <?= json_encode($chatTokenUrl) ?>,
            uploadUrl: <?= json_encode($chatUploadUrl) ?>,
            ratingUrl: <?= json_encode(Url::to(['/mall/chat/rating-submit', 'lang' => $locale])) ?>,
            lang: <?= json_encode($locale) ?>
        };
        const TEXT = <?= json_encode($chatTexts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        // 常用emoji
        const emojis = [
            '😊', '😂', '🤣', '😍', '😘', '😜', '🤔', '😎',
            '👍', '👎', '👏', '🙏', '💪', '❤️', '💕', '🎉',
            '🔥', '⭐', '☀️', '🌙', '☁️', '🌧️', '❄️', '🍎',
            '🍊', '🍋', '🍇', '🍉', '🍓', '🍑', '🍒', '🐶',
            '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🏠',
            '🚗', '✈️', '🚀', '🚢', '⛵', '🚂', '🚁', '😀'
        ];

        // 全局变量
        let ws = null;
        let uniqueId = null;
        let isConnected = false;

        // DOM元素
        const elements = {
            messagesContainer: document.getElementById('messagesContainer'),
            messageInput: document.getElementById('messageInput'),
            sendBtn: document.getElementById('sendBtn'),
            emojiBtn: document.getElementById('emojiBtn'),
            emojiPanel: document.getElementById('emojiPanel'),
            emojiGrid: document.getElementById('emojiGrid'),
            imageBtn: document.getElementById('imageBtn'),
            imageInput: document.getElementById('imageInput'),
            imagePreview: document.getElementById('imagePreview'),
            previewImg: document.getElementById('previewImg'),
            statusDot: document.getElementById('statusDot'),
            statusText: document.getElementById('statusText'),
            chatContext: document.getElementById('chatContext'),
            connectingOverlay: document.getElementById('connectingOverlay'),
            ratingForm: document.getElementById('ratingForm'),
            ratingCustomerUuid: document.getElementById('ratingCustomerUuid'),
            ratingChatUuid: document.getElementById('ratingChatUuid'),
            ratingStatus: document.getElementById('ratingStatus')
        };

        // 获取或创建uniqueId
        function getOrCreateUniqueId() {
            let id = localStorage.getItem('uniqueId');
            if (!id) {
                id = 'user_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                localStorage.setItem('uniqueId', id);
            }
            return id;
        }

        // 初始化emoji面板
        function initEmojiPanel() {
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
            toggleSendButton();
        }

        function initChatContext() {
            const parts = [];
            if (CONFIG.productId > 0) {
                parts.push(TEXT.product + ' #' + CONFIG.productId);
            }
            if (CONFIG.storeId > 0) {
                parts.push(TEXT.store + ' #' + CONFIG.storeId);
            }
            elements.chatContext.textContent = parts.join(' · ');
        }

        async function fetchImAuthToken() {
            const formData = new FormData();
            formData.append('gid', CONFIG.productId);
            formData.append('user_id', uniqueId);
            formData.append('lang', CONFIG.lang);

            const response = await fetch(CONFIG.tokenUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(TEXT.authRequestFailed);
            }

            const result = await response.json();
            if (!result || result.code != 200) {
                throw new Error(result && result.msg ? result.msg : TEXT.authFailed);
            }

            return result.data && result.data.token ? result.data.token : '';
        }

        // 连接WebSocket
        async function connect() {
            uniqueId = getOrCreateUniqueId();

            let authToken = '';
            try {
                authToken = await fetchImAuthToken();
            } catch (error) {
                addSystemMessage(error.message || TEXT.authFailedRetry);
                updateConnectionStatus(false);
                return;
            }

            ws = new WebSocket(CONFIG.wsAddress);

            ws.onopen = () => {
                // 发送初始化消息（用户身份）
                ws.send(JSON.stringify({
                    type: 'user',
                    user_id: uniqueId,
                    auth_token: authToken
                }));

                isConnected = true;
                updateConnectionStatus(true);

                // 隐藏加载动画
                elements.connectingOverlay.style.display = 'none';

                // 显示欢迎消息
                addSystemMessage(TEXT.connectedStart);

                // 加载历史消息
                loadChatHistory();
            };

            ws.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    handleWebSocketMessage(data);
                } catch (error) {
                    console.error('Invalid WebSocket message:', error);
                    addSystemMessage(TEXT.parseFailed);
                }
            };

            ws.onerror = (error) => {
                console.error('WebSocket error:', error);
                addSystemMessage(TEXT.connectFailedRetry);
                updateConnectionStatus(false);
            };

            ws.onclose = () => {
                isConnected = false;
                updateConnectionStatus(false);
                addSystemMessage(TEXT.disconnected);

                // 5秒后自动重连
                setTimeout(() => {
                    if (!isConnected) {
                        addSystemMessage(TEXT.reconnecting);
                        connect();
                    }
                }, 5000);
            };
        }

        // 更新连接状态
        function updateConnectionStatus(connected) {
            if (connected) {
                elements.statusDot.className = 'status-dot online';
                // elements.statusText.textContent = '在线';
                elements.sendBtn.disabled = false;
            } else {
                elements.statusDot.className = 'status-dot offline';
                // elements.statusText.textContent = '离线';
                elements.sendBtn.disabled = true;
            }
        }

        // 处理WebSocket消息
        function handleWebSocketMessage(data) {
            switch (data.type) {
                case 'heartbeat':
                    if (ws && ws.readyState === WebSocket.OPEN) {
                        ws.send(JSON.stringify({type: 'heartbeat'}));
                    }
                    break;
                case 'chat_history':
                    displayChatHistory(data.messages);
                    break;
                case 'chat':
                    if (data.uuid === uniqueId && data.uid == CONFIG.merchantId) {
                        displayMessage(data);
                        markChatRead();
                    }
                    break;
                case 'read_ack':
                    break;
                case 'error':
                    addSystemMessage(TEXT.errorPrefix + ': ' + (data.error || data.message || TEXT.unknownError));
                    break;
            }
        }

        // 加载聊天历史
        function loadChatHistory() {
            if (ws && isConnected) {
                ws.send(JSON.stringify({
                    type: 'chat_history',
                    uid: CONFIG.merchantId
                }));
            }
        }

        function markChatRead() {
            if (ws && isConnected && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({
                    type: 'mark_read',
                    uid: CONFIG.merchantId
                }));
            }
        }

        // 显示聊天历史
        function displayChatHistory(messages) {
            elements.messagesContainer.innerHTML = '';

            if (messages.length === 0) {
                addSystemMessage(TEXT.noHistory);
                return;
            }

            messages.forEach(msg => {
                displayMessage(msg, false);
            });

            scrollToBottom();
        }

        // 显示单条消息
        function displayMessage(msg, scroll = true) {
            const isSent = msg.from == 1;

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
                    bubble.textContent = TEXT.invalidImage;
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

            if (scroll) {
                scrollToBottom();
            }
        }

        // 添加系统消息
        function addSystemMessage(text) {
            const systemDiv = document.createElement('div');
            systemDiv.className = 'system-message';
            systemDiv.textContent = text;
            elements.messagesContainer.appendChild(systemDiv);
            scrollToBottom();
        }

        // 发送文字消息
        function sendMessage() {
            const content = elements.messageInput.value.trim();
            if (!content || !isConnected) return;

            const data = {
                type: 'chat',
                target_uid: CONFIG.merchantId,
                content: content,
                msg_type: 1,
                product_id: CONFIG.productId,
                store_id: CONFIG.storeId
            };

            ws.send(JSON.stringify(data));
            elements.messageInput.value = '';
            toggleSendButton();
        }

        // 发送图片消息
        async function sendImage(file) {
            if (!isConnected) {
                addSystemMessage(TEXT.disconnectedCannotSend);
                return;
            }

            // 检查文件大小（限制5MB）
            if (file.size > 5 * 1024 * 1024) {
                addSystemMessage(TEXT.imageTooLarge);
                return;
            }
            addSystemMessage(TEXT.uploadingImage);
            // 转换为base64
            try {
                // 创建FormData上传文件
                const formData = new FormData();
                formData.append('file', file);

                // 上传到服务器
                const response = await fetch(CONFIG.uploadUrl, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(TEXT.uploadFailed);
                }

                const result = await response.json();
                if (!result || (result.code && result.code != 200)) {
                    throw new Error(result && result.msg ? result.msg : TEXT.uploadFailed);
                }

                const imageUrl = normalizeUploadUrl(result);
                if (!imageUrl) {
                    throw new Error(TEXT.missingImageUrl);
                }

                // 通过WebSocket发送图片地址
                const data = {
                    type: 'chat',
                    target_uid: CONFIG.merchantId,
                    content: imageUrl,
                    msg_type: 2,
                    product_id: CONFIG.productId,
                    store_id: CONFIG.storeId
                };

                ws.send(JSON.stringify(data));
                addSystemMessage(TEXT.imageUploaded);

            } catch (error) {
                console.error('Image upload failed:', error);
                addSystemMessage(TEXT.imageUploadFailedRetry);
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

        async function submitRating(event) {
            event.preventDefault();
            uniqueId = uniqueId || getOrCreateUniqueId();
            elements.ratingCustomerUuid.value = uniqueId;
            elements.ratingChatUuid.value = uniqueId;
            elements.ratingStatus.textContent = TEXT.ratingSubmitting;

            try {
                const response = await fetch(CONFIG.ratingUrl, {
                    method: 'POST',
                    body: new FormData(elements.ratingForm),
                    headers: {'Accept': 'application/json'}
                });
                const result = await response.json();
                if (!response.ok || !result || result.code != 200) {
                    throw new Error(result && result.msg ? result.msg : TEXT.ratingSubmitFailed);
                }
                elements.ratingStatus.textContent = TEXT.ratingSubmitted;
            } catch (error) {
                elements.ratingStatus.textContent = error.message || TEXT.ratingSubmitFailed;
            }
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

        // 滚动到底部
        function scrollToBottom() {
            elements.messagesContainer.scrollTop = elements.messagesContainer.scrollHeight;
        }

        // 切换发送按钮状态
        function toggleSendButton() {
            const hasContent = elements.messageInput.value.trim().length > 0;
            elements.sendBtn.disabled = !hasContent || !isConnected;
        }

        // 事件绑定
        elements.sendBtn.onclick = sendMessage;

        elements.messageInput.oninput = toggleSendButton;

        elements.messageInput.onkeypress = (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        };

        elements.emojiBtn.onclick = (e) => {
            e.stopPropagation();
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

        elements.ratingForm.onsubmit = submitRating;

        // 点击其他地方关闭emoji面板
        document.onclick = (e) => {
            if (!elements.emojiBtn.contains(e.target) && !elements.emojiPanel.contains(e.target)) {
                elements.emojiPanel.classList.remove('show');
            }
        };

        // 自动调整输入框高度
        elements.messageInput.oninput = function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            toggleSendButton();
        };

        // 页面加载完成后自动连接
        window.onload = () => {
            initEmojiPanel();
            initChatContext();
            connect();
        };

        // 页面关闭时断开连接
        window.onbeforeunload = () => {
            if (ws) {
                ws.close();
            }
        };
    </script>

</section>
