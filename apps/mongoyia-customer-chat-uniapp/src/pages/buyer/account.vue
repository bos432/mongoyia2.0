<template>
  <view class="app-page" data-mongoyia-phase13-buyer-account="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="section-head">
      <text class="section-title">我的</text>
      <button size="mini" @tap="loadAccount">刷新</button>
    </view>

    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">去登录</button>
    </view>

    <view v-else>
      <view class="block">
        <view class="data-row compact" @tap="openNotifications">
          <view class="row-main">
            <text class="row-title">消息通知</text>
            <text class="row-meta">订单、物流、支付、客服、投诉处理通知</text>
          </view>
          <text class="row-status">查看</text>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">优惠券</text>
          <text class="block-count">{{ coupons.length }}</text>
        </view>
        <view v-if="!coupons.length" class="empty">暂无优惠券</view>
        <view v-for="item in coupons" :key="item.id || item.coupon_id" class="data-row compact">
          <view class="row-main">
            <text class="row-title">{{ item.name || ('Coupon #' + item.coupon_id) }}</text>
            <text class="row-meta">优惠 {{ item.money || '0' }} / 门槛 {{ item.min_amount || '0.00' }}</text>
          </view>
          <text class="row-status">{{ item.status }}</text>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">商品收藏</text>
          <text class="block-count">{{ favorites.length }}</text>
        </view>
        <view v-if="!favorites.length" class="empty">暂无商品收藏</view>
        <view v-for="item in favorites" :key="item.id || item.product_id" class="data-row compact" @tap="openProduct(item.product_id)">
          <view class="row-main">
            <text class="row-title">{{ item.name || ('Product #' + item.product_id) }}</text>
            <text class="row-meta">商品 {{ item.product_id || '-' }}</text>
          </view>
          <text class="row-status">查看</text>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">店铺收藏</text>
          <text class="block-count">{{ storeFavorites.length }}</text>
        </view>
        <view v-if="!storeFavorites.length" class="empty">暂无店铺收藏</view>
        <view v-for="item in storeFavorites" :key="item.id || item.store_id" class="data-row compact">
          <view class="row-main">
            <text class="row-title">{{ item.name || ('Store #' + item.store_id) }}</text>
            <text class="row-meta">店铺 {{ item.store_id || '-' }}</text>
          </view>
          <text class="row-status">{{ dateLabel(item.created_at) }}</text>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">我的评论</text>
          <text class="block-count">{{ reviews.length }}</text>
        </view>
        <view v-if="!reviews.length" class="empty">暂无评论</view>
        <view v-for="item in reviews" :key="item.id" class="data-row" @tap="openProduct(item.product_id)">
          <view class="row-main">
            <text class="row-title">{{ item.name || ('Product #' + item.product_id) }}</text>
            <text class="row-meta">{{ starText(item.star) }} {{ item.content || '' }}</text>
          </view>
          <text class="row-status">{{ item.moderation_status || '正常' }}</text>
        </view>
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
      state: pageState(),
      coupons: [],
      favorites: [],
      storeFavorites: [],
      reviews: []
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadAccount()
  },
  methods: {
    async loadAccount() {
      this.state.loading = true
      this.state.error = ''
      try {
        const [coupons, favorites, storeFavorites, reviews] = await Promise.all([
          appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.coupons }),
          appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.favorites }),
          appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.storeFavorites }),
          appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.myReviews })
        ])
        if ([coupons, favorites, storeFavorites, reviews].some(this.requiresLogin)) {
          this.state.error = '请先登录后查看我的资料'
          this.coupons = []
          this.favorites = []
          this.storeFavorites = []
          this.reviews = []
          return
        }
        this.coupons = normalizeListPayload(coupons)
        this.favorites = normalizeListPayload(favorites)
        this.storeFavorites = normalizeListPayload(storeFavorites)
        this.reviews = normalizeListPayload(reviews)
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    openProduct(productId) {
      const id = Number(productId || 0)
      if (id > 0) {
        uni.navigateTo({ url: '/pages/buyer/product?id=' + id + '&baseUrl=' + encodeURIComponent(this.baseUrl) })
      }
    },
    openNotifications() {
      uni.navigateTo({ url: '/pages/buyer/notifications?baseUrl=' + encodeURIComponent(this.baseUrl) })
    },
    openLogin() {
      uni.navigateTo({
        url: '/pages/auth/login?role=buyer&redirect='
          + encodeURIComponent('/pages/buyer/account')
          + '&baseUrl=' + encodeURIComponent(this.baseUrl)
      })
    },
    requiresLogin(response) {
      const data = response && response.data ? response.data : response
      return Boolean(data && data.auth_required)
    },
    starText(star) {
      return '星级 ' + Number(star || 0)
    },
    dateLabel(value) {
      const timestamp = Number(value || 0)
      if (!timestamp) {
        return '-'
      }
      const date = new Date(timestamp * 1000)
      const month = String(date.getMonth() + 1).padStart(2, '0')
      const day = String(date.getDate()).padStart(2, '0')
      return date.getFullYear() + '-' + month + '-' + day
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
.block-head,
.data-row {
  display: flex;
}

.section-head,
.block-head,
.data-row {
  align-items: center;
  justify-content: space-between;
}

.section-head,
.block-head {
  margin-bottom: 10px;
}

.section-title,
.block-title {
  color: #102a43;
  font-weight: 600;
}

.block {
  margin-bottom: 12px;
}

.block-count,
.row-meta,
.row-status {
  color: #64748b;
  font-size: 12px;
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

.data-row {
  gap: 10px;
  min-height: 78px;
  margin-bottom: 8px;
  padding: 10px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.data-row.compact {
  min-height: 58px;
}

.row-main {
  min-width: 0;
  flex: 1;
}

.row-title,
.row-meta,
.row-status {
  display: block;
}

.row-title,
.row-meta {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row-title {
  color: #102a43;
  font-weight: 600;
}

.row-meta {
  margin-top: 6px;
}

.row-status {
  width: 70px;
  text-align: right;
}
</style>
