import { cleanBaseUrl } from './config.js'

export function apiUrl(baseUrl, path, query = {}) {
  const url = cleanBaseUrl(baseUrl) + path
  const keys = Object.keys(query).filter((key) => query[key] !== undefined && query[key] !== null && query[key] !== '')
  if (!keys.length) {
    return url
  }
  const qs = keys.map((key) => encodeURIComponent(key) + '=' + encodeURIComponent(String(query[key]))).join('&')
  return url + '?' + qs
}

export function requestJson({ baseUrl, path, query = {}, data = {}, method = 'GET' }) {
  return new Promise((resolve, reject) => {
    uni.request({
      url: apiUrl(baseUrl, path, query),
      method,
      data,
      header: method === 'POST' ? { 'content-type': 'application/x-www-form-urlencoded', Accept: 'application/json' } : { Accept: 'application/json' },
      success(response) {
        const body = response.data || {}
        if (response.statusCode >= 400 || body.code >= 400) {
          reject(new Error(body.msg || 'Request failed'))
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
