export const DEFAULT_CONFIG = {
  baseUrl: 'https://demo2026.mongoyia.com',
  wsUrl: 'wss://demo2026.mongoyia.com/ws-im',
  language: 'en'
}

export function cleanBaseUrl(value) {
  const text = String(value || DEFAULT_CONFIG.baseUrl).trim()
  return text.replace(/\/+$/, '')
}

export function cleanWsUrl(value) {
  return String(value || DEFAULT_CONFIG.wsUrl).trim()
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
