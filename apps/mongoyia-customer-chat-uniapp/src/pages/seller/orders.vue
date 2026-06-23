<template>
  <view class="seller-page" data-mongoyia-phase13-seller-orders="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="section-head">
      <text class="section-title">订单发货</text>
      <button size="mini" @tap="loadOrders">刷新</button>
    </view>
    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">商家登录</button>
    </view>
    <view v-else-if="!state.items.length" class="empty">暂无订单</view>
    <view v-else class="order-list">
      <view v-for="item in state.items" :key="item.id || item.sn" class="order-row">
        <view class="order-head">
          <text class="order-sn">{{ item.sn || item.order_sn }}</text>
          <text class="order-status">{{ item.status_label || item.status || '' }}</text>
        </view>
        <text class="order-meta">{{ item.receiver || item.mobile || '' }}</text>
        <view class="ship-row">
          <input v-model="shipment[item.id || item.sn]" class="ship-input" placeholder="物流单号" />
          <button size="mini" type="primary" @tap="submitShipment(item)">发货</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import { DEFAULT_CONFIG, cleanBaseUrl } from '../../utils/config.js'
import { SELLER_ENDPOINTS, appRequest, normalizeListPayload, pageState } from '../../utils/appApi.js'

export default {
  data() {
    return {
      baseUrl: DEFAULT_CONFIG.baseUrl,
      shipment: {},
      state: pageState()
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadOrders()
  },
  methods: {
    async loadOrders() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.orders })
        this.state.items = normalizeListPayload(response)
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    async submitShipment(item) {
      const key = item.id || item.sn
      const trackingNo = String(this.shipment[key] || '').trim()
      if (!trackingNo) {
        uni.showToast({ title: '请输入物流单号', icon: 'none' })
        return
      }
      try {
        await appRequest({
          baseUrl: this.baseUrl,
          path: SELLER_ENDPOINTS.shipment,
          method: 'POST',
          data: {
            order_id: item.id,
            tracking_no: trackingNo,
            action: 'ship'
          }
        })
        uni.showToast({ title: '已提交' })
        this.loadOrders()
      } catch (error) {
        uni.showToast({ title: error.message || '提交失败', icon: 'none' })
      }
    },
    openLogin() {
      uni.navigateTo({
        url: '/pages/auth/login?role=seller&redirect='
          + encodeURIComponent('/pages/seller/orders')
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

.section-head,
.order-head,
.ship-row {
  display: flex;
  align-items: center;
}

.section-head,
.order-head {
  justify-content: space-between;
}

.section-head {
  margin-bottom: 10px;
}

.section-title {
  color: #102a43;
  font-weight: 600;
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

.order-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.order-row {
  padding: 10px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.order-sn {
  min-width: 0;
  overflow: hidden;
  color: #102a43;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.order-status,
.order-meta {
  color: #64748b;
  font-size: 12px;
}

.order-meta {
  display: block;
  margin-top: 8px;
}

.ship-row {
  gap: 8px;
  margin-top: 10px;
}

.ship-input {
  flex: 1;
  height: 36px;
  padding: 0 10px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
}
</style>
