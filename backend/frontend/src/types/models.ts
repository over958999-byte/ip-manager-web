/**
 * 跳转规则类型定义
 */
export interface JumpRule {
  id: number
  rule_type: 'ip' | 'ip_segment' | 'wildcard'
  match_key: string
  target_url: string
  enabled: boolean
  group_tag?: string
  priority: number
  hit_count: number
  remark?: string
  created_at: string
  updated_at: string
  last_access_at?: string
}

/**
 * 跳转规则创建/更新输入
 */
export interface JumpRuleInput {
  rule_type: 'ip' | 'ip_segment' | 'wildcard'
  match_key: string
  target_url: string
  enabled?: boolean
  group_tag?: string
  priority?: number
  remark?: string
}

/**
 * 短链接类型定义
 */
export interface Shortlink {
  id: number
  code: string
  target_url: string
  clicks: number
  enabled: boolean
  expires_at?: string
  max_clicks?: number
  password?: string
  created_at: string
  updated_at: string
}

/**
 * 短链接创建输入
 */
export interface ShortlinkInput {
  code?: string
  target_url: string
  expires_at?: string
  max_clicks?: number
  password?: string
}

/**
 * 域名类型定义
 */
export interface Domain {
  id: number
  domain: string
  type: 'jump' | 'shortlink' | 'both'
  enabled: boolean
  ssl_enabled: boolean
  cloudflare_zone_id?: string
  safety_status: 'safe' | 'warning' | 'danger' | 'unknown'
  safety_details?: string
  last_check_at?: string
  created_at: string
}

/**
 * 统计数据
 */
export interface DashboardStats {
  total_rules: number
  active_rules: number
  total_hits: number
  today_hits: number
  total_shortlinks: number
  total_domains: number
  antibot_blocks: number
  cache_hit_rate: number
}

/**
 * 图表数据点
 */
export interface ChartDataPoint {
  time: string
  value: number
}

/**
 * 访问日志
 */
export interface AccessLog {
  id: number
  ip_address: string
  rule_id?: number
  target_url: string
  user_agent?: string
  referer?: string
  country?: string
  city?: string
  created_at: string
}
