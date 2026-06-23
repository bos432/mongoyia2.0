<template>
  <view class="app-page" data-mongoyia-phase12-app-notifications="MONGOYIA_APP_BUYER_NOTIFICATION_CENTER_V1">
    <view class="section-head">
      <view>
        <text class="section-title">消息通知</text>
        <text class="section-meta">未读 {{ summary.unread || 0 }} / 共 {{ summary.total || 0 }}</text>
      </view>
      <button size="mini" @tap="loadNotifications">刷新</button>
    </view>

    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button v-if="state.authRequired" size="mini" @tap="openLogin">去登录</button>
    </view>

    <view v-else>
      <view class="toolbar">
        <button size="mini" :disabled="!(summary.unread > 0)" @tap="markAllRead">全部已读</button>
      </view>

      <view v-if="!items.length" class="empty">暂无通知</view>
      <view v-for="item in items" :key="item.id" class="message-row" :class="{ unread: item.is_unread }">
        <view class="row-main">
          <view class="row-head">
            <text class="row-title">{{ item.title }}</text>
            <text class="row-time">{{ dateLabel(item.created_at) }}</text>
          </view>
          <text class="row-content">{{ item.content }}</text>
          <text v-if="item.event_label" class="row-tag">{{ item.event_label }}</text>
        </view>
        <button v-if="item.is_unread" size="mini" @tap="markRead(item)">已读</button>
      </view>
    </view>
  </view>
</template>

<script>
import { DEFAULT_CONFIG, cleanBaseUrl } from '../../utils/config.js'
import { BUYER_ENDPOINTS, appRequest, normalizeListPayload, pageState } from '../../utils/appApi.js'

export default {
  data() {
    return {
      baseUrl: DEFAULT_CONFIG.baseUrl,
      state: pageState({ authRequired: false }),
      items: [],
      summary: {}
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadNotifications()
  },
  methods: {
    async loadNotifications() {
      this.state.loading = true
      this.state.error = ''
      this.state.authRequired = false
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.notifications })
        if (this.requiresLogin(response)) {
          this.state.error = '请先登录后查看消息通知'
          this.state.authRequired = true
          this.items = []
          this.summary = {}
          return
        }
        this.items = normalizeListPayload(response)
        this.summary = (response && response.summary) || {}
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '通知暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    async markRead(item) {
      try {
        const response = await appRequest({
          baseUrl: this.baseUrl,
          path: BUYER_ENDPOINTS.notifications,
          method: 'POST',
          data: { id: item.id }
        })
        if (response.item) {
          this.items = this.items.map((row) => Number(row.id) === Number(item.id) ? response.item : row)
        }
        this.summary = response.summary || this.summary
      } catch (error) {
        uni.showToast({ title: error.message || '操作失败', icon: 'none' })
      }
    },
    async markAllRead() {
      try {
        const response = await appRequest({
          baseUrl: this.baseUrl,
          path: BUYER_ENDPOINTS.notifications,
          method: 'POST',
          data: { all: 1 }
        })
        this.summary = response.summary || this.summary
        this.items = this.items.map((row) => ({ ...row, is_unread: false }))
      } catch (error) {
        uni.showToast({ title: error.message || '操作失败', icon: 'none' })
      }
    },
    openLogin() {
      uni.navigateTo({
        url: '/pages/auth/login?role=buyer&redirect='
          + encodeURIComponent('/pages/buyer/notifications')
          + '&baseUrl=' + encodeURIComponent(this.baseUrl)
      })
    },
    requiresLogin(response) {
      const data = response && response.data ? response.data : response
      return Boolean(data && data.auth_required)
    },
    dateLabel(value) {
      const timestamp = Number(value || 0)
      if (!timestamp) {
        return '-'
      }
      const date = new Date(timestamp * 1000)
      const month = String(date.getMonth() + 1).padStart(2, '0')
      const day = String(date.getDate()).padStart(2, '0')
      const hour = String(date.getHours()).padStart(2, '0')
      const minute = String(date.getMinutes()).padStart(2, '0')
      return date.getFullYear() + '-' + month + '-' + day + ' ' + hour + ':' + minute
    }
  }
}
</script>

<style scoped>
.app-page {
  min-height: 100vh;
  padding: 12px;
  background: #f6f7f9;
  box-sizing: border-box;
}

.section-head,
.row-head,
.message-row,
.toolbar {
  display: flex;
  align-items: center;
}

.section-head {
  justify-content: space-between;
  margin-bottom: 10px;
}

.section-title,
.section-meta {
  display: block;
}

.section-title {
  color: #102a43;
  font-weight: 700;
}

.section-meta,
.row-time,
.row-content,
.row-tag {
  color: #64748b;
  font-size: 12px;
}

.toolbar {
  justify-content: flex-end;
  margin-bottom: 10px;
}

.notice,
.empty {
  padding: 18px 0;
  color: #64748b;
  text-align: center;
}

.notice text {
  display: block;
  margin-bottom: 10px;
}

.message-row {
  gap: 10px;
  align-items: flex-start;
  margin-bottom: 8px;
  padding: 10px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.message-row.unread {
  border-color: #0f766e;
}

.row-main {
  min-width: 0;
  flex: 1;
}

.row-head {
  justify-content: space-between;
  gap: 8px;
}

.row-title {
  min-width: 0;
  color: #102a43;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row-content {
  display: block;
  margin-top: 6px;
  line-height: 1.5;
}

.row-tag {
  display: inline-block;
  margin-top: 8px;
  padding: 2px 6px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
}
</style>
