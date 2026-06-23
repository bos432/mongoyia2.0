<template>
  <view class="auth-page" data-mongoyia-phase13-auth-login="MONGOYIA_PHASE13_APP_AUTH_HANDOFF_V1">
    <view class="login-panel">
      <text class="title">Mongoyia</text>
      <text class="subtitle">{{ role === 'seller' ? '商家登录' : '买家登录' }}</text>

      <input v-model="username" class="form-input" placeholder="用户名 / 邮箱" />
      <input v-model="password" class="form-input" password placeholder="密码" />

      <view class="role-row">
        <button size="mini" :type="role === 'buyer' ? 'primary' : 'default'" @tap="role = 'buyer'">买家</button>
        <button size="mini" :type="role === 'seller' ? 'primary' : 'default'" @tap="role = 'seller'">商家</button>
      </view>

      <button class="login-btn" type="primary" :loading="loading" @tap="submitLogin">登录</button>
      <button class="secondary-btn" @tap="clearLogin">退出当前账号</button>
      <view v-if="error" class="notice">{{ error }}</view>
    </view>
  </view>
</template>

<script>
import { DEFAULT_CONFIG, cleanBaseUrl } from '../../utils/config.js'
import { requestJson, saveAuthSession, clearAuthSession } from '../../utils/api.js'

export default {
  data() {
    return {
      baseUrl: DEFAULT_CONFIG.baseUrl,
      username: '',
      password: '',
      role: 'buyer',
      redirect: '',
      loading: false,
      error: ''
    }
  },
  onLoad(options = {}) {
    this.baseUrl = cleanBaseUrl(options.baseUrl || options.base_url || DEFAULT_CONFIG.baseUrl)
    this.role = String(options.role || 'buyer') === 'seller' ? 'seller' : 'buyer'
    this.redirect = String(options.redirect || '')
  },
  methods: {
    async submitLogin() {
      if (!this.username || !this.password) {
        this.error = '请输入账号和密码'
        return
      }
      this.loading = true
      this.error = ''
      try {
        const response = await requestJson({
          baseUrl: this.baseUrl,
          path: '/api/site/login',
          method: 'POST',
          data: {
            username: this.username,
            password: this.password
          },
          withAuth: false
        })
        const user = response.data || response || {}
        saveAuthSession(user, this.baseUrl)
        uni.showToast({ title: '登录成功' })
        this.openAfterLogin()
      } catch (error) {
        this.error = error.message || '登录失败'
      } finally {
        this.loading = false
      }
    },
    clearLogin() {
      clearAuthSession()
      uni.showToast({ title: '已退出' })
    },
    openAfterLogin() {
      const target = this.redirect || (this.role === 'seller' ? '/pages/seller/dashboard' : '/pages/buyer/home')
      if (this.isTabPage(target)) {
        uni.switchTab({ url: target })
        return
      }
      uni.navigateTo({ url: target })
    },
    isTabPage(url) {
      return [
        '/pages/buyer/home',
        '/pages/buyer/category',
        '/pages/buyer/cart',
        '/pages/buyer/orders',
        '/pages/seller/dashboard'
      ].includes(url)
    }
  }
}
</script>

<style scoped>
.auth-page {
  min-height: 100vh;
  padding: 16px;
  background: #f6f7f9;
  box-sizing: border-box;
}

.login-panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 36px auto 0;
  max-width: 420px;
  padding: 14px;
  border: 1px solid #d8dee8;
  border-radius: 8px;
  background: #ffffff;
}

.title,
.subtitle {
  display: block;
  text-align: center;
}

.title {
  color: #102a43;
  font-size: 20px;
  font-weight: 700;
}

.subtitle {
  color: #64748b;
  font-size: 13px;
}

.form-input {
  height: 40px;
  padding: 0 10px;
  border: 1px solid #d8dee8;
  border-radius: 6px;
  background: #ffffff;
}

.role-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.login-btn,
.secondary-btn {
  height: 42px;
  border-radius: 6px;
}

.notice {
  color: #b91c1c;
  text-align: center;
}
</style>
