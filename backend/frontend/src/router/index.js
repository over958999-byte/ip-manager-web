import { createRouter, createWebHashHistory } from 'vue-router'
import { useUserStore } from '../stores/user'
import { ElMessage } from 'element-plus'

// ==================== 路由懒加载优化 ====================

/**
 * 带错误处理的组件加载器
 * 支持加载失败重试、超时处理
 */
const lazyLoad = (importFn, componentName = '') => {
  return () => {
    return new Promise((resolve, reject) => {
      importFn()
        .then(resolve)
        .catch(err => {
          console.error(`加载组件失败 [${componentName}]:`, err)
          // 尝试重新加载一次
          setTimeout(() => {
            importFn()
              .then(resolve)
              .catch(finalErr => {
                ElMessage.error('页面加载失败，请刷新重试')
                reject(finalErr)
              })
          }, 1000)
        })
    })
  }
}

/**
 * 预加载指定组件（空闲时预加载提升体验）
 */
const preloadComponent = (importFn) => {
  if ('requestIdleCallback' in window) {
    requestIdleCallback(() => importFn())
  } else {
    setTimeout(() => importFn(), 2000)
  }
}

// 预加载常用组件
const preloadCommonViews = () => {
  preloadComponent(() => import('../views/JumpRules.vue'))
  preloadComponent(() => import('../views/Dashboard.vue'))
}

// ==================== 路由配置 ====================

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: lazyLoad(() => import('../views/Login.vue'), 'Login'),
    meta: { title: '登录', hidden: true, public: true }
  },
  {
    path: '/',
    component: lazyLoad(() => import('../layout/index.vue'), 'Layout'),
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: lazyLoad(() => import('../views/Dashboard.vue'), 'Dashboard'),
        meta: { title: '仪表盘', icon: 'Odometer', keepAlive: true }
      },
      {
        path: 'jump-rules',
        name: 'JumpRules',
        component: lazyLoad(() => import('../views/JumpRules.vue'), 'JumpRules'),
        meta: { title: '跳转管理', icon: 'Promotion', keepAlive: true }
      },
      {
        path: 'resource-pool',
        name: 'ResourcePool',
        component: lazyLoad(() => import('../views/ResourcePool.vue'), 'ResourcePool'),
        meta: { title: '资源池', icon: 'Coin', keepAlive: true }
      },
      {
        path: 'antibot',
        name: 'Antibot',
        component: lazyLoad(() => import('../views/Antibot.vue'), 'Antibot'),
        meta: { title: '反爬虫管理', icon: 'Shield' }
      },
      {
        path: 'api-manager',
        name: 'ApiManager',
        component: lazyLoad(() => import('../views/ApiManager.vue'), 'ApiManager'),
        meta: { title: 'API管理', icon: 'Connection' }
      },
      {
        path: 'settings',
        name: 'Settings',
        component: lazyLoad(() => import('../views/Settings.vue'), 'Settings'),
        meta: { title: '系统设置', icon: 'Setting' }
      },
      {
        path: 'data-dashboard',
        name: 'DataDashboard',
        component: lazyLoad(() => import('../views/DataDashboard.vue'), 'DataDashboard'),
        meta: { title: '数据大盘', icon: 'DataAnalysis' }
      },
      {
        path: 'audit-logs',
        name: 'AuditLogs',
        component: lazyLoad(() => import('../views/AuditLogs.vue'), 'AuditLogs'),
        meta: { title: '审计日志', icon: 'Document' }
      },
      {
        path: 'webhooks',
        name: 'Webhooks',
        component: lazyLoad(() => import('../views/Webhooks.vue'), 'Webhooks'),
        meta: { title: 'Webhook管理', icon: 'Bell' }
      },
      {
        path: 'users',
        name: 'Users',
        component: lazyLoad(() => import('../views/Users.vue'), 'Users'),
        meta: { title: '用户管理', icon: 'User', roles: ['admin'] }
      },
      {
        path: 'backups',
        name: 'Backups',
        component: lazyLoad(() => import('../views/Backups.vue'), 'Backups'),
        meta: { title: '备份管理', icon: 'FolderOpened' }
      }
    ]
  },
  // 404 页面
  {
    path: '/:pathMatch(.*)*',
    name: 'NotFound',
    component: lazyLoad(() => import('../views/Login.vue'), 'NotFound'),
    meta: { title: '页面不存在', hidden: true }
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  // 滚动行为
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }
    return { top: 0 }
  }
})

// ==================== 路由守卫 ====================

// 路由进度条状态
let loadingTimer = null

router.beforeEach(async (to, from, next) => {
  // 设置页面标题
  document.title = `${to.meta.title || '首页'} - 困King分发平台`
  
  const userStore = useUserStore()
  
  // 公开页面直接放行
  if (to.meta.public || to.path === '/login') {
    next()
    return
  }
  
  // 检查登录状态
  if (userStore.isLoggedIn) {
    // 角色权限检查
    if (to.meta.roles && !to.meta.roles.includes(userStore.role)) {
      ElMessage.error('您没有访问该页面的权限')
      next('/')
      return
    }
    next()
  } else {
    // 尝试恢复登录状态
    const loggedIn = await userStore.checkLogin()
    if (loggedIn) {
      next()
    } else {
      next('/login')
    }
  }
})

router.afterEach((to, from) => {
  // 清除加载定时器
  if (loadingTimer) {
    clearTimeout(loadingTimer)
    loadingTimer = null
  }
  
  // 首次导航后预加载常用组件
  if (!from.name) {
    preloadCommonViews()
  }
})

// 路由错误处理
router.onError((error) => {
  console.error('路由错误:', error)
  // 尝试重新加载页面
  if (error.message.includes('Failed to fetch dynamically imported module')) {
    ElMessage.error('页面加载失败，正在重试...')
    window.location.reload()
  }
})

export default router
