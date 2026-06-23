<template>
  <view class="seller-page" data-mongoyia-phase13-seller-dashboard="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="metric-grid">
      <view class="metric">
        <text class="metric-value">{{ summary.orders || '--' }}</text>
        <text class="metric-label">订单</text>
      </view>
      <view class="metric">
        <text class="metric-value">{{ summary.products || '--' }}</text>
        <text class="metric-label">商品</text>
      </view>
      <view class="metric">
        <text class="metric-value">{{ summary.amount || '--' }}</text>
        <text class="metric-label">销售额</text>
      </view>
    </view>

    <view class="action-list">
      <button class="action-btn" @tap="goProducts">商品管理</button>
      <button class="action-btn" @tap="goOrders">订单发货</button>
      <button class="action-btn" @tap="loadDashboard">刷新数据</button>
    </view>

    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">商家登录</button>
    </view>
  </view>
</template>

<script>
import { DEFAULT_CONFIG, cleanBaseUrl } from '../../utils/config.js'
import { SELLER_ENDPOINTS, appRequest, pageState } from '../../utils/appApi.js'

export default {
  data() {
    return {
      baseUrl: DEFAULT_CONFIG.baseUrl,
      summary: {},
      state: pageState()
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadDashboard()
  },
  onShow() {
    this.loadDashboard()
  },
  methods: {
    async loadDashboard() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.dashboard })
        this.summary = (response && (response.summary || response.data || response.map)) || {}
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    goProducts() {
      uni.navigateTo({ url: '/pages/seller/products?baseUrl=' + encodeURIComponent(this.baseUrl) })
    },
    goOrders() {
      uni.navigateTo({ url: '/pages/seller/orders?baseUrl=' + encodeURIComponent(this.baseUrl) })
    },
    openLogin() {
      uni.navigateTo({
        url: '/pages/auth/login?role=seller&redirect='
          + encodeURIComponent('/pages/seller/dashboard')
          + '&baseUrl=' + encodeURIComponent(this.baseUrl)
      })
    }
  }
}
</script>

<style scoped>
.seller-page {
  min-height: 100vh;
  padding: 12px;
  background: #f6f7f9;
  box-sizing: border-box;
}

.metric-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.metric {
  min-height: 76px;
  padding: 10px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
  text-align: center;
}

.metric-value,
.metric-label {
  display: block;
}

.metric-value {
  color: #0f766e;
  font-size: 18px;
  font-weight: 700;
}

.metric-label {
  margin-top: 8px;
  color: #64748b;
  font-size: 12px;
}

.action-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 12px;
}

.action-btn {
  height: 42px;
  border-radius: 6px;
}

.notice {
  padding: 18px 0;
  color: #64748b;
  text-align: center;
}

.notice text {
  display: block;
  margin-bottom: 10px;
}
</style>
