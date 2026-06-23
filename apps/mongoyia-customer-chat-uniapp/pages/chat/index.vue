<template>
  <view class="chat-page" data-mongoyia-customer-service-uniapp="chat">
    <view class="setup">
      <view class="setup-row">
        <input v-model="baseUrl" class="setup-input" placeholder="Backend URL" />
        <input v-model="wsUrl" class="setup-input" placeholder="WSS URL" />
      </view>
      <view class="setup-row">
        <input v-model.number="productId" class="setup-input" type="number" placeholder="Product ID" />
        <input v-model.number="merchantUid" class="setup-input" type="number" placeholder="Merchant UID" />
        <input v-model.number="storeId" class="setup-input" type="number" placeholder="Store ID" />
      </view>
      <view class="setup-row">
        <picker :range="languageOptions" range-key="label" @change="changeLanguage">
          <view class="picker">{{ currentLanguageLabel }}</view>
        </picker>
        <button size="mini" type="primary" @tap="connectChat">{{ socketOpen ? '重连' : '连接' }}</button>
        <button size="mini" @tap="loadHistory" :disabled="!socketOpen">历史</button>
      </view>
      <view class="status">{{ statusText }}</view>
    </view>

    <scroll-view class="message-list" scroll-y :scroll-into-view="lastMessageAnchor">
      <view
        v-for="(message, index) in messages"
        :id="'msg-' + index"
        :key="message.local_id || message.message_id || index"
        :class="['message-row', isMine(message) ? 'mine' : 'theirs']"
      >
        <view class="bubble">
          <image
            v-if="messageType(message) === 2"
            class="message-image"
            mode="aspectFill"
            :src="mediaUrl(message.content)"
            @tap="previewImage(message.content)"
          />
          <view v-else-if="messageType(message) === 3" class="file-message" @tap="openFile(message.content)">
            <text class="file-icon">FILE</text>
            <text>{{ fileName(message.content) }}</text>
          </view>
          <video
            v-else-if="messageType(message) === 4"
            class="message-video"
            :src="mediaUrl(message.content)"
            controls
          />
          <audio
            v-else-if="messageType(message) === 5"
            class="message-audio"
            :src="mediaUrl(message.content)"
            controls
          />
          <text v-else>{{ displayText(message) }}</text>
          <view v-if="translatedHint(message)" class="translated-hint">{{ translatedHint(message) }}</view>
          <view class="time">{{ message.timestamp || '' }}</view>
        </view>
      </view>
    </scroll-view>

    <view class="toolbar">
      <button size="mini" @tap="chooseImage">图片</button>
      <button size="mini" @tap="chooseFile">文件</button>
      <button size="mini" @tap="chooseVideo">视频</button>
      <button size="mini" :type="recording ? 'warn' : 'default'" @tap="toggleRecord">{{ recording ? '停止' : '语音' }}</button>
    </view>

    <view class="input-row">
      <textarea v-model="inputText" class="message-input" auto-height maxlength="2000" placeholder="Message" />
      <button class="send-btn" type="primary" @tap="sendText">发送</button>
    </view>

    <view class="rating-card" data-mongoyia-customer-service-uniapp-rating="enabled">
      <view class="rating-title">服务评价</view>
      <radio-group @change="rating.rating = $event.detail.value">
        <label v-for="item in ratingOptions" :key="item.value" class="rating-option">
          <radio :value="item.value" :checked="rating.rating === item.value" />
          <text>{{ item.label }}</text>
        </label>
      </radio-group>
      <input v-model="rating.reason" class="rating-input" placeholder="原因，可选" />
      <textarea v-model="rating.remark" class="rating-input" auto-height placeholder="补充说明，可选" />
      <button size="mini" type="primary" @tap="submitRating">提交评价</button>
      <text class="rating-status">{{ ratingStatus }}</text>
    </view>
  </view>
</template>

<script>
import { DEFAULT_CONFIG, cleanBaseUrl, cleanWsUrl, customerUuid } from '../../utils/config.js'
import { requestJson, uploadMedia } from '../../utils/api.js'

const MEDIA_KIND = {
  file: 3,
  video: 4,
  voice: 5
}

