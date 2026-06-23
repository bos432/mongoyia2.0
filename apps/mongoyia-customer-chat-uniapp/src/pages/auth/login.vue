<template>
  <view class="auth-page" data-mongoyia-phase13-auth-login="MONGOYIA_PHASE13_APP_AUTH_HANDOFF_V1">
    <view class="login-panel">
      <text class="title">Mongoyia</text>
      <text class="subtitle">{{ role === 'seller' ? '商家登录' : '买家登录' }}</text>

      <view class="mode-row" data-mongoyia-phase12-app-account-entry="MONGOYIA_APP_ACCOUNT_SECURITY_ENTRY_V1">
        <button size="mini" :type="authMode === 'password' ? 'primary' : 'default'" @tap="authMode = 'password'">密码</button>
        <button size="mini" :type="authMode === 'code' ? 'primary' : 'default'" @tap="authMode = 'code'">验证码</button>
      </view>

      <view v-if="authMode === 'password'" class="form-stack">
        <input v-model="username" class="form-input" placeholder="用户名 / 邮箱" />
        <input v-model="password" class="form-input" password placeholder="密码" />
      </view>

      <view v-else class="form-stack">
        <input v-model="codeTarget" class="form-input" placeholder="邮箱" />
        <view class="code-row">
          <input v-model="codeValue" class="form-input code-input" placeholder="验证码" />
          <button size="mini" :loading="codeLoading" @tap="requestSecurityCode">发送</button>
        </view>
      </view>

      <view class="role-row">
        <button size="mini" :type="role === 'buyer' ? 'primary' : 'default'" @tap="role = 'buyer'">买家</button>
        <button size="mini" :type="role === 'seller' ? 'primary' : 'default'" @tap="role = 'seller'">商家</button>
      </view>

      <button class="login-btn" type="primary" :loading="loading" @tap="submitLogin">登录</button>
      <button class="secondary-btn" @tap="clearLogin">退出当前账号</button>

      <view class="social-row" data-mongoyia-phase12-social-login-entry="MONGOYIA_APP_SOCIAL_LOGIN_ENTRY_V1">
        <button size="mini" @tap="socialLogin('google')">Google</button>
        <button size="mini" @tap="socialLogin('facebook')">Facebook</button>
      </view>
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
      authMode: 'password',
      codeTarget: '',
      codeValue: '',
      codeLoading: false,
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
      if (this.authMode === 'code') {
        return this.submitCodeLogin()
      }
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
    async requestSecurityCode() {
      if (!this.codeTarget) {
        this.error = '请输入邮箱'
        return
      }
      this.codeLoading = true
      this.error = ''
      try {
        const response = await requestJson({
          baseUrl: this.baseUrl,
          path: '/api/site/security-code-request',
          method: 'POST',
          data: {
            channel: 'email',
            target: this.codeTarget
          },
          withAuth: false
        })
        const data = response.data || response || {}
        uni.showToast({ title: data.message || '验证码已发送', icon: 'none' })
      } catch (error) {
        this.error = error.message || '验证码发送失败'
      } finally {
        this.codeLoading = false
      }
    },
    async submitCodeLogin() {
      if (!this.codeTarget || !this.codeValue) {
        this.error = '请输入邮箱和验证码'
        return
      }
      this.loading = true
      this.error = ''
      try {
        const response = await requestJson({
          baseUrl: this.baseUrl,
          path: '/api/site/security-code-login',
          method: 'POST',
          data: {
            channel: 'email',
            target: this.codeTarget,
            code: this.codeValue
          },
          withAuth: false
        })
        const user = response.data || response || {}
        saveAuthSession(user, this.baseUrl)
        uni.showToast({ title: '登录成功' })
        this.openAfterLogin()
      } catch (error) {
        this.error = error.message || '验证码登录失败'
      } finally {
        this.loading = false
      }
    },
    socialLogin(provider) {
      const url = cleanBaseUrl(this.baseUrl)
        + '/social-auth/redirect?provider=' + encodeURIComponent(provider)
        + '&returnUrl=' + encodeURIComponent('/')
      if (typeof window !== 'undefined') {
        window.location.href = url
        return
      }
      uni.showToast({ title: '请在浏览器打开第三方登录', icon: 'none' })
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

.form-stack {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.mode-row,
.role-row,
.social-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.code-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.code-input {
  flex: 1;
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
