/**
 * 权限指令
 * 用法: v-permission="['admin']" 或 v-permission="'user:add'"
 */
import { useUserStore } from '@/stores'

export default {
  mounted(el, binding) {
    const userStore = useUserStore()
    const { value } = binding
    
    if (!value) return
    
    // 超级管理员拥有所有权限
    if (userStore.isAdmin) return
    
    const hasPermission = checkPermission(value, userStore)
    
    if (!hasPermission) {
      el.parentNode && el.parentNode.removeChild(el)
    }
  }
}

/**
 * 检查权限
 * @param {string|string[]} value 权限值
 * @param {object} userStore 用户store
 * @returns {boolean}
 */
function checkPermission(value, userStore) {
  if (Array.isArray(value)) {
    // 数组形式：检查角色
    if (value.length === 0) return true
    return value.some(role => userStore.hasRole(role))
  }
  
  if (typeof value === 'string') {
    // 字符串形式：检查权限
    return userStore.hasPermission(value)
  }
  
  return false
}

/**
 * 函数式权限检查
 * @param {string|string[]} value 权限值
 * @returns {boolean}
 */
export function hasPermission(value) {
  const userStore = useUserStore()
  
  if (!value) return true
  if (userStore.isAdmin) return true
  
  return checkPermission(value, userStore)
}

/**
 * 检查是否有角色
 * @param {string|string[]} roles 角色
 * @returns {boolean}
 */
export function hasRole(roles) {
  const userStore = useUserStore()
  
  if (!roles) return true
  if (userStore.isAdmin) return true
  
  if (Array.isArray(roles)) {
    return roles.some(role => userStore.hasRole(role))
  }
  
  return userStore.hasRole(roles)
}
