import axios from 'axios'
import { ElMessage } from 'element-plus'

// ==================== 请求配置 ====================

// 创建axios实例
const request = axios.create({
  baseURL: '/backend/api/api_v2.php',
  timeout: 30000,
  withCredentials: true
})

// 重试配置
const retryConfig = {
  maxRetries: 3,
  retryDelay: 1000,
  retryableStatuses: [408, 500, 502, 503, 504],
}

// 请求取消令牌存储
const pendingRequests = new Map()

// 生成请求唯一键
const getRequestKey = (config) => {
  const { method, url, params, data } = config
  return [method, url, JSON.stringify(params), JSON.stringify(data)].join('&')
}

// 取消重复请求
const cancelDuplicateRequest = (config) => {
  const requestKey = getRequestKey(config)
  if (pendingRequests.has(requestKey)) {
    const controller = pendingRequests.get(requestKey)
    controller.abort()
    pendingRequests.delete(requestKey)
  }
}

// 添加请求到pending
const addPendingRequest = (config) => {
  const requestKey = getRequestKey(config)
  if (!config.signal) {
    const controller = new AbortController()
    config.signal = controller.signal
    pendingRequests.set(requestKey, controller)
  }
}

// 移除请求从pending
const removePendingRequest = (config) => {
  if (config) {
    const requestKey = getRequestKey(config)
    pendingRequests.delete(requestKey)
  }
}

// ==================== 请求拦截器 ====================

request.interceptors.request.use(
  config => {
    // 取消重复请求（可选，某些操作需要重复）
    if (config.cancelDuplicate !== false) {
      cancelDuplicateRequest(config)
      addPendingRequest(config)
    }
    
    // 添加 CSRF Token（如果启用）
    const csrfToken = localStorage.getItem('csrf_token')
    if (csrfToken) {
      config.headers['X-CSRF-Token'] = csrfToken
    }
    
    // 添加时间戳防止浏览器缓存
    config.params = {
      ...config.params,
      _t: Date.now()
    }
    
    return config
  },
  error => Promise.reject(error)
)

// ==================== 响应拦截器 ====================

request.interceptors.response.use(
  response => {
    removePendingRequest(response.config)
    
    // 保存 CSRF Token
    if (response.data?.csrf_token) {
      localStorage.setItem('csrf_token', response.data.csrf_token)
    }
    
    return response.data
  },
  async error => {
    const config = error.config
    removePendingRequest(config)
    
    // 请求被取消
    if (axios.isCancel(error)) {
      console.log('请求已取消:', config?.url)
      return Promise.reject(error)
    }
    
    // 重试逻辑
    if (config && !config.__retryCount) {
      config.__retryCount = 0
    }
    
    const shouldRetry = config && 
      config.__retryCount < retryConfig.maxRetries &&
      (retryConfig.retryableStatuses.includes(error.response?.status) || !error.response)
    
    if (shouldRetry) {
      config.__retryCount++
      console.log(`请求重试 (${config.__retryCount}/${retryConfig.maxRetries}):`, config.url)
      
      // 等待后重试
      await new Promise(resolve => setTimeout(resolve, retryConfig.retryDelay * config.__retryCount))
      
      // 移除旧的取消令牌
      delete config.signal
      config.cancelDuplicate = false
      
      return request(config)
    }
    
    // 显示错误消息
    const message =
      error?.response?.data?.message ||
      error?.message ||
      '请求失败'
    ElMessage.error(message)
    return Promise.reject(error)
  }
)

// ==================== 工具函数 ====================

/**
 * 取消所有pending请求
 */
export const cancelAllRequests = () => {
  pendingRequests.forEach((controller, key) => {
    controller.abort()
  })
  pendingRequests.clear()
}

/**
 * 创建可取消的请求
 */
export const createCancellableRequest = () => {
  const controller = new AbortController()
  return {
    signal: controller.signal,
    cancel: () => controller.abort()
  }
}

// ==================== 统一跳转管理 API ====================

// 获取跳转规则列表
export const getJumpRules = (params = {}) => request.get('?action=jump_list', { params })

// 创建跳转规则
export const createJumpRule = (type, data) => request.post('?action=jump_create', { rule_type: type, ...data })

// 更新跳转规则
export const updateJumpRule = (id, data) => request.post('?action=jump_update', { id, ...data })

// 删除跳转规则
export const deleteJumpRule = (id) => request.post('?action=jump_delete', { id })

// 切换跳转规则状态
export const toggleJumpRule = (id) => request.post('?action=jump_toggle', { id })

// 批量创建跳转规则
export const batchCreateJumpRules = (type, items, targetUrl = '', domainId = null) => 
  request.post('?action=jump_batch_create', { rule_type: type, items, target_url: targetUrl, domain_id: domainId })

// 获取跳转规则统计
export const getJumpRuleStats = (id, days = 7) => request.get('?action=jump_stats', { params: { id, days } })

