<template>
  <view
    class="seller-page"
    data-mongoyia-phase13-seller-products="MONGOYIA_PHASE13_APP_SHELL_V1"
    data-mongoyia-phase13-seller-product-write="MONGOYIA_APP_SELLER_PRODUCT_WRITE_V1"
  >
    <view class="section-head">
      <text class="section-title">商品管理</text>
      <view class="head-actions">
        <button size="mini" @tap="openCreate">新增</button>
        <button size="mini" @tap="loadProducts">刷新</button>
      </view>
    </view>

    <view v-if="state.error" class="notice">
      <text>{{ state.error }}</text>
      <button size="mini" @tap="openLogin">商家登录</button>
    </view>

    <view v-if="formVisible" class="product-form">
      <view class="form-head">
        <text class="form-title">{{ form.id ? '编辑商品' : '新增商品' }}</text>
        <button size="mini" @tap="closeForm">关闭</button>
      </view>

      <label class="field">
        <text>名称</text>
        <input v-model="form.name" placeholder="商品名称" />
      </label>
      <label class="field">
        <text>SKU</text>
        <input v-model="form.sku" placeholder="唯一库存编号" />
      </label>
      <label v-if="categories.length" class="field">
        <text>分类</text>
        <picker :range="categories" range-key="name" @change="chooseCategory">
          <view class="picker-value">{{ selectedCategoryName || '请选择分类' }}</view>
        </picker>
      </label>
      <label v-else class="field">
        <text>分类ID</text>
        <input v-model="form.category_id" type="number" placeholder="category_id" />
      </label>
      <view class="field-grid">
        <label class="field">
          <text>售价</text>
          <input v-model="form.price" type="digit" placeholder="0.00" />
        </label>
        <label class="field">
          <text>库存</text>
          <input v-model="form.stock" type="number" placeholder="0" />
        </label>
      </view>
      <label class="field">
        <text>主图</text>
        <input v-model="form.thumb" placeholder="图片 URL" />
      </label>
      <label class="field">
        <text>视频</text>
        <input v-model="form.video_url" placeholder="视频 URL" />
      </label>
      <label class="field">
        <text>简介</text>
        <textarea v-model="form.brief" maxlength="300" placeholder="商品卖点" />
      </label>
      <button class="submit-btn" :disabled="saveState.loading" @tap="saveProduct">
        {{ saveState.loading ? '提交中' : '提交审核' }}
      </button>
      <text v-if="saveState.message" class="save-message">{{ saveState.message }}</text>
    </view>

    <view v-if="!state.error && !state.items.length" class="empty">暂无商品</view>
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
          <view class="product-actions">
            <text class="audit-status">{{ auditLabel(item.audit_status) }}</text>
            <button size="mini" @tap="editProduct(item)">编辑</button>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import { DEFAULT_CONFIG, cleanBaseUrl } from '../../utils/config.js'
import { SELLER_ENDPOINTS, appRequest, normalizeListPayload, pageState } from '../../utils/appApi.js'

function blankForm() {
  return {
    id: 0,
    name: '',
    sku: '',
    category_id: '',
    price: '',
    stock: '',
    thumb: '',
    video_url: '',
    brief: ''
  }
}

export default {
  data() {
    return {
      baseUrl: DEFAULT_CONFIG.baseUrl,
      state: pageState(),
      categories: [],
      formVisible: false,
      form: blankForm(),
      saveState: {
        loading: false,
        message: ''
      }
    }
  },
  computed: {
    selectedCategoryName() {
      const categoryId = Number(this.form.category_id || 0)
      const category = this.categories.find((item) => Number(item.id) === categoryId)
      return category ? category.name : ''
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
        this.categories = Array.isArray(response.categories) ? response.categories : []
        this.state.summary = response.summary || {}
        this.state.loaded = true
      } catch (error) {
        this.state.error = error.message || '数据暂不可用'
      } finally {
        this.state.loading = false
      }
    },
    openCreate() {
      this.form = blankForm()
      if (this.categories.length) {
        this.form.category_id = this.categories[0].id
      }
      this.saveState.message = ''
      this.formVisible = true
    },
    editProduct(item) {
      this.form = {
        id: item.id || item.product_id || 0,
        name: item.name || '',
        sku: item.sku || '',
        category_id: item.category_id || '',
        price: item.price || '',
        stock: item.stock || '',
        thumb: item.thumb || item.image || '',
        video_url: item.video_url || '',
        brief: item.brief || ''
      }
      this.saveState.message = ''
      this.formVisible = true
    },
    closeForm() {
      this.formVisible = false
      this.saveState.message = ''
    },
    chooseCategory(event) {
      const index = Number(event.detail.value || 0)
      const category = this.categories[index]
      if (category) {
        this.form.category_id = category.id
      }
    },
    async saveProduct() {
      this.saveState.loading = true
      this.saveState.message = ''
      try {
        const response = await appRequest({
          baseUrl: this.baseUrl,
          path: SELLER_ENDPOINTS.products,
          method: 'POST',
          data: this.form
        })
        this.saveState.message = response.message || '已提交审核'
        await this.loadProducts()
        if (response.product) {
          this.editProduct(response.product)
        }
      } catch (error) {
        this.saveState.message = error.message || '提交失败'
      } finally {
        this.saveState.loading = false
      }
    },
    auditLabel(status) {
      const map = {
        approved: '已审核',
        submitted: '待审核',
        rejected: '已驳回',
        draft: '草稿'
      }
      return map[status] || '待审核'
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
.head-actions,
.form-head,
.product-row,
.product-bottom,
.product-actions,
.field-grid {
  display: flex;
}

.section-head,
.form-head,
.product-bottom,
.product-actions {
  align-items: center;
  justify-content: space-between;
}

.section-head {
  margin-bottom: 10px;
}

.head-actions {
  gap: 8px;
}

.section-title,
.form-title {
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

.product-form,
.product-row {
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.product-form {
  margin-bottom: 12px;
  padding: 10px;
}

.form-head {
  margin-bottom: 8px;
}

.field {
  display: block;
  margin-top: 8px;
}

.field text {
  display: block;
  margin-bottom: 4px;
  color: #64748b;
  font-size: 12px;
}

.field input,
.field textarea,
.picker-value {
  width: 100%;
  min-height: 38px;
  padding: 8px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
  box-sizing: border-box;
}

.field textarea {
  min-height: 76px;
}

.field-grid {
  gap: 8px;
}

.field-grid .field {
  flex: 1;
}

.submit-btn {
  height: 42px;
  margin-top: 10px;
  border-radius: 6px;
}

.save-message {
  display: block;
  margin-top: 8px;
  color: #0f766e;
  font-size: 12px;
}

.product-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.product-row {
  gap: 8px;
  min-height: 104px;
  padding: 8px;
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
.product-stock,
.audit-status {
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

.product-bottom,
.product-actions {
  margin-top: 10px;
}

.product-price {
  color: #b45309;
  font-weight: 600;
}

.product-stock,
.audit-status {
  color: #64748b;
}

.audit-status {
  font-size: 12px;
}
</style>
