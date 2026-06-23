<template>
  <view
    class="seller-page"
    data-mongoyia-phase13-seller-coupons="MONGOYIA_PHASE13_APP_SHELL_V1"
    data-mongoyia-phase13-seller-coupon-write="MONGOYIA_APP_SELLER_COUPON_PARTICIPATION_WRITE_V1"
  >
    <view class="section-head">
      <text class="section-title">优惠券</text>
      <button size="mini" @tap="loadCoupons">刷新</button>
    </view>

    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">商家登录</button>
    </view>

    <view v-else>
      <view class="block">
        <view class="block-head">
          <text class="block-title">平台券参与</text>
          <text class="block-count">{{ platformCoupons.length }}</text>
        </view>
        <view v-if="!platformCoupons.length" class="empty">暂无平台优惠券</view>
        <view v-for="item in platformCoupons" :key="item.id" class="coupon-row">
          <view class="coupon-main">
            <text class="coupon-title">{{ item.name || ('Coupon #' + item.id) }}</text>
            <text class="coupon-meta">优惠 {{ item.money || '0' }} / 门槛 {{ item.min_amount || '0.00' }}</text>
            <text class="coupon-meta">{{ dateLabel(item.started_at) }} ~ {{ dateLabel(item.ended_at) }}</text>
          </view>
          <view class="coupon-side">
            <text class="coupon-status">{{ participationLabel(item.participation_status) }}</text>
            <button size="mini" :disabled="actionState.loading" @tap="participateCoupon(item)">
              {{ item.participation_status === 'joined' ? '退出' : '参与' }}
            </button>
          </view>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">本店优惠券</text>
          <text class="block-count">{{ storeCoupons.length }}</text>
        </view>
        <view v-if="!storeCoupons.length" class="empty">暂无本店优惠券</view>
        <view v-for="item in storeCoupons" :key="item.id" class="coupon-row compact">
          <view class="coupon-main">
            <text class="coupon-title">{{ item.name || ('Coupon #' + item.id) }}</text>
            <text class="coupon-meta">优惠 {{ item.money || '0' }} / 门槛 {{ item.min_amount || '0.00' }}</text>
          </view>
          <text class="coupon-status">{{ item.status || '' }}</text>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">领取/使用记录</text>
          <text class="block-count">{{ usageRows.length }}</text>
        </view>
        <view v-if="!usageRows.length" class="empty">暂无记录</view>
        <view v-for="item in usageRows" :key="item.id || item.coupon_id" class="coupon-row compact">
          <view class="coupon-main">
            <text class="coupon-title">{{ item.name || ('Coupon #' + item.coupon_id) }}</text>
            <text class="coupon-meta">用户 {{ item.user_id || '-' }} / 订单 {{ item.order_id || '-' }}</text>
          </view>
          <text class="coupon-status">{{ item.status || '' }}</text>
        </view>
      </view>

      <text v-if="actionState.message" class="action-message">{{ actionState.message }}</text>
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
      state: pageState(),
      storeCoupons: [],
      platformCoupons: [],
      usageRows: [],
      actionState: {
        loading: false,
        message: ''
      }
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadCoupons()
  },
  methods: {
    async loadCoupons() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.coupons })
        this.storeCoupons = normalizeListPayload(response)
        this.platformCoupons = Array.isArray(response.platform_participation) ? response.platform_participation : []
        this.usageRows = Array.isArray(response.usage) ? response.usage : []
        this.state.summary = response.summary || {}
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    async participateCoupon(item) {
      this.actionState.loading = true
      this.actionState.message = ''
      const action = item.participation_status === 'joined' ? 'leave' : 'join'
      try {
        const response = await appRequest({
          baseUrl: this.baseUrl,
          path: SELLER_ENDPOINTS.coupons,
          method: 'POST',
          data: {
            coupon_type_id: item.id || item.coupon_type_id,
            action
          }
        })
        this.actionState.message = response.message || '已更新'
        await this.loadCoupons()
      } catch (error) {
        this.actionState.message = error.message || '操作失败'
      } finally {
        this.actionState.loading = false
      }
    },
    participationLabel(status) {
      return status === 'joined' ? '已参与' : '未参与'
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
    },
    openLogin() {
      uni.navigateTo({
        url: '/pages/auth/login?role=seller&redirect='
          + encodeURIComponent('/pages/seller/coupons')
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
.block-head,
.coupon-row {
  display: flex;
}

.section-head,
.block-head,
.coupon-row {
  align-items: center;
  justify-content: space-between;
}

.section-head {
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

.block-head {
  margin-bottom: 8px;
}

.block-count {
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

.coupon-row {
  gap: 10px;
  min-height: 88px;
  margin-bottom: 8px;
  padding: 10px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.coupon-row.compact {
  min-height: 64px;
}

.coupon-main {
  min-width: 0;
  flex: 1;
}

.coupon-side {
  width: 78px;
  text-align: right;
}

.coupon-title,
.coupon-meta,
.coupon-status,
.action-message {
  display: block;
}

.coupon-title,
.coupon-meta {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.coupon-title {
  color: #102a43;
  font-weight: 600;
}

.coupon-meta {
  margin-top: 6px;
  color: #64748b;
  font-size: 12px;
}

.coupon-status {
  margin-bottom: 8px;
  color: #0f766e;
  font-size: 12px;
}

.action-message {
  margin-top: 6px;
  color: #0f766e;
  font-size: 12px;
  text-align: center;
}
</style>
