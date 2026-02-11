import { createApp } from 'vue'
import { createPinia } from 'pinia'
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate'
import ElementPlus from 'element-plus'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import 'element-plus/dist/index.css'
import zhCn from 'element-plus/dist/locale/zh-cn.mjs'

// SVG 图标
import 'virtual:svg-icons-register'

// 全局样式
import 'normalize.css'
import './styles/index.scss'
import './styles/sidebar.scss'

import App from './App.vue'
import router from './router'

// 组件
import SvgIcon from '@/components/SvgIcon.vue'

// 指令
import directives from '@/directives'

const app = createApp(App)

// Pinia 状态管理
const pinia = createPinia()
pinia.use(piniaPluginPersistedstate)

// 注册所有Element Plus图标
for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component)
}

// 注册全局组件
app.component('SvgIcon', SvgIcon)

// 注册全局指令
app.use(directives)

app.use(pinia)
app.use(router)
app.use(ElementPlus, { 
  locale: zhCn,
  size: 'default',
  zIndex: 3000
})

app.mount('#app')
