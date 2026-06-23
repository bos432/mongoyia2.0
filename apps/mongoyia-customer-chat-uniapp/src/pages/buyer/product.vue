<template>
  <view class="app-page" data-mongoyia-phase13-buyer-product="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view v-if="state.error" class="notice">{{ state.error }}</view>
    <view v-else class="product-detail">
      <video
        v-if="product.video_url"
        class="hero-video"
        :src="product.video_url"
        controls
        object-fit="contain"
        data-mongoyia-phase14-product-video="MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_V1"
      />
      <image class="hero-image" :src="product.thumb || product.image || ''" mode="aspectFill" />
      <view class="detail-band">
        <text class="product-title">{{ product.name || product.title || '商品' }}</text>
        <text class="product-price">{{ product.price || product.amount || '' }}</text>
        <text class="product-meta">{{ product.sku || product.store_name || '' }}</text>
      </view>

      <view class="detail-band">
        <text class="band-title">SKU</text>
        <view v-if="skuList.length" class="sku-list">
          <button
            v-for="sku in skuList"
            :key="sku.id || sku.sku"
            size="mini"
            :type="selectedSku === sku ? 'primary' : 'default'"
            @tap="selectedSku = sku"
          >
            {{ sku.name || sku.sku || sku.attribute_value }}
          </button>
        </view>
        <text v-else class="muted">默认规格</text>
      </view>

      <view class="detail-band">
        <text class="band-title">商品信息</text>
        <text class="description">{{ product.brief || product.description || '' }}</text>
      </view>
    </view>

    <view class="bottom-actions">
      <button class="action-btn" @tap="openChat">客服</button>
      <button class="action-btn" @tap="addCart">加入购物车</button>
      <button class="action-btn primary" type="primary" @tap="buyNow">购买</button>
    </view>
  </view>
</template>

<script>
import { DEFAULT_CONFIG, cleanBaseUrl, cleanWsUrl } from '../../utils/config.js'
import { BUYER_ENDPOINTS, appRequest, pageState } from '../../utils/appApi.js'

export default {
  data() {
    return {
      baseUrl: DEFAULT_CONFIG.baseUrl,
      wsUrl: DEFAULT_CONFIG.wsUrl,
      productId: 0,
      product: {},
      skuList: [],
      selectedSku: null,
      state: pageState()
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.wsUrl = cleanWsUrl(options.wsUrl || options.ws_url || DEFAULT_CONFIG.wsUrl)
    this.productId = Number(options.id || options.product_id || options.gid || 0)
    this.loadProduct()
  },
  methods: {
    async loadProduct() {
      if (!this.productId) {
        this.state.error = '商品不存在'
        return
      }
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.product, query: { id: this.productId } })
        const data = response.data || response || {}
        this.product = data.product || data
        this.skuList = data.skus || data.sku_list || []
        this.selectedSku = this.skuList[0] || null
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    async addCart() {
      try {
        await appRequest({
          baseUrl: this.baseUrl,
          path: BUYER_ENDPOINTS.cart,
          method: 'POST',
          data: {
            product_id: this.productId,
            sku_id: this.selectedSku ? this.selectedSku.id : 0,
            number: 1
          }
        })
        uni.showToast({ title: '已加入购物车' })
        return true
      } catch (error) {
        uni.showToast({ title: error.message || '操作失败', icon: 'none' })
        return false
      }
    },
    async buyNow() {
      const ok = await this.addCart()
      if (ok) {
        uni.switchTab({ url: '/pages/buyer/cart' })
      }
    },
    openChat() {
      uni.navigateTo({
        url: '/pages/chat/index?gid=' + this.productId
          + '&baseUrl=' + encodeURIComponent(this.baseUrl)
          + '&wsUrl=' + encodeURIComponent(this.wsUrl)
      })
    }
  }
}
</script>

<style scoped>
.app-page {
  min-height: 100vh;
  padding-bottom: 64px;
  background: #f6f7f9;
}

.hero-image {
  width: 100%;
  height: 260px;
  background: #e9eef4;
}

.hero-video {
  width: 100%;
  height: 240px;
  background: #000000;
}

.detail-band {
  padding: 12px;
  border-bottom: 1px solid #d8dee8;
  background: #ffffff;
}

.product-title,
.product-price,
.product-meta,
.band-title,
.description,
.muted {
  display: block;
}

.product-title {
  color: #102a43;
  font-size: 18px;
  font-weight: 600;
}

.product-price {
  margin-top: 8px;
  color: #b45309;
  font-size: 18px;
  font-weight: 700;
}

.product-meta,
.muted {
  margin-top: 6px;
  color: #64748b;
  font-size: 12px;
}

.band-title {
  margin-bottom: 8px;
  color: #102a43;
  font-weight: 600;
}

.sku-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.description {
  color: #334155;
  line-height: 1.6;
}

.notice {
  padding: 18px 0;
  color: #64748b;
  text-align: center;
}

.bottom-actions {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  gap: 8px;
  padding: 10px;
  border-top: 1px solid #d8dee8;
  background: #ffffff;
}

.action-btn {
  flex: 1;
  height: 42px;
  border-radius: 6px;
}

.primary {
  font-weight: 600;
}
</style>
