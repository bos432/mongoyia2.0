import { defineConfig } from 'vite'
import uni from '@dcloudio/vite-plugin-uni'

export default defineConfig({
  plugins: [uni()],
  server: {
    proxy: {
      '/demo-api': {
        target: 'https://demo2026.mongoyia.com',
        changeOrigin: true,
        secure: false,
        rewrite: (path) => path.replace(/^\/demo-api/, '')
      },
      '/ws-im': {
        target: 'wss://demo2026.mongoyia.com',
        changeOrigin: true,
        secure: false,
        ws: true
      }
    }
  }
})
