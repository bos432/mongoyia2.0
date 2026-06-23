import { cleanBaseUrl } from './config.js'

export const PHASE13_APP_AUTH_HANDOFF_VERSION = 'MONGOYIA_PHASE13_APP_AUTH_HANDOFF_V1'
export const APP_ACCESS_TOKEN_KEY = 'mongoyia_app_access_token'
export const APP_REFRESH_TOKEN_KEY = 'mongoyia_app_refresh_token'
export const APP_USER_KEY = 'mongoyia_app_user'
export const APP_BASE_URL_KEY = 'mongoyia_app_base_url'

export function getStoredAccessToken() {
  try {
    return uni.getStorageSync(APP_ACCESS_TOKEN_KEY) || ''
  } catch (error) {
    return ''
  }
}

export function saveAuthSession(user = {}, baseUrl = '') {
  const accessToken = user.access_token || ''
  if (!accessToken) {
    throw new Error('Login response missing access token')
  }
  uni.setStorageSync(APP_ACCESS_TOKEN_KEY, accessToken)
  uni.setStorageSync(APP_REFRESH_TOKEN_KEY, user.refresh_token || '')
  uni.setStorageSync(APP_USER_KEY, {
    id: user.id || 0,
    username: user.username || '',
    email: user.email || '',
    store: user.store || null
  })
  if (baseUrl) {
    uni.setStorageSync(APP_BASE_URL_KEY, cleanBaseUrl(baseUrl))
  }
}

export function clearAuthSession() {
  uni.removeStorageSync(APP_ACCESS_TOKEN_KEY)
  uni.removeStorageSync(APP_REFRESH_TOKEN_KEY)
  uni.removeStorageSync(APP_USER_KEY)
}

export function isAuthed() {
  return !!getStoredAccessToken()
}

export function apiUrl(baseUrl, path, query = {}) {
  const url = cleanBaseUrl(baseUrl) + path
  const keys = Object.keys(query).filter((key) => query[key] !== undefined && query[key] !== null && query[key] !== '')
  if (!keys.length) {
    return url
  }
  const qs = keys.map((key) => encodeURIComponent(key) + '=' + encodeURIComponent(String(query[key]))).join('&')
  return url + '?' + qs
}

export function requestJson({ baseUrl, path, query = {}, data = {}, method = 'GET', header = {}, withAuth = true }) {
  return new Promise((resolve, reject) => {
    const token = withAuth ? getStoredAccessToken() : ''
    const requestHeader = method === 'POST'
      ? { 'content-type': 'application/x-www-form-urlencoded', Accept: 'application/json', ...header }
      : { Accept: 'application/json', ...header }
    if (token && !requestHeader['access-token']) {
      requestHeader['access-token'] = token
    }
    uni.request({
      url: apiUrl(baseUrl, path, query),
      method,
      data,
      header: requestHeader,
      success(response) {
        const body = response.data || {}
        if (response.statusCode >= 400 || body.code >= 400) {
          reject(new Error(body.msg || body.message || 'Request failed'))
          return
        }
        resolve(body)
      },
      fail(error) {
        reject(error)
      }
    })
  })
}

export function uploadMedia({ baseUrl, filePath, media, duration = 0, language = 'en' }) {
  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: apiUrl(baseUrl, '/mall/chat/media-upload', { lang: language }),
      name: 'file',
      filePath,
      formData: { media, duration },
      success(response) {
        let body = {}
        try {
          body = JSON.parse(response.data || '{}')
        } catch (error) {
          reject(error)
          return
        }
        if (response.statusCode >= 400 || body.code >= 400) {
          reject(new Error(body.msg || 'Upload failed'))
          return
        }
        resolve(body.data)
      },
      fail(error) {
        reject(error)
      }
    })
  })
}