// 获取分组列表
export const getJumpGroups = () => request.get('?action=jump_groups')

// 创建分组
export const createJumpGroup = (data) => request.post('?action=jump_group_create', data)

// 删除分组
export const deleteJumpGroup = (tag) => request.post('?action=jump_group_delete', { tag })

// 获取仪表盘统计
export const getJumpDashboardStats = (ruleType = '') => 
  request.get('?action=jump_dashboard', { params: { rule_type: ruleType } })

// ==================== 域名池管理 API ====================

// 获取域名列表
export const getDomains = (enabledOnly = false) => 
  request.get('?action=domain_list', { params: { enabled_only: enabledOnly ? 1 : 0 } })

// 添加域名
export const addDomain = (data) => request.post('?action=domain_add', data)

// 更新域名
export const updateDomain = (id, data) => request.post('?action=domain_update', { id, ...data })

// 删除域名
export const deleteDomain = (id) => request.post('?action=domain_delete', { id })

// 检测域名解析
export const checkDomain = (domain) => request.get('?action=domain_check', { params: { domain } })

// 批量检测所有域名
export const checkAllDomains = () => request.get('?action=domain_check_all')

// ==================== 旧版兼容 API ====================

const api = {
  // 通用请求方法 - 支持两种调用方式：
  // 1. api.request({ action: 'xxx', ... }) 
  // 2. api.request('action', { ... })
  request: (actionOrData, params = {}) => {
    let data
    if (typeof actionOrData === 'string') {
      // 兼容旧调用方式: api.request('action_name', { param1: val1 })
      data = { action: actionOrData, ...params }
    } else {
      // 新调用方式: api.request({ action: 'action_name', ... })
      data = actionOrData
    }
    return request.post('', data, { cancelDuplicate: false })
  },
  
  // 登录相关
  login: (username, password, totpCode = '', remember = false) => request.post('?action=login', { 
    username, password, totp_code: totpCode, remember 
  }),
  logout: () => request.post('?action=logout'),
  checkLogin: () => request.get('?action=check_login'),
  
  // 统计相关
  getStats: () => request.get('?action=get_stats'),
  getIpStats: (ip) => request.get('?action=get_ip_stats', { params: { ip } }),
  clearStats: (ip) => request.post('?action=clear_stats', { ip }),
  
  // 系统设置
  changePassword: (oldPassword, newPassword) => request.post('?action=change_password', { 
    old_password: oldPassword, 
    new_password: newPassword 
  }),
  
  // 系统更新
  checkUpdate: () => request.get('?action=system_check_update'),
  doUpdate: () => request.post('?action=system_update'),
  getSystemInfo: () => request.get('?action=system_info'),
  
  // IP 池管理
  getIpPool: () => request.get('?action=get_ip_pool'),
  addToPool: (ips) => request.post('?action=add_to_pool', { ips }),
  removeFromPool: (ip) => request.delete('?action=remove_from_pool', { data: { ip } }),
  clearPool: () => request.delete('?action=clear_pool'),
  activateFromPool: (ips, url, note) => request.post('?action=activate_from_pool', { ips, url, note }),
  returnToPool: (ip) => request.post('?action=return_to_pool', { ip }),
  
  // Antibot 统计
  getAntibotStats: () => request.get('?action=get_antibot_stats'),
}

// 导出 getAntibotStats 供 Dashboard 使用
export const getAntibotStats = () => request.get('?action=get_antibot_stats')

// Cloudflare API 导出
export const cfGetConfig = () => request.get('?action=cf_get_config')
export const cfSaveConfig = (data) => request.post('?action=cf_save_config', data)
export const cfListZones = () => request.get('?action=cf_list_zones')
export const cfAddDomain = (data) => request.post('?action=cf_add_domain', data)
export const cfBatchAddDomains = (data) => request.post('?action=cf_batch_add_domains', data)
export const cfEnableHttps = (domain) => request.post('?action=cf_enable_https', { domain })
export const cfGetDnsRecords = (zoneId) => request.post('?action=cf_get_dns_records', { zone_id: zoneId })
export const cfAddDnsRecord = (data) => request.post('?action=cf_add_dns_record', data)
export const cfUpdateDnsRecord = (data) => request.post('?action=cf_update_dns_record', data)
export const cfDeleteDnsRecord = (zoneId, recordId) => request.post('?action=cf_delete_dns_record', { zone_id: zoneId, record_id: recordId })
export const cfGetZoneDetails = (zoneId) => request.post('?action=cf_get_zone_details', { zone_id: zoneId })
export const cfDeleteZone = (zoneId, domain) => request.post('?action=cf_delete_zone', { zone_id: zoneId, domain })

