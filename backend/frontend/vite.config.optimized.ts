import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// 注意：以下插件需要先安装
// npm install -D unplugin-auto-import unplugin-vue-components vite-plugin-pwa
// import AutoImport from 'unplugin-auto-import/vite'
// import Components from 'unplugin-vue-components/vite'
// import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
// import { VitePWA } from 'vite-plugin-pwa'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    
    // Element Plus 按需导入（需要先安装依赖）
    // AutoImport({
    //   imports: ['vue', 'vue-router', 'pinia'],
    //   resolvers: [ElementPlusResolver()],
    //   dts: 'src/auto-imports.d.ts',
    //   eslintrc: {
    //     enabled: true,
    //   },
    // }),
    // 
    // Components({
    //   resolvers: [ElementPlusResolver()],
    //   dts: 'src/components.d.ts',
    // }),
    
    // PWA 支持（需要先安装 vite-plugin-pwa）
    // VitePWA({
    //   registerType: 'autoUpdate',
    //   includeAssets: ['favicon.ico', 'robots.txt', 'apple-touch-icon.png'],
    //   manifest: {
    //     name: '困King分发平台',
    //     short_name: 'IP管理器',
    //     description: 'IP跳转管理系统',
    //     theme_color: '#409eff',
    //     background_color: '#ffffff',
    //     display: 'standalone',
    //     start_url: '/',
    //     icons: [
    //       {
    //         src: 'pwa-192x192.png',
    //         sizes: '192x192',
    //         type: 'image/png',
    //       },
    //       {
    //         src: 'pwa-512x512.png',
    //         sizes: '512x512',
    //         type: 'image/png',
    //       },
    //       {
    //         src: 'pwa-512x512.png',
    //         sizes: '512x512',
    //         type: 'image/png',
    //         purpose: 'any maskable',
    //       },
    //     ],
    //   },
    //   workbox: {
    //     globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
    //     runtimeCaching: [
    //       {
    //         urlPattern: /^https:\/\/api\..*/i,
    //         handler: 'NetworkFirst',
    //         options: {
    //           cacheName: 'api-cache',
    //           expiration: {
    //             maxEntries: 100,
    //             maxAgeSeconds: 60 * 60, // 1 hour
    //           },
    //           cacheableResponse: {
    //             statuses: [0, 200],
    //           },
    //         },
    //       },
    //     ],
    //   },
    // }),
  ],
  
  resolve: {
    alias: {
      '@': new URL('./src', import.meta.url).pathname,
    },
  },
  
  css: {
    preprocessorOptions: {
      scss: {
        additionalData: `@use "@/styles/variables.scss" as *;`,
      },
    },
  },
  
  build: {
    target: 'es2020',
    minify: 'esbuild',
    // 如需使用 terser，请安装: npm install -D terser
    // minify: 'terser',
    // terserOptions: {
    //   compress: {
    //     drop_console: true,
    //     drop_debugger: true,
    //   },
    // },
    rollupOptions: {
      output: {
        manualChunks: {
          'vue-vendor': ['vue', 'vue-router', 'pinia'],
          'element-plus': ['element-plus'],
          'echarts': ['echarts'],
        },
      },
    },
    chunkSizeWarningLimit: 1000,
  },
  
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:80',
        changeOrigin: true,
      },
    },
  },
  
  optimizeDeps: {
    include: ['vue', 'vue-router', 'pinia', 'axios', 'echarts'],
  },
})
