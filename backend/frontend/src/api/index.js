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
    ElMessage.error(error.message || '请求失败')
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
  getSystemInfo: () => request.get('?action=system_info')
}

export default api
