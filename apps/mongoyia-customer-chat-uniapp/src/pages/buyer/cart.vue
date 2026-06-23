<template>
  <view class="app-page" data-mongoyia-phase13-buyer-cart="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="section-head">
      <text class="section-title">购物车</text>
      <button size="mini" @tap="loadCart">刷新</button>
    </view>
    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">去登录</button>
    </view>
    <view v-else-if="!state.items.length" class="empty">购物车为空</view>
    <view v-else class="cart-list">
      <view v-for="item in state.items" :key="item.id" class="cart-row">
        <image class="cart-thumb" :src="item.thumb || item.image || ''" mode="aspectFill" />
        <view class="cart-body">
          <text class="cart-title">{{ item.name || item.title }}</text>
          <text class="cart-meta">{{ item.sku || item.product_attribute_value || '' }}</text>
          <view class="cart-bottom">
            <text class="cart-price">{{ item.price || item.amount || '' }}</text>
            <text class="cart-number">x{{ item.number || 1 }}</text>
          </view>
        </view>
      </view>
    </view>

    <view class="checkout-bar">
      <text class="total">{{ totalText }}</text>
      <button class="checkout-btn" type="primary" @tap="checkout">结算</button>
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
      state: pageState()
    }
  },
  computed: {
    totalText() {
      const total = this.state.summary.total || this.state.summary.amount || ''
      return total ? '合计 ' + total : '合计 --'
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadCart()
  },
  onShow() {
    this.loadCart()
  },
  methods: {
    async loadCart() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.cart })
        this.state.items = normalizeListPayload(response)
        this.state.summary = (response && (response.summary || response.map)) || {}
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    checkout() {
      if (!this.state.items.length) {
        uni.showToast({ title: '购物车为空', icon: 'none' })
        return
      }
      uni.navigateTo({ url: '/pages/buyer/orders?mode=checkout&baseUrl=' + encodeURIComponent(this.baseUrl) })
    },
    openLogin() {
      uni.navigateTo({
        url: '/pages/auth/login?role=buyer&redirect='
          + encodeURIComponent('/pages/buyer/cart')
          + '&baseUrl=' + encodeURIComponent(this.baseUrl)
      })
    }
  }
}
</script>

<style scoped>
.app-page {
  min-height: 100vh;
  padding: 12px 12px 72px;
  background: #f6f7f9;
  box-sizing: border-box;
}

.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
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

.cart-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.cart-row {
  display: flex;
  gap: 8px;
  min-height: 90px;
  padding: 8px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.cart-thumb {
  width: 84px;
  height: 84px;
  border-radius: 6px;
  background: #e9eef4;
}

.cart-body {
  min-width: 0;
  flex: 1;
}

.cart-title,
.cart-meta,
.cart-price,
.cart-number {
  display: block;
}

.cart-title,
.cart-meta {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cart-title {
  font-weight: 600;
}

.cart-meta {
  margin-top: 8px;
  color: #64748b;
  font-size: 12px;
}

.cart-bottom {
  display: flex;
  justify-content: space-between;
  margin-top: 12px;
}

.cart-price {
  color: #b45309;
  font-weight: 600;
}

.cart-number {
  color: #64748b;
}

.checkout-bar {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-top: 1px solid #d8dee8;
  background: #ffffff;
}

.total {
  flex: 1;
  color: #102a43;
  font-weight: 600;
}

.checkout-btn {
  width: 96px;
  height: 42px;
  border-radius: 6px;
}
</style>
