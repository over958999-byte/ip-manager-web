import { createRouter, createWebHashHistory } from 'vue-router'
import NProgress from 'nprogress'
import 'nprogress/nprogress.css'
import { getToken } from '@/utils/auth'

// NProgress 配置
NProgress.configure({ showSpinner: false })

// 布局组件
import Layout from '@/layout/index.vue'

/**
 * 常量路由
 * 不需要权限的基础路由
 */
export const constantRoutes = [
  {
    path: '/redirect',
    component: Layout,
    hidden: true,
    children: [
      {
        path: '/redirect/:path(.*)',
        component: () => import('@/views/Redirect.vue')
      }
    ]
  },
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/Login.vue'),
    hidden: true,
    meta: { title: '登录' }
  },
  {
    path: '/404',
    component: () => import('@/views/Error404.vue'),
    hidden: true,
    meta: { title: '404' }
  },
  {
    path: '/',
    component: Layout,
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/Dashboard.vue'),
        meta: { title: '仪表盘', icon: 'Odometer', affix: true }
      }
    ]
  }
]

/**
 * 异步路由
 * 需要根据用户角色动态加载
 */
export const asyncRoutes = [
  {
    path: '/jump',
    component: Layout,
    redirect: '/jump/rules',
    name: 'Jump',
    meta: { title: '跳转管理', icon: 'Promotion' },
    children: [
      {
        path: 'rules',
        name: 'JumpRules',
        component: () => import('@/views/JumpRules.vue'),
        meta: { title: '跳转规则', icon: 'List' }
      },
      {
        path: 'resource-pool',
        name: 'ResourcePool',
        component: () => import('@/views/ResourcePool.vue'),
        meta: { title: '资源池', icon: 'Coin' }
      }
    ]
  },
  {
    path: '/security',
    component: Layout,
    redirect: '/security/antibot',
    name: 'Security',
    meta: { title: '安全防护', icon: 'Shield' },
    children: [
      {
        path: 'antibot',
        name: 'Antibot',
        component: () => import('@/views/Antibot.vue'),
        meta: { title: '反爬虫管理', icon: 'Lock' }
      },
      {
        path: 'ip-pool',
        name: 'IpPool',
        component: () => import('@/views/IpPool.vue'),
        meta: { title: 'IP黑白名单', icon: 'Connection' }
      }
    ]
  },
  {
    path: '/api',
    component: Layout,
    children: [
      {
        path: 'manager',
        name: 'ApiManager',
        component: () => import('@/views/ApiManager.vue'),
        meta: { title: 'API管理', icon: 'Connection' }
      }
    ]
  },
  {
    path: '/data',
    component: Layout,
    redirect: '/data/dashboard',
    name: 'Data',
    meta: { title: '数据分析', icon: 'DataAnalysis' },
    children: [
      {
        path: 'dashboard',
        name: 'DataDashboard',
        component: () => import('@/views/DataDashboard.vue'),
        meta: { title: '数据大盘', icon: 'TrendCharts' }
      },
      {
        path: 'audit',
        name: 'AuditLogs',
        component: () => import('@/views/AuditLogs.vue'),
        meta: { title: '审计日志', icon: 'Document' }
      }
    ]
  },
  {
    path: '/system',
    component: Layout,
    redirect: '/system/settings',
    name: 'System',
    meta: { title: '系统管理', icon: 'Setting', roles: ['admin'] },
    children: [
      {
        path: 'settings',
        name: 'Settings',
        component: () => import('@/views/Settings.vue'),
        meta: { title: '系统设置', icon: 'Setting' }
      },
      {
        path: 'users',
        name: 'Users',
        component: () => import('@/views/Users.vue'),
        meta: { title: '用户管理', icon: 'User', roles: ['admin'] }
      },
      {
        path: 'webhooks',
        name: 'Webhooks',
        component: () => import('@/views/Webhooks.vue'),
        meta: { title: 'Webhook管理', icon: 'Bell' }
      },
      {
        path: 'backups',
        name: 'Backups',
        component: () => import('@/views/Backups.vue'),
        meta: { title: '备份管理', icon: 'FolderOpened' }
      }
    ]
  },
  // 404 必须放在最后
  { path: '/:pathMatch(.*)*', redirect: '/404', hidden: true }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes: constantRoutes,
  scrollBehavior: () => ({ top: 0 })
})

// 白名单
const whiteList = ['/login', '/404']

// 路由守卫
router.beforeEach(async (to, from, next) => {
  // 开始进度条
  NProgress.start()
  
  // 设置页面标题
  document.title = `${to.meta.title || '首页'} - 困King分发平台`
  
  const hasToken = getToken()
  
  if (hasToken) {
    if (to.path === '/login') {
      // 已登录，跳转首页
      next({ path: '/' })
      NProgress.done()
    } else {
      // 动态导入 store，避免循环依赖
      const { useUserStore, usePermissionStore } = await import('@/stores')
      const userStore = useUserStore()
      const permissionStore = usePermissionStore()
      
      // 判断是否已有角色信息
      const hasRoles = userStore.roles && userStore.roles.length > 0
      
      if (hasRoles) {
        next()
      } else {
        try {
          // 尝试从缓存恢复
          userStore.restoreFromCache()
          
          // 验证登录状态
          const loggedIn = await userStore.checkLogin()
          
          if (loggedIn) {
            // 生成可访问路由
            const accessRoutes = await permissionStore.generateRoutes(userStore.roles)
            
            // 动态添加路由
            accessRoutes.forEach(route => {
              router.addRoute(route)
            })
            
            // 重新导航，确保路由已添加
            next({ ...to, replace: true })
          } else {
            // 未登录，重置并跳转登录页
            userStore.resetState()
            next(`/login?redirect=${to.path}`)
            NProgress.done()
          }
        } catch (error) {
          console.error('Permission error:', error)
          userStore.resetState()
          next(`/login?redirect=${to.path}`)
          NProgress.done()
        }
      }
    }
  } else {
    // 无token
    if (whiteList.includes(to.path)) {
      next()
    } else {
      next(`/login?redirect=${to.path}`)
      NProgress.done()
    }
  }
})

router.afterEach(() => {
  // 结束进度条
  NProgress.done()
})

// 重置路由
export function resetRouter() {
  const newRouter = createRouter({
    history: createWebHashHistory(),
    routes: constantRoutes,
    scrollBehavior: () => ({ top: 0 })
  })
  router.matcher = newRouter.matcher
}

export default router
