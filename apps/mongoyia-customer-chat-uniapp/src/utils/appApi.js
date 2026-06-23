import { requestJson } from './api.js'

export const PHASE13_APP_SHELL_VERSION = 'MONGOYIA_PHASE13_APP_SHELL_V1'

export const BUYER_ENDPOINTS = {
  home: '/api/v1/app-buyer/home',
  category: '/api/v1/app-buyer/categories',
  search: '/api/v1/app-buyer/search',
  suggestions: '/api/v1/app-buyer/suggestions',
  product: '/api/v1/app-buyer/product',
  cart: '/api/v1/app-buyer/cart',
  orders: '/api/v1/app-buyer/orders',
  coupons: '/api/v1/app-buyer/coupons',
  favorites: '/api/v1/app-buyer/favorites',
  storeFavorites: '/api/v1/app-buyer/store-favorites',
  reviews: '/api/v1/app-buyer/reviews',
  myReviews: '/api/v1/app-buyer/my-reviews'
}

export const SELLER_ENDPOINTS = {
  dashboard: '/api/v1/app-seller/dashboard',
  products: '/api/v1/app-seller/products',
  orders: '/api/v1/app-seller/orders',
  shipment: '/api/v1/app-seller/shipment',
  logistics: '/api/v1/app-seller/logistics',
  deposit: '/api/v1/app-seller/deposit',
  coupons: '/api/v1/app-seller/coupons',
  statistics: '/api/v1/app-seller/statistics',
  distribution: '/api/v1/app-seller/distribution'
}

export function authHeaders(token = '') {
  return token ? { 'access-token': token } : {}
}

export function appRequest({ baseUrl, path, query = {}, data = {}, method = 'GET', header = {}, withAuth = true }) {
  return requestJson({ baseUrl, path, query, data, method, header, withAuth }).then((response) => {
    return response && response.data ? response.data : response
  })
}

export function pageState(overrides = {}) {
  return {
    loading: false,
    loaded: false,
    error: '',
    items: [],
    summary: {},
    ...overrides
  }
}

export function normalizeListPayload(response) {
  const data = response && response.data ? response.data : response
  if (Array.isArray(data)) {
    return data
  }
  if (data && Array.isArray(data.items)) {
    return data.items
  }
  if (data && Array.isArray(data.data)) {
    return data.data
  }
  return []
}
