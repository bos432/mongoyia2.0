<template>
  <view class="app-page" data-mongoyia-phase13-buyer-home="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="search-row">
      <input v-model="keyword" class="search-input" placeholder="搜索商品" confirm-type="search" @confirm="openSearch" />
      <button class="search-btn" size="mini" type="primary" @tap="openSearch">搜索</button>
    </view>

    <view class="quick-grid">
      <button class="quick-btn" @tap="go('/pages/buyer/category')">分类</button>
      <button class="quick-btn" @tap="go('/pages/buyer/cart')">购物车</button>
      <button class="quick-btn" @tap="go('/pages/buyer/orders')">订单</button>
      <button class="quick-btn" @tap="openAccount">我的</button>
      <button class="quick-btn" @tap="openNotifications">消息</button>
      <button class="quick-btn" @tap="go('/pages/seller/dashboard')">商家</button>
      <button class="quick-btn" @tap="openLogin('buyer')">登录</button>
    </view>

    <view class="section-head">
      <text class="section-title">精选商品</text>
      <button size="mini" @tap="loadHome">刷新</button>
    </view>

    <view v-if="state.error" class="notice">{{ state.error }}</view>
    <view v-else-if="!state.items.length" class="empty">暂无商品</view>
    <view v-else class="product-grid">
      <view v-for="item in state.items" :key="item.id || item.product_id" class="product-item" @tap="openProduct(item)">
        <image class="product-thumb" :src="item.thumb || item.image || ''" mode="aspectFill" />
        <text class="product-name">{{ item.name || item.title }}</text>
        <text class="product-price">{{ item.price || item.amount || '' }}</text>
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
      keyword: '',
      state: pageState()
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadHome()
  },
  methods: {
    async loadHome() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.home })
        this.state.items = normalizeListPayload(response)
        this.state.summary = (response && response.summary) || {}
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    openSearch() {
      uni.navigateTo({ url: '/pages/buyer/search?keyword=' + encodeURIComponent(this.keyword) + '&baseUrl=' + encodeURIComponent(this.baseUrl) })
    },
    openProduct(item) {
      const id = Number(item.id || item.product_id || 0)
      if (id > 0) {
        uni.navigateTo({ url: '/pages/buyer/product?id=' + id + '&baseUrl=' + encodeURIComponent(this.baseUrl) })
      }
    },
    go(url) {
      uni.switchTab({ url })
    },
    openAccount() {
      uni.navigateTo({ url: '/pages/buyer/account?baseUrl=' + encodeURIComponent(this.baseUrl) })
    },
    openNotifications() {
      uni.navigateTo({ url: '/pages/buyer/notifications?baseUrl=' + encodeURIComponent(this.baseUrl) })
    },
    openLogin(role) {
      uni.navigateTo({
        url: '/pages/auth/login?role=' + role
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

.search-row,
.section-head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.search-input {
  flex: 1;
  height: 38px;
  padding: 0 10px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
}

.search-btn {
  width: 70px;
}

.quick-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin: 12px 0;
}

.quick-btn {
  height: 38px;
  padding: 0;
  border-radius: 6px;
  font-size: 12px;
}

.section-head {
  justify-content: space-between;
  margin: 8px 0;
}

.section-title {
  font-weight: 600;
  color: #102a43;
}

.notice,
.empty {
  padding: 18px 0;
  color: #64748b;
  text-align: center;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.product-item {
  min-width: 0;
  padding: 8px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.product-thumb {
  width: 100%;
  height: 120px;
  border-radius: 6px;
  background: #e9eef4;
}

.product-name,
.product-price {
  display: block;
  margin-top: 6px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-price {
  color: #b45309;
  font-weight: 600;
}
</style>