// Namemart 域名购买 API 导出
export const nmGetConfig = () => request.get('?action=nm_get_config')
export const nmSaveConfig = (data) => request.post('?action=nm_save_config', data)
export const nmCheckDomains = (domains) => request.post('?action=nm_check_domains', { domains })
export const nmRegisterDomains = (data) => request.post('?action=nm_register_domains', data)
export const nmGetTaskStatus = (taskNo) => request.post('?action=nm_get_task_status', { task_no: taskNo })
export const nmGetDomainInfo = (domain) => request.post('?action=nm_get_domain_info', { domain })
export const nmUpdateDns = (domain, dns1, dns2) => request.post('?action=nm_update_dns', { domain, dns1, dns2 })
export const nmCreateContact = (data) => request.post('?action=nm_create_contact', data)
export const nmGetContactInfo = (contactId) => request.post('?action=nm_get_contact_info', { contact_id: contactId })

// 域名安全检测 API 导出
export const domainSafetyCheck = (domain, domainId) => request.post('?action=domain_safety_check', { domain, domain_id: domainId })
export const domainSafetyCheckAll = () => request.post('?action=domain_safety_check_all')
export const domainSafetyStats = () => request.get('?action=domain_safety_stats')
export const domainSafetyLogs = (limit = 100) => request.get('?action=domain_safety_logs', { params: { limit } })

// ==================== 数据大盘 API ====================
export const getDashboardStats = () => request.get('?action=dashboard_stats')
export const getTrendData = (range = '7d') => request.get('?action=dashboard_trend', { params: { range } })
export const getRealtimeLogs = (limit = 20) => request.get('?action=realtime_logs', { params: { limit } })
export const getSystemStatus = () => request.get('?action=system_status')

// ==================== 批量导入导出 API ====================
export const exportData = (type, format = 'csv', filters = {}) => 
  request.post('?action=export_data', { type, format, filters }, { responseType: 'blob' })
export const importData = (type, file) => {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('type', type)
  return request.post('?action=import_data', formData, {
    headers: { 'Content-Type': 'multipart/form-data' }
  })
}
export const getExportTemplate = (type, format = 'csv') =>
  request.get('?action=export_template', { params: { type, format }, responseType: 'blob' })

// ==================== Webhook 管理 API ====================
export const getWebhooks = () => request.get('?action=webhooks_list')
export const createWebhook = (data) => request.post('?action=webhook_create', data)
export const updateWebhook = (id, data) => request.post('?action=webhook_update', { id, ...data })
export const deleteWebhook = (id) => request.post('?action=webhook_delete', { id })
export const testWebhook = (id) => request.post('?action=webhook_test', { id })
export const getWebhookLogs = (webhookId, limit = 50) => 
  request.get('?action=webhook_logs', { params: { webhook_id: webhookId, limit } })

// ==================== 审计日志 API ====================
export const getAuditLogs = (params = {}) => request.get('?action=audit_logs', { params })
export const exportAuditLogs = (params = {}) => 
  request.post('?action=audit_logs_export', params, { responseType: 'blob' })

// ==================== 用户管理 API ====================
export const getUsers = () => request.get('?action=users_list')
export const createUser = (data) => request.post('?action=user_create', data)
export const updateUser = (id, data) => request.post('?action=user_update', { id, ...data })
export const deleteUser = (id) => request.post('?action=user_delete', { id })
export const resetUserPassword = (id) => request.post('?action=user_reset_password', { id })

// ==================== TOTP 双因素认证 API ====================
export const getTotpStatus = () => request.get('?action=totp_status')
export const enableTotp = () => request.post('?action=totp_enable')
export const verifyTotp = (code) => request.post('?action=totp_verify', { code })
export const disableTotp = (code) => request.post('?action=totp_disable', { code })

// ==================== API Key 管理 ====================
export const getApiKeys = () => request.get('?action=api_keys_list')
export const createApiKey = (data) => request.post('?action=api_key_create', data)
export const updateApiKey = (id, data) => request.post('?action=api_key_update', { id, ...data })
export const deleteApiKey = (id) => request.post('?action=api_key_delete', { id })
export const regenerateApiKey = (id) => request.post('?action=api_key_regenerate', { id })

// ==================== 备份管理 API ====================
export const getBackupList = () => request.get('?action=backup_list')
export const createBackup = (uploadToCloud = true) => 
  request.post('?action=backup_create', { upload_to_cloud: uploadToCloud })
export const restoreBackup = (filename) => request.post('?action=backup_restore', { filename })
export const downloadBackup = (filename) => 
  request.get('?action=backup_download', { params: { filename }, responseType: 'blob' })
export const deleteBackup = (filename) => request.post('?action=backup_delete', { filename })

// ==================== 系统监控 API ====================
export const getSystemHealth = () => request.get('?action=system_health')
export const getPrometheusMetrics = () => request.get('?action=prometheus_metrics')
export const getCacheStats = () => request.get('?action=cache_stats')

export default api
