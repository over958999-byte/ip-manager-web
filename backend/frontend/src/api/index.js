import axios from 'axios'
import { ElMessage } from 'element-plus'

// 创建axios实例
const request = axios.create({
  baseURL: '/api.php',
  timeout: 30000,
  withCredentials: true
})

// 响应拦截器
request.interceptors.response.use(
  response => response.data,
  error => {
    const message =
      error?.response?.data?.message ||
      error?.message ||
      '请求失败'
    ElMessage.error(message)
    return Promise.reject(error)
  }
)

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
  // 登录相关
  login: (password) => request.post('?action=login', { password }),
  logout: () => request.post('?action=logout'),
  checkLogin: () => request.get('?action=check_login'),
  
  // IP跳转管理（兼容旧版）
  getRedirects: () => request.get('?action=get_redirects'),
  addRedirect: (data) => request.post('?action=add_redirect', data),
  updateRedirect: (data) => request.post('?action=update_redirect', data),
  deleteRedirect: (ip) => request.post('?action=delete_redirect', { ip }),
  toggleRedirect: (ip) => request.post('?action=toggle_redirect', { ip }),
  batchAdd: (data) => request.post('?action=batch_add', data),
  
  // 统计相关
  getStats: () => request.get('?action=get_stats'),
  getIpStats: (ip) => request.get('?action=get_ip_stats', { params: { ip } }),
  clearStats: (ip) => request.post('?action=clear_stats', { ip }),
  
  // IP池管理
  getIpPool: () => request.get('?action=get_ip_pool'),
  addToPool: (ips) => request.post('?action=add_to_pool', { ips }),
  removeFromPool: (ips) => request.post('?action=remove_from_pool', { ips }),
  clearPool: () => request.post('?action=clear_pool'),
  activateFromPool: (data) => request.post('?action=activate_from_pool', data),
  returnToPool: (ip) => request.post('?action=return_to_pool', { ip }),
  
  // 反爬虫管理
  getAntibotStats: () => request.get('?action=get_antibot_stats'),
  getAntibotConfig: () => request.get('?action=get_antibot_config'),
  updateAntibotConfig: (config) => request.post('?action=update_antibot_config', { config }),
  antibotUnblock: (ip) => request.post('?action=antibot_unblock', { ip }),
  antibotClearBlocks: () => request.post('?action=antibot_clear_blocks'),
  antibotResetStats: () => request.post('?action=antibot_reset_stats'),
  antibotAddBlacklist: (ip) => request.post('?action=antibot_add_blacklist', { ip }),
  antibotRemoveBlacklist: (ip) => request.post('?action=antibot_remove_blacklist', { ip }),
  antibotAddWhitelist: (ip) => request.post('?action=antibot_add_whitelist', { ip }),
  antibotRemoveWhitelist: (ip) => request.post('?action=antibot_remove_whitelist', { ip }),
  
  // 系统设置
  changePassword: (oldPassword, newPassword) => request.post('?action=change_password', { 
    old_password: oldPassword, 
    new_password: newPassword 
  }),
  exportData: () => request.get('?action=export'),
  importData: (data) => request.post('?action=import', { data }),
  
  // 系统更新
  checkUpdate: () => request.get('?action=system_check_update'),
  doUpdate: () => request.post('?action=system_update'),
  getSystemInfo: () => request.get('?action=system_info'),
  
  // Cloudflare API
  cfGetConfig: () => request.get('?action=cf_get_config'),
  cfSaveConfig: (data) => request.post('?action=cf_save_config', data),
  cfListZones: () => request.get('?action=cf_list_zones'),
  cfAddDomain: (data) => request.post('?action=cf_add_domain', data),
  cfBatchAddDomains: (data) => request.post('?action=cf_batch_add_domains', data),
  cfEnableHttps: (domain) => request.post('?action=cf_enable_https', { domain }),
  
  // 域名安全检测 API
  domainSafetyCheck: (domain, domainId) => request.post('?action=domain_safety_check', { domain, domain_id: domainId }),
  domainSafetyCheckAll: () => request.post('?action=domain_safety_check_all'),
  domainSafetyStats: () => request.get('?action=domain_safety_stats'),
  domainSafetyLogs: (limit = 100) => request.get('?action=domain_safety_logs', { params: { limit } }),
  domainSafetyConfig: () => request.get('?action=domain_safety_config'),
  domainSafetySaveConfig: (config) => request.post('?action=domain_safety_config', { config }),
  
  // 通用请求方法
  request: (action, data = {}) => request.post(`?action=${action}`, data)
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

export default api
