import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/api'
import { getToken, setToken, removeToken, getUserInfo, setUserInfo, removeUserInfo } from '@/utils/auth'
import router from '@/router'
import { usePermissionStore } from './permission'
import { useTagsViewStore } from './tagsView'

export const useUserStore = defineStore('user', () => {
  // State
  const token = ref(getToken() || '')
  const username = ref('')
  const avatar = ref('')
  const roles = ref([])
  const permissions = ref([])
  const userInfo = ref(null)

  // Getters
  const isLoggedIn = computed(() => !!token.value)
  const isAdmin = computed(() => roles.value.includes('admin'))
  
  // Actions
  async function login(user, password, totpCode = '') {
    try {
      const res = await api.login(user, password, totpCode)
      if (res.success) {
        // 设置token
        const tokenValue = res.token || res.session_id || 'logged_in'
        token.value = tokenValue
        setToken(tokenValue)
        
        // 设置用户信息
        username.value = res.username || user || 'admin'
        avatar.value = res.avatar || ''
        roles.value = res.roles || ['user']
        permissions.value = res.permissions || []
        
        // 缓存用户信息
        const info = {
          username: username.value,
          avatar: avatar.value,
          roles: roles.value,
          permissions: permissions.value
        }
        userInfo.value = info
        setUserInfo(info)
        
        // 生成动态路由
        const permissionStore = usePermissionStore()
        const accessRoutes = await permissionStore.generateRoutes(roles.value)
        accessRoutes.forEach(route => {
          router.addRoute(route)
        })
      }
      return res
    } catch (error) {
      console.error('Login error:', error)
      throw error
    }
  }

  async function logout() {
    try {
      await api.logout()
    } catch (error) {
      console.error('Logout error:', error)
    } finally {
      // 清除状态
      resetState()
      
      // 清除标签页
      const tagsViewStore = useTagsViewStore()
      tagsViewStore.delAllViews()
      
      // 重置路由
      const permissionStore = usePermissionStore()
      permissionStore.resetRoutes()
      
      // 跳转登录页
      router.push('/login')
    }
  }

  async function checkLogin() {
    try {
      // 如果没有token，直接返回false
      if (!token.value) {
        return false
      }
      
      const res = await api.checkLogin()
      if (res.logged_in) {
        username.value = res.username || ''
        avatar.value = res.avatar || ''
        roles.value = res.roles || ['user']
        permissions.value = res.permissions || []
        
        // 如果还没生成动态路由，则生成
        const permissionStore = usePermissionStore()
        if (permissionStore.addRoutes.length === 0) {
          const accessRoutes = await permissionStore.generateRoutes(roles.value)
          accessRoutes.forEach(route => {
            router.addRoute(route)
          })
        }
        
        return true
      } else {
        resetState()
        return false
      }
    } catch (e) {
      console.error('Check login error:', e)
      resetState()
      return false
    }
  }

  // 获取用户信息
  async function getUserInfoAction() {
    try {
      const res = await api.getUserInfo()
      if (res.success) {
        username.value = res.username || ''
        avatar.value = res.avatar || ''
        roles.value = res.roles || ['user']
        permissions.value = res.permissions || []
        
        userInfo.value = {
          username: username.value,
          avatar: avatar.value,
          roles: roles.value,
          permissions: permissions.value
        }
        
        return userInfo.value
      }
    } catch (error) {
      console.error('Get user info error:', error)
    }
    return null
  }

  // 重置状态
  function resetState() {
    token.value = ''
    username.value = ''
    avatar.value = ''
    roles.value = []
    permissions.value = []
    userInfo.value = null
    
    removeToken()
    removeUserInfo()
  }

  // 判断是否有权限
  function hasPermission(permission) {
    if (isAdmin.value) return true
    return permissions.value.includes(permission)
  }

  // 判断是否有角色
  function hasRole(role) {
    return roles.value.includes(role)
  }

  // 从缓存恢复用户信息
  function restoreFromCache() {
    const cached = getUserInfo()
    if (cached && token.value) {
      username.value = cached.username || ''
      avatar.value = cached.avatar || ''
      roles.value = cached.roles || []
      permissions.value = cached.permissions || []
      userInfo.value = cached
      return true
    }
    return false
  }

  return {
    // State
    token,
    username,
    avatar,
    roles,
    permissions,
    userInfo,
    
    // Getters
    isLoggedIn,
    isAdmin,
    
    // Actions
    login,
    logout,
    checkLogin,
    getUserInfo: getUserInfoAction,
    resetState,
    hasPermission,
    hasRole,
    restoreFromCache
  }
})

export default useUserStore
