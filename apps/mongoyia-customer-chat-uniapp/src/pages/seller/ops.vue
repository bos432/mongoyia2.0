<template>
  <view class="seller-page" data-mongoyia-phase13-seller-ops="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="section-head">
      <text class="section-title">经营概览</text>
      <button size="mini" @tap="loadOps">刷新</button>
    </view>

    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">商家登录</button>
    </view>

    <view v-else>
      <view class="store-panel">
        <text class="store-name">{{ store.name || '店铺' }}</text>
        <text class="store-meta">{{ store.host_name || store.brief || '' }}</text>
        <view class="metric-grid">
          <view class="metric">
            <text class="metric-value">{{ dashboard.orders || 0 }}</text>
            <text class="metric-label">订单</text>
          </view>
          <view class="metric">
            <text class="metric-value">{{ dashboard.products || 0 }}</text>
            <text class="metric-label">商品</text>
          </view>
          <view class="metric">
            <text class="metric-value">{{ dashboard.fund || '0.00' }}</text>
            <text class="metric-label">预存金</text>
          </view>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">物流方式</text>
          <text class="block-count">{{ logistics.length }}</text>
        </view>
        <view v-if="!logistics.length" class="empty">暂无物流方式</view>
        <view v-for="item in logistics" :key="item.id" class="data-row">
          <view class="row-main">
            <text class="row-title">{{ item.name || item.code }}</text>
            <text class="row-meta">基础 {{ item.base_fee || '0.00' }} / 重量 {{ item.fee_per_kg || '0.00' }} / 体积 {{ item.fee_per_volume || '0.00' }}</text>
          </view>
          <text class="row-status">{{ item.selection_status || '-' }}</text>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">预存金流水</text>
          <text class="block-count">{{ depositLogs.length }}</text>
        </view>
        <view v-if="!depositLogs.length" class="empty">暂无流水</view>
        <view v-for="item in depositLogs" :key="item.id" class="data-row compact">
          <view class="row-main">
            <text class="row-title">{{ item.name || item.remark || ('#' + item.id) }}</text>
            <text class="row-meta">{{ item.remark || '' }}</text>
          </view>
          <text class="amount">{{ item.change || '0.00' }}</text>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">统计</text>
          <text class="block-count">{{ shipmentTotal }}</text>
        </view>
        <view class="period-grid">
          <view v-for="(item, key) in periods" :key="key" class="period">
            <text class="metric-value">{{ item.orders || 0 }}</text>
            <text class="metric-label">{{ item.label || key }}</text>
          </view>
        </view>
      </view>

      <view class="block">
        <view class="block-head">
          <text class="block-title">分销概览</text>
          <text class="block-count">{{ distributionRows.length }}</text>
        </view>
        <view v-if="!distributionRows.length" class="empty">暂无分销记录</view>
        <view v-for="item in distributionRows" :key="item.id" class="data-row compact">
          <view class="row-main">
            <text class="row-title">{{ item.commission_status || item.status || '-' }}</text>
            <text class="row-meta">订单 {{ item.order_id || '-' }}</text>
          </view>
          <text class="amount">{{ item.commission_amount || '0.00' }}</text>
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
      state: pageState(),
      store: {},
      dashboard: {},
      logistics: [],
      depositLogs: [],
      periods: {},
      distributionRows: []
    }
  },
  computed: {
    shipmentTotal() {
      const stats = this.statistics.shipment || {}
      return Object.keys(stats).reduce((sum, key) => sum + Number(stats[key] || 0), 0)
    },
    statistics() {
      return this.state.summary.statistics || {}
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadOps()
  },
  methods: {
    async loadOps() {
      this.state.loading = true
      this.state.error = ''
      try {
        const [dashboard, logistics, deposit, statistics, distribution] = await Promise.all([
          appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.dashboard }),
          appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.logistics }),
          appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.deposit }),
          appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.statistics }),
          appRequest({ baseUrl: this.baseUrl, path: SELLER_ENDPOINTS.distribution })
        ])
        this.store = dashboard.store || {}
        this.dashboard = dashboard.summary || {}
        this.logistics = normalizeListPayload(logistics)
        this.depositLogs = normalizeListPayload(deposit)
        this.periods = statistics.periods || {}
        this.distributionRows = normalizeListPayload(distribution)
        this.state.summary = { statistics }
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
          + encodeURIComponent('/pages/seller/ops')
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
.block-title,
.store-name {
  color: #102a43;
  font-weight: 600;
}

.store-panel,
.block {
  margin-bottom: 12px;
}

.store-panel,
.data-row,
.metric,
.period {
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.store-panel {
  padding: 10px;
}

.store-name,
.store-meta,
.row-title,
.row-meta,
.row-status,
.amount {
  display: block;
}

.store-meta,
.row-meta,
.row-status,
.block-count {
  color: #64748b;
  font-size: 12px;
}

.metric-grid,
.period-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-top: 10px;
}

.period-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.metric,
.period {
  min-height: 72px;
  padding: 10px;
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
}

.data-row.compact {
  min-height: 58px;
}

.row-main {
  min-width: 0;
  flex: 1;
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

.row-status,
.amount {
  width: 76px;
  text-align: right;
}

.amount {
  color: #b45309;
  font-weight: 600;
}
</style>
