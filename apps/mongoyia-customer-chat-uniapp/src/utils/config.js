export const DEFAULT_CONFIG = {
  baseUrl: 'https://demo2026.mongoyia.com',
  wsUrl: 'wss://demo2026.mongoyia.com/ws-im',
  language: 'en'
}

export const APP_BASE_URL_KEY = 'mongoyia_app_base_url'

export function storedBaseUrl() {
  try {
    return uni.getStorageSync(APP_BASE_URL_KEY) || ''
  } catch (error) {
    return ''
  }
}

export function cleanBaseUrl(value) {
  const text = decodeMaybeUrl(String(value || storedBaseUrl() || DEFAULT_CONFIG.baseUrl).trim())
  return text.replace(/\/+$/, '')
}

export function cleanWsUrl(value) {
  return decodeMaybeUrl(String(value || DEFAULT_CONFIG.wsUrl).trim())
}

function decodeMaybeUrl(value) {
  if (!value || !value.includes('%')) {
    return value
  }
  try {
    const decoded = decodeURIComponent(value)
    if (/^https?:\/\//.test(decoded) || /^wss?:\/\//.test(decoded)) {
      return decoded
    }
  } catch (error) {}
  return value
}

export function customerUuid() {
  const cached = uni.getStorageSync('mongoyia_customer_uuid')
  if (cached) {
    return cached
  }
  const uuid = 'app-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)
  uni.setStorageSync('mongoyia_customer_uuid', uuid)
  return uuid
}
