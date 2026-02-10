import { createRouter, createWebHashHistory } from 'vue-router'
import { useUserStore } from '../stores/user'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/Login.vue'),
    meta: { title: '登录', hidden: true }
  },
  {
    path: '/',
    component: () => import('../layout/index.vue'),
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('../views/Dashboard.vue'),
        meta: { title: '仪表盘', icon: 'Odometer' }
      },
      {
        path: 'jump-rules',
        name: 'JumpRules',
        component: () => import('../views/JumpRules.vue'),
        meta: { title: '跳转管理', icon: 'Promotion' }
      },
      {
        path: 'resource-pool',
        name: 'ResourcePool',
        component: () => import('../views/ResourcePool.vue'),
        meta: { title: '资源池', icon: 'Coin' }
      },
      {
        path: 'antibot',
        name: 'Antibot',
        component: () => import('../views/Antibot.vue'),
        meta: { title: '反爬虫管理', icon: 'Shield' }
      },
      {
        path: 'api-manager',
        name: 'ApiManager',
        component: () => import('../views/ApiManager.vue'),
        meta: { title: 'API管理', icon: 'Connection' }
      },
      {
        path: 'settings',
        name: 'Settings',
        component: () => import('../views/Settings.vue'),
        meta: { title: '系统设置', icon: 'Setting' }
      },
      {
        path: 'data-dashboard',
        name: 'DataDashboard',
        component: () => import('../views/DataDashboard.vue'),
        meta: { title: '数据大盘', icon: 'DataAnalysis' }
      },
      {
        path: 'audit-logs',
        name: 'AuditLogs',
        component: () => import('../views/AuditLogs.vue'),
        meta: { title: '审计日志', icon: 'Document' }
      },
      {
        path: 'webhooks',
        name: 'Webhooks',
        component: () => import('../views/Webhooks.vue'),
        meta: { title: 'Webhook管理', icon: 'Bell' }
      },
      {
        path: 'users',
        name: 'Users',
        component: () => import('../views/Users.vue'),
        meta: { title: '用户管理', icon: 'User', roles: ['admin'] }
      },
      {
        path: 'backups',
        name: 'Backups',
        component: () => import('../views/Backups.vue'),
        meta: { title: '备份管理', icon: 'FolderOpened' }
      }
    ]
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

// 路由守卫
router.beforeEach(async (to, from, next) => {
  document.title = `${to.meta.title || '首页'} - 困King分发平台`
  
  const userStore = useUserStore()
  
  if (to.path === '/login') {
    next()
  } else {
    if (userStore.isLoggedIn) {
      next()
    } else {
      // 检查登录状态
      const loggedIn = await userStore.checkLogin()
      if (loggedIn) {
        next()
      } else {
        next('/login')
      }
    }
  }
})

export default router