export default {
  data() {
    return {
      baseUrl: DEFAULT_CONFIG.baseUrl,
      wsUrl: DEFAULT_CONFIG.wsUrl,
      productId: 0,
      storeId: 0,
      merchantUid: 0,
      customerUuid: '',
      customerUserId: 0,
      language: DEFAULT_CONFIG.language,
      authToken: '',
      socketTask: null,
      socketOpen: false,
      statusText: '未连接',
      inputText: '',
      messages: [],
      recording: false,
      recorder: null,
      languageOptions: [
        { value: 'zh-CN', label: '中文' },
        { value: 'en', label: 'English' },
        { value: 'mn', label: 'Монгол' }
      ],
      ratingOptions: [
        { value: 'satisfied', label: '满意' },
        { value: 'neutral', label: '一般' },
        { value: 'dissatisfied', label: '不满意' }
      ],
      rating: {
        rating: '',
        reason: '',
        remark: ''
      },
      ratingStatus: ''
    }
  },
  computed: {
    currentLanguageLabel() {
      const item = this.languageOptions.find((row) => row.value === this.language)
      return item ? item.label : this.language
    },
    lastMessageAnchor() {
      return this.messages.length > 0 ? 'msg-' + (this.messages.length - 1) : ''
    }
  },
  onLoad(options = {}) {
    // MONGOYIA_CUSTOMER_SERVICE_UNIAPP_CHAT_V1
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.wsUrl = cleanWsUrl(options.wsUrl || options.ws_url || DEFAULT_CONFIG.wsUrl)
    this.productId = Number(options.gid || options.product_id || 0)
    this.storeId = Number(options.store_id || 0)
    this.merchantUid = Number(options.uid || options.merchant_uid || 0)
    this.customerUserId = Number(options.customer_user_id || options.user_id || 0)
    this.customerUuid = String(options.customer_uuid || customerUuid())
    this.language = String(options.lang || options.language || DEFAULT_CONFIG.language)
    this.initRecorder()
  },
  onUnload() {
    this.closeSocket()
  },
  methods: {
    changeLanguage(event) {
      const item = this.languageOptions[Number(event.detail.value)]
      this.language = item ? item.value : DEFAULT_CONFIG.language
    },
    async connectChat() {
      try {
        await this.ensureToken()
        this.closeSocket()
        this.statusText = '连接中...'
        this.socketTask = uni.connectSocket({
          url: this.wsUrl,
          complete() {}
        })
        this.socketTask.onOpen(() => {
          this.socketOpen = true
          this.statusText = '已连接'
          this.socketTask.send({
            data: JSON.stringify({
              type: 'user',
              user_id: this.customerUuid,
              uid: this.merchantUid,
              product_id: this.productId,
              store_id: this.storeId,
              auth_token: this.authToken
            })
          })
          this.loadHistory()
        })
        this.socketTask.onMessage((event) => this.handleSocketMessage(event.data))
        this.socketTask.onError(() => {
          this.statusText = '连接错误'
          this.socketOpen = false
        })
        this.socketTask.onClose(() => {
          this.statusText = '已断开'
          this.socketOpen = false
        })
      } catch (error) {
        this.statusText = error.message || '连接失败'
      }
    },
    closeSocket() {
      if (this.socketTask) {
        try {
          this.socketTask.close({})
        } catch (error) {}
      }
      this.socketTask = null
      this.socketOpen = false
    },
    async ensureToken() {
      if (!this.productId) {
        throw new Error('Product ID is required')
      }
      const response = await requestJson({
        baseUrl: this.baseUrl,
        path: '/mall/chat/token',
        query: {
          gid: this.productId,
          user_id: this.customerUuid,
          lang: this.language
        }
      })
      const data = response.data || {}
      this.authToken = data.token || ''
      this.merchantUid = Number(data.uid || this.merchantUid || 0)
      this.productId = Number(data.product_id || this.productId || 0)
      this.storeId = Number(data.store_id || this.storeId || 0)
      if (!this.authToken || !this.merchantUid) {
        throw new Error('Customer-service token is incomplete')
      }
    },
    loadHistory() {
      if (!this.socketOpen || !this.socketTask || !this.merchantUid) {
        return
      }
      this.socketTask.send({
        data: JSON.stringify({
          type: 'chat_history',
          uid: this.merchantUid
        })
      })
    },
    handleSocketMessage(raw) {
      let data = {}
      try {
        data = typeof raw === 'string' ? JSON.parse(raw) : raw
      } catch (error) {
        return
      }
      if (data.type === 'heartbeat') {
        this.sendSocket({ type: 'heartbeat' })
        return
      }
      if (data.type === 'chat_history') {
        this.messages = Array.isArray(data.messages) ? data.messages : []
        return
      }
      if (data.type === 'chat') {
        this.messages.push(data)
      }
      if (data.type === 'error') {
        this.statusText = data.error || 'IM error'
      }
    },
    sendSocket(payload) {
      if (!this.socketOpen || !this.socketTask) {
        this.statusText = '请先连接客服'
        return false
      }
      this.socketTask.send({ data: JSON.stringify(payload) })
      return true
    },
    async sendText() {
      const text = this.inputText.trim()
      if (!text) {
        return
      }
      try {
        const translation = await this.translateMessage(text)
        const metadata = translation.metadata || {}
        this.sendSocket({
          type: 'chat',
          target_uid: this.merchantUid,
          product_id: this.productId,
          store_id: this.storeId,
          content: text,
          msg_type: 1,
          ...metadata
        })
        this.inputText = ''
      } catch (error) {
        this.statusText = error.message || '发送失败'
      }
    },
    async translateMessage(content) {
      const fallback = {
        metadata: {
          original_content: content,
          source_language: this.language,
          target_language: '',
          translated_content: '',
          translation_status: 'none',
          translation_provider: '',
          translation_error: '',
          translated_at: 0
        }
      }
      try {
        const response = await requestJson({
          baseUrl: this.baseUrl,
          path: '/mall/chat/translate',
          query: { lang: this.language },
          method: 'POST',
          data: {
            content,
            source_language: this.language,
            direction: 'user_to_staff'
          }
        })
        return response.data || fallback
      } catch (error) {
        return fallback
      }
    },
    chooseImage() {
      uni.chooseImage({
        count: 1,
        success: (result) => {
          const path = result.tempFilePaths && result.tempFilePaths[0]
          if (path) {
            this.uploadAndSend(path, 'image')
          }
        }
      })
    },
    chooseVideo() {
      uni.chooseVideo({
        sourceType: ['album', 'camera'],
        success: (result) => {
          if (result.tempFilePath) {
            this.uploadAndSend(result.tempFilePath, 'video', Number(result.duration || 0))
          }
        }
      })
    },
    chooseFile() {
      const choose = uni.chooseFile || uni.chooseMessageFile
      if (!choose) {
        this.statusText = '当前端不支持文件选择'
        return
      }
      choose({
        count: 1,
        success: (result) => {
          const file = (result.tempFiles && result.tempFiles[0]) || {}
          const path = file.path || (result.tempFilePaths && result.tempFilePaths[0])
          if (path) {
            this.uploadAndSend(path, 'file')
          }
        }
      })
    },
    initRecorder() {
      if (!uni.getRecorderManager) {
        return
      }
      this.recorder = uni.getRecorderManager()
      this.recorder.onStop((result) => {
        this.recording = false
        if (result.tempFilePath) {
          this.uploadAndSend(result.tempFilePath, 'voice', Number(result.duration || 0))
        }
      })
      this.recorder.onError(() => {
        this.recording = false
        this.statusText = '录音失败'
      })
    },
    toggleRecord() {
      if (!this.recorder) {
        this.statusText = '当前端不支持录音'
        return
      }
      if (this.recording) {
        this.recorder.stop()
        return
      }
      this.recording = true
      this.recorder.start({
        duration: 120000,
        sampleRate: 16000,
        numberOfChannels: 1,
        encodeBitRate: 48000,
        format: 'mp3'
      })
    },
    async uploadAndSend(filePath, media, duration = 0) {
      try {
        const uploaded = await uploadMedia({
          baseUrl: this.baseUrl,
          filePath,
          media,
          duration,
          language: this.language
        })
        const msgType = media === 'image' ? 2 : Number(uploaded.msg_type || MEDIA_KIND[media] || 3)
        this.sendSocket({
          type: 'chat',
          target_uid: this.merchantUid,
          product_id: this.productId,
          store_id: this.storeId,
          content: uploaded.url,
          msg_type: msgType
        })
      } catch (error) {
        this.statusText = error.message || '上传失败'
      }
    },
    async submitRating() {
      if (!this.rating.rating) {
        this.ratingStatus = '请选择评价'
        return
      }
      try {
        await requestJson({
          baseUrl: this.baseUrl,
          path: '/mall/chat/rating-submit',
          query: { lang: this.language },
          method: 'POST',
          data: {
            store_id: this.storeId,
            product_id: this.productId,
            customer_uuid: this.customerUuid,
            chat_uuid: this.customerUuid,
            rating: this.rating.rating,
            reason: this.rating.reason,
            remark: this.rating.remark
          }
        })
        this.ratingStatus = '评价已提交'
      } catch (error) {
        this.ratingStatus = error.message || '提交失败'
      }
    },
    isMine(message) {
      return String(message.uuid || '') === this.customerUuid && Number(message.from || 1) === 1
    },
    messageType(message) {
      return Number(message.msg_type || message.type || 1)
    },
    displayText(message) {
      if (!this.isMine(message) && message.translation_status === 'translated' && message.translated_content) {
        return message.translated_content
      }
      return message.content || ''
    },
    translatedHint(message) {
      if (message.translation_status !== 'translated' || !message.translated_content || message.translated_content === message.content) {
        return ''
      }
      return this.isMine(message) ? message.translated_content : message.content
    },
    mediaUrl(value) {
      const text = String(value || '')
      if (text.indexOf('http://') === 0 || text.indexOf('https://') === 0) {
        return text
      }
      if (text.indexOf('/') === 0) {
        return cleanBaseUrl(this.baseUrl) + text
      }
      return text
    },
    fileName(value) {
      const text = String(value || '')
      return decodeURIComponent(text.split('/').pop() || 'attachment')
    },
    previewImage(value) {
      uni.previewImage({ urls: [this.mediaUrl(value)] })
    },
    openFile(value) {
      uni.downloadFile({
        url: this.mediaUrl(value),
        success: (result) => {
          if (result.statusCode === 200) {
            uni.openDocument({ filePath: result.tempFilePath, showMenu: true })
          }
        }
      })
    }
  }
}
</script>

