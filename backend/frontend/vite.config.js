import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import { createSvgIconsPlugin } from 'vite-plugin-svg-icons'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd())
  
  return {
    plugins: [
      vue(),
      // 自动导入 Vue 相关函数
      AutoImport({
        imports: ['vue', 'vue-router', 'pinia'],
        resolvers: [ElementPlusResolver()],
        dts: 'src/auto-imports.d.ts',
        eslintrc: {
          enabled: true
        }
      }),
      // 自动注册组件
      Components({
        resolvers: [ElementPlusResolver()],
        dts: 'src/components.d.ts'
      }),
      // SVG 图标
      createSvgIconsPlugin({
        iconDirs: [path.resolve(process.cwd(), 'src/icons/svg')],
        symbolId: 'icon-[dir]-[name]'
      })
    ],
    
    resolve: {
      alias: {
        '@': path.resolve(__dirname, 'src'),
        'path': 'path-browserify'
      }
    },
    
    base: '/admin/',
    
    server: {
      port: 3000,
      host: true,
      open: true,
      proxy: {
        '/api.php': {
          target: env.VITE_API_URL || 'http://localhost:8080/backend/api',
          changeOrigin: true
        }
      }
    },
    
    css: {
      preprocessorOptions: {
        scss: {
          additionalData: `@import "@/styles/variables.scss";`
        }
      }
    },
    
    build: {
      outDir: '../../dist',
      emptyOutDir: true,
      chunkSizeWarningLimit: 2000,
      rollupOptions: {
        output: {
          manualChunks: {
            'vue-vendor': ['vue', 'vue-router', 'pinia'],
            'element-plus': ['element-plus'],
            'echarts': ['echarts'],
            'utils': ['axios', 'dayjs', 'js-cookie', 'nprogress']
          },
          entryFileNames: 'assets/[name]-[hash].js',
          chunkFileNames: 'assets/[name]-[hash].js',
          assetFileNames: 'assets/[name]-[hash].[ext]'
        }
      },
      cssCodeSplit: true,
      sourcemap: false,
      minify: 'esbuild'
    },
    
    optimizeDeps: {
      include: [
        'vue',
        'vue-router',
        'pinia',
        'element-plus',
        'axios',
        'echarts',
        'dayjs',
        'js-cookie',
        'nprogress',
        '@vueuse/core',
        'fuse.js',
        'sortablejs'
      ]
    }
  }
})
