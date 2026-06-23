<template>
  <view class="seller-page" data-mongoyia-phase13-seller-products="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="section-head">
      <text class="section-title">商品管理</text>
      <button size="mini" @tap="loadProducts">刷新</button>
    </view>
    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">商家登录</button>
    </view>
    <view v-else-if="!state.items.length" class="empty">暂无商品</view>
    <view v-else class="product-list">
      <view v-for="item in state.items" :key="item.id || item.product_id" class="product-row">
        <image class="product-thumb" :src="item.thumb || item.image || ''" mode="aspectFill" />
        <view class="product-body">
          <text class="product-title">{{ item.name || item.title }}</text>
          <text class="product-meta">{{ item.sku || item.status_label || '' }}</text>
          <view class="product-bottom">
            <text class="product-price">{{ item.price || '' }}</text>
            <text class="product-stock">库存 {{ item.stock || 0 }}</text>
          </view>
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
      state: pageState()
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadProducts()
  },
  methods: {
    async loadProducts() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.products })
        this.state.items = normalizeListPayload(response)
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    openLogin() {
      uni.navigateTo({
        url: '/pages/auth/login?role=seller&redirect='
          + encodeURIComponent('/pages/seller/products')
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
.product-row,
.product-bottom {
  display: flex;
}

.section-head,
.product-bottom {
  align-items: center;
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

.product-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.product-row {
  gap: 8px;
  min-height: 90px;
  padding: 8px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.product-thumb {
  width: 84px;
  height: 84px;
  border-radius: 6px;
  background: #e9eef4;
}

.product-body {
  min-width: 0;
  flex: 1;
}

.product-title,
.product-meta,
.product-price,
.product-stock {
  display: block;
}

.product-title,
.product-meta {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-title {
  font-weight: 600;
}

.product-meta {
  margin-top: 8px;
  color: #64748b;
  font-size: 12px;
}

.product-bottom {
  margin-top: 12px;
}

.product-price {
  color: #b45309;
  font-weight: 600;
}

.product-stock {
  color: #64748b;
}
</style>