<style scoped>
.chat-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: #f6f7f9;
}

.setup {
  padding: 10px;
  background: #ffffff;
  border-bottom: 1px solid #d8dee8;
}

.setup-row {
  display: flex;
  gap: 8px;
  margin-bottom: 8px;
}

.setup-input,
.picker {
  min-width: 0;
  flex: 1;
  height: 34px;
  padding: 0 10px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
  line-height: 34px;
}

.status {
  min-height: 18px;
  color: #607080;
  font-size: 12px;
}

.message-list {
  flex: 1;
  height: 0;
  padding: 12px;
  box-sizing: border-box;
}

.message-row {
  display: flex;
  margin-bottom: 10px;
}

.message-row.mine {
  justify-content: flex-end;
}

.message-row.theirs {
  justify-content: flex-start;
}

.bubble {
  max-width: 78%;
  padding: 10px;
  border-radius: 8px;
  background: #ffffff;
  border: 1px solid #d8dee8;
  word-break: break-word;
}

.mine .bubble {
  background: #dff7ef;
  border-color: #a8dfcf;
}

.message-image {
  width: 180px;
  height: 130px;
  border-radius: 6px;
  background: #e9eef4;
}

.message-video {
  width: 220px;
  height: 150px;
}

.message-audio {
  width: 220px;
}

.file-message {
  display: flex;
  align-items: center;
  gap: 8px;
}

.file-icon {
  padding: 2px 6px;
  border-radius: 4px;
  background: #0f766e;
  color: #ffffff;
  font-size: 11px;
}

.translated-hint,
.time {
  margin-top: 4px;
  color: #607080;
  font-size: 11px;
}

.toolbar,
.input-row,
.rating-card {
  padding: 10px;
  background: #ffffff;
  border-top: 1px solid #d8dee8;
}

.toolbar {
  display: flex;
  gap: 8px;
}

.input-row {
  display: flex;
  align-items: flex-end;
  gap: 8px;
}

.message-input {
  flex: 1;
  min-height: 42px;
  max-height: 120px;
  padding: 8px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
}

.send-btn {
  width: 72px;
}

.rating-title {
  margin-bottom: 8px;
  font-weight: 600;
}

.rating-option {
  margin-right: 14px;
}

.rating-input {
  width: 100%;
  min-height: 34px;
  margin-top: 8px;
  padding: 8px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  box-sizing: border-box;
}

.rating-status {
  margin-left: 8px;
  color: #607080;
}
</style>
