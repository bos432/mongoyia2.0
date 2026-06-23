<template>
  <view class="app-page" data-mongoyia-phase13-buyer-search="MONGOYIA_PHASE13_APP_SHELL_V1">
    <view class="search-row">
      <input v-model="keyword" class="search-input" placeholder="关键词/SKU" confirm-type="search" @input="onKeywordInput" @confirm="searchProducts" />
      <button class="search-btn" size="mini" type="primary" @tap="searchProducts">搜索</button>
    </view>
    <view v-if="suggestions.length" class="suggestion-row" data-mongoyia-phase14-search-suggestions="MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_V1">
      <button v-for="item in suggestions" :key="item.type + item.value" class="suggestion-chip" size="mini" @tap="selectSuggestion(item)">
        {{ item.label || item.value }}
      </button>
    </view>
    <view class="filter-row">
      <input v-model="filters.brand" class="filter-input" placeholder="品牌" />
      <input v-model="filters.min_price" class="filter-input" type="digit" placeholder="最低价" />
      <input v-model="filters.max_price" class="filter-input" type="digit" placeholder="最高价" />
    </view>
    <view class="sort-row" data-mongoyia-phase14-search-sort="MONGOYIA_PRODUCT_SEARCH_VIDEO_PHASE14_V1">
      <picker class="sort-picker" :range="sortOptions" range-key="label" :value="selectedSortIndex" @change="changeSort">
        <view class="sort-picker-inner">{{ sortOptions[selectedSortIndex].label }}</view>
      </picker>
    </view>

    <view v-if="state.error" class="notice">{{ state.error }}</view>
    <view v-else-if="!state.items.length" class="empty">暂无结果</view>
    <view v-else class="result-list">
      <view v-for="item in state.items" :key="item.id || item.product_id" class="result-row" @tap="openProduct(item)">
        <image class="result-thumb" :src="item.thumb || item.image || ''" mode="aspectFill" />
        <view class="result-body">
          <text class="result-title">{{ item.name || item.title }}</text>
          <text class="result-meta">{{ item.sku || item.brand || '' }}</text>
          <text class="result-meta">销量 {{ item.sales || 0 }}{{ item.has_video ? ' · 视频' : '' }}</text>
          <text class="result-price">{{ item.price || item.amount || '' }}</text>
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
      keyword: '',
      categoryId: 0,
      filters: {
        brand: '',
        min_price: '',
        max_price: '',
        sort: ''
      },
      suggestions: [],
      suggestionTimer: null,
      selectedSortIndex: 0,
      sortOptions: [
        { label: '综合排序', value: '' },
        { label: '销量优先', value: 'sales_desc' },
        { label: '价格低到高', value: 'price_asc' },
        { label: '价格高到低', value: 'price_desc' },
        { label: '最新上架', value: 'newest' }
      ],
      state: pageState()
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.keyword = String(options.keyword || '')
    this.categoryId = Number(options.category_id || 0)
    this.searchProducts()
  },
  methods: {
    async searchProducts() {
      this.state.loading = true
      this.state.error = ''
      try {
        const response = await appRequest({
          baseUrl: this.baseUrl,
          path: BUYER_ENDPOINTS.search,
          query: {
            keyword: this.keyword,
            category_id: this.categoryId,
            ...this.filters
          }
        })
        this.state.items = normalizeListPayload(response)
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    onKeywordInput(event) {
      if (event && event.detail && typeof event.detail.value === 'string') {
        this.keyword = event.detail.value
      }
      clearTimeout(this.suggestionTimer)
      this.suggestionTimer = setTimeout(() => this.loadSuggestions(), 250)
    },
    async loadSuggestions() {
      if (!this.keyword || this.keyword.trim().length < 1) {
        this.suggestions = []
        return
      }
      try {
        const response = await appRequest({
          baseUrl: this.baseUrl,
          path: BUYER_ENDPOINTS.suggestions,
          query: {
            keyword: this.keyword,
            limit: 8
          }
        })
        this.suggestions = normalizeListPayload(response)
      } catch (error) {
        this.suggestions = []
      }
    },
    selectSuggestion(item) {
      this.keyword = item.value || item.label || ''
      this.suggestions = []
      this.searchProducts()
    },
    changeSort(event) {
      const index = Number(event.detail.value || 0)
      this.selectedSortIndex = index >= 0 && index < this.sortOptions.length ? index : 0
      this.filters.sort = this.sortOptions[this.selectedSortIndex].value
      this.searchProducts()
    },
    openProduct(item) {
      const id = Number(item.id || item.product_id || 0)
      if (id > 0) {
        uni.navigateTo({ url: '/pages/buyer/product?id=' + id + '&baseUrl=' + encodeURIComponent(this.baseUrl) })
      }
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
.filter-row,
.result-row {
  display: flex;
  gap: 8px;
}

.search-row {
  align-items: center;
}

.suggestion-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.suggestion-chip {
  max-width: 100%;
  border-radius: 6px;
  font-size: 12px;
}

.filter-row {
  margin: 10px 0;
}

.sort-row {
  margin-bottom: 10px;
}

.sort-picker {
  height: 38px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
}

.sort-picker-inner {
  height: 38px;
  padding: 0 10px;
  color: #334155;
  line-height: 38px;
}

.search-input,
.filter-input {
  min-width: 0;
  height: 38px;
  padding: 0 10px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
}

.search-input {
  flex: 1;
}

.filter-input {
  flex: 1;
}

.search-btn {
  width: 70px;
}

.notice,
.empty {
  padding: 18px 0;
  color: #64748b;
  text-align: center;
}

.result-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.result-row {
  min-height: 90px;
  padding: 8px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.result-thumb {
  width: 84px;
  height: 84px;
  border-radius: 6px;
  background: #e9eef4;
}

.result-body {
  min-width: 0;
  flex: 1;
}

.result-title,
.result-meta,
.result-price {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.result-title {
  font-weight: 600;
}

.result-meta {
  margin-top: 8px;
  color: #64748b;
  font-size: 12px;
}

.result-price {
  margin-top: 10px;
  color: #b45309;
  font-weight: 600;
}
</style>
