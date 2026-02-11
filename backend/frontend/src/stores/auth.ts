import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
// @ts-ignore - JS 模块
import api from '@/api'
import type { User, LoginForm, LoginResponse } from '@/types/auth'

/**
 * 用户认证 Store
 * 支持持久化、自动刷新 Token
 */
export const useAuthStore = defineStore('auth', () => {
  // ==================== State ====================
  const token = ref<string | null>(null)
  const user = ref<User | null>(null)
  const expiresAt = ref<number | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  // ==================== Getters ====================
  const isLoggedIn = computed(() => !!token.value && !!user.value)
  
  const isAdmin = computed(() => user.value?.role === 'admin')
  
  const isOperator = computed(() => 
    user.value?.role === 'admin' || user.value?.role === 'operator'
  )
  
  const tokenExpired = computed(() => {
    if (!expiresAt.value) return true
    return Date.now() > expiresAt.value
  })
  
  const permissions = computed(() => {
    const role = user.value?.role
    if (role === 'admin') {
      return ['read', 'write', 'delete', 'admin']
    } else if (role === 'operator') {
      return ['read', 'write']
    }
    return ['read']
  })

  // ==================== Actions ====================
  
  /**
   * 用户登录
   */
  async function login(form: LoginForm): Promise<boolean> {
    isLoading.value = true
    error.value = null
    
    try {
      const response = await api.post<LoginResponse>('/auth/login', form)
      
      if (response.data.success) {
        token.value = response.data.data.token
        user.value = response.data.data.user
        expiresAt.value = new Date(response.data.data.expires_at).getTime()
        
        // 设置 axios 默认 header
        api.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
        
        return true
      } else {
        error.value = response.data.error || '登录失败'
        return false
      }
    } catch (err: any) {
      error.value = err.response?.data?.error || err.message || '网络错误'
      return false
    } finally {
      isLoading.value = false
    }
  }
  
  /**
   * 用户登出
   */
  async function logout(): Promise<void> {
    try {
      await api.post('/auth/logout')
    } catch {
      // 忽略登出 API 错误
    } finally {
      clearAuth()
    }
  }
  
  /**
   * 清除认证状态
   */
  function clearAuth(): void {
    token.value = null
    user.value = null
    expiresAt.value = null
    delete api.defaults.headers.common['Authorization']
  }
  
  /**
   * 检查登录状态
   */
  async function checkAuth(): Promise<boolean> {
    if (!token.value) return false
    
    try {
      const response = await api.get('/auth/check')
      if (response.data.success) {
        user.value = response.data.data.user
        return true
      }
      clearAuth()
      return false
    } catch {
      clearAuth()
      return false
    }
  }
  
  /**
   * 刷新 Token
   */
  async function refreshToken(): Promise<boolean> {
    if (!token.value) return false
    
    try {
      const response = await api.post('/auth/refresh')
      if (response.data.success) {
        token.value = response.data.data.token
        expiresAt.value = new Date(response.data.data.expires_at).getTime()
        api.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
        return true
      }
      return false
    } catch {
      return false
    }
  }
  
  /**
   * 检查是否有指定权限
   */
  function hasPermission(permission: string): boolean {
    return permissions.value.includes(permission)
  }
  
  /**
   * 初始化（从持久化存储恢复）
   */
  function initialize(): void {
    if (token.value) {
      api.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
      
      // 如果 token 即将过期（5分钟内），自动刷新
      if (expiresAt.value && expiresAt.value - Date.now() < 5 * 60 * 1000) {
        refreshToken()
      }
    }
  }

  return {
    // State
    token,
    user,
    expiresAt,
    isLoading,
    error,
    
    // Getters
    isLoggedIn,
    isAdmin,
    isOperator,
    tokenExpired,
    permissions,
    
    // Actions
    login,
    logout,
    clearAuth,
    checkAuth,
    refreshToken,
    hasPermission,
    initialize,
  }
})

// 如需启用持久化，请安装 pinia-plugin-persistedstate:
// npm install pinia-plugin-persistedstate
// 然后在 main.ts 中配置，并取消以下注释：
// }, {
//   persist: {
//     key: 'ip-manager-auth',
//     storage: localStorage,
//     paths: ['token', 'user', 'expiresAt'],
//   },
// })
