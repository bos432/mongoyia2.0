<template>
  <view class="app-page" data-mongoyia-phase13-buyer-category="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="section-head">
      <text class="section-title">商品分类</text>
      <button size="mini" @tap="loadCategories">刷新</button>
    </view>
    <view v-if="state.error" class="notice">{{ state.error }}</view>
    <view v-else-if="!state.items.length" class="empty">暂无分类</view>
    <view v-else class="category-list">
      <view v-for="item in state.items" :key="item.id" class="category-row" @tap="openSearch(item)">
        <text class="category-name">{{ item.name || item.title }}</text>
        <text class="category-count">{{ item.product_count || '' }}</text>
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
      state: pageState()
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.loadCategories()
  },
  methods: {
    async loadCategories() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({ baseUrl: this.baseUrl, path: BUYER_ENDPOINTS.category })
        this.state.items = normalizeListPayload(response)
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    openSearch(item) {
      const id = Number(item.id || item.category_id || 0)
      uni.navigateTo({ url: '/pages/buyer/search?category_id=' + id + '&baseUrl=' + encodeURIComponent(this.baseUrl) })
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

.category-list {
  border: 1px solid #d8dee8;
  border-radius: 8px;
  overflow: hidden;
  background: #ffffff;
}

.category-row {
  display: flex;
  justify-content: space-between;
  min-height: 44px;
  padding: 0 12px;
  border-bottom: 1px solid #edf2f7;
  line-height: 44px;
}

.category-row:last-child {
  border-bottom: 0;
}

.category-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.category-count {
  color: #64748b;
}
</style>
