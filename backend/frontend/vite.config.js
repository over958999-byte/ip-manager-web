import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  base: '/admin/',
  server: {
    port: 3000,
    proxy: {
      '/backend/api': {
        target: 'http://localhost:8080', // PHP服务器地址
        changeOrigin: true
      }
    }
  },
  build: {
    outDir: '../../dist',
    emptyOutDir: true
  }
})
