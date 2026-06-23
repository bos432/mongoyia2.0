<template>
  <view class="app-page" data-mongoyia-phase13-buyer-orders="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="section-head">
      <text class="section-title">{{ mode === 'checkout' ? '确认订单' : '我的订单' }}</text>
      <button size="mini" @tap="loadOrders">刷新</button>
    </view>

    <view v-if="mode === 'checkout'" class="checkout-form">
      <input v-model="address.name" class="form-input" placeholder="收件人" />
      <input v-model="address.mobile" class="form-input" placeholder="手机号" />
      <input v-model="address.email" class="form-input" placeholder="邮箱（可选）" />
      <input v-model="address.country" class="form-input" placeholder="国家/地区" />
      <input v-model="address.city" class="form-input" placeholder="城市" />
      <textarea v-model="address.address" class="form-input textarea" placeholder="收货地址" />
      <input v-model="address.postcode" class="form-input" placeholder="邮编（可选）" />
      <picker mode="selector" :range="paymentOptions" range-key="label" @change="onPaymentChange">
        <view class="picker-row">支付方式：{{ currentPayment.label }}</view>
      </picker>
      <button type="primary" @tap="submitOrder">提交订单</button>
    </view>

    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">去登录</button>
    </view>
    <view v-else-if="!state.items.length" class="empty">暂无订单</view>
    <view v-else class="order-list">
      <view v-for="item in state.items" :key="item.id || item.sn" class="order-row">
        <view class="order-head">
          <text class="order-sn">{{ item.sn || item.order_sn }}</text>
          <text class="order-status">{{ item.status_label || item.status || '' }}</text>
        </view>
        <text class="order-amount">{{ item.amount || item.total || '' }}</text>
        <view class="order-actions">
          <button v-if="item.payment_url" size="mini" type="primary" @tap="openPayment(item)">去支付</button>
          <button size="mini" @tap="openChat(item)">客服</button>
          <button size="mini" @tap="viewOrder(item)">详情</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import { DEFAULT_CONFIG, cleanBaseUrl, cleanWsUrl } from '../../utils/config.js'
import { BUYER_ENDPOINTS, appRequest, normalizeListPayload, pageState } from '../../utils/appApi.js'

export default {
  data() {
    return {
      baseUrl: DEFAULT_CONFIG.baseUrl,
      wsUrl: DEFAULT_CONFIG.wsUrl,
      mode: '',
      address: {
        name: '',
        mobile: '',
        email: '',
        country: '',
        city: '',
        postcode: '',
        address: ''
      },
      paymentMethod: 1,
      paymentOptions: [
        { label: '在线支付', value: 1 },
        { label: '货到付款', value: 2 }
      ],
      state: pageState()
    }
  },
  computed: {
    currentPayment() {
      return this.paymentOptions.find((item) => item.value === this.paymentMethod) || this.paymentOptions[0]
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.wsUrl = cleanWsUrl(options.wsUrl || options.ws_url || DEFAULT_CONFIG.wsUrl)
    this.mode = String(options.mode || '')
    this.loadOrders()
  },
  onShow() {
    this.loadOrders()
  },
  methods: {
    async loadOrders() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.orders })
        this.state.items = normalizeListPayload(response)
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    async submitOrder() {
      if (!this.address.name || !this.address.mobile || !this.address.address) {
        uni.showToast({ title: '请填写收件人、手机号和地址', icon: 'none' })
        return
      }
      try {
        const response = await appRequest({
          baseUrl: this.baseUrl,
          path: BUYER_ENDPOINTS.orders,
          method: 'POST',
          data: {
            address: this.address,
            payment_method: this.paymentMethod
          }
        })
        uni.showToast({ title: '订单已提交' })
        this.mode = ''
        await this.loadOrders()
        const paymentUrl = response && response.payment && response.payment.payment_url
        if (paymentUrl && response.payment.requires_online_payment) {
          this.openPayment({ payment_url: paymentUrl })
        }
      } catch (error) {
        uni.showToast({ title: error.message || '提交失败', icon: 'none' })
      }
    },
    onPaymentChange(event) {
      const index = Number(event.detail.value || 0)
      this.paymentMethod = this.paymentOptions[index] ? this.paymentOptions[index].value : 1
    },
    openChat(item) {
      const firstItem = item.items && item.items.length ? item.items[0] : {}
      const productId = Number(item.product_id || item.gid || firstItem.product_id || 0)
      uni.navigateTo({
        url: '/pages/chat/index?gid=' + productId
          + '&baseUrl=' + encodeURIComponent(this.baseUrl)
          + '&wsUrl=' + encodeURIComponent(this.wsUrl)
      })
    },
    viewOrder(item) {
      const firstItem = item.items && item.items.length ? item.items[0] : {}
      uni.showModal({
        title: item.sn || item.order_sn || '订单',
        content: [
          '状态：' + (item.status_label || item.status || ''),
          '金额：' + (item.amount || item.total || ''),
          firstItem.name ? '商品：' + firstItem.name : ''
        ].filter(Boolean).join('\n'),
        showCancel: false
      })
    },
    openPayment(item) {
      const url = String(item.payment_url || '')
      if (!url) {
        return
      }
      const target = url.indexOf('http') === 0 ? url : this.baseUrl.replace(/\/$/, '') + url
      if (typeof window !== 'undefined' && window.location) {
        window.location.href = target
        return
      }
      uni.showModal({
        title: '支付链接',
        content: target,
        showCancel: false
      })
    },
    openLogin() {
      uni.navigateTo({
        url: '/pages/auth/login?role=buyer&redirect='
          + encodeURIComponent('/pages/buyer/orders')
          + '&baseUrl=' + encodeURIComponent(this.baseUrl)
      })
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
.order-head,
.order-actions {
  display: flex;
  align-items: center;
}

.section-head,
.order-head {
  justify-content: space-between;
}

.section-title {
  color: #102a43;
  font-weight: 600;
}

.checkout-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 12px;
  padding: 10px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.form-input {
  min-height: 38px;
  padding: 0 10px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
}

.textarea {
  min-height: 72px;
  padding-top: 8px;
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

.order-status {
  color: #0f766e;
  font-size: 12px;
}

.order-amount {
  display: block;
  margin-top: 8px;
  color: #b45309;
  font-weight: 600;
}

.order-actions {
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}
</style>
