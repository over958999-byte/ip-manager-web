import { defineStore } from 'pinia'
import { constantRoutes, asyncRoutes } from '@/router'

/**
 * 通过meta.role判断是否有权限
 * @param {Array} roles 用户角色
 * @param {Object} route 路由
 */
function hasPermission(roles, route) {
  if (route.meta && route.meta.roles) {
    return roles.some(role => route.meta.roles.includes(role))
  }
  return true
}

/**
 * 递归过滤异步路由
 * @param {Array} routes 路由配置
 * @param {Array} roles 用户角色
 */
function filterAsyncRoutes(routes, roles) {
  const res = []
  
  routes.forEach(route => {
    const tmp = { ...route }
    if (hasPermission(roles, tmp)) {
      if (tmp.children) {
        tmp.children = filterAsyncRoutes(tmp.children, roles)
      }
      res.push(tmp)
    }
  })
  
  return res
}

const usePermissionStore = defineStore('permission', {
  state: () => ({
    // 完整路由
    routes: [],
    // 动态添加的路由
    addRoutes: [],
    // 侧边栏菜单
    sidebarRoutes: []
  }),
  
  actions: {
    // 生成路由
    generateRoutes(roles) {
      return new Promise(resolve => {
        let accessedRoutes
        
        // 超级管理员拥有所有权限
        if (roles.includes('admin')) {
          accessedRoutes = asyncRoutes || []
        } else {
          accessedRoutes = filterAsyncRoutes(asyncRoutes, roles)
        }
        
        this.addRoutes = accessedRoutes
        this.routes = constantRoutes.concat(accessedRoutes)
        this.sidebarRoutes = this.routes.filter(route => !route.hidden)
        
        resolve(accessedRoutes)
      })
    },
    
    // 设置路由（用于从服务器获取路由）
    setRoutes(routes) {
      this.addRoutes = routes
      this.routes = constantRoutes.concat(routes)
      this.sidebarRoutes = this.routes.filter(route => !route.hidden)
    },
    
    // 重置路由
    resetRoutes() {
      this.routes = constantRoutes
      this.addRoutes = []
      this.sidebarRoutes = constantRoutes.filter(route => !route.hidden)
    }
  }
})

export default usePermissionStore
